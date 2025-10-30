<?php

namespace Leek\LaravelVendorCleanup\Tests\Unit;

use Leek\LaravelVendorCleanup\Commands\ViewDiffVendorCommand;
use Leek\LaravelVendorCleanup\Tests\TestCase;
use ReflectionClass;

class ViewDiffVendorCommandTest extends TestCase
{
    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }

    public function test_extract_package_name_from_vendor_path(): void
    {
        $command = new ViewDiffVendorCommand;

        $vendorPath = '/vendor/laravel/horizon/resources/views/index.blade.php';
        $result = $this->invokePrivateMethod($command, 'extractPackageName', [$vendorPath]);

        $this->assertEquals('horizon', $result);
    }

    public function test_extract_package_name_handles_windows_paths(): void
    {
        $command = new ViewDiffVendorCommand;

        // Path must be normalized first in actual usage
        $vendorPath = 'C:/vendor/laravel/horizon/resources/views/index.blade.php';
        $result = $this->invokePrivateMethod($command, 'extractPackageName', [$vendorPath]);

        $this->assertEquals('horizon', $result);
    }

    public function test_extract_package_name_returns_unknown_for_invalid_path(): void
    {
        $command = new ViewDiffVendorCommand;

        $invalidPath = '/invalid/path/file.php';
        $result = $this->invokePrivateMethod($command, 'extractPackageName', [$invalidPath]);

        $this->assertEquals('unknown', $result);
    }

    public function test_get_relative_view_path_extracts_path_after_views(): void
    {
        $command = new ViewDiffVendorCommand;

        $vendorPath = '/vendor/laravel/horizon/resources/views/dashboard/index.blade.php';
        $result = $this->invokePrivateMethod($command, 'getRelativeViewPath', [$vendorPath]);

        $this->assertEquals('dashboard/index.blade.php', $result);
    }

    public function test_get_relative_view_path_returns_basename_if_no_views_directory(): void
    {
        $command = new ViewDiffVendorCommand;

        $invalidPath = '/vendor/laravel/horizon/index.blade.php';
        $result = $this->invokePrivateMethod($command, 'getRelativeViewPath', [$invalidPath]);

        $this->assertEquals('index.blade.php', $result);
    }

    public function test_get_vendor_basename_combines_package_and_relative_path(): void
    {
        $command = new ViewDiffVendorCommand;

        $vendorPath = '/vendor/laravel/horizon/resources/views/dashboard/index.blade.php';
        $result = $this->invokePrivateMethod($command, 'getVendorBasename', [$vendorPath]);

        $this->assertEquals('horizon/dashboard/index.blade.php', $result);
    }
}
