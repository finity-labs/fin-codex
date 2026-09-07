<?php

arch('no Filament 3 leftovers')
    ->expect('FinityLabs\FinCodex')
    ->not->toUse([
        'Filament\Forms\Form',
        'Filament\Resources\Form',
        'Filament\Tables\Actions',
    ]);

arch('strict types everywhere')
    ->expect('FinityLabs\FinCodex')
    ->toUseStrictTypes();

arch('render hooks are registered per panel, never app-wide')
    ->expect('FinityLabs\FinCodex')
    ->not->toUse('Filament\Support\Facades\FilamentView');
