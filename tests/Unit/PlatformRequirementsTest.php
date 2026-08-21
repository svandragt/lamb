<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * The declared platform requirements have to name the extensions the code
 * actually uses. When they don't, the platform check fails on exactly the wrong
 * hosts: `composer install` refuses a working SQLite-only host over an
 * extension nothing calls, and happily installs on a host with no SQLite PDO
 * driver, where the first write dies as "Could not connect to database (?)".
 */
class PlatformRequirementsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function declaredRequires(): array
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        $this->assertIsString($json, 'composer.json should be readable');
        $manifest = json_decode($json, true);
        $this->assertIsArray($manifest);
        $this->assertIsArray($manifest['require'] ?? null);

        return $manifest['require'];
    }

    public function testSqlitePdoDriverIsRequired(): void
    {
        // bootstrap_db() connects with a `sqlite:` DSN through PDO, so this is
        // the one database extension the application cannot start without.
        $this->assertArrayHasKey('ext-pdo_sqlite', $this->declaredRequires());
    }

    /**
     * @dataProvider unusedDatabaseExtensionProvider
     */
    public function testUnusedDatabaseExtensionsAreNotRequired(string $extension): void
    {
        $this->assertArrayNotHasKey(
            $extension,
            $this->declaredRequires(),
            $extension . ' is not used, and requiring it blocks installs that would work'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusedDatabaseExtensionProvider(): array
    {
        return [
            'MySQL PDO driver' => ['ext-pdo_mysql'],
            'mysqli'           => ['ext-mysqli'],
            'sqlite3 API'      => ['ext-sqlite3'],
        ];
    }

    public function testStoringABeanNeverLoadsTheMysqlQueryWriter(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        $post = R::dispense('post');
        $post->body = 'x';
        R::store($post);

        // The evidence behind the requirement above: a sqlite DSN picks the
        // SQLiteT writer and never touches the MySQL one, so no MySQL
        // extension is reachable from Lamb's data layer.
        $this->assertTrue(class_exists('RedBeanPHP\QueryWriter\SQLiteT', false));
        $this->assertFalse(class_exists('RedBeanPHP\QueryWriter\MySQL', false));
    }

    public function testEveryDeclaredExtensionIsUsedByTheCodebase(): void
    {
        // Each declared extension is paired with something the source actually
        // calls into, so a requirement nothing uses cannot be added silently.
        $probes = [
            'ext-curl'       => ['curl_init', 'src/http.php fetch_pinned()'],
            'ext-gettext'    => ['ngettext', 'src/response/feeds.php result count'],
            'ext-mbstring'   => ['mb_strimwidth', 'src/themes/base/parts/_related.php'],
            'ext-pdo_sqlite' => ['pdo-sqlite-driver', 'src/bootstrap.php R::setup("sqlite:…")'],
            'ext-simplexml'  => ['simplexml_load_string', 'src/import.php load_feed()'],
        ];

        foreach (array_keys($this->declaredRequires()) as $requirement) {
            if (!str_starts_with($requirement, 'ext-')) {
                continue;
            }
            $this->assertArrayHasKey(
                $requirement,
                $probes,
                $requirement . ' is required but this test knows of no code that uses it'
            );
            [$symbol, $caller] = $probes[$requirement];
            $this->assertTrue(
                $this->probeSucceeds($symbol),
                $requirement . ' is declared for ' . $caller . ', which cannot run here'
            );
        }
    }

    /**
     * Whether the capability a declared extension provides is actually present.
     */
    private function probeSucceeds(string $symbol): bool
    {
        if ($symbol === 'pdo-sqlite-driver') {
            // class_exists('PDO') would also pass with only ext-pdo and no
            // driver at all — the driver list is what the DSN needs.
            return in_array('sqlite', \PDO::getAvailableDrivers(), true);
        }

        return function_exists($symbol) || class_exists($symbol);
    }
}
