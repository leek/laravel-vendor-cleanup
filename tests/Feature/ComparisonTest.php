<?php

namespace Leek\LaravelVendorCleanup\Tests\Feature;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Translation\Translator;
use Leek\LaravelVendorCleanup\Tests\TestCase;
use ReflectionClass;

class ComparisonTest extends TestCase
{
    private function report(string $command, array $options = []): array
    {
        Artisan::call($command, ['--json' => true] + $options);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_config_customized_via_hardcoded_env_value_and_list_order_is_modified(): void
    {
        putenv('VENDOR_CLEANUP_DRIVER=redis');

        $vendor = $this->writeVendor('config/probe.php', "<?php return ['driver' => env('VENDOR_CLEANUP_DRIVER', 'database'), 'stack' => ['a', 'b']];");
        $local = $this->writeLocal(config_path('probe.php'), "<?php return ['driver' => 'redis', 'stack' => ['b', 'a']];");
        $this->registerPackage(publishes: [$vendor => config_path('probe.php')]);

        $report = $this->report('vendor-cleanup:config', ['--delete' => true, '--force' => true]);

        putenv('VENDOR_CLEANUP_DRIVER');

        $this->assertSame(['config/probe.php'], array_column($report['modified'], 'path'));
        $this->assertSame([], $report['deleted']);
        $this->assertFileExists($local);
    }

    public function test_json_lang_files_are_compared_without_printing_them(): void
    {
        $this->writeVendor('lang/en.json', '{"Hello":"Hi","Bye":"Ciao"}');
        $this->writeVendor('lang/fr.json', '{"Hello":"Salut"}');
        $this->writeLocal(lang_path('vendor/acme/en.json'), '{"Bye":"Ciao","Hello":"Hi"}');
        $this->writeLocal(lang_path('vendor/acme/fr.json'), '{"Hello":"Bonjour"}');
        $this->registerPackage(publishes: [$this->vendorPath('lang') => lang_path('vendor/acme')]);

        ob_start();
        $report = $this->report('vendor-cleanup:lang');
        $leaked = ob_get_clean();

        $this->assertSame('', $leaked);
        $this->assertSame(['lang/vendor/acme/en.json'], $report['unchanged']);
        $this->assertSame(['lang/vendor/acme/fr.json'], array_column($report['modified'], 'path'));
    }

    public function test_framework_lang_files_are_matched(): void
    {
        $frameworkLang = dirname((new ReflectionClass(Translator::class))->getFileName()).'/lang';
        $this->writeLocal(lang_path('en/auth.php'), file_get_contents($frameworkLang.'/en/auth.php'));

        $report = $this->report('vendor-cleanup:lang');

        $this->assertContains('lang/en/auth.php', $report['unchanged']);
    }

    public function test_namespaced_translations_are_matched_to_their_override_path(): void
    {
        $contents = "<?php return ['welcome' => 'Welcome'];";
        $this->writeVendor('lang/en/messages.php', $contents);
        $this->writeLocal(lang_path('vendor/acme/en/messages.php'), $contents);
        $this->writeLocal(lang_path('vendor/gone/en/messages.php'), $contents);
        $this->registerPackage(translations: ['acme' => $this->vendorPath('lang')]);

        $report = $this->report('vendor-cleanup:lang');

        $this->assertSame(['lang/vendor/acme/en/messages.php'], $report['unchanged']);
        $this->assertSame(['lang/vendor/gone/en/messages.php'], $report['orphaned']);
    }

    public function test_published_views_are_matched_by_publish_destination(): void
    {
        $this->writeVendor('resources/views/card.blade.php', '<div>{{ $slot }}</div>');
        $this->writeLocal(resource_path('views/vendor/acme-ui/card.blade.php'), '<div>{{ $slot }}</div>');
        $this->registerPackage(publishes: [$this->vendorPath('resources/views') => resource_path('views/vendor/acme-ui')]);

        $report = $this->report('vendor-cleanup:view');

        $this->assertContains('resources/views/vendor/acme-ui/card.blade.php', $report['unchanged']);
        $this->assertSame([], $report['orphaned']);
    }

    public function test_view_namespaces_are_matched_without_publish_paths(): void
    {
        $this->writeVendor('views/card.blade.php', '<div>{{ $slot }}</div>');
        $this->writeLocal(resource_path('views/vendor/acme-ui/card.blade.php'), '<div>{{ $slot }} custom</div>');
        $this->registerPackage(views: ['acme-ui' => $this->vendorPath('views')]);

        $report = $this->report('vendor-cleanup:view');

        $this->assertContains('resources/views/vendor/acme-ui/card.blade.php', array_column($report['modified'], 'path'));
        $this->assertSame([], $report['orphaned']);
    }

    public function test_framework_pagination_views_are_matched(): void
    {
        $paginationViews = dirname((new ReflectionClass(Paginator::class))->getFileName()).'/resources/views';
        $this->writeLocal(resource_path('views/vendor/pagination/tailwind.blade.php'), file_get_contents($paginationViews.'/tailwind.blade.php'));

        $report = $this->report('vendor-cleanup:view');

        $this->assertContains('resources/views/vendor/pagination/tailwind.blade.php', $report['unchanged']);
        $this->assertNotContains('resources/views/vendor/pagination/tailwind.blade.php', $report['orphaned']);
    }

    public function test_migrations_are_never_deleted(): void
    {
        $contents = "<?php\n\nreturn new class {};\n";
        $vendor = $this->writeVendor('database/migrations/create_acme_table.php.stub', $contents);
        $local = $this->writeLocal(database_path('migrations/2024_01_01_000000_create_acme_table.php'), $contents);
        $this->registerPackage(publishes: [$vendor => database_path('migrations/create_acme_table.php')]);

        $this->assertSame([], $this->report('vendor-cleanup:migration', ['--delete' => true, '--force' => true])['deleted']);
        $this->assertFileExists($local);

        $this->artisan('vendor-cleanup:migration', ['--delete' => true, '--force' => true])
            ->expectsOutputToContain('never deleted automatically')
            ->assertSuccessful();

        $this->assertFileExists($local);
    }

    public function test_application_migrations_are_summarized_unless_requested(): void
    {
        $this->writeVendor('database/migrations/create_acme_table.php', '<?php');
        $this->registerPackage(publishes: [$this->vendorPath('database/migrations') => database_path('migrations')]);
        $this->writeLocal(database_path('migrations/2024_01_01_000000_create_posts_table.php'), '<?php');

        $this->artisan('vendor-cleanup:migration')
            ->expectsOutputToContain('Use --orphans to list them')
            ->doesntExpectOutputToContain('create_posts_table')
            ->assertSuccessful();

        $this->artisan('vendor-cleanup:migration', ['--orphans' => true])
            ->expectsOutputToContain('create_posts_table')
            ->assertSuccessful();
    }

    public function test_local_file_matching_any_vendor_copy_is_reported_once_as_unchanged(): void
    {
        $same = $this->writeVendor('a/config/shared.php', "<?php return ['a' => 1];");
        $other = $this->writeVendor('b/config/shared.php', "<?php return ['a' => 2];");
        $this->writeLocal(config_path('shared.php'), "<?php return ['a' => 1];");
        $this->registerPackage(publishes: [$same => config_path('shared.php'), $other => config_path('shared.php')]);

        $report = $this->report('vendor-cleanup:config');

        $this->assertSame(['config/shared.php'], $report['unchanged']);
        $this->assertSame([], $report['modified']);
    }

    public function test_delete_requires_force_when_not_interactive(): void
    {
        $vendor = $this->writeVendor('config/probe.php', '<?php return [];');
        $local = $this->writeLocal(config_path('probe.php'), '<?php return [];');
        $this->registerPackage(publishes: [$vendor => config_path('probe.php')]);

        Artisan::call('vendor-cleanup:config', ['--delete' => true, '--no-interaction' => true]);
        $this->assertStringContainsString('pass --force', Artisan::output());
        $this->assertFileExists($local);

        Artisan::call('vendor-cleanup:config', ['--delete' => true, '--force' => true]);
        $this->assertFileDoesNotExist($local);
    }

    public function test_delete_asks_for_confirmation(): void
    {
        $vendor = $this->writeVendor('config/probe.php', '<?php return [];');
        $local = $this->writeLocal(config_path('probe.php'), '<?php return [];');
        $this->registerPackage(publishes: [$vendor => config_path('probe.php')]);

        $this->artisan('vendor-cleanup:config', ['--delete' => true])
            ->expectsConfirmation('Delete 1 unchanged config file(s)?', 'no')
            ->assertSuccessful();
        $this->assertFileExists($local);

        $this->artisan('vendor-cleanup:config', ['--delete' => true])
            ->expectsConfirmation('Delete 1 unchanged config file(s)?', 'yes')
            ->assertSuccessful();
        $this->assertFileDoesNotExist($local);
    }

    public function test_fail_on_unchanged_sets_exit_code(): void
    {
        $vendor = $this->writeVendor('config/probe.php', '<?php return [];');
        $this->writeLocal(config_path('probe.php'), '<?php return [];');
        $this->registerPackage(publishes: [$vendor => config_path('probe.php')]);

        $this->artisan('vendor-cleanup:config', ['--fail-on-unchanged' => true])->assertFailed();
        $this->artisan('vendor-cleanup:config', ['--fail-on-unchanged' => true, '--delete' => true, '--force' => true])->assertSuccessful();
    }

    public function test_differently_cased_vendor_directories_are_not_configs(): void
    {
        $classFile = $this->writeLocal(base_path('vendor/acme/kernel/Config/FileLocator.php'), '<?php class FileLocator {}');

        $report = $this->report('vendor-cleanup:config');

        $this->assertNotContains(realpath($classFile), $report['missing']);
        $this->assertNotContains($classFile, $report['missing']);
        $this->assertEmpty(array_filter($report['missing'], fn ($path) => str_contains($path, 'acme/kernel')));
    }

    public function test_json_report_lists_every_category(): void
    {
        $vendor = $this->writeVendor('config/probe.php', '<?php return [];');
        $missing = $this->writeVendor('config/unpublished.php', '<?php return [];');
        $this->writeLocal(config_path('probe.php'), '<?php return [];');
        $this->registerPackage(publishes: [$vendor => config_path('probe.php'), $missing => config_path('unpublished.php')]);

        $report = $this->report('vendor-cleanup:config');

        $this->assertSame(['modified', 'unchanged', 'orphaned', 'missing', 'deleted'], array_keys($report));
        $this->assertContains('config/probe.php', $report['unchanged']);
        $this->assertContains(realpath($missing), $report['missing']);
    }
}
