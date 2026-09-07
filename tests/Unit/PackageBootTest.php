<?php

use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\FinCodex\FinCodexServiceProvider;
use FinityLabs\LinCodex\LinCodexServiceProvider;

it('registers both service providers', function () {
    expect(app()->getProvider(LinCodexServiceProvider::class))->not->toBeNull()
        ->and(app()->getProvider(FinCodexServiceProvider::class))->not->toBeNull();
});

it('exposes the plugin under the fin-codex id', function () {
    expect(FinCodexPlugin::make()->getId())->toBe('fin-codex');
});

it('reads table names from the core package config', function () {
    expect(config('lin-codex.table_names.articles'))->toBe('codex_articles');
});
