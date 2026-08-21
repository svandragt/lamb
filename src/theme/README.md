# Theme rendering (`Lamb\Theme`)

Developer notes for the theme-rendering subsystem. This is the *why* behind
the code in this directory; the per-function docblocks carry the contract
(params, returns, invariants) and point here for the design. For the
project-wide architecture, the full `$data`/`$config` reference, and the
theme-authoring checklist see [AGENTS.md](../../AGENTS.md#theme-system--complete-reference).

## Files

All files declare the `Lamb\Theme` namespace (a split, not separate
namespaces — a part calling `Theme\escape()` doesn't care which file it lives
in):

| File | Responsibility |
|------|----------------|
| `../theme.php` | Core rendering: part resolution, post/nav helpers, CSRF, admin toolbar |
| `assets.php` | CSS/JS loading: inlining, minification, cache-busting |
| `formatting.php` | Escaping, heading re-levelling, relative-time formatting |
| `meta.php` | OpenGraph/Twitter/robots/preconnect `<meta>` tags |

## Escaping is per-context, not per-file

There are three escapers, and using the wrong one is the whole risk surface
here:

- `escape()` — `htmlspecialchars()` for HTML5 body/attribute output. Used on
  every user-supplied value rendered into markup.
- `og_escape()` — `htmlspecialchars_decode()` first, then re-encode. OpenGraph
  and Twitter Card content is pulled from fields (`description`, `title`)
  that may already carry entities from an earlier render pass; encoding
  without decoding first double-escapes them (`&amp;amp;`).
- The URL-scheme gap: `escape()` (and `og_escape()`) only encode HTML
  metacharacters — neither touches a URL's *scheme*. `link_source()`,
  `syndication_links()`, and `the_reply_context()` (`theme.php`,
  `formatting.php`) each link a value that is not author-only —
  `source_url` comes from a subscribed feed, `syndicated_to` and
  `in_reply_to` can be set by a Micropub client holding only `create`
  scope — so each calls `Http\is_valid_http_url()` before emitting an
  `<a href>`, and falls back to plain escaped text for anything that isn't a
  genuine `http(s)` URL. A `javascript:`-scheme value would otherwise reach
  the page verbatim.

## Part resolution is theme-optional by design

`part()` lets a theme override only the files that differ from `base`: it
tries `THEME_DIR . $dir . '/' . $name . '.php'` first and falls back to
`src/themes/base/$dir/$name.php`. Every part in the render flow
(`html.php` → `parts/<template>.php` → `parts/_items.php`) goes through this,
so a minimal theme can ship nothing but a stylesheet — see AGENTS.md's
["Minimal new theme
checklist"](../../AGENTS.md#minimal-new-theme-checklist). `$name`/`$dir` are
sanitised to `[a-zA-Z0-9-_]` (`sanitize_filename()`) before touching the
filesystem, since `$name` reaches here as the raw `$template` value derived
from the request URL.

## Asset loading (`assets.php`)

### Why CSS gets minified and inlined, but JS never does

`the_styles()` inlines the active theme's stylesheet as a `<style>` tag when
it's small enough — the render-blocking round-trip a `<link>` costs is the
single biggest mobile PageSpeed hit for a one-file theme. `the_scripts()`
never does this: scripts load `defer`, so they don't block first paint the
way a render-blocking stylesheet does, and there's no equivalent win to buy.

`minify_css()` is deliberately conservative — only whitespace and comments,
nothing that touches CSS semantics. "Insignificant whitespace" is the whole
difficulty: collapsing it blindly around `{}:;,>` also reaches inside quoted
strings and `url()` tokens, where that whitespace is content
(`content: "Note: hello"` → `content:"Note:hello"`, and worse, a selector
like `[data-label="a > b"]` silently stops matching). String literals and
`url()` tokens are split out with a capturing regex and copied through
untouched; only the syntax between them is collapsed. No theme Lamb ships
triggers this, but `the_styles()` inlines whichever theme the `theme` setting
names, so a hand-written or third-party stylesheet is an input this has to
survive.

`rewrite_css_urls()` exists because inlining changes the CSS's frame of
reference: a relative `url('fonts/x.woff2')` resolves against the
*stylesheet's* directory when the CSS lives in an external file, but against
the *page* URL once it's pasted into the HTML. Absolute, `data:`, and
fragment URLs are left alone; only bare relative paths are rewritten against
`$base_url`.

### Cache-busting is content-addressed

`asset_version()` hashes the file's *contents* (`md5_file`), not its URL or a
build timestamp. A deploy that doesn't touch a given CSS/JS file leaves its
`?ver=` hash unchanged, so returning visitors keep that file cached across
deploys — only files that actually changed get invalidated. It falls back to
hashing the URL for assets it can't read locally (e.g. a remote asset).

### `asset_loader()`'s three-way key

The `$assets` array passed to `asset_loader()` (see `the_scripts()`) is keyed
by when a group should load: `''` always, `SESSION_LOGIN` only when the
viewer is authenticated, and any other string only when it matches the
current `$template`. This is how admin-only JS (`growing-input.js`,
`confirm-delete.js`, …) stays off anonymous pages and how a page can carry
JS scoped to just its own template (e.g. `search-highlight.js` on `search`)
without a per-template loader function.

## Heading re-levelling (`anchor_headings()`)

Post bodies are stored at the author's literal Markdown heading levels —
storage is theme-neutral, so the same body has to fit into whatever outline
each theme wraps it in. A theme that renders the post title as `<h2>` passes
`$top = 3`: the body's highest heading becomes `<h3>`, sitting directly under
the title, and everything else shifts by the same signed amount (a body
written deeper than `$top` is pulled up; one written shallower is pushed
down). No level is skipped at the top of the body, which keeps the document
outline in the order screen readers expect (WCAG heading order). Results
clamp at `h6`; a body with no headings is returned unchanged.

## OpenGraph image selection (`meta.php`)

`og_image()` picks a social-card image in order of specificity: the first
image embedded in the post body (so sharing a photo post previews that
photo, and the Twitter card upgrades to `summary_large_image`), then a
site-supplied `og-image.<ext>` dropped in the web root (the same
user-replaceable convention as `favicon.png`/`logo.png`), then the shipped
Lamb card as the final fallback. Width/height/type are only emitted when the
image maps to a real, readable local file — an embedded or site image that
doesn't resolve locally still gets a URL, just without the dimension hints,
since a wrong guess is worse than no hint ("assume success, communicate
failure").

`og_local_path()` is the guard behind that resolution: an embedded image's
`src` is whatever the post body's Markdown said, and the body is not always
the author's — a subscribed feed's item description reaches here just as
readily as a Micropub `create`-scope client's post, and both importers.
`![](/../../etc/whatever.png)` would otherwise build a path straight out of
the web root, so `og_local_path()` resolves against `ROOT_DIR` with
`realpath()` containment (covering symlinks too) before `image_dimensions()`
is allowed to `getimagesize()` it and publish the result into `og:image`
meta tags for anyone to read. The containment root is `ROOT_DIR` rather than
the narrower uploads tree, because the site-default and shipped fallback
images legitimately live outside it.

## Security notes that live in AGENTS.md, not here

CSRF handling, session cookie hardening, and the login throttle are
documented once in AGENTS.md's ["Security"](../../AGENTS.md#security)
section — `csrf_token()` here is just the token accessor. Don't duplicate
that material when touching this module; link to it instead.
