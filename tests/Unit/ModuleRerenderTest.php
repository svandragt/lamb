<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\rerender_for_module_change;
use function Lamb\Hooks\loaded_modules;
use function Lamb\Hooks\module_registry;

use const Lamb\Bootstrap\RENDER_MODULES_OPTION;

/**
 * Stored post HTML follows the loaded modules: when the set changes, posts are
 * marked for re-rendering by the data migrations.
 */
class ModuleRerenderTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $savedRegistry;

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
        R::exec('CREATE TABLE post (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT, version INTEGER)');
        R::exec('INSERT INTO post (body, version) VALUES (?, ?), (?, ?)', ['a', POST_VERSION, 'b', POST_VERSION]);
        $this->savedRegistry = module_registry();
    }

    protected function tearDown(): void
    {
        $registry = &module_registry();
        $registry = $this->savedRegistry;
    }

    /** @param array<string, string|null> $modules */
    private function loaded(array $modules): void
    {
        $registry = &module_registry();
        $registry = $modules;
    }

    private function storedVersions(): array
    {
        return array_map('intval', R::getCol('SELECT version FROM post ORDER BY id'));
    }

    public function testLoadedModulesListsOnlyModulesThatLoaded(): void
    {
        $this->loaded(['/m/websub' => 'websub', '/m/broken' => null, '/m/highlight' => 'highlight']);

        $this->assertSame(['highlight', 'websub'], loaded_modules());
    }

    public function testTheFirstRunOnlyRecordsTheModules(): void
    {
        $this->loaded(['/m/highlight' => 'highlight']);

        $this->assertSame(0, rerender_for_module_change());
        $this->assertSame([POST_VERSION, POST_VERSION], $this->storedVersions());
        $this->assertSame('highlight', R::getCell('SELECT value FROM option WHERE name = ?', [RENDER_MODULES_OPTION]));
    }

    public function testAnUnchangedSetMarksNothing(): void
    {
        $this->loaded(['/m/highlight' => 'highlight']);
        rerender_for_module_change();

        $this->assertSame(0, rerender_for_module_change());
        $this->assertSame([POST_VERSION, POST_VERSION], $this->storedVersions());
    }

    public function testAChangedSetMarksEveryCurrentPost(): void
    {
        $this->loaded(['/m/highlight' => 'highlight']);
        rerender_for_module_change();
        $this->loaded([]);

        $this->assertSame(2, rerender_for_module_change(dry_run: true));
        $this->assertSame([POST_VERSION, POST_VERSION], $this->storedVersions(), 'dry run writes nothing');

        $this->assertSame(2, rerender_for_module_change());
        $this->assertSame([POST_VERSION - 1, POST_VERSION - 1], $this->storedVersions());
        $this->assertSame(0, rerender_for_module_change(), 'the new set is recorded');
    }
}
