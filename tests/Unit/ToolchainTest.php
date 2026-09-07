<?php

use Composer\InstalledVersions;

it('runs Livewire 3 with Filament 4 and Livewire 4 with Filament 5', function (): void {
    $major = static fn (string $package): int => (int) explode('.', ltrim((string) InstalledVersions::getPrettyVersion($package), 'v'))[0];

    $filament = $major('filament/filament');

    expect($filament)->toBeIn([4, 5])
        ->and($major('livewire/livewire'))->toBe($filament === 4 ? 3 : 4);
});
