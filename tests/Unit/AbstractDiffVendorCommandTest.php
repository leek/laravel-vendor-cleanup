<?php

namespace Leek\LaravelVendorCleanup\Tests\Unit;

use Leek\LaravelVendorCleanup\Commands\AbstractDiffVendorCommand;
use Leek\LaravelVendorCleanup\Tests\TestCase;

class AbstractDiffVendorCommandTest extends TestCase
{
    private function getTestCommand(): AbstractDiffVendorCommand
    {
        return new class extends AbstractDiffVendorCommand
        {
            protected $signature = 'vendor-cleanup:test';

            protected $description = 'Test command';

            protected function getVendorGlobPattern(): string
            {
                return '';
            }

            protected function getLocalPath(string $vendorFile): string
            {
                return '';
            }

            protected function getLocalFiles(): array
            {
                return [];
            }

            public function exposeNormalizePath(string $path): string
            {
                return $this->normalizePath($path);
            }

            public function exposeToRelativePath(string $absolutePath): string
            {
                return $this->toRelativePath($absolutePath);
            }

            public function exposeStripComments(string $content): string
            {
                return $this->stripComments($content);
            }

            public function exposeNormalizeWhitespace(string $content): string
            {
                return $this->normalizeWhitespace($content);
            }

            public function exposeCalculateDiffPercentage(string $vendor, string $local): float
            {
                return $this->calculateDiffPercentage($vendor, $local);
            }

            public function exposeGetDiffColor(float $percentage): string
            {
                return $this->getDiffColor($percentage);
            }
        };
    }

    public function test_normalize_path_converts_backslashes_to_forward_slashes(): void
    {
        $command = $this->getTestCommand();

        $windowsPath = 'C:\\Users\\test\\file.php';
        $expected = 'C:/Users/test/file.php';

        $this->assertEquals($expected, $command->exposeNormalizePath($windowsPath));
    }

    public function test_normalize_path_handles_mixed_separators(): void
    {
        $command = $this->getTestCommand();

        $mixedPath = 'C:\\Users/test\\file.php';
        $expected = 'C:/Users/test/file.php';

        $this->assertEquals($expected, $command->exposeNormalizePath($mixedPath));
    }

    public function test_to_relative_path_strips_base_path(): void
    {
        $command = $this->getTestCommand();

        $absolutePath = base_path('config/app.php');
        $expected = 'config/app.php';

        $this->assertEquals($expected, $command->exposeToRelativePath($absolutePath));
    }

    public function test_normalize_path_handles_windows_style_paths(): void
    {
        $command = $this->getTestCommand();

        $windowsAbsolutePath = 'C:\\Users\\test\\project\\config\\app.php';

        $normalizedPath = $command->exposeNormalizePath($windowsAbsolutePath);

        $this->assertStringNotContainsString('\\', $normalizedPath);
        $this->assertEquals('C:/Users/test/project/config/app.php', $normalizedPath);
    }

    public function test_strip_comments_removes_block_comments(): void
    {
        $command = $this->getTestCommand();

        $content = "<?php\n/* This is a comment */\necho 'test';";
        $stripped = $command->exposeStripComments($content);

        $this->assertStringNotContainsString('This is a comment', $stripped);
        $this->assertStringContainsString("echo 'test';", $stripped);
    }

    public function test_strip_comments_removes_line_comments(): void
    {
        $command = $this->getTestCommand();

        $content = "<?php\n// This is a comment\necho 'test';";
        $stripped = $command->exposeStripComments($content);

        $this->assertStringNotContainsString('This is a comment', $stripped);
        $this->assertStringContainsString("echo 'test';", $stripped);
    }

    public function test_strip_comments_removes_hash_comments(): void
    {
        $command = $this->getTestCommand();

        $content = "<?php\n# This is a comment\necho 'test';";
        $stripped = $command->exposeStripComments($content);

        $this->assertStringNotContainsString('This is a comment', $stripped);
        $this->assertStringContainsString("echo 'test';", $stripped);
    }

