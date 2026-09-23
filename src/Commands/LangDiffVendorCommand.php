<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\Translator;
use ReflectionClass;

class LangDiffVendorCommand extends AbstractDiffVendorCommand
{
    protected $name = 'vendor-cleanup:lang';

    protected $description = 'Report which published lang files differ from their vendor originals (and optionally delete unchanged ones).';

    protected function getPublishRoot(): string
    {
        return lang_path();
    }

    protected function isComparableFile(string $path): bool
    {
        return str_ends_with($path, '.php') || str_ends_with($path, '.json');
    }

    /**
     * Map the framework's lang files (copied by lang:publish) and every
     * namespace registered via loadTranslationsFrom() to its override path.
     */
    protected function guessVendorFiles(): array
    {
        $sources = [];

        $frameworkLang = dirname((new ReflectionClass(Translator::class))->getFileName()).'/lang';
        if (is_dir($frameworkLang)) {
            $sources[$frameworkLang] = lang_path();
        }

        $loader = app('translator')->getLoader();
        foreach ($loader->namespaces() as $namespace => $path) {
            if (is_dir($path) && ! $this->isInside($path, lang_path())) {
                $sources[$path] = lang_path("vendor/{$namespace}");
            }
        }

        $fs = app(Filesystem::class);
        $files = [];

        foreach ($sources as $sourceDir => $targetDir) {
            foreach ($fs->allFiles($sourceDir) as $file) {
                if ($this->isComparableFile($file->getPathname())) {
                    $files[$file->getPathname()] = $this->normalizePath($targetDir).'/'.$this->normalizePath($file->getRelativePathname());
                }
            }
        }

        return $files;
    }

    /**
     * Only namespaced package overrides can be orphaned; the rest of lang/
     * holds the application's own translations.
     */
    protected function getLocalFiles(): array
    {
        $vendorLangPath = lang_path('vendor');

        if (! is_dir($vendorLangPath)) {
            return [];
        }

        $files = [];
        foreach (app(Filesystem::class)->allFiles($vendorLangPath) as $file) {
            if ($this->isComparableFile($file->getPathname())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    protected function getFileTypeName(): string
    {
        return 'lang file(s)';
    }

    private function isInside(string $path, string $directory): bool
    {
        return str_starts_with($this->canonicalPath($path).'/', rtrim($this->canonicalPath($directory), '/').'/');
    }
}
