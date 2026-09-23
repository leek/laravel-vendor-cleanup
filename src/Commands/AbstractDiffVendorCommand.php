<?php

namespace Leek\LaravelVendorCleanup\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Input\InputOption;

abstract class AbstractDiffVendorCommand extends Command
{
    /**
     * Get the local directory that published files of this type are written to.
     * Publish destinations outside this directory are ignored.
     */
    abstract protected function getPublishRoot(): string;

    /**
     * Get all local files to check for orphans.
     */
    abstract protected function getLocalFiles(): array;

    /**
     * Guess vendor files that are not registered via ServiceProvider::publishes().
     *
     * @return array<string, string> Vendor file => local file
     */
    protected function guessVendorFiles(): array
    {
        return [];
    }

    /**
     * Determine if a file is of the type this command compares.
     */
    protected function isComparableFile(string $path): bool
    {
        return str_ends_with($path, '.php');
    }

    /**
     * Determine if unchanged files may be deleted with --delete.
     */
    protected function canDelete(): bool
    {
        return true;
    }

    protected function getFileTypeName(): string
    {
        return 'files';
    }

    protected function getOptions(): array
    {
        $type = $this->getFileTypeName();

        return [
            ['delete', null, InputOption::VALUE_NONE, $this->canDelete()
                ? "Delete {$type} that are identical to their vendor version"
                : "Not supported: {$type} are never deleted automatically"],
            ['force', null, InputOption::VALUE_NONE, 'Delete without asking for confirmation (use with --delete)'],
            ['normalize', null, InputOption::VALUE_NONE, 'Also normalize whitespace and line endings (comments are always ignored)'],
            ['json', null, InputOption::VALUE_NONE, 'Output the report as JSON'],
            ['fail-on-unchanged', null, InputOption::VALUE_NONE, 'Exit with a non-zero status when unchanged files remain'],
        ];
    }

    /**
     * Get vendor files and the local path each one publishes to. Files registered
     * via ServiceProvider::publishes() are authoritative; guesses only fill gaps.
     *
     * @return array<string, string> Vendor file => local file
     */
    protected function getVendorFiles(): array
    {
        $files = [];
        foreach ($this->getPublishedFiles() as $vendorFile => $localFile) {
            $files[$this->canonicalPath($vendorFile)] = $localFile;
        }

        foreach ($this->guessVendorFiles() as $vendorFile => $localFile) {
            $files[$this->canonicalPath($vendorFile)] ??= $localFile;
        }

        return $files;
    }

    /**
     * Get vendor files registered for publishing into this command's publish root.
     *
     * @return array<string, string> Vendor file => local file
     */
    protected function getPublishedFiles(): array
    {
        $root = rtrim($this->normalizePath($this->getPublishRoot()), '/');
        $fs = app(Filesystem::class);
        $files = [];

        foreach (ServiceProvider::pathsToPublish() as $from => $to) {
            $to = rtrim($this->normalizePath($to), '/');

            if ($to !== $root && ! str_starts_with($to, $root.'/')) {
                continue;
            }

            if (is_dir($from)) {
                foreach ($fs->allFiles($from) as $file) {
                    if ($this->isComparableFile($file->getPathname())) {
                        $files[$file->getPathname()] = $to.'/'.$this->normalizePath($file->getRelativePathname());
                    }
                }
            } elseif (is_file($from) && $this->isComparableFile($from)) {
                $files[$from] = $to;
            }
        }

        return $files;
    }

    /**
     * Normalize path separators for cross-platform compatibility.
     */
    protected function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Resolve a path to a comparable key (symlinks and ".." resolved when it exists).
     */
    protected function canonicalPath(string $path): string
    {
        return $this->normalizePath(realpath($path) ?: $path);
    }

