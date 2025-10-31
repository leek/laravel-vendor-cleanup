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
