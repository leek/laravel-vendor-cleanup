<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Support\Str;

class LangDiffVendorCommand extends AbstractDiffVendorCommand
{
    protected $signature = 'vendor-cleanup:lang
                            {--delete : Delete lang files that are identical to their vendor version}
                            {--normalize : Also normalize whitespace and line endings (comments are always ignored)}';

    protected $description = 'Report which published lang files differ from their vendor originals (and optionally delete unchanged ones).';

    protected function getVendorGlobPattern(): string
    {
        // Find all PHP and JSON files in vendor lang directories (including nested)
        $phpFiles  = glob(base_path('vendor/*/*/lang/**/*.php'), GLOB_BRACE) ?: [];
        $jsonFiles = glob(base_path('vendor/*/*/lang/*.json'), GLOB_BRACE) ?: [];

        // Since glob() doesn't support multiple patterns directly, we'll handle this in getVendorFiles()
        return base_path('vendor/*/*/lang');
    }

    protected function getLocalPath(string $vendorFile): string
    {
        // Extract the relative path from the vendor lang directory
        $relativePath = $this->getRelativeLangPath($vendorFile);

        return lang_path($relativePath);
    }

    protected function getLocalFiles(): array
    {
        $phpFiles  = glob(lang_path('**/*.php'), GLOB_BRACE) ?: [];
        $jsonFiles = glob(lang_path('*.json')) ?: [];

        return array_merge($phpFiles, $jsonFiles);
    }

    protected function getVendorBasename(string $vendorFile): string
    {
        return $this->getRelativeLangPath($vendorFile);
    }

    protected function getLocalBasename(string $localFile): string
    {
        return Str::after($localFile, lang_path() . '/');
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
     */
    private function getRelativeLangPath(string $vendorFile): string
    {
        // Find the 'lang/' part in the path and get everything after it
        if (preg_match('#/lang/(.+)$#', $vendorFile, $matches)) {
            return $matches[1];
        }

        return basename($vendorFile);
    }

    /**
     * Override to use custom globbing for nested directories.
     */
    public function handle(\Illuminate\Filesystem\Filesystem $fs): int
    {
        // Get all vendor lang files (PHP and JSON)
        $phpFiles    = glob(base_path('vendor/*/*/lang/**/*.php'), GLOB_BRACE) ?: [];
        $jsonFiles   = glob(base_path('vendor/*/*/lang/*.json'), GLOB_BRACE) ?: [];
        $vendorFiles = array_merge($phpFiles, $jsonFiles);

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
                $isUnchanged = false;

                // Try array comparison if applicable
                if ($this->shouldCompareAsArrays() && Str::endsWith($vendorFile, '.php')) {
                    try {
                        /** @var array $vendorArr */
                        $vendorArr = include $vendorFile;
                        /** @var array $targetArr */
                        $targetArr = include $target;

                        // Sort recursively to ignore ordering differences
                        $vendorArr = \Illuminate\Support\Arr::sortRecursive($vendorArr);
                        $targetArr = \Illuminate\Support\Arr::sortRecursive($targetArr);

                        if ($vendorArr === $targetArr) {
                            $isUnchanged = true;
                        }
                    } catch (\Throwable $e) {
                        // Fall through to regular diff calculation
                    }
                }

                if ($isUnchanged) {
                    $unchanged[] = $target;
                } else {
                    $diffPercentage = $this->calculateDiffPercentage($vStripped, $tStripped);
                    $modified[]     = ['path' => $target, 'diff' => $diffPercentage];
                }
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
