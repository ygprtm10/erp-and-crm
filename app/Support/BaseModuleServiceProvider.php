<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base service provider that standardizes module asset, migration, and route loading.
 */
abstract class BaseModuleServiceProvider extends ServiceProvider
{
    /**
     * Return the directory name of the module under the modules/ folder.
     */
    abstract protected function moduleName(): string;

    /**
     * Resolve an absolute path inside the current module directory.
     */
    protected function modulePath(string $subPath = ''): string
    {
        $base = base_path('modules/'.$this->moduleName());

        if ($subPath === '') {
            return $base;
        }

        return $base.'/'.ltrim($subPath, '/');
    }

    /**
     * Bootstrap module migrations, views, routes, and event listeners.
     */
    public function boot(): void
    {
        $this->bootMigrations();
        $this->bootViews();
        $this->bootRoutes();
        $this->bootListeners();
    }

    /**
     * Register database migrations belonging to this module if present.
     */
    protected function bootMigrations(): void
    {
        $path = $this->modulePath('Database/Migrations');

        if (! is_dir($path)) {
            return;
        }

        $this->loadMigrationsFrom($path);
    }

    /**
     * Register Blade views under the lowercase module namespace if present.
     */
    protected function bootViews(): void
    {
        $path = $this->modulePath('Resources/views');

        if (! is_dir($path)) {
            return;
        }

        $this->loadViewsFrom($path, strtolower($this->moduleName()));
    }

    /**
     * Register web and API routes belonging to this module if present.
     */
    protected function bootRoutes(): void
    {
        /** Load module web routes with standard web middleware. */
        $webRoute = $this->modulePath('Routes/web.php');
        if (file_exists($webRoute)) {
            Route::middleware('web')->group($webRoute);
        }

        /** Load module API routes under the /api prefix. */
        $apiRoute = $this->modulePath('Routes/api.php');
        if (file_exists($apiRoute)) {
            Route::prefix('api')->middleware('api')->group($apiRoute);
        }
    }

    /**
     * Register cross-module event listeners in concrete module providers.
     */
    protected function bootListeners(): void
    {
        /** Override in child providers when the module subscribes to domain events. */
    }
}
