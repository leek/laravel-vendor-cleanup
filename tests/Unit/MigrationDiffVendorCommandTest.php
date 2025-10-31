<?php

namespace Leek\LaravelVendorCleanup\Tests\Unit;

use Leek\LaravelVendorCleanup\Commands\MigrationDiffVendorCommand;
use Leek\LaravelVendorCleanup\Tests\TestCase;
use ReflectionClass;

class MigrationDiffVendorCommandTest extends TestCase
{
    private function invokePrivateMethod(object $object, string $method, array $args = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $args);
    }

    public function test_strip_timestamp_removes_laravel_timestamp_prefix(): void
    {
        $command = new MigrationDiffVendorCommand;

        $filename = '2024_01_15_123456_create_users_table.php';
        $result = $this->invokePrivateMethod($command, 'stripTimestamp', [$filename]);

        $this->assertEquals('create_users_table.php', $result);
    }

    public function test_strip_timestamp_handles_stub_extension(): void
    {
        $command = new MigrationDiffVendorCommand;

        $filename = '2024_01_15_123456_create_users_table.php.stub';
        $result = $this->invokePrivateMethod($command, 'stripTimestamp', [$filename]);

        $this->assertEquals('create_users_table.php', $result);
    }

    public function test_strip_timestamp_returns_original_if_no_timestamp(): void
    {
        $command = new MigrationDiffVendorCommand;

        $filename = 'create_users_table.php';
        $result = $this->invokePrivateMethod($command, 'stripTimestamp', [$filename]);

        $this->assertEquals('create_users_table.php', $result);
    }

    public function test_should_exclude_vendor_file_excludes_test_directories(): void
    {
        $command = new MigrationDiffVendorCommand;

        $testPath = '/vendor/package/tests/migrations/test_migration.php';
        $result = $this->invokePrivateMethod($command, 'shouldExcludeVendorFile', [$testPath]);

        $this->assertTrue($result);
    }

    public function test_should_exclude_vendor_file_excludes_stubs_directories(): void
    {
        $command = new MigrationDiffVendorCommand;

        $stubPath = '/vendor/package/stubs/migration.php';
        $result = $this->invokePrivateMethod($command, 'shouldExcludeVendorFile', [$stubPath]);

        $this->assertTrue($result);
    }

    public function test_should_exclude_vendor_file_excludes_testbench_migrations(): void
    {
        $command = new MigrationDiffVendorCommand;

        $testbenchPath = '/vendor/package/migrations/testbench_migration.php';
        $result = $this->invokePrivateMethod($command, 'shouldExcludeVendorFile', [$testbenchPath]);

        $this->assertTrue($result);
    }

    public function test_should_exclude_vendor_file_allows_regular_migrations(): void
    {
        $command = new MigrationDiffVendorCommand;

        $regularPath = '/vendor/package/database/migrations/create_users_table.php';
        $result = $this->invokePrivateMethod($command, 'shouldExcludeVendorFile', [$regularPath]);

        $this->assertFalse($result);
    }

    public function test_should_exclude_vendor_file_handles_windows_paths(): void
    {
        $command = new MigrationDiffVendorCommand;

        $windowsPath = 'C:\\vendor\\package\\tests\\migrations\\test_migration.php';
        $result = $this->invokePrivateMethod($command, 'shouldExcludeVendorFile', [$windowsPath]);

        $this->assertTrue($result);
    }

    public function test_strip_timestamp_handles_multiple_files_with_same_basename(): void
    {
        $command = new MigrationDiffVendorCommand;

        $file1 = '2024_01_01_000000_create_users_table.php';
        $file2 = '2024_02_01_000000_create_users_table.php';
        $file3 = '2024_03_01_000000_create_users_table.php';

        $stripped1 = $this->invokePrivateMethod($command, 'stripTimestamp', [$file1]);
        $stripped2 = $this->invokePrivateMethod($command, 'stripTimestamp', [$file2]);
        $stripped3 = $this->invokePrivateMethod($command, 'stripTimestamp', [$file3]);

        // All should strip to the same basename
        $this->assertEquals('create_users_table.php', $stripped1);
        $this->assertEquals('create_users_table.php', $stripped2);
        $this->assertEquals('create_users_table.php', $stripped3);
        $this->assertEquals($stripped1, $stripped2);
        $this->assertEquals($stripped2, $stripped3);
    }
}
