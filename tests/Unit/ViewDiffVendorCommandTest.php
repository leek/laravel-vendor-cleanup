<?php

namespace Leek\LaravelVendorCleanup\Tests\Unit;

use Leek\LaravelVendorCleanup\Commands\ViewDiffVendorCommand;
use Leek\LaravelVendorCleanup\Tests\TestCase;
use ReflectionClass;

class ViewDiffVendorCommandTest extends TestCase
{
    private function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        return (new ReflectionClass($object))->getMethod($method)->invokeArgs($object, $args);
    }

    public function test_format_path_strips_views_prefix(): void
    {
        $result = $this->invokeMethod(new ViewDiffVendorCommand, 'formatPathForDisplay', [resource_path('views/vendor/horizon/layout.blade.php')]);

        $this->assertEquals('vendor/horizon/layout.blade.php', $result);
    }

    public function test_maps_view_namespaces_to_override_paths(): void
    {
        $this->writeVendor('views/dashboard/index.blade.php', '<div></div>');
        $this->registerPackage(views: ['acme-ui' => $this->vendorPath('views')]);

        $vendorFiles = $this->invokeMethod(new ViewDiffVendorCommand, 'guessVendorFiles');

        $this->assertContains(resource_path('views/vendor/acme-ui/dashboard/index.blade.php'), array_values($vendorFiles));
    }

    public function test_maps_framework_view_namespaces(): void
    {
        $vendorFiles = $this->invokeMethod(new ViewDiffVendorCommand, 'guessVendorFiles');

        $this->assertContains(resource_path('views/vendor/pagination/tailwind.blade.php'), array_values($vendorFiles));
    }
}
