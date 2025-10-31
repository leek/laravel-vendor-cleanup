<?php

namespace Leek\LaravelVendorCleanup\Commands;

class ConfigDiffVendorCommand extends AbstractDiffVendorCommand
{
    protected $signature = 'vendor-cleanup:config
                            {--delete : Delete config files that are identical to their vendor version}
                            {--normalize : Also normalize whitespace and line endings (comments are always ignored)}';

    protected $description = 'Report which published config files differ from their vendor originals (and optionally delete unchanged ones).';

    protected function getVendorGlobPattern(): string
    {
        return base_path('vendor/*/*/config/*.php');
    }

    protected function getLocalPath(string $vendorFile): string
    {
        return config_path(basename($vendorFile));
    }

    protected function getLocalFiles(): array
    {
        return glob(config_path('*.php')) ?: [];
    }

    protected function shouldCompareAsArrays(): bool
    {
        return true;
    }

    protected function getFileTypeName(): string
    {
        return 'config file(s)';
    }
}
