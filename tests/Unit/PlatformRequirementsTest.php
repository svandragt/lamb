<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * The declared platform requirements have to name the extensions the code
 * actually loads. When they don't, the platform check fails on exactly the
 * wrong hosts: `composer install` refuses a host that would work, or installs
 * on one where the first request fatals.
 *
 * Lamb only ever connects to SQLite, so pdo_sqlite is the driver it needs. But
 * pdo_mysql is required too, for a non-obvious reason: RedBeanPHP's
 * Functions.php (loaded via Composer's `files` autoload) dereferences the
 * MySQL PDO constant PDO::MYSQL_ATTR_INIT_COMMAND at load time, so autoloading
 * the library fatals with "Undefined constant PDO::MYSQL_ATTR_INIT_COMMAND"
 * before any request logic runs. That is a load-time symbol reference, not the
 * MySQL query writer — the writer never loads for a sqlite DSN (proven below).
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
        // the database driver the application actually opens.
        $this->assertArrayHasKey('ext-pdo_sqlite', $this->declaredRequires());
    }

    public function testMysqlPdoDriverIsRequired(): void
    {
        // Not because Lamb connects to MySQL — it never does — but because
        // RedBeanPHP's autoloaded Functions.php references
        // PDO::MYSQL_ATTR_INIT_COMMAND at load time and fatals without it.
        $this->assertArrayHasKey('ext-pdo_mysql', $this->declaredRequires());
    }

    /**
     * @dataProvider unusedExtensionProvider
     */
    public function testUnusedExtensionsAreNotRequired(string $extension): void
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
    public static function unusedExtensionProvider(): array
    {
        return [
            'mysqli'      => ['ext-mysqli'],
            'sqlite3 API' => ['ext-sqlite3'],
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

        // The pdo_mysql requirement is a load-time constant reference, NOT the
        // query writer: a sqlite DSN picks SQLiteT and never touches MySQL.
        $this->assertTrue(class_exists('RedBeanPHP\QueryWriter\SQLiteT', false));
        $this->assertFalse(class_exists('RedBeanPHP\QueryWriter\MySQL', false));
    }

    public function testEveryDeclaredExtensionIsUsedByTheCodebase(): void
    {
        // Each declared extension is paired with something that actually reads
        // it — a source call, or in pdo_mysql's case a symbol the vendored
        // library dereferences at load — so a requirement nothing uses cannot
        // be added silently.
        $probes = [
            'ext-curl'       => ['curl_init', 'src/http.php fetch_pinned()'],
            'ext-gettext'    => ['ngettext', 'src/response/feeds.php result count'],
            'ext-mbstring'   => ['mb_strimwidth', 'src/themes/base/parts/_related.php'],
            'ext-pdo_mysql'  => ['pdo-mysql-init-command-constant', 'RedBeanPHP/Functions.php load-time constant'],
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

        if ($symbol === 'pdo-mysql-init-command-constant') {
            // The exact symbol RedBeanPHP's Functions.php dereferences at load,
            // in either the PHP 8.4 class-constant form or the legacy form.
            // Both are provided only by ext-pdo_mysql.
            return defined('Pdo\Mysql::ATTR_INIT_COMMAND')
                || defined('PDO::MYSQL_ATTR_INIT_COMMAND');
        }

        return function_exists($symbol) || class_exists($symbol);
    }
}
