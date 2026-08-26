<?php

namespace Lamb\Micropub;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use RedBeanPHP\OODBBean;
use Psr\Log\AbstractLogger;
use Taproot\Micropub\MicropubAdapter;

use function Lamb\add_body_tags;
use function Lamb\Bootstrap\cache_headers;
use function Lamb\get_tags;
use function Lamb\is_deleted;
use function Lamb\is_publicly_visible;
use function Lamb\is_unpublished;
use function Lamb\normalize_datetime;
use function Lamb\parse_bean;
use function Lamb\permalink;
use function Lamb\remove_body_tags;
use function Lamb\sanitize_tag_name;
use function Lamb\strip_trailing_body_tags;
use function Lamb\Post\build_matter;
use function Lamb\Post\matter_string;
use function Lamb\Post\matter_url_list;
use function Lamb\Post\normalize_frontmatter_fence;
use function Lamb\Post\parse_matter;
use function Lamb\Post\populate_bean;
use function Lamb\Post\save;
use function Lamb\Post\set_frontmatter_key;
use function Lamb\Post\set_reply_to;
use function Lamb\Post\split_frontmatter;
use function Lamb\Post\split_reply_targets;

class LambMicropubAdapter extends MicropubAdapter
{
    /**
     * Micropub properties an update writes to a single front-matter key.
     *
     * `in-reply-to` is deliberately absent: it needs h-cite unwrapping and its
     * own multi-target rules, so it keeps its own branch in each apply method.
     */
    private const MATTER_PROPERTIES = [
        'name'            => 'title',
        'syndication'     => 'syndicated-to',
        'mp-syndicate-to' => 'syndicated-to',
    ];

    /**
     * Return the source properties of a post identified by URL.
     *
     * @param string $url
     * @param list<string>|null $properties Specific properties to return; null means all.
     * @return array{type: list<string>, properties: array<string, mixed>}|false
     */
    public function sourceQueryCallback(string $url, ?array $properties = null)
    {
        $bean = $this->findPostByUrl($url);
        if ($bean === null) {
            return false;
        }

        // Unlike create/update/delete, a source query has no scope of its own —
        // any token that merely verifies for this site would otherwise get
        // full read access to every draft/scheduled/trashed post's content
        // (sequential /status/<id> ids make this trivial to enumerate). A
        // publicly-visible post's content is already public, so any valid
        // token may read it; a hidden one requires 'update' scope (the same
        // trust level needed to edit it) and otherwise looks exactly like a
        // nonexistent post, so this can't be used as an existence oracle either.
        if (!is_publicly_visible($bean) && $this->lacksScope('update')) {
            return false;
        }

        $props = $this->beanToMf2Properties($bean);

        if ($properties !== null) {
            $props = array_intersect_key($props, array_flip($properties));
        }

        return ['type' => ['h-entry'], 'properties' => $props];
    }

