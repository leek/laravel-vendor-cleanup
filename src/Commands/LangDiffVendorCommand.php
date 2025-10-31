<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class LangDiffVendorCommand extends AbstractDiffVendorCommand
{
    protected $signature = 'vendor-cleanup:lang
                            {--delete : Delete lang files that are identical to their vendor version}
                            {--normalize : Also normalize whitespace and line endings (comments are always ignored)}';

    protected $description = 'Report which published lang files differ from their vendor originals (and optionally delete unchanged ones).';

    protected function getVendorGlobPattern(): string
    {
        return base_path('vendor/*/*/lang');
    }

    protected function getVendorFiles(): array
    {
        $vendorPackages = glob(base_path('vendor/*/*/lang'), GLOB_ONLYDIR) ?: [];
        $files = [];

        $fs = app(Filesystem::class);

        foreach ($vendorPackages as $langDir) {
            if (is_dir($langDir)) {
                $allFiles = $fs->allFiles($langDir);
                foreach ($allFiles as $file) {
                    $path = $file->getPathname();
                    if (str_ends_with($path, '.php') || str_ends_with($path, '.json')) {
                        $files[] = $path;
                    }
                }
            }
        }

        return $files;
    }

    protected function getLocalPath(string $vendorFile): string
    {
        $vendorFile = $this->normalizePath($vendorFile);
        $relativePath = $this->getRelativeLangPath($vendorFile);

        return lang_path($relativePath);
    }

    protected function getLocalFiles(): array
    {
        $langPath = lang_path();

        if (! is_dir($langPath)) {
            return [];
        }

        $fs = app(Filesystem::class);
        $allFiles = $fs->allFiles($langPath);
        $files = [];

        foreach ($allFiles as $file) {
            $path = $file->getPathname();
            if (str_ends_with($path, '.php') || str_ends_with($path, '.json')) {
                $files[] = $path;
            }
        }

        return $files;
    }

    protected function getVendorBasename(string $vendorFile): string
    {
        $vendorFile = $this->normalizePath($vendorFile);

        return $this->getRelativeLangPath($vendorFile);
    }

    protected function getLocalBasename(string $localFile): string
    {
        $localFile = $this->normalizePath($localFile);
        $langPath = $this->normalizePath(lang_path());

        return Str::after($localFile, $langPath.'/');
    }

    protected function shouldCompareAsArrays(): bool
    {
        return true;
    }

    protected function getFileTypeName(): string
    {
        return 'lang file(s)';
    }

    /**
     * Get the relative path from the lang directory.
     * Handles vendor package paths like /lang/vendor/{package}/{rest}.
     */
    private function getRelativeLangPath(string $vendorFile): string
    {
        // Check for vendor package pattern: /lang/vendor/{package}/{rest}
        if (preg_match('#/lang/vendor/([^/]+)/(.+)$#', $vendorFile, $matches)) {
            return 'vendor/'.$matches[1].'/'.$matches[2];
        }

        // Standard pattern: /lang/{rest}
        if (preg_match('#/lang/(.+)$#', $vendorFile, $matches)) {
            return $matches[1];
        }

        // Fallback to basename
        return basename($vendorFile);
    }
}
