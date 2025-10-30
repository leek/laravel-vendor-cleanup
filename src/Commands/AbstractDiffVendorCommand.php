<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

abstract class AbstractDiffVendorCommand extends Command
{
    protected $signature = '';

    protected $description = '';

    /**
     * Get the glob pattern to find vendor files.
     */
    abstract protected function getVendorGlobPattern(): string;

    /**
     * Get the local path for a given vendor file.
     */
    abstract protected function getLocalPath(string $vendorFile): string;

    /**
     * Get all local files to check for orphans.
     */
    abstract protected function getLocalFiles(): array;

    /**
     * Get the vendor file basename for comparison.
     */
    protected function getVendorBasename(string $vendorFile): string
    {
        return basename($vendorFile);
    }

    /**
     * Get the local file basename for comparison.
     */
    protected function getLocalBasename(string $localFile): string
    {
        return basename($localFile);
    }

    /**
     * Determine if two files should be compared as PHP arrays.
     */
    protected function shouldCompareAsArrays(): bool
    {
        return false;
    }

    /**
     * Normalize file content before comparison.
     */
    protected function shouldNormalizeContent(): bool
    {
        return true;
    }

    /**
     * Get vendor files for comparison. Override this method in subclasses
     * for custom file discovery logic.
     */
    protected function getVendorFiles(): array
    {
        return glob($this->getVendorGlobPattern()) ?: [];
    }

    /**
     * Normalize path separators for cross-platform compatibility.
     */
    protected function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public function handle(Filesystem $fs): int
    {
        $vendorFiles = $this->getVendorFiles();
        if (empty($vendorFiles)) {
            $this->info('No vendor ' . $this->getFileTypeName() . ' found.');

            return self::SUCCESS;
        }

        [$unchanged, $modified, $missing, $orphaned] = $this->collectAndCategorizeFiles($vendorFiles, $fs);

        $this->displayResults($modified, $unchanged, $orphaned, $missing);

        $this->handleDeletions($unchanged, $fs);

        $this->line(PHP_EOL . 'Done.');

        return self::SUCCESS;
    }

    /**
     * Collect and categorize files into unchanged, modified, missing, and orphaned.
     */
    protected function collectAndCategorizeFiles(array $vendorFiles, Filesystem $fs): array
    {
        // Build a map of vendor file basenames for quick lookup
        $vendorBasenames = [];
        foreach ($vendorFiles as $vendorFile) {
            $basename = $this->getVendorBasename($vendorFile);
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
                if ($this->shouldCompareAsArrays()) {
                    try {
                        $vendorArr = include $vendorFile;
                        $targetArr = include $target;

                        // Verify both includes returned arrays
                        if (is_array($vendorArr) && is_array($targetArr)) {
                            // Sort recursively to ignore ordering differences
                            $vendorArr = \Illuminate\Support\Arr::sortRecursive($vendorArr);
                            $targetArr = \Illuminate\Support\Arr::sortRecursive($targetArr);

                            if ($vendorArr === $targetArr) {
                                $isUnchanged = true;
                            }
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

        return [$unchanged, $modified, $missing, $orphaned];
    }

    /**
     * Display results in formatted tables.
     */
    protected function displayResults(array $modified, array $unchanged, array $orphaned, array $missing): void
    {
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
    }

    /**
     * Handle optional file deletions.
     */
    protected function handleDeletions(array $unchanged, Filesystem $fs): void
    {
        if ($this->option('delete') && $unchanged) {
            if ($this->confirm('Delete ' . count($unchanged) . ' unchanged ' . $this->getFileTypeName() . '?')) {
                foreach ($unchanged as $f) {
                    $fs->delete($f);
                    $this->line('  ✖ deleted ' . $this->toRelativePath($f));
                }
            }
        }
    }

    /**
     * Strip all PHP comments from the content (always applied).
     */
    protected function stripComments(string $s): string
    {
        // Strip block comments /* ... */
        $s = preg_replace('!/\*.*?\*/!s', '', $s);
        // Strip line comments // ...
        $s = preg_replace('![ \t]*//.*$!m', '', $s);
        // Strip hash comments # ... (sometimes used in PHP)
        $s = preg_replace('![ \t]*#.*$!m', '', $s);

        return $s;
    }

    /**
     * Normalize whitespace and line endings (only applied with --normalize flag).
     */
    protected function normalizeWhitespace(string $s): string
    {
        // Strip php open tags
        $s = preg_replace('/^\s*<\?php\s*/', '', $s);
        // Normalize line endings
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        // Normalize tabs to spaces
        $s = preg_replace("/[ \t]+/m", ' ', $s);
        // Convert any 2+ spaces to single space
        $s = preg_replace('/[ ]{2,}/', ' ', $s);
        $s = trim($s);

        return $s;
    }

    protected function calculateDiffPercentage(string $vendor, string $local): float
    {
        if ($vendor === $local) {
            return 0.0;
        }

        if (strlen($vendor) === 0 && strlen($local) === 0) {
            return 0.0;
        }

        // Calculate similarity percentage using similar_text
        $similarity = 0;
        similar_text($vendor, $local, $similarity);

        // Return difference percentage (inverse of similarity)
        return round(100 - $similarity, 1);
    }

    protected function getDiffColor(float $percentage): string
    {
        return match (true) {
            $percentage < 5  => 'fg=green',    // Very minor changes
            $percentage < 15 => 'fg=yellow',   // Small changes
            $percentage < 30 => 'fg=magenta',  // Moderate changes
            default          => 'fg=red',      // Significant changes
        };
    }

    protected function getFileTypeName(): string
    {
        return 'files';
    }

    /**
     * Convert an absolute path to a relative path from the base directory.
     * Cross-platform compatible (Windows and Unix).
     */
    protected function toRelativePath(string $absolutePath): string
    {
        // Normalize both paths to use forward slashes
        $absolutePath = $this->normalizePath($absolutePath);
        $basePath = rtrim($this->normalizePath(base_path()), '/');

        if (str_starts_with($absolutePath, $basePath . '/')) {
            return substr($absolutePath, strlen($basePath) + 1);
        }

        return $absolutePath;
    }

    /**
     * Format files into 2-column table rows.
     */
    protected function formatTwoColumnTable(array $files): array
    {
        $files = array_map(fn ($f) => $this->toRelativePath($f), $files);
        $chunks = array_chunk($files, 2);

        return array_map(function ($chunk) {
            return [
                $chunk[0] ?? '',
                $chunk[1] ?? '',
            ];
        }, $chunks);
    }

    /**
     * Recursively find files matching a pattern.
     * PHP's glob() doesn't support ** for recursive traversal.
     */
    protected function recursiveGlob(string $pattern): array
    {
        $files = [];
        $flags = GLOB_MARK | GLOB_BRACE;

        // Get files matching the pattern
        $matchedFiles = glob($pattern, $flags) ?: [];
        $files = array_merge($files, array_filter($matchedFiles, 'is_file'));

        // Get all directories at this level
        $baseDir = dirname($pattern);
        $dirs = glob($baseDir . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [];

        // Recursively search subdirectories
        foreach ($dirs as $dir) {
            $subPattern = $dir . '/' . basename($pattern);
            $files = array_merge($files, $this->recursiveGlob($subPattern));
        }

        return $files;
    }
}