    public function handle(Filesystem $fs): int
    {
        $vendorFiles = $this->getVendorFiles();

        if (empty($vendorFiles) && ! $this->option('json')) {
            $this->info('No vendor '.$this->getFileTypeName().' found.');

            return self::SUCCESS;
        }

        [$unchanged, $modified, $missing, $orphaned] = $this->collectAndCategorizeFiles($vendorFiles, $fs);

        usort($modified, fn ($a, $b) => $b['diff'] <=> $a['diff']);

        if ($this->option('json')) {
            $deleted = $this->option('delete') && $this->option('force') && $this->canDelete()
                ? $this->deleteFiles($unchanged, $fs)
                : [];

            $this->outputJson($modified, $unchanged, $orphaned, $missing, $deleted);
        } else {
            $this->displayResults($modified, $unchanged, $orphaned, $missing);

            $deleted = $this->handleDeletions($unchanged, $fs);

            $this->line(PHP_EOL.'Done.');
        }

        if ($this->option('fail-on-unchanged') && array_diff($unchanged, $deleted)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Compare two files and return their comparison result.
     *
     * @return array{status: string, diff: float|null} Returns ['status' => 'unchanged'|'modified', 'diff' => float|null]
     */
    protected function compareFileContents(string $vendorFile, string $localFile, Filesystem $fs): array
    {
        $v = $fs->get($vendorFile);
        $t = $fs->get($localFile);

        // Always strip comments for comparison
        $vStripped = $this->stripComments($v);
        $tStripped = $this->stripComments($t);

        // Apply additional normalization if requested
        if ($this->option('normalize')) {
            $vStripped = $this->normalizeWhitespace($vStripped);
            $tStripped = $this->normalizeWhitespace($tStripped);
        }

        // Check if files are identical after stripping
        if (hash('sha256', $vStripped) === hash('sha256', $tStripped)) {
            return ['status' => 'unchanged', 'diff' => null];
        }

        // JSON key order carries no meaning, so compare decoded structures.
        // PHP files are never executed: evaluating them would resolve env()
        // calls and hide real customizations.
        if (str_ends_with($vendorFile, '.json') && str_ends_with($localFile, '.json')) {
            $vendorJson = json_decode($v, true);
            $localJson = json_decode($t, true);

            if (is_array($vendorJson) && is_array($localJson)
                && $this->sortKeysRecursive($vendorJson) === $this->sortKeysRecursive($localJson)) {
                return ['status' => 'unchanged', 'diff' => null];
            }
        }

        // Calculate diff percentage
        $diffPercentage = $this->calculateDiffPercentage($vStripped, $tStripped);

        return ['status' => 'modified', 'diff' => $diffPercentage];
    }

    /**
     * Sort associative keys recursively, leaving list order intact.
     */
    protected function sortKeysRecursive(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->sortKeysRecursive($value);
            }
        }

        if (! array_is_list($array)) {
            ksort($array);
        }

        return $array;
    }

    /**
     * Collect and categorize files into unchanged, modified, missing, and orphaned.
     *
     * @param  array<string, string>  $vendorFiles  Vendor file => local file
     */
    protected function collectAndCategorizeFiles(array $vendorFiles, Filesystem $fs): array
    {
        $unchanged = [];
        $modified = [];
        $missing = [];
        $targets = [];

        foreach ($vendorFiles as $vendorFile => $localFile) {
            $targets[$this->canonicalPath($localFile)] = true;

            if (! $fs->exists($localFile)) {
                $missing[] = $vendorFile; // Show vendor path so user knows where it comes from

                continue;
            }

            $this->recordComparison($unchanged, $modified, $localFile, $this->compareFileContents($vendorFile, $localFile, $fs));
        }

        // Local files that no vendor file publishes to
        $orphaned = array_values(array_filter(
            $this->getLocalFiles(),
            fn ($localFile) => ! isset($targets[$this->canonicalPath($localFile)])
        ));

        return [array_keys($unchanged), $this->formatModified($modified), $missing, $orphaned];
    }

    /**
     * Record a comparison result for a local file. A local file that matches any
     * vendor file counts as unchanged; otherwise its closest match wins.
     *
     * @param  array<string, true>  $unchanged
     * @param  array<string, float>  $modified
     */
    protected function recordComparison(array &$unchanged, array &$modified, string $localFile, array $result): void
    {
        if ($result['status'] === 'unchanged') {
            $unchanged[$localFile] = true;
            unset($modified[$localFile]);

            return;
        }

        if (! isset($unchanged[$localFile]) && (! isset($modified[$localFile]) || $result['diff'] < $modified[$localFile])) {
            $modified[$localFile] = $result['diff'];
        }
    }

    /**
     * @param  array<string, float>  $modified
     * @return array<int, array{path: string, diff: float}>
     */
    protected function formatModified(array $modified): array
    {
        $rows = [];
        foreach ($modified as $path => $diff) {
            $rows[] = ['path' => $path, 'diff' => $diff];
        }

        return $rows;
    }

