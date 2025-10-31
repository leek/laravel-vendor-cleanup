<?php

namespace Leek\LaravelVendorCleanup\Tests;

use Leek\LaravelVendorCleanup\VendorCleanupServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            VendorCleanupServiceProvider::class,
        ];
    }
}
