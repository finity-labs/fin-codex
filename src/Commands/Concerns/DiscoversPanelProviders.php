<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Commands\Concerns;

use Illuminate\Support\Str;

trait DiscoversPanelProviders
{
    /**
     * Scan app/Providers/Filament/ for panel provider files.
     *
     * @return array<string, string> Panel ID => file path
     */
    protected function discoverPanelProviders(): array
    {
        $directory = app_path('Providers/Filament');

        if (! is_dir($directory)) {
            return [];
        }

        $providers = [];
        $files = glob($directory.'/*PanelProvider.php');

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            // AdminPanelProvider.php → admin
            $filename = basename($file, '.php');
            $panelId = (string) Str::of($filename)
                ->before('PanelProvider')
                ->snake()
                ->replace('_', '-');

            if ($panelId !== '') {
                $providers[$panelId] = $file;
            }
        }

        return $providers;
    }
}
