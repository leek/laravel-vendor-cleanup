<?php

namespace Leek\LaravelVendorCleanup\Tests;

use Illuminate\Support\ServiceProvider;

class FixtureServiceProvider extends ServiceProvider
{
    public static array $publishPaths = [];

    public static array $viewPaths = [];

    public static array $translationPaths = [];

    public function boot(): void
    {
        $this->publishes(static::$publishPaths);

        foreach (static::$viewPaths as $namespace => $path) {
            $this->loadViewsFrom($path, $namespace);
        }

        foreach (static::$translationPaths as $namespace => $path) {
            $this->loadTranslationsFrom($path, $namespace);
        }
    }
}
