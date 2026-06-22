<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\syndication_links;

class SyndicationLinksTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = $config ?? [];
    }

    protected function tearDown(): void
    {
        global $config;
        unset($config['syndicate_to']);
    }

    private function makeBean(string $syndicatedTo = ''): \RedBeanPHP\OODBBean
    {
        $bean = R::dispense('post');
        $bean->syndicated_to = $syndicatedTo;
        return $bean;
    }

    public function testSyndicationLinksReturnsEmptyWhenBeanHasNone(): void
    {
        $bean = $this->makeBean('');
        $this->assertSame('', syndication_links($bean));
    }

    public function testSyndicationLinksRendersUSyndicationLink(): void
    {
        $bean = $this->makeBean('https://bsky.app/profile/me');
        $html = syndication_links($bean);
        $this->assertStringContainsString('u-syndication', $html);
        $this->assertStringContainsString('https://bsky.app/profile/me', $html);
        $this->assertStringContainsString('rel="syndication"', $html);
    }

    public function testSyndicationLinksUsesConfigNameWhenAvailable(): void
    {
        global $config;
        $config['syndicate_to'] = ['https://bsky.app/profile/me' => 'Bluesky'];

        $bean = $this->makeBean('https://bsky.app/profile/me');
        $html = syndication_links($bean);
        $this->assertStringContainsString('Bluesky', $html);
    }

    public function testSyndicationLinksFallsBackToHostname(): void
    {
        global $config;
        unset($config['syndicate_to']);

        $bean = $this->makeBean('https://mastodon.social/@me');
        $html = syndication_links($bean);
        $this->assertStringContainsString('mastodon.social', $html);
    }

    public function testSyndicationLinksRendersMultipleUrls(): void
    {
        $bean = $this->makeBean('https://bsky.app/profile/me https://mastodon.social/@me');
        $html = syndication_links($bean);
        $this->assertStringContainsString('https://bsky.app/profile/me', $html);
        $this->assertStringContainsString('https://mastodon.social/@me', $html);
    }

    public function testSyndicationLinksContainsAlsoOnLabel(): void
    {
        $bean = $this->makeBean('https://bsky.app/profile/me');
        $html = syndication_links($bean);
        $this->assertStringContainsString('Also on', $html);
    }
}
