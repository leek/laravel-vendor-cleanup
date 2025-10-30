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
        $phpFiles  = glob(base_path('vendor/*/*/database/migrations/*.php')) ?: [];
        $stubFiles = glob(base_path('vendor/*/*/database/migrations/*.php.stub')) ?: [];

        return array_merge($phpFiles, $stubFiles);
    }

    protected function getLocalPath(string $vendorFile): string
    {
        // This won't be used since we override handle()
        return database_path('migrations/' . basename($vendorFile));
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
        $vendorMap = [];
        foreach ($vendorFiles as $vendorFile) {
            $basename                 = basename($vendorFile);
            $strippedName             = $this->stripTimestamp($basename);
            $vendorMap[$strippedName] = $vendorFile;
        }

        // Build a map of local migrations by their base name (without timestamp)
        $localFiles = $this->getLocalFiles();
        $localMap   = [];
        foreach ($localFiles as $localFile) {
            $basename                = basename($localFile);
            $strippedName            = $this->stripTimestamp($basename);
            $localMap[$strippedName] = $localFile;
        }

        $unchanged = [];
        $modified  = [];
        $missing   = [];
        $orphaned  = [];

        // Check for orphaned migrations (no vendor counterpart)
        foreach ($localMap as $strippedName => $localFile) {
            if (! isset($vendorMap[$strippedName])) {
                $orphaned[] = $localFile;
            }
        }

        // Compare vendor migrations to local ones
        foreach ($vendorMap as $strippedName => $vendorFile) {
            if (! isset($localMap[$strippedName])) {
                $missing[] = $vendorFile; // Show vendor path instead of local path

                continue;
            }

            $target = $localMap[$strippedName];

            $v = $fs->get($vendorFile);
            $t = $fs->get($target);

            // Always strip comments for comparison
            $vStripped = $this->stripComments($v);
            $tStripped = $this->stripComments($t);

            // Apply additional normalization if requested
            if ($this->shouldNormalizeContent() && $this->option('normalize')) {
                $vStripped = $this->normalizeWhitespace($vStripped);
                $tStripped = $this->normalizeWhitespace($tStripped);
            }

            if (hash('sha256', $vStripped) === hash('sha256', $tStripped)) {
                $unchanged[] = $target;
            } else {
                $diffPercentage = $this->calculateDiffPercentage($vStripped, $tStripped);
                $modified[]     = ['path' => $target, 'diff' => $diffPercentage];
            }
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
