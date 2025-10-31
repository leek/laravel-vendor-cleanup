<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Filesystem\Filesystem;
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

    protected function getVendorFiles(): array
    {
        $vendorViewDirs = glob(base_path('vendor/*/*/resources/views'), GLOB_ONLYDIR) ?: [];
        $files = [];

        $fs = app(Filesystem::class);

        foreach ($vendorViewDirs as $viewDir) {
            if (is_dir($viewDir)) {
                $allFiles = $fs->allFiles($viewDir);
                foreach ($allFiles as $file) {
                    $path = $file->getPathname();
                    if (str_ends_with($path, '.blade.php') || str_ends_with($path, '.php')) {
                        $files[] = $path;
                    }
                }
            }
        }

        return array_unique($files);
    }

    protected function getLocalPath(string $vendorFile): string
    {
        $vendorFile = $this->normalizePath($vendorFile);
        $packageName = $this->extractPackageName($vendorFile);
        $relativePath = $this->getRelativeViewPath($vendorFile);

        return resource_path("views/vendor/{$packageName}/{$relativePath}");
    }

    protected function getLocalFiles(): array
    {
        $viewVendorPath = resource_path('views/vendor');

        if (! is_dir($viewVendorPath)) {
            return [];
        }

        $fs = app(Filesystem::class);
        $allFiles = $fs->allFiles($viewVendorPath);
        $files = [];

        foreach ($allFiles as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, '.blade.php') || str_ends_with($path, '.php')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    protected function getVendorBasename(string $vendorFile): string
    {
        $vendorFile = $this->normalizePath($vendorFile);
        $packageName = $this->extractPackageName($vendorFile);
        $relativePath = $this->getRelativeViewPath($vendorFile);

        return "{$packageName}/{$relativePath}";
    }

    protected function getLocalBasename(string $localFile): string
    {
        $localFile = $this->normalizePath($localFile);
        $viewVendorPath = $this->normalizePath(resource_path('views/vendor'));

        return Str::after($localFile, $viewVendorPath.'/');
    }

    protected function getFileTypeName(): string
    {
        return 'view file(s)';
    }

    /**
     * Override formatPathForDisplay to also strip resources/views/ prefix.
     */
    protected function formatPathForDisplay(string $path): string
    {
        $relativePath = $this->toRelativePath($path);

        // Remove "vendor/" prefix if present
        if (str_starts_with($relativePath, 'vendor/')) {
            $relativePath = substr($relativePath, 7);
        }

        // Remove "resources/views/" prefix if present (implied for views)
        if (str_starts_with($relativePath, 'resources/views/')) {
            $relativePath = substr($relativePath, 16); // Remove "resources/views/"
        }

        return $relativePath;
    }

    /**
     * Override displayResults to show orphaned and missing views in single column.
     */
    protected function displayResults(array $modified, array $unchanged, array $orphaned, array $missing): void
    {
        // Display modified and unchanged tables normally
        if ($modified) {
            usort($modified, fn ($a, $b) => $b['diff'] <=> $a['diff']);

            $this->newLine();
            $this->info('MODIFIED');

            $rows = array_map(function ($item) {
                $diffColor = $this->getDiffColor($item['diff']);

                return [
                    $this->formatPathForDisplay($item['path']),
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

        // Display orphaned views in single column (paths are too long)
        if ($orphaned) {
            $this->newLine();
            $this->line('<fg=red>ORPHANED (no vendor counterpart - likely from uninstalled packages)</>');

            $rows = array_map(function ($file) {
                return [$this->formatPathForDisplay($file)];
            }, $orphaned);

            $this->table(['File'], $rows);
        }

        // Display missing views in single column (paths are too long)
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
}
