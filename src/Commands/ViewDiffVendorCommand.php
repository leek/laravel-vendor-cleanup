<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Filesystem\Filesystem;

class ViewDiffVendorCommand extends AbstractDiffVendorCommand
{
    protected $name = 'vendor-cleanup:view';

    protected $description = 'Report which published view files differ from their vendor originals (and optionally delete unchanged ones).';

    protected function getPublishRoot(): string
    {
        return resource_path('views');
    }

    /**
     * Map every view namespace registered via loadViewsFrom() to the path
     * Laravel checks for overrides: resources/views/vendor/{namespace}.
     */
    protected function guessVendorFiles(): array
    {
        $fs = app(Filesystem::class);
        $viewsPath = rtrim($this->canonicalPath(resource_path('views')), '/').'/';
        $files = [];

        foreach (app('view')->getFinder()->getHints() as $namespace => $paths) {
            foreach ($paths as $path) {
                if (! is_dir($path) || str_starts_with($this->canonicalPath($path).'/', $viewsPath)) {
                    continue;
                }

                foreach ($fs->allFiles($path) as $file) {
                    if ($this->isComparableFile($file->getPathname())) {
                        $files[$file->getPathname()] = resource_path("views/vendor/{$namespace}/".$this->normalizePath($file->getRelativePathname()));
                    }
                }
            }
        }

        return $files;
    }

    protected function getLocalFiles(): array
    {
        $viewVendorPath = resource_path('views/vendor');

        if (! is_dir($viewVendorPath)) {
            return [];
        }

        $files = [];
        foreach (app(Filesystem::class)->allFiles($viewVendorPath) as $file) {
            if ($this->isComparableFile($file->getPathname())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
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
}
