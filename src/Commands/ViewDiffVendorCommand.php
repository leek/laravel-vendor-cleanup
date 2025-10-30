<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Support\Str;

class ViewDiffVendorCommand extends AbstractDiffVendorCommand
{
    protected $signature = 'vendor-cleanup:view
                            {--delete : Delete view files that are identical to their vendor version}
                            {--normalize : Also normalize whitespace and line endings (comments are always ignored)}';

    protected $description = 'Report which published view files differ from their vendor originals (and optionally delete unchanged ones).';

    protected function getVendorGlobPattern(): string
    {
        return base_path('vendor/*/*/resources/views');
    }

    protected function getLocalPath(string $vendorFile): string
    {
        // Extract package name and relative view path
        $packageName  = $this->extractPackageName($vendorFile);
        $relativePath = $this->getRelativeViewPath($vendorFile);

        return resource_path("views/vendor/{$packageName}/{$relativePath}");
    }

    protected function getLocalFiles(): array
    {
        $files    = glob(resource_path('views/vendor/**/*.blade.php'), GLOB_BRACE) ?: [];
        $phpFiles = glob(resource_path('views/vendor/**/*.php'), GLOB_BRACE) ?: [];

        return array_merge($files, $phpFiles);
    }

    protected function getVendorBasename(string $vendorFile): string
    {
        $packageName  = $this->extractPackageName($vendorFile);
        $relativePath = $this->getRelativeViewPath($vendorFile);

        return "{$packageName}/{$relativePath}";
    }

    protected function getLocalBasename(string $localFile): string
    {
        // Extract everything after 'views/vendor/'
        return Str::after($localFile, resource_path('views/vendor') . '/');
    }

    protected function getFileTypeName(): string
    {
        return 'view file(s)';
    }

    /**
     * Extract the package name from the vendor path.
     * e.g., vendor/laravel/horizon/... -> horizon
     */
    private function extractPackageName(string $vendorFile): string
    {
        if (preg_match('#vendor/[^/]+/([^/]+)/#', $vendorFile, $matches)) {
            return $matches[1];
        }

        return 'unknown';
    }

    /**
     * Get the relative path from the views directory.
     */
    private function getRelativeViewPath(string $vendorFile): string
    {
        // Find the 'resources/views/' part in the path and get everything after it
        if (preg_match('#resources/views/(.+)$#', $vendorFile, $matches)) {
            return $matches[1];
        }

        return basename($vendorFile);
    }

    /**
     * Override to use custom globbing for nested directories.
     */
    public function handle(\Illuminate\Filesystem\Filesystem $fs): int
    {
        // Get all vendor view files
        $bladeFiles  = glob(base_path('vendor/*/*/resources/views/**/*.blade.php'), GLOB_BRACE) ?: [];
        $phpFiles    = glob(base_path('vendor/*/*/resources/views/**/*.php'), GLOB_BRACE) ?: [];
        $vendorFiles = array_merge($bladeFiles, $phpFiles);

        // Remove duplicates (*.blade.php will match both patterns)
        $vendorFiles = array_unique($vendorFiles);

        if (empty($vendorFiles)) {
            $this->info('No vendor ' . $this->getFileTypeName() . ' found.');

            return self::SUCCESS;
        }

        // Build a map of vendor file basenames for quick lookup
        $vendorBasenames = [];
        foreach ($vendorFiles as $vendorFile) {
            $basename = $this->getVendorBasename($vendorFile);
            if (! isset($vendorBasenames[$basename])) {
                $vendorBasenames[$basename] = [];
            }
            $vendorBasenames[$basename][] = $vendorFile;
        }

        $unchanged = [];
        $modified  = [];
        $missing   = [];
        $orphaned  = [];

        // Check for orphaned files (no vendor counterpart)
        $localFiles = $this->getLocalFiles();
        foreach ($localFiles as $localFile) {
            $basename = $this->getLocalBasename($localFile);
            if (! isset($vendorBasenames[$basename])) {
                $orphaned[] = $localFile;
            }
        }

        foreach ($vendorFiles as $vendorFile) {
            $target = $this->getLocalPath($vendorFile);
            if (! $fs->exists($target)) {
                $missing[] = $target;

                continue;
            }

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

        // Output
        if ($modified) {
            // Sort by difference percentage (most changed first)
            usort($modified, fn ($a, $b) => $b['diff'] <=> $a['diff']);

            $this->newLine();
            $this->info('MODIFIED');

            $rows = array_map(function ($item) {
                $diffColor = $this->getDiffColor($item['diff']);
                return [
                    $this->toRelativePath($item['path']),
                    "<{$diffColor}>{$item['diff']}%</>",
                ];
            }, $modified);

            $this->table(['File', 'Difference'], $rows);
        }

        if ($unchanged) {
            $this->newLine();
            $this->comment('UNCHANGED (matches vendor)');

            $rows = $this->formatTwoColumnTable($unchanged);
            $this->table(['File', 'File'], $rows);
        }

        if ($orphaned) {
            $this->newLine();
            $this->line('<fg=red>ORPHANED (no vendor counterpart - likely from uninstalled packages)</>');

            $rows = $this->formatTwoColumnTable($orphaned);
            $this->table(['File', 'File'], $rows);
        }

        if ($missing) {
            $this->newLine();
            $this->line('<fg=gray>MISSING (not published locally)</>');

            $rows = $this->formatTwoColumnTable($missing);
            $this->table(['File', 'File'], $rows);
        }

        // Optional delete
        if ($this->option('delete') && $unchanged) {
            if ($this->confirm('Delete ' . count($unchanged) . ' unchanged ' . $this->getFileTypeName() . '?')) {
                foreach ($unchanged as $f) {
                    $fs->delete($f);
                    $this->line("  ✖ deleted {$f}");
                }
            }
        }

        $this->line(PHP_EOL . 'Done.');

        return self::SUCCESS;
    }
}
