<?php

namespace Leek\LaravelVendorCleanup\Commands;

class ConfigDiffVendorCommand extends AbstractDiffVendorCommand
{
    protected $name = 'vendor-cleanup:config';

    protected $description = 'Report which published config files differ from their vendor originals (and optionally delete unchanged ones).';

    protected function getPublishRoot(): string
    {
        return config_path();
    }

    /**
     * Configs not registered for publishing, such as the framework's own
     * (copied by config:publish), are matched by basename.
     */
    protected function guessVendorFiles(): array
    {
        $files = [];
        foreach ($this->globExactCase(base_path('vendor/*/*/config/*.php')) as $vendorFile) {
            $files[$vendorFile] = config_path(basename($vendorFile));
        }

        return $files;
    }

    protected function getLocalFiles(): array
    {
        return glob(config_path('*.php')) ?: [];
    }

    protected function getFileTypeName(): string
    {
        return 'config file(s)';
    }
}
