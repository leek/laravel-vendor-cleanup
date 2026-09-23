<?php

namespace Leek\LaravelVendorCleanup\Tests;

use Illuminate\Filesystem\Filesystem;
use Leek\LaravelVendorCleanup\VendorCleanupServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var array<int, string> Files written into the application skeleton */
    private array $fixtureFiles = [];

    /** @var array<int, string> Directories created in the application skeleton */
    private array $fixtureDirs = [];

    private ?string $vendorRoot = null;

    protected function getPackageProviders($app): array
    {
        return [
            VendorCleanupServiceProvider::class,
        ];
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtureFiles as $file) {
            @unlink($file);
        }

        foreach (array_reverse($this->fixtureDirs) as $dir) {
            @rmdir($dir);
        }

        if ($this->vendorRoot) {
            (new Filesystem)->deleteDirectory($this->vendorRoot);
        }

        $this->fixtureFiles = $this->fixtureDirs = [];
        $this->vendorRoot = null;

        parent::tearDown();
    }

    /**
     * Write a file into the application skeleton, removed again on tear down.
     */
    protected function writeLocal(string $path, string $contents): string
    {
        $missing = [];
        for ($dir = dirname($path); ! is_dir($dir); $dir = dirname($dir)) {
            array_unshift($missing, $dir);
        }

        foreach ($missing as $dir) {
            mkdir($dir);
            $this->fixtureDirs[] = $dir;
        }

        file_put_contents($path, $contents);
        $this->fixtureFiles[] = $path;

        return $path;
    }

    /**
     * Write a file into a throwaway package directory outside the skeleton.
     */
    protected function writeVendor(string $relativePath, string $contents): string
    {
        $path = $this->vendorPath($relativePath);
        (new Filesystem)->ensureDirectoryExists(dirname($path));
        file_put_contents($path, $contents);

        return $path;
    }

    protected function vendorPath(string $relativePath = ''): string
    {
        $this->vendorRoot ??= sys_get_temp_dir().'/vendor-cleanup-'.bin2hex(random_bytes(6));

        return rtrim($this->vendorRoot.'/'.$relativePath, '/');
    }

    /**
     * Register publish paths, view namespaces and translation namespaces
     * the way a package service provider would.
     */
    protected function registerPackage(array $publishes = [], array $views = [], array $translations = []): void
    {
        FixtureServiceProvider::$publishPaths = $publishes;
        FixtureServiceProvider::$viewPaths = $views;
        FixtureServiceProvider::$translationPaths = $translations;

        $this->app->register(FixtureServiceProvider::class, true);
    }
}
