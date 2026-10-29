<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Config\compose_config;
use function Lamb\Config\get_default_ini_text;
use function Lamb\SocialSpec\about_markdown;
use function Lamb\SocialSpec\about_post;
use function Lamb\SocialSpec\contact_vcard;
use function Lamb\SocialSpec\is_valid_opml;
use function Lamb\SocialSpec\refresh_following;
use function Lamb\SocialSpec\well_known_document;

/**
 * SocialSpec (socialspec.org) well-known files: /.well-known/about.md,
 * /.well-known/contact.vcf and /.well-known/following.opml. Each is derived
 * from data Lamb already holds, and is absent (404) when that data is.
 */
class SocialSpecTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
        unset($_SESSION[SESSION_LOGIN]);
        $this->dir = sys_get_temp_dir() . '/lamb-socialspec-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function post(string $body, string $slug, array $fields = []): \RedBeanPHP\OODBBean
    {
        $bean = R::dispense('post');
        $bean->body = $body;
        $bean->slug = $slug;
        $bean->created = date('Y-m-d H:i:s', time() - 60);
        $bean->updated = $bean->created;
        // Fluid RedBean answers a query naming a column no bean has yet with
        // nothing at all, so give every fixture the visibility columns.
        $bean->draft = 0;
        $bean->deleted = 0;
        foreach ($fields as $key => $value) {
            $bean->$key = $value;
        }
        R::store($bean);
        return $bean;
    }

    // --- about.md ---------------------------------------------------------

    public function testAboutPostIsThePublishedPostSluggedAbout(): void
    {
        $this->post("---\ntitle: About\n---\nHi", 'about');
        $this->assertSame('about', about_post()?->slug);
    }

    public function testAboutPostIgnoresDraftsDeletedAndScheduledPosts(): void
    {
        $this->post('a', 'about', ['draft' => 1]);
        $this->assertNull(about_post());
        R::nuke();
        $this->post('a', 'about', ['deleted' => 1]);
        $this->assertNull(about_post());
        R::nuke();
        $this->post('a', 'about', ['created' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertNull(about_post());
    }

    public function testAboutPostIsNullWithoutAnAboutPost(): void
    {
        $this->post('Hello', 'hello');
        $this->assertNull(about_post());
    }

    public function testAboutMarkdownHasSummaryTitleAndBodyWithoutLambFrontMatter(): void
    {
        $post = $this->post("---\ntitle: About me\nslug: about\n---\nI write *notes*.", 'about');
        $md = about_markdown($post, ['site_description' => 'Notes on Linux'], 'https://example.com');
        $this->assertSame("---\nsummary: 'Notes on Linux'\n---\n\n# About me\n\nI write *notes*.\n", $md);
    }

    public function testAboutMarkdownPrefersThePostsOwnSummary(): void
    {
        $post = $this->post("---\ntitle: About\nsummary: 'Engineer: web'\n---\nBody", 'about');
        $md = about_markdown($post, ['site_description' => 'Site'], 'https://example.com');
        $this->assertStringStartsWith("---\nsummary: 'Engineer: web'\n---\n", $md);
    }

    public function testAboutMarkdownOmitsFrontMatterAndTitleWhenAbsent(): void
    {
        $post = $this->post('Just a body', 'about');
        $this->assertSame("Just a body\n", about_markdown($post, [], 'https://example.com'));
    }

    public function testAboutMarkdownMakesRootRelativeLinksAndImagesAbsolute(): void
    {
        $post = $this->post('![me](/assets/me.webp) [posts](/tag/x) [ext](https://a.b/) [proto](//cdn.x/y)', 'about');
        $md = about_markdown($post, [], 'https://example.com');
        $this->assertSame(
            "![me](https://example.com/assets/me.webp) [posts](https://example.com/tag/x) [ext](https://a.b/) [proto](//cdn.x/y)\n",
            $md
        );
    }

    public function testAboutMarkdownMakesRootRelativeReferenceDefinitionsAbsolute(): void
    {
        $post = $this->post("See [home].\n\n[home]: /home\n  [ext]: https://a.b/\n[proto]: //cdn.x/y", 'about');
        $this->assertSame(
            "See [home].\n\n[home]: https://example.com/home\n  [ext]: https://a.b/\n[proto]: //cdn.x/y\n",
            about_markdown($post, [], 'https://example.com')
        );
    }

    // --- contact.vcf ------------------------------------------------------

    public function testContactVcardIsNullWithoutAnAuthorEmail(): void
    {
        $this->assertNull(contact_vcard(['author_name' => 'Jo'], 'https://example.com'));
        $this->assertNull(contact_vcard(['author_email' => 'not-an-email'], 'https://example.com'));
    }

    public function testContactVcardIgnoresThePlaceholderIdentityAFreshInstallGets(): void
    {
        $fresh = compose_config('', get_default_ini_text());
        $this->assertNull(contact_vcard($fresh, 'https://example.com'));

        $emailOnly = compose_config("author_email = jo@example.com\nsite_title = Jo's notes", get_default_ini_text());
        $this->assertStringContainsString("\r\nFN:Jo's notes\r\n", (string) contact_vcard($emailOnly, 'https://example.com'));
    }

    public function testContactVcardCarriesNameEmailSiteAndMeLinks(): void
    {
        $vcf = contact_vcard([
            'author_name' => 'Jo Doe',
            'author_email' => 'jo@example.com',
            'me' => ['Github' => 'https://github.com/jo', 'Email' => 'mailto:jo@example.com'],
        ], 'https://example.com');

        $this->assertSame(
            "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Jo Doe\r\nEMAIL;PREF=1:jo@example.com\r\n"
            . "URL:https://example.com/\r\nURL:https://github.com/jo\r\nEND:VCARD\r\n",
            $vcf
        );
    }

    public function testContactVcardFallsBackToSiteTitleForTheName(): void
    {
        $vcf = contact_vcard(['author_email' => 'jo@example.com', 'site_title' => 'Jo, Notes; etc'], 'https://example.com');
        $this->assertStringContainsString("\r\nFN:Jo\\, Notes\\; etc\r\n", (string) $vcf);
    }

    public function testContactVcardFoldsLongLinesAt75Octets(): void
    {
        $vcf = (string) contact_vcard(['author_email' => 'jo@example.com', 'author_name' => str_repeat('é', 60)], 'https://example.com');
        foreach (explode("\r\n", $vcf) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'), 'folding must not split a character');
        }
        $this->assertStringContainsString("\r\n ", $vcf);
    }

    // --- following.opml ---------------------------------------------------

    private const T0 = 1_800_000_000;

    private const OPML = '<?xml version="1.0"?><opml version="2.0"><head><title>t</title></head>'
        . '<body><outline text="A" xmlUrl="https://a.example/feed"/></body></opml>';

    public function testIsValidOpmlAcceptsAnOpmlDocument(): void
    {
        $this->assertTrue(is_valid_opml(self::OPML));
    }

    public function testIsValidOpmlRejectsNonOpmlAndDoctypes(): void
    {
        $this->assertFalse(is_valid_opml('<html><body/></html>'));
        $this->assertFalse(is_valid_opml('not xml'));
        $this->assertFalse(is_valid_opml('<?xml version="1.0"?><!DOCTYPE opml [<!ENTITY a "b">]><opml version="2.0"><body/></opml>'));
    }

    private function fetcher(array &$calls, ?array $response): callable
    {
        return static function (string $url, array $opts) use (&$calls, $response): ?array {
            $calls[] = [$url, $opts];
            return $response;
        };
    }

    public function testRefreshFollowingStoresAValidListAndItsEtag(): void
    {
        $calls = [];
        $path = $this->dir . '/following.opml';
        $fetch = $this->fetcher($calls, ['status' => 200, 'headers' => ['HTTP/1.1 200 OK', 'ETag: "v1"'], 'body' => self::OPML]);

        $line = refresh_following('https://reader.example/f.opml', $path, $fetch, self::T0);

        $this->assertSame(self::OPML, file_get_contents($path));
        $this->assertSame('https://reader.example/f.opml', $calls[0][0]);
        $this->assertStringContainsString('updated', $line);

        // The next due run revalidates with the stored ETag and keeps the file on 304.
        $calls = [];
        $fetch = $this->fetcher($calls, ['status' => 304, 'headers' => ['HTTP/1.1 304 Not Modified'], 'body' => '']);
        $line = refresh_following('https://reader.example/f.opml', $path, $fetch, self::T0 + 1800);
        $this->assertContains('If-None-Match: "v1"', $calls[0][1]['headers']);
        $this->assertSame(self::OPML, file_get_contents($path));
        $this->assertStringContainsString('unchanged', $line);
    }

    public function testRefreshFollowingIsRateLimitedTo30Minutes(): void
    {
        $calls = [];
        $path = $this->dir . '/following.opml';
        $fetch = $this->fetcher($calls, ['status' => 200, 'headers' => [], 'body' => self::OPML]);
        refresh_following('https://reader.example/f.opml', $path, $fetch, self::T0);
        refresh_following('https://reader.example/f.opml', $path, $fetch, self::T0 + 60);
        $this->assertCount(1, $calls);
    }

    public function testRefreshFollowingKeepsTheLastGoodCopyOnFailureOrInvalidBody(): void
    {
        $calls = [];
        $path = $this->dir . '/following.opml';
        refresh_following('https://reader.example/f.opml', $path, $this->fetcher($calls, ['status' => 200, 'headers' => [], 'body' => self::OPML]), self::T0);

        foreach ([null, ['status' => 500, 'headers' => [], 'body' => 'oops'], ['status' => 200, 'headers' => [], 'body' => '<html/>']] as $i => $response) {
            $line = refresh_following('https://reader.example/f.opml', $path, $this->fetcher($calls, $response), self::T0 + 1800 * ($i + 1));
            $this->assertStringContainsString('failed', $line);
            $this->assertSame(self::OPML, file_get_contents($path));
        }
    }

    public function testRefreshFollowingDropsTheOldCopyWhenTheUrlChanges(): void
    {
        $calls = [];
        $path = $this->dir . '/following.opml';
        refresh_following('https://old.example/f.opml', $path, $this->fetcher($calls, ['status' => 200, 'headers' => ['ETag: "old"'], 'body' => self::OPML]), self::T0);

        // Inside the old URL's window, and the new source is down: the old list must not linger.
        refresh_following('https://new.example/f.opml', $path, $this->fetcher($calls, null), self::T0 + 60);

        $this->assertCount(2, $calls);
        $this->assertNotContains('If-None-Match: "old"', $calls[1][1]['headers']);
        $this->assertFileDoesNotExist($path);
    }

    public function testRefreshFollowingSurvivesACorruptSidecar(): void
    {
        $calls = [];
        $path = $this->dir . '/following.opml';
        file_put_contents($path . '.json', '1');
        $line = refresh_following('https://reader.example/f.opml', $path, $this->fetcher($calls, ['status' => 200, 'headers' => [], 'body' => self::OPML]), self::T0);
        $this->assertStringContainsString('updated', $line);
    }

    public function testRefreshFollowingFailureLineDoesNotLeakTheUrl(): void
    {
        $calls = [];
        $path = $this->dir . '/following.opml';
        $line = refresh_following('https://reader.example/following/s3cret.opml', $path, $this->fetcher($calls, null), self::T0);
        $this->assertStringContainsString('failed', $line);
        $this->assertStringNotContainsString('s3cret', $line);
    }

    public function testRefreshFollowingRemovesTheCopyWhenUnconfigured(): void
    {
        $path = $this->dir . '/following.opml';
        file_put_contents($path, self::OPML);
        $calls = [];
        $this->assertSame('', refresh_following(null, $path, $this->fetcher($calls, null), self::T0));
        $this->assertFileDoesNotExist($path);
        $this->assertCount(0, $calls);
    }

    // --- routing ----------------------------------------------------------

    public function testWellKnownDocumentServesEachFileWithItsType(): void
    {
        $this->post('Hi', 'about');
        $path = $this->dir . '/following.opml';
        $calls = [];
        refresh_following('https://reader.example/f.opml', $path, $this->fetcher($calls, ['status' => 200, 'headers' => [], 'body' => self::OPML]), self::T0);
        $config = ['author_email' => 'jo@example.com', 'following_url' => 'https://reader.example/f.opml'];

        $about = well_known_document('about.md', $config, 'https://example.com', $path);
        $this->assertSame('text/markdown; charset=utf-8', $about['type']);
        $this->assertSame("Hi\n", $about['body']);

        $this->assertSame('text/vcard; charset=utf-8', well_known_document('contact.vcf', $config, 'https://example.com', $path)['type']);

        $opml = well_known_document('following.opml', $config, 'https://example.com', $path);
        $this->assertSame('text/x-opml; charset=utf-8', $opml['type']);
        $this->assertSame(self::OPML, $opml['body']);
    }

    public function testWellKnownDocumentHidesACopyFetchedFromAnotherUrl(): void
    {
        $calls = [];
        $path = $this->dir . '/following.opml';
        refresh_following('https://old.example/f.opml', $path, $this->fetcher($calls, ['status' => 200, 'headers' => [], 'body' => self::OPML]), self::T0);

        $this->assertNotNull(well_known_document('following.opml', ['following_url' => 'https://old.example/f.opml'], 'https://example.com', $path));
        $this->assertNull(well_known_document('following.opml', ['following_url' => 'https://new.example/f.opml'], 'https://example.com', $path));
    }

    public function testWellKnownDocumentIsNullForMissingDataOrUnknownNames(): void
    {
        $path = $this->dir . '/following.opml';
        $this->assertNull(well_known_document('about.md', [], 'https://example.com', $path));
        $this->assertNull(well_known_document('contact.vcf', [], 'https://example.com', $path));
        $this->assertNull(well_known_document('following.opml', [], 'https://example.com', $path));
        $this->assertNull(well_known_document('passwd', [], 'https://example.com', $path));

        // A cached list is not served once following_url is removed.
        file_put_contents($path, self::OPML);
        $this->assertNull(well_known_document('following.opml', [], 'https://example.com', $path));
    }
}
