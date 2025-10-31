<?php

namespace Leek\LaravelVendorCleanup\Tests\Feature;

use Leek\LaravelVendorCleanup\Tests\TestCase;

class CommandsTest extends TestCase
{
    public function test_config_command_is_registered(): void
    {
        $this->artisan('vendor-cleanup:config')
            ->assertSuccessful();
    }

    public function test_migration_command_is_registered(): void
    {
        $this->artisan('vendor-cleanup:migration')
            ->assertSuccessful();
    }

    public function test_lang_command_is_registered(): void
    {
        $this->artisan('vendor-cleanup:lang')
            ->assertSuccessful();
    }

    public function test_view_command_is_registered(): void
    {
        $this->artisan('vendor-cleanup:view')
            ->assertSuccessful();
    }

    public function test_commands_have_correct_descriptions(): void
    {
        $expectedDescriptions = [
            'vendor-cleanup:config' => 'Report which published config files differ from their vendor originals (and optionally delete unchanged ones).',
            'vendor-cleanup:migration' => 'Report which published migration files differ from their vendor originals (and optionally delete unchanged ones).',
            'vendor-cleanup:lang' => 'Report which published lang files differ from their vendor originals (and optionally delete unchanged ones).',
            'vendor-cleanup:view' => 'Report which published view files differ from their vendor originals (and optionally delete unchanged ones).',
        ];

        // Check each command's help output contains the expected description
        foreach ($expectedDescriptions as $commandName => $expectedDescription) {
            $this->artisan($commandName, ['--help' => true])
                ->expectsOutputToContain($expectedDescription)
                ->assertSuccessful();
        }
    }

    public function test_commands_support_delete_option(): void
    {
        $commands = [
            'vendor-cleanup:config',
            'vendor-cleanup:migration',
            'vendor-cleanup:lang',
            'vendor-cleanup:view',
        ];

        foreach ($commands as $command) {
            // Test that the command accepts --delete option without error
            $this->artisan($command, ['--delete' => true])
                ->assertSuccessful();
        }
    }

    public function test_commands_support_normalize_option(): void
    {
        $commands = [
            'vendor-cleanup:config',
            'vendor-cleanup:migration',
            'vendor-cleanup:lang',
            'vendor-cleanup:view',
        ];

        foreach ($commands as $command) {
            // Test that the command accepts --normalize option without error
            $this->artisan($command, ['--normalize' => true])
                ->assertSuccessful();
        }
    }
}
