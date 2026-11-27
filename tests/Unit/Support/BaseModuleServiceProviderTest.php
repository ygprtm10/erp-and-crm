<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\BaseModuleServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Unit tests verifying module path resolution and auto-loading in BaseModuleServiceProvider.
 */
class BaseModuleServiceProviderTest extends TestCase
{
    private string $tempModuleName = 'TestModuleStub';

    private string $tempModulePath;

    /**
     * Prepare a temporary module directory path before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tempModulePath = base_path('modules/'.$this->tempModuleName);
    }

    /**
     * Clean up any temporary module files created during tests.
     */
    protected function tearDown(): void
    {
        if (is_dir($this->tempModulePath)) {
            File::deleteDirectory($this->tempModulePath);
        }

        parent::tearDown();
    }

    /**
     * Ensure modulePath resolves base and relative paths cleanly without duplicate slashes.
     */
    public function test_resolves_module_base_and_sub_paths(): void
    {
        /** Arrange a concrete service provider instance for the temporary module. */
        $provider = $this->createProvider($this->tempModuleName);

        /** Act and assert both empty and leading-slash subpaths resolve accurately. */
        $this->assertSame($this->tempModulePath, $provider->exposedModulePath());
        $this->assertSame($this->tempModulePath.'/Routes/web.php', $provider->exposedModulePath('/Routes/web.php'));
        $this->assertSame($this->tempModulePath.'/Database/Migrations', $provider->exposedModulePath('Database/Migrations'));
    }

    /**
     * Ensure boot automatically registers migrations, views, web routes, API routes, and listeners.
     */
    public function test_boot_loads_migrations_views_routes_and_listeners_when_present(): void
    {
        /** Arrange temporary module directories and stub asset files. */
        File::ensureDirectoryExists($this->tempModulePath.'/Database/Migrations');
        File::ensureDirectoryExists($this->tempModulePath.'/Resources/views');
        File::ensureDirectoryExists($this->tempModulePath.'/Routes');

        File::put(
            $this->tempModulePath.'/Resources/views/ping.blade.php',
            '<!-- Temporary stub view for module view loading test --><div>pong</div>',
        );
        File::put(
            $this->tempModulePath.'/Routes/web.php',
            "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/__test-module-web', static fn (): string => 'web-ok')->name('test-module.web');\n",
        );
        File::put(
            $this->tempModulePath.'/Routes/api.php',
            "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/__test-module-api', static fn (): string => 'api-ok')->name('test-module.api');\n",
        );

        $provider = $this->createProvider($this->tempModuleName);

        /** Act by booting the module provider and refreshing route name lookups. */
        $provider->boot();
        Route::getRoutes()->refreshNameLookups();

        /** Assert migrations path, namespaced view, web/API routes, and listener hook were loaded. */
        $this->assertContains($this->tempModulePath.'/Database/Migrations', $this->app->make('migrator')->paths());
        $this->assertTrue(View::exists('testmodulestub::ping'));
        $this->assertTrue(Route::has('test-module.web'));
        $this->assertTrue(Route::has('test-module.api'));
        $this->assertContains('web', Route::getRoutes()->getByName('test-module.web')->gatherMiddleware());
        $this->assertSame('api/__test-module-api', Route::getRoutes()->getByName('test-module.api')->uri());
        $this->assertContains('api', Route::getRoutes()->getByName('test-module.api')->gatherMiddleware());
        $this->assertTrue($provider->listenersBooted);
    }

    /**
     * Ensure boot gracefully skips missing module asset directories without throwing errors.
     */
    public function test_boot_handles_missing_directories_gracefully(): void
    {
        /** Arrange a provider pointing to a module directory with no assets on disk. */
        $provider = $this->createProvider('NonExistentModuleStub');

        /** Act by booting the provider. */
        $provider->boot();

        /** Assert listeners hook still ran without errors. */
        $this->assertTrue($provider->listenersBooted);
    }

    /**
     * Instantiate an anonymous BaseModuleServiceProvider subclass with test inspection hooks.
     */
    private function createProvider(string $name): BaseModuleServiceProvider
    {
        return new class($this->app, $name) extends BaseModuleServiceProvider
        {
            public bool $listenersBooted = false;

            /**
             * Store the module directory name for the test stub provider.
             */
            public function __construct($app, private readonly string $name)
            {
                parent::__construct($app);
            }

            /**
             * Return the stub module directory name.
             */
            protected function moduleName(): string
            {
                return $this->name;
            }

            /**
             * Expose protected modulePath for direct unit testing.
             */
            public function exposedModulePath(string $subPath = ''): string
            {
                return $this->modulePath($subPath);
            }

            /**
             * Record when bootListeners is invoked by the parent boot lifecycle.
             */
            protected function bootListeners(): void
            {
                $this->listenersBooted = true;
            }
        };
    }
}
