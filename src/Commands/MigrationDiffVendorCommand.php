<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Filesystem\Filesystem;

class MigrationDiffVendorCommand extends AbstractDiffVendorCommand
{
    protected $signature = 'vendor-cleanup:migration
                            {--delete : Delete migration files that are identical to their vendor version}
                            {--normalize : Also normalize whitespace and line endings (comments are always ignored)}';

    protected $description = 'Report which published migration files differ from their vendor originals (and optionally delete unchanged ones).';

    protected function getVendorGlobPattern(): string
    {
        // Not used, we'll get files in handle() method
        return base_path('vendor/*/*/database/migrations/*.php');
    }

    /**
     * Get all vendor migration files including .php.stub files.
     */
    private function getVendorMigrationFiles(): array
    {
        $phpFiles = glob(base_path('vendor/*/*/database/migrations/*.php')) ?: [];
        $stubFiles = glob(base_path('vendor/*/*/database/migrations/*.php.stub')) ?: [];

        return array_merge($phpFiles, $stubFiles);
    }

    protected function getLocalPath(string $vendorFile): string
    {
        // This won't be used since we override handle()
        return database_path('migrations/'.basename($vendorFile));
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
            usort($modified, fn ($a, $b) => $b['diff'] <=> $a['diff']);

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

        // Display orphaned migrations in single column
        if ($orphaned) {
            $this->newLine();
            $this->line('<fg=red>ORPHANED (no vendor counterpart - likely from uninstalled packages)</>');

            $rows = array_map(function ($file) {
                return [$this->formatPathForDisplay($file)];
            }, $orphaned);

            $this->table(['File'], $rows);
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
     * Override collectAndCategorizeFiles to implement custom matching logic for timestamped migrations.
     */
    protected function collectAndCategorizeFiles(array $vendorFiles, Filesystem $fs): array
    {
        // Build a map of vendor migrations by their base name (without timestamp)
        // Store arrays to handle multiple files with the same stripped basename
        $vendorMap = [];
        foreach ($vendorFiles as $vendorFile) {
            $basename = basename($vendorFile);
            $strippedName = $this->stripTimestamp($basename);
            $vendorMap[$strippedName][] = $vendorFile;
        }

        // Build a map of local migrations by their base name (without timestamp)
        // Store arrays to handle multiple files with the same stripped basename
        $localFiles = $this->getLocalFiles();
        $localMap = [];
        foreach ($localFiles as $localFile) {
            $basename = basename($localFile);
            $strippedName = $this->stripTimestamp($basename);
            $localMap[$strippedName][] = $localFile;
        }

        // Use keyed arrays to deduplicate entries when comparing multiple combinations
        $unchangedMap = [];
        $modifiedMap = [];
        $missingMap = [];
        $orphanedMap = [];

        // Check for orphaned migrations (no vendor counterpart)
        foreach ($localMap as $strippedName => $localFileList) {
            if (! isset($vendorMap[$strippedName])) {
                // All local files with this basename are orphaned
                foreach ($localFileList as $localFile) {
                    $orphanedMap[$localFile] = true;
                }
            }
        }

        // Compare vendor migrations to local ones
        foreach ($vendorMap as $strippedName => $vendorFileList) {
            if (! isset($localMap[$strippedName])) {
                // All vendor files with this basename are missing locally
                foreach ($vendorFileList as $vendorFile) {
                    $missingMap[$vendorFile] = true;
                }

                continue;
            }

            // Compare all combinations of vendor and local files with this basename
            // Store the best (lowest) diff for each file to avoid duplicates
            foreach ($vendorFileList as $vendorFile) {
                foreach ($localMap[$strippedName] as $localFile) {
                    $result = $this->compareFileContents($vendorFile, $localFile, $fs);

                    if ($result['status'] === 'unchanged') {
                        $unchangedMap[$localFile] = true;
                        // Remove from modified if it was there
                        unset($modifiedMap[$localFile]);
                    } else {
                        // Only store if unchanged map doesn't have it, and either:
                        // - We don't have this file yet, or
                        // - The new diff is lower (better match)
                        if (! isset($unchangedMap[$localFile])) {
                            if (! isset($modifiedMap[$localFile]) || $result['diff'] < $modifiedMap[$localFile]) {
                                $modifiedMap[$localFile] = $result['diff'];
                            }
                        }
                    }
                }
            }
        }

        // Convert maps back to arrays
        $unchanged = array_keys($unchangedMap);
        $missing = array_keys($missingMap);
        $orphaned = array_keys($orphanedMap);

        // Convert modified map to the expected format
        $modified = [];
        foreach ($modifiedMap as $path => $diff) {
            $modified[] = ['path' => $path, 'diff' => $diff];
        }

        return [$unchanged, $modified, $missing, $orphaned];
    }

    /**
     * Override getVendorFiles to filter out test/example migrations.
     */
    protected function getVendorFiles(): array
    {
        $allVendorFiles = $this->getVendorMigrationFiles();

        // Filter out test/example migrations
        return array_filter($allVendorFiles, fn ($file) => ! $this->shouldExcludeVendorFile($file));
    }
}
