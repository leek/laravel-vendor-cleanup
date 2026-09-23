<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Input\InputOption;

class MigrationDiffVendorCommand extends AbstractDiffVendorCommand
{
    protected $name = 'vendor-cleanup:migration';

    protected $description = 'Report which published migration files differ from their vendor originals (and optionally delete unchanged ones).';

    protected function getOptions(): array
    {
        return array_merge(parent::getOptions(), [
            ['orphans', null, InputOption::VALUE_NONE, 'List local migrations that have no vendor counterpart'],
        ]);
    }

    protected function getPublishRoot(): string
    {
        return database_path('migrations');
    }

    protected function isComparableFile(string $path): bool
    {
        return str_ends_with($path, '.php') || str_ends_with($path, '.php.stub');
    }

    /**
     * A deleted published migration does not fall back to the vendor copy the
     * way configs, views and lang files do: fresh installs would silently
     * skip creating the package's tables.
     */
    protected function canDelete(): bool
    {
        return false;
    }

    /**
     * Guess vendor migrations (including .php.stub files) that are not
     * registered for publishing, skipping test and example migrations.
     * Local paths are unknown until matched, since published names carry
     * a fresh timestamp.
     */
    protected function guessVendorFiles(): array
    {
        $phpFiles = $this->globExactCase(base_path('vendor/*/*/database/migrations/*.php'));
        $stubFiles = $this->globExactCase(base_path('vendor/*/*/database/migrations/*.php.stub'));

        $files = [];
        foreach (array_merge($phpFiles, $stubFiles) as $vendorFile) {
            if (! $this->shouldExcludeVendorFile($vendorFile)) {
                $files[$vendorFile] = database_path('migrations/'.basename($vendorFile));
            }
        }

        return $files;
    }

    protected function getLocalFiles(): array
    {
        return glob(database_path('migrations/*.php')) ?: [];
    }

    protected function getFileTypeName(): string
    {
        return 'migration file(s)';
    }

    /**
     * Override formatPathForDisplay to also strip database/migrations/ prefix.
     */
    protected function formatPathForDisplay(string $path): string
    {
        $relativePath = $this->toRelativePath($path);

        // Remove "vendor/" prefix if present
        if (str_starts_with($relativePath, 'vendor/')) {
            $relativePath = substr($relativePath, 7);
        }

        // Remove "database/migrations/" prefix if present (implied for migrations)
        if (str_starts_with($relativePath, 'database/migrations/')) {
            $relativePath = substr($relativePath, 20); // Remove "database/migrations/"
        }

        return $relativePath;
    }

    /**
     * Override displayResults to show all migrations in single column (paths are too long).
     */
    protected function displayResults(array $modified, array $unchanged, array $orphaned, array $missing): void
    {
        // Display modified migrations in single column with diff inline
        if ($modified) {
            $this->newLine();
            $this->info('MODIFIED');

            $rows = array_map(function ($item) {
                $diffColor = $this->getDiffColor($item['diff']);
                $path = $this->formatPathForDisplay($item['path']);

                return ["{$path} - <{$diffColor}>{$item['diff']}%</>"];
            }, $modified);

            $this->table(['File'], $rows);
        }

        // Display unchanged migrations in single column
        if ($unchanged) {
            $this->newLine();
            $this->comment('UNCHANGED (matches vendor)');

            $rows = array_map(function ($file) {
                return [$this->formatPathForDisplay($file)];
            }, $unchanged);

            $this->table(['File'], $rows);
        }

        // Most local migrations are the application's own, so only list them on request
        if ($orphaned) {
            $this->newLine();

            if ($this->option('orphans')) {
                $this->line('<fg=red>NO VENDOR COUNTERPART (application-owned or from removed packages)</>');

                $rows = array_map(function ($file) {
                    return [$this->formatPathForDisplay($file)];
                }, $orphaned);

                $this->table(['File'], $rows);
            } else {
                $this->line('<fg=gray>'.count($orphaned).' local migration(s) have no vendor counterpart (application-owned or from removed packages). Use --orphans to list them.</>');
            }
        }

        // Display missing migrations in single column
        if ($missing) {
            $this->newLine();
            $this->line('<fg=gray>MISSING (not published locally)</>');

            $rows = array_map(function ($file) {
                return [$this->formatPathForDisplay($file)];
            }, $missing);

            $this->table(['File'], $rows);
        }
    }

    /**
     * Strip the timestamp prefix from a migration filename.
     * e.g., "2024_01_15_123456_create_jobs_table.php" -> "create_jobs_table.php"
     * Also handles .php.stub files.
     */
    private function stripTimestamp(string $filename): string
    {
        // Strip .stub extension if present
        $filename = str_replace('.php.stub', '.php', $filename);

        // Match the Laravel timestamp pattern: YYYY_MM_DD_HHMMSS_
        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', $filename, $matches)) {
            return $matches[1];
        }

        return $filename;
    }

    /**
     * Check if a vendor file should be excluded (test/example migrations).
     */
    private function shouldExcludeVendorFile(string $path): bool
    {
        $path = $this->normalizePath($path);

        // Exclude test directories
        if (str_contains($path, '/tests/') || str_contains($path, '/test/')) {
            return true;
        }

        // Exclude example/stub migrations (common patterns)
        if (str_contains($path, '/stubs/') || str_contains($path, '/examples/')) {
            return true;
        }

        // Exclude testbench migrations
        if (str_contains(basename($path), 'testbench')) {
            return true;
        }

        return false;
    }

    /**
     * Override collectAndCategorizeFiles to match timestamped migrations by name.
     */
    protected function collectAndCategorizeFiles(array $vendorFiles, Filesystem $fs): array
    {
        // Map vendor and local migrations by name without timestamp. Store lists
        // to handle multiple files with the same stripped name.
        $vendorMap = [];
        foreach (array_keys($vendorFiles) as $vendorFile) {
            $vendorMap[$this->stripTimestamp(basename($vendorFile))][] = $vendorFile;
        }

        $localMap = [];
        foreach ($this->getLocalFiles() as $localFile) {
            $localMap[$this->stripTimestamp(basename($localFile))][] = $localFile;
        }

        $unchanged = [];
        $modified = [];
        $missing = [];
        $orphaned = [];

        foreach ($localMap as $strippedName => $localFileList) {
            if (! isset($vendorMap[$strippedName])) {
                array_push($orphaned, ...$localFileList);
            }
        }

        foreach ($vendorMap as $strippedName => $vendorFileList) {
            if (! isset($localMap[$strippedName])) {
                array_push($missing, ...$vendorFileList);

                continue;
            }

            // Compare all combinations; the best match for each local file wins
            foreach ($vendorFileList as $vendorFile) {
                foreach ($localMap[$strippedName] as $localFile) {
                    $this->recordComparison($unchanged, $modified, $localFile, $this->compareFileContents($vendorFile, $localFile, $fs));
                }
            }
        }

        return [array_keys($unchanged), $this->formatModified($modified), $missing, $orphaned];
    }
}