    public function test_strip_comments_preserves_urls_in_strings(): void
    {
        $command = $this->getTestCommand();

        $content = "<?php\n// Comment\n\$url = 'https://example.com/path';";
        $stripped = $command->exposeStripComments($content);

        $this->assertStringNotContainsString('// Comment', $stripped);
        $this->assertStringContainsString('https://example.com/path', $stripped);
    }

    public function test_strip_comments_preserves_hash_in_strings(): void
    {
        $command = $this->getTestCommand();

        $content = "<?php\n# Comment\n\$color = '#FF5733';";
        $stripped = $command->exposeStripComments($content);

        $this->assertStringNotContainsString('# Comment', $stripped);
        $this->assertStringContainsString('#FF5733', $stripped);
    }

    public function test_strip_comments_preserves_double_slash_in_strings(): void
    {
        $command = $this->getTestCommand();

        $content = "<?php\n// This is a comment\n\$path = 'path//to//file';";
        $stripped = $command->exposeStripComments($content);

        $this->assertStringNotContainsString('This is a comment', $stripped);
        $this->assertStringContainsString('path//to//file', $stripped);
    }

    public function test_normalize_whitespace_removes_php_tags(): void
    {
        $command = $this->getTestCommand();

        $content = "<?php\necho 'test';";
        $normalized = $command->exposeNormalizeWhitespace($content);

        $this->assertStringNotContainsString('<?php', $normalized);
    }

    public function test_normalize_whitespace_normalizes_line_endings(): void
    {
        $command = $this->getTestCommand();

        $content = "line1\r\nline2\rline3\nline4";
        $normalized = $command->exposeNormalizeWhitespace($content);

        // All line endings should be normalized to \n
        $this->assertStringNotContainsString("\r\n", $normalized);
        $this->assertStringNotContainsString("\r", str_replace("\n", '', $normalized));
    }

    public function test_normalize_whitespace_converts_tabs_to_spaces(): void
    {
        $command = $this->getTestCommand();

        $content = "line1\t\tline2";
        $normalized = $command->exposeNormalizeWhitespace($content);

        $this->assertStringNotContainsString("\t", $normalized);
    }

    public function test_calculate_diff_percentage_returns_zero_for_identical_strings(): void
    {
        $command = $this->getTestCommand();

        $content = 'test content';
        $diff = $command->exposeCalculateDiffPercentage($content, $content);

        $this->assertEquals(0.0, $diff);
    }

    public function test_calculate_diff_percentage_returns_zero_for_empty_strings(): void
    {
        $command = $this->getTestCommand();

        $diff = $command->exposeCalculateDiffPercentage('', '');

        $this->assertEquals(0.0, $diff);
    }

    public function test_calculate_diff_percentage_returns_percentage_for_different_strings(): void
    {
        $command = $this->getTestCommand();

        $vendor = 'Hello World';
        $local = 'Hello There';
        $diff = $command->exposeCalculateDiffPercentage($vendor, $local);

        $this->assertGreaterThan(0.0, $diff);
        $this->assertLessThan(100.0, $diff);
    }

    public function test_get_diff_color_returns_green_for_minor_changes(): void
    {
        $command = $this->getTestCommand();

        $color = $command->exposeGetDiffColor(3.0);

        $this->assertEquals('fg=green', $color);
    }

    public function test_get_diff_color_returns_yellow_for_small_changes(): void
    {
        $command = $this->getTestCommand();

        $color = $command->exposeGetDiffColor(10.0);

        $this->assertEquals('fg=yellow', $color);
    }

    public function test_get_diff_color_returns_magenta_for_moderate_changes(): void
    {
        $command = $this->getTestCommand();

        $color = $command->exposeGetDiffColor(20.0);

        $this->assertEquals('fg=magenta', $color);
    }

    public function test_get_diff_color_returns_red_for_significant_changes(): void
    {
        $command = $this->getTestCommand();

        $color = $command->exposeGetDiffColor(50.0);

        $this->assertEquals('fg=red', $color);
    }
}