    /**
     * Find a post bean by its permalink URL.
     *
     * @param string $url
     * @return OODBBean|null
     */
    private function findPostByUrl(string $url): ?OODBBean
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return \Lamb\find_post_by_path($path);
    }

    /**
     * Convert a post bean to a flat MF2 properties array.
     *
     * @param OODBBean $bean
     * @return array<string, mixed>
     */
    private function beanToMf2Properties(OODBBean $bean): array
    {
        $body = $bean->body ?? '';
        [, $content] = split_frontmatter($body);
        $content = trim($content);

        // Strip trailing hashtags — categories appended by buildBody during creation.
        $content = rtrim(preg_replace('/([ \t]+#\S+)+$/', '', $content) ?? $content);

        $props = ['content' => [$content]];

        if (!empty($bean->title)) {
            $props['name'] = [$bean->title];
        }

        $tags = get_tags($body);
        if (!empty($tags)) {
            $props['category'] = $tags;
        }

        // A post may record several reply targets (#583); the source query
        // reports every one, space-separated in the stored column just like
        // syndicated_to.
        $reply_targets = split_reply_targets((string) ($bean->in_reply_to ?? ''));
        if ($reply_targets !== []) {
            $props['in-reply-to'] = $reply_targets;
        }

        if (!empty($bean->syndicated_to)) {
            $props['syndication'] = preg_split('/\s+/', trim((string) $bean->syndicated_to));
        }

        return $props;
    }

    /**
     * Verify the bearer token by introspecting it against the configured token endpoint.
     *
     * @param string $token
     * @return array{me: mixed, scope: list<string>}|false
     */
    public function verifyAccessTokenCallback(string $token)
    {
        global $config;
        $endpoint = $config['token_endpoint'] ?? 'https://tokens.indieauth.com/token';

        $data = $this->introspectToken($token, $endpoint);
        if ($data === null) {
            mp_log('token_verify', [
                'reason'   => 'introspection_failed',
                'endpoint' => $endpoint,
                'token'    => token_fingerprint($token),
            ]);
            return false;
        }
        if (empty($data['me'])) {
            mp_log('token_verify', [
                'reason' => 'no_me',
                'token'  => token_fingerprint($token),
            ]);
            return false;
        }

        // Compare against the *configured* canonical URL, never ROOT_URL: ROOT_URL
        // falls back to the client-supplied Host header, so an attacker holding a
        // token the endpoint issued for their own identity could send
        // `Host: their-site.example` and have this check compare their `me` against
        // their own host — accepting the token as ours. Fail closed when no
        // canonical URL is configured: with nothing trustworthy to compare, the
        // identity of the token cannot be established.
        $expected = \Lamb\Config\canonical_site_url($config);
        if ($expected === null) {
            mp_log('token_verify', [
                'reason' => 'no_site_url',
                'token'  => token_fingerprint($token),
            ]);
            // Surfaced unconditionally: mp_log() is silent unless micropub_debug is
            // on, and an author whose client suddenly gets 403 needs to be told why.
            error_log('micropub: rejecting token, no site_url configured (set site_url in /settings)');
            return false;
        }

        if (rtrim($data['me'], '/') !== rtrim($expected, '/')) {
            mp_log('token_verify', [
                'reason'   => 'me_mismatch',
                'me'       => $data['me'],
                'expected' => $expected,
                'token'    => token_fingerprint($token),
            ]);
            return false;
        }

        $scope = isset($data['scope']) ? explode(' ', $data['scope']) : [];

        mp_log('token_verify', [
            'reason' => 'ok',
            'me'     => $data['me'],
            'scope'  => $scope,
            'token'  => token_fingerprint($token),
        ]);

        return [
            'me'    => $data['me'],
            'scope' => $scope,
        ];
    }

    /**
     * Call the token endpoint to introspect a bearer token.
     * Returns the parsed JSON response or null on failure.
     *
     * @param string $token
     * @param string $endpoint
     * @return array<string, mixed>|null
     */
    protected function introspectToken(string $token, string $endpoint): ?array
    {
        $result = \Lamb\Http\fetch($endpoint, [
            'headers' => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            'timeout' => 5,
            // Never follow a redirect on this request. PHP's stream wrapper
            // re-sends the context's `header` option verbatim to the redirect
            // target, including across a change of authority — so following one
            // would hand the author's bearer token to whatever host the token
            // endpoint points at (an open redirect there, or a takeover of it, is
            // enough). A token endpoint that answers with a redirect is treated
            // as a failed introspection instead.
            'follow_location' => 0,
            'max_redirects' => 0,
            // An introspection response is a small JSON document; cap the read so
            // a misbehaving endpoint cannot stream an unbounded body into memory.
            'max_bytes' => 65536,
        ]);

        if ($result === null) {
            mp_log('introspect', ['endpoint' => $endpoint, 'reason' => 'fetch_failed']);
            return null;
        }

        // Http\parse_status_line(), not a substring test for ' 200 '. That test
        // answered on the wrong thing in both directions: it read a status line
        // with no reason phrase ("HTTP/1.1 200", "HTTP/2 200") as a failure, so a
        // conforming token endpoint could take the whole Micropub endpoint down
        // with no usable diagnosis; and it matched ' 200 ' anywhere in the line,
        // so "HTTP/1.1 500 Error 200 x" read as a success — a fail-open answer on
        // the request that establishes who the caller is.
        $statusLine = $result['headers'][0] ?? '';
        $status = \Lamb\Http\parse_status_line($statusLine);
        if ($status !== 200) {
            mp_log('introspect', ['endpoint' => $endpoint, 'reason' => 'non_200', 'status' => trim($statusLine)]);
            return null;
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            mp_log('introspect', ['endpoint' => $endpoint, 'reason' => 'bad_json']);
            return null;
        }

        // Log only the response's keys (e.g. me, scope, client_id) — never the values,
        // which can echo the token on some endpoints.
        mp_log('introspect', ['endpoint' => $endpoint, 'reason' => 'ok', 'keys' => array_keys($data)]);
        return $data;
    }

    /**
     * Build the 403 response for a token that lacks the scope an action requires.
     *
     * Both RFC 6750 §3.1 and the W3C Micropub error-response section map
     * `insufficient_scope` to HTTP 403 (a valid token was supplied, but it lacks
     * the privilege) — 401 is reserved for a missing or invalid token. The
     * taproot/micropub-adapter already returns 403, but without the RFC 6750 §3
     * `WWW-Authenticate: Bearer` challenge, so the callbacks return this response
     * directly to attach it. (micropub.rocks test 804 wants 401, contradicting the
     * spec — see aaronpk/micropub.rocks#101 — so we follow the spec, not the test.)
     *
     * @param string $requiredScope The scope the rejected action needs (e.g. 'create', 'update').
     * @return Response
     */
    private function insufficientScopeResponse(string $requiredScope): Response
    {
        return new Response(
            403,
            [
                'content-type'     => 'application/json',
                'www-authenticate' => bearer_challenge('insufficient_scope', $requiredScope),
            ],
            json_encode([
                'error'             => 'insufficient_scope',
                'error_description' => 'Your access token does not grant the scope required for this action.',
            ]) ?: ''
        );
    }

    /**
     * The insufficient_scope response for an action the token may not perform,
     * or null when it may proceed.
     *
     * The gate every scope-checked callback shares, so all four ask the same
     * question and name their own scope in the challenge. A request with no token
     * at all ($this->user === null) is not gated here: the callbacks are also
     * reached from the logged-in web paths, whose authorisation happened earlier.
     *
     * @param string $scope The scope this action requires (e.g. 'create', 'delete').
     * @return Response|null
     */
    private function scopeRejection(string $scope): ?Response
    {
        return $this->lacksScope($scope) ? $this->insufficientScopeResponse($scope) : null;
    }

    /**
     * Whether the request carries a token that does *not* grant $scope.
     *
     * False for an untokened request, so the callbacks stay usable from the
     * logged-in web paths (see scopeRejection()). Kept separate from
     * scopeRejection() for the one gate that answers with something other than
     * an insufficient_scope response: a source query for a hidden post hides it
     * instead (returns false), rather than confirming it exists.
     *
     * @param string $scope The scope being demanded.
     * @return bool
     */
    private function lacksScope(string $scope): bool
    {
        return $this->user !== null && !in_array($scope, $this->user['scope'] ?? [], true);
    }

    /**
     * Handle a micropub create request.
     *
     * @param array<string, mixed> $data  Normalised microformats2 data.
     * @param array<string, mixed> $uploadedFiles
     * @return string|array<string, mixed>|\Psr\Http\Message\ResponseInterface
     */
    public function createCallback(array $data, array $uploadedFiles = [])
    {
        $rejection = $this->scopeRejection('create');
        if ($rejection !== null) {
            return $rejection;
        }

        $props = $data['properties'] ?? [];

        // Merge any uploaded photo files into the photo property as URLs.
        $uploadedPhotoUrls = $this->saveUploadedPhotos($uploadedFiles);
        if (!empty($uploadedPhotoUrls)) {
            $props['photo'] = array_merge($props['photo'] ?? [], $uploadedPhotoUrls);
        }

        ['content' => $content, 'is_html' => $isHtml] = $this->extractContent($props);
        if ($content === null) {
            return 'invalid_request';
        }

        $body = $this->buildBody($props, $isHtml ? strip_tags($content) : $content);

        $bean = populate_bean($body);

        if ($isHtml) {
            $bean->transformed = $this->sanitizeHtml($content);
        }

        // normalize_datetime() rather than strtotime(): it accepts the same
        // shapes front matter does and returns null instead of false, so a
        // non-string `published` no longer TypeErrors and an unparseable one no
        // longer silently backdates the post to 1970 (strtotime() returns false,
        // which date() reads as the epoch).
        $published = normalize_datetime($props['published'][0] ?? null);
        if ($published !== null) {
            $bean->created = $published;
        }

        $postStatus = $props['post-status'][0] ?? null;
        if ($postStatus === 'draft') {
            $bean->draft = 1;
        }
        // A "scheduled" post is a published-intent post with a future `published` date;
        // it is never a draft. Visibility is driven by the future `created` date set
        // above, so it stays hidden from public listings until that time arrives.

        // Unpublished posts 404 anonymously (#284), but clients GET the Location
        // URL we return to show the just-created post. Attach a short-lived
        // preview token so that URL works without a Lamb session (#285).
        $needs_preview = is_unpublished($bean);
        \Lamb\ensure_preview_token($bean);

        // Stores, pins the final slug, and emits post.published — the slug must
        // be settled before the Location permalink is computed below.
        save($bean, ['finalize_slug' => true, 'notify' => true]);

        $location = permalink($bean);
        if ($needs_preview) {
            $location .= '?preview=' . $bean->preview_token;
        }

        return $location;
    }

    /**
     * Return the configuration query response including configured syndicate-to targets.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function configurationQueryCallback(array $params): array
    {
        global $config;
        $targets = [];
        foreach ($config['syndicate_to'] ?? [] as $uid => $name) {
            $targets[] = ['uid' => (string) $uid, 'name' => (string) $name];
        }
        return [
            'q'              => ['config', 'source', 'syndicate-to'],
            'media-endpoint' => ROOT_URL . '/micropub-media',
            'syndicate-to'   => $targets,
        ];
    }

    /**
     * Handle a micropub delete request.
     *
     * @param string $url
     * @return true|string|\Psr\Http\Message\ResponseInterface
     */
    public function deleteCallback(string $url)
    {
        $rejection = $this->scopeRejection('delete');
        if ($rejection !== null) {
            return $rejection;
        }

        $bean = $this->findPostByUrl($url);
        if ($bean === null) {
            return 'invalid_request';
        }

        // Share the web delete path so a Micropub delete also stamps deleted_at
        // (for purging) and re-sends webmentions for the now-gone post (#331).
        \Lamb\Response\soft_delete_post($bean);

        return true;
    }

    /**
     * Handle a micropub undelete request.
     *
     * @param string $url
     * @return true|string|\Psr\Http\Message\ResponseInterface
     */
    public function undeleteCallback(string $url)
    {
        // Gated on 'delete' scope, not a separate 'undelete' one: undelete is
        // the reversal of the same destructive action, and this codebase
        // doesn't otherwise define a distinct scope for it.
        $rejection = $this->scopeRejection('delete');
        if ($rejection !== null) {
            return $rejection;
        }

        $bean = $this->findPostByUrl($url);
        if ($bean === null) {
            return 'invalid_request';
        }

        // Share the web restore path so a Micropub undelete also reconciles any
        // deletion webmention re-sends (#331).
        \Lamb\Response\restore_post($bean);

        return true;
    }

    /**
     * Handle a micropub update request (replace/add/delete operations).
     *
     * @param string $url
     * @param array<string, mixed>  $actions
     * @return true|string|array<string, mixed>|\Psr\Http\Message\ResponseInterface
     */
    public function updateCallback(string $url, array $actions)
    {
        $bean = $this->findPostByUrl($url);
        // A soft-deleted post is meant to stay immutable until explicitly
        // restored via the delete-scoped undeleteCallback(); treating it the
        // same as "no such post" here also means this can't be used to tell
        // a trashed post's id apart from a nonexistent one.
        if ($bean === null || is_deleted($bean)) {
            return 'invalid_request';
        }

        $rejection = $this->scopeRejection('update');
        if ($rejection !== null) {
            return $rejection;
        }

        // Captured before any operation runs: whether this update *mints* a
        // slug decides whether it is finalised below.
        $slug_before = (string) ($bean->slug ?? '');

        foreach ($actions['replace'] ?? [] as $property => $values) {
            if (!is_array($values) || !array_is_list($values)) {
                return 'invalid_request';
            }
            if (!$this->applyReplace($bean, $property, $values)) {
                return 'invalid_request';
            }
        }

        foreach ($actions['add'] ?? [] as $property => $values) {
            if (!is_array($values) || !array_is_list($values)) {
                return 'invalid_request';
            }
            // Nothing is stored until the end of this method, so refusing here
            // leaves the post exactly as it was — which is the point: a 200 for
            // an add the storage cannot hold reads to the client as "saved".
            if (!$this->applyAdd($bean, $property, $values)) {
                return 'invalid_request';
            }
        }

        $delete = $actions['delete'] ?? [];
        if (array_is_list($delete)) {
            // Indexed array: delete entire properties.
            foreach ($delete as $property) {
                if (!$this->applyDeleteProperty($bean, (string) $property)) {
                    return 'invalid_request';
                }
            }
        } else {
            // Associative array: delete specific values from each property.
            foreach ($delete as $property => $values) {
                if (!is_array($values) || !array_is_list($values)) {
                    return 'invalid_request';
                }
                if (!$this->applyDeleteValues($bean, $property, $values)) {
                    return 'invalid_request';
                }
            }
        }

        parse_bean($bean);
        $bean->updated = \Lamb\now();
        // An update can mint a slug where there was none: naming a titleless
        // status post derives one from the title. Without finalize_slug() that
        // slug skipped the uniqueness and reserved-route checks every other
        // save path applies, so two posts could end up sharing a URL — one of
        // them unreachable at its own permalink, with its feed entry pointing
        // at the other's content.
        //
        // Only a freshly minted slug is finalised. A post that already had one
        // keeps it untouched (pinSlug() writes it into the front matter before
        // a rename): moving a live permalink — the URL the client just used to
        // address the post — is worse than leaving a pre-existing duplicate be.
        // Captured here, before save(), since it depends on $slug_before (the
        // pre-edit state) rather than anything the store changes.
        $needs_slug = $slug_before === '';
        save($bean, ['finalize_slug' => $needs_slug, 'notify' => true]);

        return true;
    }

    /**
     * Apply an add operation for a single property to a post bean.
     *
     * @param OODBBean    $bean
     * @param string      $property
     * @param list<mixed> $values
     * @return bool False when the operation cannot be honoured (the caller turns
     *              that into an invalid_request response).
     */
    private function applyAdd(OODBBean $bean, string $property, array $values): bool
    {
        if ($property === 'category') {
            $bean->body = add_body_tags($bean->body ?? '', self::textValues($values));
            return true;
        }

        if (isset(self::MATTER_PROPERTIES[$property])) {
            // Micropub's `add` appends to a multi-valued property, and these are
            // one front-matter line each: there is nothing to append to. Replace
            // is the operation that fits, so say so rather than report a success
            // the storage did not have.
            return false;
        }

        if ($property === 'in-reply-to') {
            // Adding nothing is a no-op, not a failure.
            if ($values === []) {
                return true;
            }
            // A post may record several reply targets (#583): `add` appends every
            // value the client sent to whatever is already stored (deduplicated),
            // rather than refusing a second target outright as it used to (#582)
            // or silently keeping only the first. A value carrying no URL is an
            // add that cannot be honoured — refuse it, as applyReplace() does,
            // rather than report a success the storage did not have.
            $targets = $this->currentReplyToList($bean);
            foreach ($values as $value) {
                $target = $this->replyTargetUrl($value);
                if ($target === null) {
                    return false;
                }
                if (!in_array($target, $targets, true)) {
                    $targets[] = $target;
                }
            }
            $bean->body = set_reply_to($bean->body ?? '', $targets);
            return true;
        }

        return false;
    }

    /**
     * Apply a delete-property operation (remove all values) to a post bean.
     *
     * @param OODBBean $bean
     * @param string   $property
     * @return bool False when the operation cannot be honoured (the caller turns
     *              that into an invalid_request response).
     */
    private function applyDeleteProperty(OODBBean $bean, string $property): bool
    {
        if ($property === 'category') {
            $bean->body = strip_trailing_body_tags($bean->body ?? '');
            return true;
        }

        if ($property === 'in-reply-to') {
            $bean->body = set_reply_to($bean->body ?? '', '');
            return true;
        }

        if (isset(self::MATTER_PROPERTIES[$property])) {
            $this->pinSlug($bean, self::MATTER_PROPERTIES[$property]);
            $bean->body = set_frontmatter_key($bean->body ?? '', self::MATTER_PROPERTIES[$property], '');
            return true;
        }

        return false;
    }

    /**
     * Apply a delete-values operation for a single property to a post bean.
     *
     * @param OODBBean    $bean
     * @param string      $property
     * @param list<mixed> $values
     * @return bool False when the operation cannot be honoured (the caller turns
     *              that into an invalid_request response).
     */
    private function applyDeleteValues(OODBBean $bean, string $property, array $values): bool
    {
        if ($property === 'category') {
            $bean->body = remove_body_tags($bean->body ?? '', self::textValues($values));
            return true;
        }

        if ($property === 'in-reply-to') {
            // Value-scoped delete: only the target(s) the client named go, so a
            // stale value in a client's copy cannot clear a target still in
            // use, and (#583) deleting one of several leaves the rest intact.
            $current = $this->currentReplyToList($bean);
            if ($current === []) {
                return true;
            }
            $remove = array_filter(
                array_map(fn($value) => $this->replyTargetUrl($value), $values),
                fn(?string $target) => $target !== null
            );
            $remaining = array_values(array_diff($current, $remove));
            if ($remaining !== $current) {
                $bean->body = set_reply_to($bean->body ?? '', $remaining);
            }
            return true;
        }

        return false;
    }

    /**
     * Apply a replace operation for a single property to a post bean.
     *
     * @param OODBBean    $bean
     * @param string      $property
     * @param list<mixed> $values
     * @return bool False when the operation cannot be honoured (the caller turns
     *              that into an invalid_request response).
     */
    private function applyReplace(OODBBean $bean, string $property, array $values): bool
    {
        if ($property === 'content') {
            // An empty value list is Micropub's "replace with nothing".
            if ($values === []) {
                $bean->body = $this->rebuildBody($bean, '');
                return true;
            }
            // extractContent(), not (string) $values[0]: a content value is
            // legitimately `{"html": …}` or `{"value": …}`, which the create
            // path has always unwrapped. Cast here instead, that object became
            // the literal string "Array" (with an "Array to string conversion"
            // warning) and rebuildBody() wrote it over the entire post — a
            // spec-legal update from the same client that created the post
            // destroyed its content.
            ['content' => $newContent, 'is_html' => $isHtml] = $this->extractContent(['content' => $values]);
            if ($newContent === null) {
                // A value carrying no text at all (an object with neither key)
                // is a replace the storage cannot honour; reporting success for
                // it reads to the client as "saved", as applyAdd() notes.
                return false;
            }
            // strip_tags() for the html shape, matching what createCallback()
            // stores in the body. `transformed` is regenerated from the body by
            // the parse_bean() at the end of updateCallback() either way.
            $bean->body = $this->rebuildBody($bean, $isHtml ? strip_tags($newContent) : $newContent);
            return true;
        }

        if ($property === 'category') {
            // Replace, not add: the categories the client names become the whole
            // set, so the hashtags already on the body go first.
            $bean->body = add_body_tags(
                strip_trailing_body_tags($bean->body ?? ''),
                self::textValues($values)
            );
            return true;
        }

        if ($property === 'in-reply-to') {
            // An empty value list is Micropub's "replace with nothing", i.e. the
            // post stops being a reply.
            if ($values === []) {
                $bean->body = set_reply_to($bean->body ?? '', '');
                return true;
            }

            // A post may record several reply targets (#583): every value the
            // client sent becomes the new (deduplicated) set.
            $targets = [];
            foreach ($values as $value) {
                $target = $this->replyTargetUrl($value);
                // A value carrying no URL is a replace that cannot be
                // honoured; reporting success for it reads to the client as
                // "saved", as the single-target path already guarded against.
                if ($target === null) {
                    return false;
                }
                if (!in_array($target, $targets, true)) {
                    $targets[] = $target;
                }
            }
            $bean->body = set_reply_to($bean->body ?? '', $targets);
            return true;
        }

        if (isset(self::MATTER_PROPERTIES[$property])) {
            $value = $this->matterValue($property, $values);
            if ($value === null) {
                return false;
            }
            $this->pinSlug($bean, self::MATTER_PROPERTIES[$property]);
            $bean->body = set_frontmatter_key($bean->body ?? '', self::MATTER_PROPERTIES[$property], $value);
            return true;
        }

        return false;
    }

    /**
     * Pin the slug a post is already served under before its title changes.
     *
     * parse_matter() derives the slug from the title when the front matter has
     * no explicit one, so a rename would move the permalink — the very URL the
     * Micropub client used to address the post.
     *
     * @param OODBBean $bean
     * @param string   $key The front-matter key about to be written.
     * @return void
     */
    private function pinSlug(OODBBean $bean, string $key): void
    {
        $slug = (string) ($bean->slug ?? '');
        if ($key !== 'title' || $slug === '') {
            return;
        }

        $bean->body = set_frontmatter_key($bean->body ?? '', 'slug', $slug);
    }

    /**
     * The front-matter text a replace of a single-valued property writes.
     *
     * Mirrors buildBody(): syndication targets join into one line, everything
     * else takes the first value. An empty value list is Micropub's "replace
     * with nothing", so it clears the key.
     *
     * @param string      $property
     * @param list<mixed> $values
     * @return string|null Null when the value carries no faithful text, which is
     *                     a replace that cannot be honoured.
     */
    private function matterValue(string $property, array $values): ?string
    {
        if ($values === []) {
            return '';
        }

        if ($property === 'name') {
            return matter_string($values[0] ?? null);
        }

        $targets = array_filter(
            array_map(matter_string(...), $values),
            fn(?string $target) => $target !== null && trim($target) !== ''
        );

        return implode(' ', $targets);
    }

    /**
     * The reply target(s) currently recorded in a bean's front matter.
     *
     * Read from the body rather than $bean->in_reply_to: an update applies a
     * sequence of operations to the body, and the column is only refreshed by
     * the parse_bean() call at the end of updateCallback(). matter_url_list(),
     * not matter_string(): a post may record several targets (#583), and
     * collapsing to the first here would make `add`/`delete` blind to the rest.
     *
     * @param OODBBean $bean
     * @return list<string> The target URLs, in order, or [] when the post is not a reply.
     */
    private function currentReplyToList(OODBBean $bean): array
    {
        $matter = parse_matter((string) ($bean->body ?? ''));

        return matter_url_list($matter['in-reply-to'] ?? null);
    }

    /**
     * Extract a reply target URL from a Micropub `in-reply-to` value.
     *
     * The value is a URL string in the simple case, but Micropub also allows an
     * embedded h-cite object (`{type: [h-cite], properties: {url: [...]}}`), which
     * is what a client sends when it has the parent's author and name to hand.
     * Both shapes have to yield the URL — matter_string() alone returns null for
     * the object, which silently turned an h-cite reply into a normal post.
     *
     * @param mixed $value A single value from the `in-reply-to` property.
     * @return string|null The target URL, or null when the value carries none.
     */
    private function replyTargetUrl(mixed $value): ?string
    {
        if (is_array($value)) {
            $nested = $value['properties']['url'] ?? $value['url'] ?? null;
            if ($nested !== null) {
                return $this->replyTargetUrl(is_array($nested) ? ($nested[0] ?? null) : $nested);
            }
        }

        $url = matter_string($value);

        return $url !== null && trim($url) !== '' ? trim($url) : null;
    }

    /**
     * Rebuild the post body with new content, preserving existing front matter and hashtags.
     *
     * The front-matter block is carried over verbatim rather than re-assembled:
     * assembly can only reproduce the keys this class knows about, and an update
     * is the one Micropub call that re-reads front matter the author wrote by
     * hand — including load-bearing keys like `draft:` and a pinned `slug:`.
     *
     * @param OODBBean $bean
     * @param string   $newContent
     * @return string
     */
    private function rebuildBody(OODBBean $bean, string $newContent): string
    {
        $currentBody = (string) ($bean->body ?? '');
        [$yaml] = split_frontmatter(normalize_frontmatter_fence($currentBody));

        // add_body_tags() rather than appending the old body's tags outright:
        // a tag the replacement content already carries must not be appended a
        // second time, or every content update grows the trailing run
        // (`goodbye #php #php`).
        $content = add_body_tags($newContent, get_tags($currentBody));

        return $yaml === '' ? $content : "---\n" . $yaml . "\n---\n" . $content;
    }

    /**
     * Assemble the post front-matter array from individual fields.
     *
     * Create-path only: an update edits the stored block in place instead, so
     * that keys this class does not know about survive.
     *
     * @param string|null $title
     * @param list<string> $replyTo One or more reply targets (#583), or [] for none.
     * @param string|null $syndicatedTo
     * @return array<string, string|list<string>>
     */
    private function assembleFrontMatter(?string $title, array $replyTo, ?string $syndicatedTo): array
    {
        $matter = [];
        if ($title !== null) {
            $matter['title'] = $title;
        }
        if ($replyTo !== []) {
            // A single target keeps the plain `in-reply-to: url` shape every
            // existing post already has; two or more become a YAML list.
            $matter['in-reply-to'] = count($replyTo) === 1 ? $replyTo[0] : $replyTo;
        }
        if ($syndicatedTo !== null && $syndicatedTo !== '') {
            $matter['syndicated-to'] = $syndicatedTo;
        }
        return $matter;
    }

    /**
     * Save uploaded photo files to the assets directory and return their public URLs.
     *
     * @param array<string, mixed> $uploadedFiles Associative array of field name → UploadedFileInterface (or array thereof).
     * @return list<string>
     */
    private function saveUploadedPhotos(array $uploadedFiles): array
    {
        $files = $uploadedFiles['photo'] ?? [];
        if ($files instanceof UploadedFileInterface) {
            $files = [$files];
        }

        $urls = [];
        foreach ($files as $file) {
            if (!($file instanceof UploadedFileInterface) || $file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $ext = \Lamb\Response\safe_upload_extension($file->getClientFilename() ?? '');
            if ($ext === null) {
                continue;
            }
            $sub_path  = \Lamb\Response\upload_subpath();
            $uploadDir = \Lamb\Response\get_upload_dir($sub_path);
            // Always salt with uniqid(), not only when the client filename is
            // absent — otherwise two uploads sharing a client filename in the
            // same month collide and the later one silently overwrites the
            // earlier, already-published one on disk.
            $seed      = sha1(($file->getClientFilename() ?? '') . uniqid('', true));

            $filename = \Lamb\Response\persist_image_bytes(
                (string) $file->getStream(),
                $ext,
                $uploadDir,
                $seed
            );
            if ($filename === null) {
                continue;
            }

            $urls[] = \Lamb\Response\asset_url($sub_path, $filename);
        }

        return $urls;
    }

    /**
     * Extract content from micropub properties.
     * Returns ['content' => string|null, 'is_html' => bool].
     *
     * @param array<string, mixed> $props
     * @return array{content: string|null, is_html: bool}
     */
    private function extractContent(array $props): array
    {
        if (empty($props['content'])) {
            return ['content' => null, 'is_html' => false];
        }

        $raw = $props['content'][0];

        if (is_array($raw)) {
            if (isset($raw['html'])) {
                return ['content' => $raw['html'], 'is_html' => true];
            }
            return ['content' => $raw['value'] ?? null, 'is_html' => false];
        }

        return ['content' => (string) $raw, 'is_html' => false];
    }

    /**
     * Build a Lamb post body (YAML front matter + markdown content).
     *
     * @param array<string, mixed> $props   Micropub properties.
     * @param string $content Plain-text body content.
     * @return string
     */
    private function buildBody(array $props, string $content): string
    {
        // Microformats properties are arrays of values, and a value is not
        // necessarily a string: `in-reply-to` is legitimately an embedded
        // h-cite object, and a client is free to send a nested array for
        // `name`. Both reached the ?string parameters of assembleFrontMatter()
        // as arrays and 500ed the create.
        $title = matter_string($props['name'][0] ?? null);

        // A client may legitimately send several reply targets (#583):
        // u-in-reply-to repeats in mf2, so `in-reply-to` here can carry more
        // than one value.
        $replyTargets = [];
        foreach ($props['in-reply-to'] ?? [] as $value) {
            $target = $this->replyTargetUrl($value);
            if ($target !== null && !in_array($target, $replyTargets, true)) {
                $replyTargets[] = $target;
            }
        }

        $photos = $this->buildPhotos($props['photo'] ?? []);
        if ($photos !== '') {
            $content = $content . "\n\n" . $photos;
        }

        $tags = $this->buildTags($props['category'] ?? []);
        if ($tags !== '') {
            $content = $content . ' ' . $tags;
        }

        $extra = $this->buildExtraProperties($props);
        if ($extra !== '') {
            $content = $content . "\n\n" . $extra;
        }

        // Filter to scalars before imploding: a nested array here produced an
        // "Array to string conversion" warning and stored the literal "Array"
        // as the syndication target.
        $syndicateTo  = array_filter(
            array_map(matter_string(...), array_values((array) ($props['mp-syndicate-to'] ?? []))),
            fn(?string $uid) => $uid !== null && $uid !== ''
        );
        $syndicatedTo = !empty($syndicateTo) ? implode(' ', $syndicateTo) : null;

        return build_matter(
            $this->assembleFrontMatter($title, $replyTargets, $syndicatedTo),
            $content
        );
    }

    /**
     * Serialize any extra nested MF2 properties (not content/name/category/photo/published)
     * as a JSON code block so they are preserved in storage.
     *
     * @param array<string, mixed> $props
     * @return string
     */
    private function buildExtraProperties(array $props): string
    {
        // `in-reply-to` belongs here with the rest: buildBody() consumes it into
        // front matter, so leaving it out dumped the reply target into the post
        // body a second time as a JSON code block readers could see.
        $known = [
            'content', 'name', 'category', 'photo', 'published', 'post-status',
            'mp-syndicate-to', 'in-reply-to',
        ];
        $extra = array_diff_key($props, array_flip($known));

        if (empty($extra)) {
            return '';
        }

        return "```json\n" . json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```";
    }

    /**
     * Tags kept in Micropub `content.html`. Everything else is unwrapped by
     * strip_tags(), which keeps the text inside it.
     */
    private const HTML_TAGS = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'caption',
        'code', 'del', 'em', 'figcaption', 'figure', 'h1', 'h2', 'h3',
        'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'li', 'ol', 'p',
        'pre', 'q', 's', 'small', 'strong', 'sub', 'sup',
        'table', 'tbody', 'td', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    /**
     * Attributes kept on a surviving element, by lower-case tag name. `*`
     * applies to every tag. An allowlist rather than a denylist of `on*`: a
     * denylist has to keep pace with every attribute that can run script or
     * reposition the page, and `style` alone is enough to cover the viewport.
     */
    private const HTML_ATTRIBUTES = [
        '*'          => ['title'],
        'a'          => ['href'],
        'blockquote' => ['cite'],
        'img'        => ['src', 'alt', 'width', 'height'],
        'ol'         => ['start'],
        'q'          => ['cite'],
        'td'         => ['colspan', 'rowspan'],
        'th'         => ['colspan', 'rowspan', 'scope'],
    ];

    /** Kept attributes whose value is a URL and must clear the scheme allowlist. */
    private const HTML_URL_ATTRIBUTES = ['href', 'src', 'cite'];

    /**
     * Sanitise the HTML of a Micropub `content.html` create.
     *
     * The result is written straight to the post's `transformed` column, which
     * every theme echoes raw into the page (`<?= anchor_headings($bean->transformed, …) ?>`)
     * and which is syndicated verbatim inside the Atom `<content type="html">`
     * and the JSON Feed's `content_html`. It never passes through Parsedown, so
     * safe mode does not apply to it.
     *
     * strip_tags() alone was not a sanitiser for that: it drops disallowed
     * *tags* and keeps every attribute of the ones it allows, so
     * `<img src=x onerror=…>`, `<p onmouseover=…>` and `<a href="javascript:…">`
     * all survived intact — stored XSS reachable by any token holding only
     * `create` scope, running in the author's logged-in session. The codebase
     * already treats that scope as a limited-trust principal (see
     * apply_frontmatter()'s note on `id:`/`deleted:`, and syndication_links()).
     *
     * Import\sanitize_html_in_dom() scrubs `on*` off imported HTML for the same
     * reason; this does the equivalent for the Micropub path, as an attribute
     * allowlist, and hands every URL attribute to LambDown::filterContentUrl()
     * so a link here is filtered exactly as the Markdown path filters one.
     *
     * @param string $html
     * @return string
     */
    private function sanitizeHtml(string $html): string
    {
        $html = strip_tags($html, self::HTML_TAGS);
        if (trim($html) === '') {
            return '';
        }

        $filter = new \Lamb\LambDown();
        $dom = \Lamb\Import\load_html_fragment($html);
        foreach ((new \DOMXPath($dom))->query('//*') ?: [] as $element) {
            if (!$element instanceof \DOMElement) {
                continue;
            }
            $this->sanitizeAttributes($element, $filter);
        }

        return \Lamb\Import\dump_html_fragment($dom);
    }

    /**
     * Reduces one element's attributes to the allowlisted set, with any URL
     * among them run through the same filter Parsedown's safe mode applies.
     */
    private function sanitizeAttributes(\DOMElement $element, \Lamb\LambDown $filter): void
    {
        $allowed = array_merge(
            self::HTML_ATTRIBUTES['*'],
            self::HTML_ATTRIBUTES[strtolower($element->tagName)] ?? []
        );

        // The names are snapshotted first: DOMNamedNodeMap is live, and removing
        // while iterating it skips the entry that shuffles into the freed slot.
        $names = [];
        foreach ($element->attributes as $attribute) {
            $names[] = $attribute->nodeName;
        }

        foreach ($names as $name) {
            $lower = strtolower($name);
            if (!in_array($lower, $allowed, true)) {
                $element->removeAttribute($name);
                continue;
            }
            if (in_array($lower, self::HTML_URL_ATTRIBUTES, true)) {
                $element->setAttribute($name, $filter->filterContentUrl($element->getAttribute($name)));
            }
        }
    }

    /**
     * Convert an array of photo URLs to newline-separated Markdown images.
     *
     * @param array<int, mixed> $photos
     * @return string
     */
    private function buildPhotos(array $photos): string
    {
        $markdown = [];
        foreach ($photos as $photo) {
            if (is_array($photo)) {
                $url = matter_string($photo['value'] ?? null);
                $alt = matter_string($photo['alt'] ?? null) ?? '';
            } else {
                $url = matter_string($photo);
                $alt = '';
            }
            // A photo with no usable URL is dropped rather than written as
            // `![](Array)`: the value has no faithful text, and an image link
            // to it is worse than no image link.
            if ($url === null || trim($url) === '') {
                continue;
            }
            $markdown[] = '![' . $alt . '](' . $url . ')';
        }

        return implode("\n", $markdown);
    }

    /**
     * Convert an array of category strings to space-separated hashtags.
     *
     * @param array<int, mixed> $categories
     * @return string
     */
    private function buildTags(array $categories): string
    {
        $tags = array_values(array_filter(array_map(
            sanitize_tag_name(...),
            self::textValues($categories)
        ), fn(string $c) => $c !== ''));

        return $tags === [] ? '' : implode(' ', array_map(fn(string $c) => '#' . $c, $tags));
    }

    /**
     * The text values of a Micropub property, dropping anything with no
     * faithful textual form.
     *
     * Microformats properties are arrays of values, and a value is not
     * necessarily a string. Casting one with (string) or strval() stored the
     * literal "Array" — as a hashtag, as an image URL — and raised an
     * "Array to string conversion" warning on the way. matter_string() applies
     * the same rule front matter already uses: a list collapses to its first
     * entry, and anything else with no text is absent.
     *
     * @param array<array-key, mixed> $values
     * @return list<string>
     */
    private static function textValues(array $values): array
    {
        $texts = [];
        foreach ($values as $value) {
            $text = matter_string($value);
            if ($text !== null && trim($text) !== '') {
                $texts[] = $text;
            }
        }

        return $texts;
    }
}

/**
 * A minimal PSR-3 logger that funnels the taproot adapter's own trace into mp_log().
 *
 * Wired into the adapter's $logger (only when micropub_debug is on), so its
 * info/warning/error messages — token verification, query handling, error
 * responses — land in the same file as Lamb's own diagnostic log points. An
 * anonymous class keeps micropub.php to a single named class (PSR1).
 */
function mp_adapter_logger(): \Psr\Log\LoggerInterface
{
    return new class extends AbstractLogger {
        /**
         * @param mixed              $level
         * @param string|\Stringable $message
         * @param array<mixed>       $context
         */
        public function log($level, string|\Stringable $message, array $context = []): void
        {
            mp_log('adapter', ['level' => (string) $level, 'message' => (string) $message] + $context);
        }
    };
}

/**
 * Whether opt-in Micropub diagnostic logging is enabled (config key `micropub_debug`).
 * Off by default and for any non-truthy value, so no token/PII is ever written unless
 * the operator explicitly turns it on at /settings to debug a client.
 */
function mp_debug_enabled(): bool
{
    global $config;
    $value = $config['micropub_debug'] ?? false;
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Path of the Micropub diagnostic log. Lives next to the SQLite DB in data/.
 * Overridable via $GLOBALS['lamb_mp_log_path'] for tests.
 */
function mp_log_path(): string
{
    if (!empty($GLOBALS['lamb_mp_log_path'])) {
        return (string) $GLOBALS['lamb_mp_log_path'];
    }
    return \Lamb\Bootstrap\data_dir() . '/micropub.log';
}

/**
 * Append one diagnostic event to the Micropub log. No-op unless micropub_debug is on.
 *
 * The token itself is never passed in here — callers log token_fingerprint() instead.
 *
 * @param string       $event   Short event name (e.g. 'request', 'token_verify', 'response').
 * @param array<mixed> $context Structured fields to record alongside the event.
 */
function mp_log(string $event, array $context = []): void
{
    if (!mp_debug_enabled()) {
        return;
    }

    // JSON_INVALID_UTF8_SUBSTITUTE, as the export manifest, the JSON feed and
    // the upload response all use: without it json_encode() returns false for
    // the whole document over a single malformed byte, and `?: ''` then wrote a
    // blank line — losing the event entirely. A logged value can carry one
    // (a remote response body, a raw request body, a client-supplied filename),
    // and a diagnostic log that drops the entry explaining a failure is worse
    // than one that renders a byte as U+FFFD.
    $line = json_encode(
        ['ts' => \Lamb\now(), 'event' => $event] + $context,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: '';
    @file_put_contents(mp_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Build a `WWW-Authenticate: Bearer` challenge value per OAuth bearer-token RFC 6750 §3.
 *
 * With no error this returns a bare `Bearer` — RFC 6750 §3.1 says a request that
 * supplied no credentials at all SHOULD NOT carry an error code. When an error is
 * given, the optional `error_description` and `scope` attributes are appended in the
 * spec's listed order (error, error_description, scope).
 *
 * @param string|null $error       Error code (e.g. 'invalid_token', 'insufficient_scope').
 * @param string|null $scope       Scope the action requires (insufficient_scope only).
 * @param string|null $description Human-readable error description.
 */
/**
 * Whether a verified access-token user carries a given Micropub scope.
 *
 * Shared by every scope-gated Micropub action (post creation/update, and the
 * media endpoint) so a token issued without the scope an action needs is
 * rejected consistently.
 *
 * @param array{me?: mixed, scope?: list<string>}|false $user The result of verifyAccessTokenCallback().
 * @param string                                         $scope Required scope (e.g. 'create').
 * @return bool
 */
function has_micropub_scope(array|false $user, string $scope): bool
{
    return is_array($user) && in_array($scope, $user['scope'] ?? [], true);
}

function bearer_challenge(?string $error = null, ?string $scope = null, ?string $description = null): string
{
    $params = [];
    if ($error !== null) {
        $params[] = 'error="' . $error . '"';
    }
    if ($description !== null) {
        $params[] = 'error_description="' . $description . '"';
    }
    if ($scope !== null) {
        $params[] = 'scope="' . $scope . '"';
    }

    return $params === [] ? 'Bearer' : 'Bearer ' . implode(', ', $params);
}

/**
 * Non-reversible fingerprint of a bearer token: enough to correlate the same token
 * across log lines without ever writing the secret itself.
 */
function token_fingerprint(string $token): string
{
    if ($token === '') {
        return 'empty';
    }
    return 'len=' . strlen($token) . ' sha256=' . substr(hash('sha256', $token), 0, 12);
}

/**
 * Route handler for GET/POST /micropub.
 * Builds a PSR-7 request from globals, delegates to LambMicropubAdapter,
 * emits the response and exits — same pattern as respond_feed.
 */
function respond_micropub(): void
{
    // index.php's pre-route cache_headers() call only sees the session cookie, so
    // it always emits the anonymous max-age=300 header here — Micropub auth is a
    // bearer token, never that cookie. Override before touching the request: every
    // code path below (draft source queries, write results, error bodies) carries
    // bearer-token-gated content that a shared cache must never store or replay.
    foreach (cache_headers(true) as $cache_header) {
        header($cache_header);
    }

    $headers = getallheaders() ?: [];
    $rawBody = file_get_contents('php://input') ?: null;

    $request = new ServerRequest(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ROOT_URL . ($_SERVER['REQUEST_URI'] ?? '/micropub'),
        $headers,
        $rawBody,
        '1.1',
        $_SERVER
    );

    if (!empty($_POST)) {
        $request = $request->withParsedBody($_POST);
    }

    if (!empty($_FILES)) {
        $psr7Files = [];
        foreach ($_FILES as $field => $info) {
            if (is_array($info['tmp_name'])) {
                $fieldFiles = [];
                foreach ($info['tmp_name'] as $i => $tmpName) {
                    $fieldFiles[] = new UploadedFile(
                        $tmpName,
                        (int) $info['size'][$i],
                        (int) $info['error'][$i],
                        $info['name'][$i],
                        $info['type'][$i]
                    );
                }
                $psr7Files[$field] = $fieldFiles;
            } else {
                $psr7Files[$field] = new UploadedFile(
                    $info['tmp_name'],
                    (int) $info['size'],
                    (int) $info['error'],
                    $info['name'],
                    $info['type']
                );
            }
        }
        $request = $request->withUploadedFiles($psr7Files);
    }

    $lcHeaders = array_change_key_case($headers, CASE_LOWER);
    mp_log('request', [
        'method'          => $request->getMethod(),
        'uri'             => (string) $request->getUri(),
        'q'               => $request->getQueryParams()['q'] ?? null,
        'content_type'    => $lcHeaders['content-type'] ?? null,
        'user_agent'      => $lcHeaders['user-agent'] ?? null,
        'has_auth_header' => isset($lcHeaders['authorization']),
        'has_body_token'  => isset($_POST['access_token']),
        'body_len'        => $rawBody !== null ? strlen($rawBody) : 0,
    ]);

    $adapter = new LambMicropubAdapter();
    if (mp_debug_enabled()) {
        $adapter->logger = mp_adapter_logger();
    }
    $response = $adapter->handleRequest($request);

    // The adapter returns a bare 401 when no access token is supplied; RFC 6750 §3
    // requires a WWW-Authenticate: Bearer challenge on such responses. (Our own
    // insufficient_scope path is a 403 that already sets the header, so it is untouched.)
    $status = $response->getStatusCode();
    if ($status === 401 && !$response->hasHeader('WWW-Authenticate')) {
        $response = $response->withHeader('WWW-Authenticate', bearer_challenge());
    }

    // The adapter returns a bare 201 + Location on success with no body (spec-compliant —
    // Micropub only requires the Location header). Some clients (e.g. mpcli) unconditionally
    // JSON-parse the body and require url/preview/edit fields, even though the post succeeded.
    // Lamb has no separate preview/edit URL, so all three point at the same permalink.
    if ($status === 201 && $response->getBody()->getSize() === 0) {
        $location = $response->getHeaderLine('Location');
        $response = $response
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(json_encode([
                'url'     => $location,
                'preview' => $location,
                'edit'    => $location,
            ]) ?: '{}'));
    }

    mp_log('response', [
        'status' => $status,
        // Only echo the body into the log on failures — it carries the error reason.
        // mb_substr(), not substr(): a response body is remote text, and cutting
        // it mid-sequence at byte 300 produced the malformed byte above. 300
        // characters is what "an excerpt" meant anyway.
        'body'   => $status >= 400 ? mb_substr((string) $response->getBody(), 0, 300) : null,
    ]);

    http_response_code($status);
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value", false);
        }
    }
    echo $response->getBody();
    exit;
}

/**
 * Emit a JSON Micropub error response and terminate the request.
 *
 * Centralises the status code + Content-Type header + {error, error_description}
 * body that every guard in the media endpoint would otherwise repeat.
 *
 * @param int         $status          HTTP status code.
 * @param string      $error           Micropub error code (e.g. 'unauthorized', 'invalid_request').
 * @param string      $description     Human-readable error description.
 * @param string|null $wwwAuthenticate Optional RFC 6750 `WWW-Authenticate` challenge (see bearer_challenge()).
 * @return never
 */
function micropub_error(int $status, string $error, string $description, ?string $wwwAuthenticate = null): never
{
    mp_log('response', ['status' => $status, 'error' => $error, 'error_description' => $description]);
    http_response_code($status);
    header('Content-Type: application/json');
    if ($wwwAuthenticate !== null) {
        header('WWW-Authenticate: ' . $wwwAuthenticate);
    }
    // Same flag as mp_log() above: $description reaches here from a validation
    // failure and can quote client input, and `echo false` would answer the
    // error with an empty body.
    echo json_encode(
        ['error' => $error, 'error_description' => $description],
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/**
 * Handles Micropub media endpoint requests (POST multipart/form-data with a 'file' field).
 * Validates the bearer token, saves the uploaded file, and returns HTTP 201 + Location.
 *
 * @param mixed $args The router's positional route arguments. Unused — this
 *     endpoint reads $_FILES/$_POST directly — but declared first because
 *     call_route() invokes every handler as $callback($args); a typed first
 *     parameter would receive that array and fatal (a 500 on every request).
 * @param LambMicropubAdapter|null $adapter The adapter to verify the bearer token against;
 *     defaults to a new LambMicropubAdapter. Injectable so tests can stub token
 *     introspection instead of hitting the real token endpoint over HTTP. Typed to
 *     the concrete class, not the MicropubAdapter base, because this function relies
 *     on LambMicropubAdapter's narrower verifyAccessTokenCallback() return shape
 *     (array{me, scope}|false) rather than the base's array|string|false|ResponseInterface.
 * @return void
 */
function respond_micropub_media(mixed $args = null, ?LambMicropubAdapter $adapter = null): void
{
    // Same override as respond_micropub() above, and for the same reason: this
    // endpoint is bearer-token-gated, not session-cookie-gated, so index.php's
    // pre-route cache_headers() call always guesses "anonymous" here.
    foreach (cache_headers(true) as $cache_header) {
        header($cache_header);
    }

    $headers = getallheaders() ?: [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $lcHeaders = array_change_key_case($headers, CASE_LOWER);

    mp_log('media_request', [
        'method'          => $_SERVER['REQUEST_METHOD'] ?? null,
        'content_type'    => $lcHeaders['content-type'] ?? null,
        'user_agent'      => $lcHeaders['user-agent'] ?? null,
        'has_auth_header' => $authHeader !== '',
        'has_file'        => !empty($_FILES['file']),
    ]);

    $token = null;
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        $token = trim($m[1]);
    }

    if (!$token) {
        // No credentials supplied: bare Bearer challenge, no error code (RFC 6750 §3.1).
        micropub_error(401, 'unauthorized', 'Missing bearer token.', bearer_challenge());
    }

    $adapter ??= new LambMicropubAdapter();
    if (mp_debug_enabled()) {
        $adapter->logger = mp_adapter_logger();
    }
    $user = $adapter->verifyAccessTokenCallback($token);
    if (!$user) {
        micropub_error(
            401,
            'unauthorized',
            'Invalid or expired token.',
            bearer_challenge('invalid_token', null, 'The access token is invalid or expired.')
        );
    }

    // The base MicropubAdapter never enforces scope itself — it's left to the
    // implementing callbacks, and createCallback()/updateCallback() both do
    // (requiring 'create'/'update'). This endpoint is reached independently
    // of those callbacks, so without its own check here a token issued with
    // any scope at all (e.g. 'update'-only) could still upload files.
    if (!has_micropub_scope($user, 'create')) {
        micropub_error(
            403,
            'insufficient_scope',
            'Your access token does not grant the scope required for this action.',
            bearer_challenge('insufficient_scope', 'create')
        );
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_FILES['file'])) {
        micropub_error(400, 'invalid_request', 'Expected a multipart/form-data POST with a file field.');
    }

    $file = $_FILES['file'];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        micropub_error(400, 'invalid_request', 'File upload failed.');
    }

    $ext = \Lamb\Response\safe_upload_extension(\Lamb\Http\request_string($file['name'] ?? null) ?? '');
    if ($ext === null) {
        micropub_error(400, 'invalid_request', 'Unsupported file type.');
    }

    // The extension comes from the client's filename; check the bytes agree with it.
    $sniffed = \Lamb\Response\sniff_file_content_type((string) ($file['tmp_name'] ?? ''));
    if (!\Lamb\Response\upload_content_allowed($sniffed, $ext)) {
        micropub_error(400, 'invalid_request', 'File contents do not match its type.');
    }

    $sub_path  = \Lamb\Response\upload_subpath();
    $uploadDir = \Lamb\Response\get_upload_dir($sub_path);
    $seed      = sha1((\Lamb\Http\request_string($file['name'] ?? null) ?? '') . uniqid('', true));

    // Re-encode JPEG/PNG to WebP, falling back to the original bytes on failure.
    $filename = \Lamb\Response\store_upload_or_fallback($file['tmp_name'], $ext, $uploadDir, $seed);
    if ($filename === null) {
        // Same reasoning as the web upload endpoint (response/upload.php): a
        // 201 with a Location the file was never written to would tell the
        // client its upload durably succeeded when it didn't.
        micropub_error(500, 'server_error', 'Failed to store the uploaded file.');
    }

    // The media endpoint hands this URL back to an external Micropub client, so
    // it must be absolute (resolvable off-site); content URLs stay root-relative.
    $url = ROOT_URL . \Lamb\Response\asset_url($sub_path, $filename);

    http_response_code(201);
    header('Location: ' . $url);
    header('Content-Type: application/json');
    echo json_encode(['url' => $url, 'preview' => $url, 'edit' => $url]);
    exit;
}
