<?php

namespace Leek\LaravelVendorCleanup\Tests\Unit;

use Leek\LaravelVendorCleanup\Commands\LangDiffVendorCommand;
use Leek\LaravelVendorCleanup\Tests\TestCase;
use ReflectionClass;

class LangDiffVendorCommandTest extends TestCase
{
    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }

    public function test_get_relative_lang_path_extracts_path_after_lang(): void
    {
        $command = new LangDiffVendorCommand;

        $vendorPath = '/vendor/laravel/horizon/lang/en/messages.php';
        $result = $this->invokePrivateMethod($command, 'getRelativeLangPath', [$vendorPath]);

        $this->assertEquals('en/messages.php', $result);
    }

    public function test_get_relative_lang_path_handles_nested_directories(): void
    {
        $command = new LangDiffVendorCommand;

        $vendorPath = '/vendor/laravel/horizon/lang/en/validation/errors.php';
        $result = $this->invokePrivateMethod($command, 'getRelativeLangPath', [$vendorPath]);

        $this->assertEquals('en/validation/errors.php', $result);
    }

    public function test_get_relative_lang_path_returns_basename_if_no_lang_directory(): void
    {
        $command = new LangDiffVendorCommand;

        $invalidPath = '/vendor/laravel/horizon/messages.php';
        $result = $this->invokePrivateMethod($command, 'getRelativeLangPath', [$invalidPath]);

        $this->assertEquals('messages.php', $result);
    }

    public function test_get_vendor_basename_returns_relative_lang_path(): void
    {
        $command = new LangDiffVendorCommand;

        $vendorPath = '/vendor/laravel/horizon/lang/en/messages.php';
        $result = $this->invokePrivateMethod($command, 'getVendorBasename', [$vendorPath]);

        $this->assertEquals('en/messages.php', $result);
    }

    public function test_should_compare_as_arrays_returns_true(): void
    {
        $command = new LangDiffVendorCommand;

        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('shouldCompareAsArrays');
        $method->setAccessible(true);

        $result = $method->invoke($command);

        $this->assertTrue($result);
    }

    public function test_get_relative_lang_path_handles_vendor_package_paths(): void
    {
        $command = new LangDiffVendorCommand;

        $vendorPath = '/vendor/laravel/horizon/lang/vendor/horizon/en/messages.php';
        $result = $this->invokePrivateMethod($command, 'getRelativeLangPath', [$vendorPath]);

        $this->assertEquals('vendor/horizon/en/messages.php', $result);
    }

    public function test_get_relative_lang_path_handles_nested_vendor_package_paths(): void
    {
        $command = new LangDiffVendorCommand;

        $vendorPath = '/vendor/laravel/horizon/lang/vendor/horizon/en/validation/errors.php';
        $result = $this->invokePrivateMethod($command, 'getRelativeLangPath', [$vendorPath]);

        $this->assertEquals('vendor/horizon/en/validation/errors.php', $result);
    }

    public function test_get_vendor_basename_handles_vendor_package_paths(): void
    {
        $command = new LangDiffVendorCommand;

        $vendorPath = '/vendor/laravel/horizon/lang/vendor/horizon/en/messages.php';
        $result = $this->invokePrivateMethod($command, 'getVendorBasename', [$vendorPath]);

        $this->assertEquals('vendor/horizon/en/messages.php', $result);
    }
}
