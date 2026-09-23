<?php

namespace Leek\LaravelVendorCleanup\Tests\Unit;

use Leek\LaravelVendorCleanup\Commands\LangDiffVendorCommand;
use Leek\LaravelVendorCleanup\Tests\TestCase;
use ReflectionClass;

class LangDiffVendorCommandTest extends TestCase
{
    private function invokeMethod(object $object, string $method, array $args = []): mixed
    {
        return (new ReflectionClass($object))->getMethod($method)->invokeArgs($object, $args);
    }

    public function test_compares_php_and_json_files(): void
    {
        $command = new LangDiffVendorCommand;

        $this->assertTrue($this->invokeMethod($command, 'isComparableFile', ['/lang/en/messages.php']));
        $this->assertTrue($this->invokeMethod($command, 'isComparableFile', ['/lang/en.json']));
        $this->assertFalse($this->invokeMethod($command, 'isComparableFile', ['/lang/README.md']));
    }

    public function test_only_namespaced_overrides_can_be_orphaned(): void
    {
        $this->writeLocal(lang_path('en/app-owned.php'), '<?php return [];');
        $override = $this->writeLocal(lang_path('vendor/acme/en/messages.php'), '<?php return [];');

        $localFiles = $this->invokeMethod(new LangDiffVendorCommand, 'getLocalFiles');

        $this->assertSame([$override], $localFiles);
    }

    public function test_maps_framework_lang_files_to_lang_root(): void
    {
        $vendorFiles = $this->invokeMethod(new LangDiffVendorCommand, 'guessVendorFiles');

        $this->assertContains(lang_path('en/validation.php'), array_values($vendorFiles));
    }
}
