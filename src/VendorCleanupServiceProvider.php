<?php

namespace Leek\LaravelVendorCleanup;

use Illuminate\Support\ServiceProvider;
use Leek\LaravelVendorCleanup\Commands\ConfigDiffVendorCommand;
use Leek\LaravelVendorCleanup\Commands\LangDiffVendorCommand;
use Leek\LaravelVendorCleanup\Commands\MigrationDiffVendorCommand;
use Leek\LaravelVendorCleanup\Commands\ViewDiffVendorCommand;

class VendorCleanupServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConfigDiffVendorCommand::class,
                MigrationDiffVendorCommand::class,
                LangDiffVendorCommand::class,
                ViewDiffVendorCommand::class,
            ]);
        }
    }
}