    /**
     * Display results in formatted tables.
     */
    protected function displayResults(array $modified, array $unchanged, array $orphaned, array $missing): void
    {
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

    protected function outputJson(array $modified, array $unchanged, array $orphaned, array $missing, array $deleted): void
    {
        $relative = fn (array $paths) => array_map(fn ($p) => $this->toRelativePath($p), array_values($paths));

        $this->line((string) json_encode([
            'modified' => array_map(fn ($item) => [
                'path' => $this->toRelativePath($item['path']),
                'diff' => $item['diff'],
            ], $modified),
            'unchanged' => $relative($unchanged),
            'orphaned' => $relative($orphaned),
            'missing' => $relative($missing),
            'deleted' => $relative($deleted),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Handle optional file deletions.
     *
     * @return array<int, string> Deleted files
     */
    protected function handleDeletions(array $unchanged, Filesystem $fs): array
    {
        if (! $this->option('delete') || ! $unchanged) {
            return [];
        }

        if (! $this->canDelete()) {
            $this->newLine();
            $this->warn('Unchanged '.$this->getFileTypeName().' are never deleted automatically. Review and remove them by hand.');

            return [];
        }

        if (! $this->option('force') && ! $this->confirm('Delete '.count($unchanged).' unchanged '.$this->getFileTypeName().'?')) {
            if (! $this->input->isInteractive()) {
                $this->warn('Nothing deleted: pass --force to delete without a confirmation prompt.');
            }

            return [];
        }

        $deleted = $this->deleteFiles($unchanged, $fs);

        foreach ($deleted as $f) {
            $this->line('  ✖ deleted '.$this->formatPathForDisplay($f));
        }

        return $deleted;
    }

    /**
     * @return array<int, string> Deleted files
     */
    protected function deleteFiles(array $files, Filesystem $fs): array
    {
        return array_values(array_filter($files, fn ($f) => $fs->delete($f)));
    }

    /**
     * Strip all PHP comments from the content (always applied).
     * Uses token-based stripping to preserve string literals.
     */
    protected function stripComments(string $s): string
    {
        $tokens = token_get_all($s);
        $output = '';

        foreach ($tokens as $token) {
            // If token is an array, it's a structured token
            if (is_array($token)) {
                [$id, $text] = $token;

                // Skip comment tokens
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    continue;
                }

                $output .= $text;
            } else {
                // Single-character token (like punctuation)
                $output .= $token;
            }
        }

        return $output;
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
        // Collapse runs of spaces and tabs to a single space
        $s = preg_replace('/[ \t]+/', ' ', $s);
        // Trim each line
        $s = preg_replace('/ *\n */', "\n", $s);
        // Drop blank lines
        $s = preg_replace('/\n{2,}/', "\n", $s);

        return trim($s);
    }

    /**
     * Percentage of lines that differ, based on the longest common subsequence
     * of lines (the same measure line-based diff tools use).
     */
    protected function calculateDiffPercentage(string $vendor, string $local): float
    {
        if ($vendor === $local) {
            return 0.0;
        }

        $a = explode("\n", str_replace(["\r\n", "\r"], "\n", $vendor));
        $b = explode("\n", str_replace(["\r\n", "\r"], "\n", $local));

        $common = $this->longestCommonSubsequence($a, $b);

        return round(100 - (200 * $common / (count($a) + count($b))), 1);
    }

    /**
     * Length of the longest common subsequence of two line arrays.
     */
    protected function longestCommonSubsequence(array $a, array $b): int
    {
        // Common prefix and suffix need no dynamic programming
        $common = 0;
        while ($a && $b && $a[0] === $b[0]) {
            array_shift($a);
            array_shift($b);
            $common++;
        }
        while ($a && $b && end($a) === end($b)) {
            array_pop($a);
            array_pop($b);
            $common++;
        }

        if (! $a || ! $b) {
            return $common;
        }

        $previous = array_fill(0, count($b) + 1, 0);
        foreach ($a as $lineA) {
            $current = [0];
            foreach ($b as $j => $lineB) {
                $current[$j + 1] = $lineA === $lineB
                    ? $previous[$j] + 1
                    : max($previous[$j + 1], $current[$j]);
            }
            $previous = $current;
        }

        return $common + end($previous);
    }

    protected function getDiffColor(float $percentage): string
    {
        return match (true) {
            $percentage < 5 => 'fg=green',    // Very minor changes
            $percentage < 15 => 'fg=yellow',   // Small changes
            $percentage < 30 => 'fg=magenta',  // Moderate changes
            default => 'fg=red',      // Significant changes
        };
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

        if (str_starts_with($absolutePath, $basePath.'/')) {
            return substr($absolutePath, strlen($basePath) + 1);
        }

        return $absolutePath;
    }

    /**
     * Format a path for display, removing "vendor/" prefix if present.
     */
    protected function formatPathForDisplay(string $path): string
    {
        $relativePath = $this->toRelativePath($path);

        // Remove "vendor/" prefix if present since it's implied
        if (str_starts_with($relativePath, 'vendor/')) {
            return substr($relativePath, 7); // Remove "vendor/"
        }

        return $relativePath;
    }

    /**
     * Format files into 2-column table rows.
     */
    protected function formatTwoColumnTable(array $files): array
    {
        $files = array_map(fn ($f) => $this->formatPathForDisplay($f), $files);
        $chunks = array_chunk($files, 2);

        return array_map(function ($chunk) {
            return [
                $chunk[0] ?? '',
                $chunk[1] ?? '',
            ];
        }, $chunks);
    }
}
