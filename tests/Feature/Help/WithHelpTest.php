<?php

use FinityLabs\FinCodex\Help\HasHelp;
use FinityLabs\FinCodex\Help\WithHelp;

/*
 * The declaration surface of HELP-01 on its own: HasHelp answers slugs per
 * panel and WithHelp reads them from the using class's $helpArticles. Every
 * row builds the declaring class inline so the shapes stay next to what they
 * are expected to answer. No panel is booted here.
 */

it('applies a flat list to every panel', function (): void {
    $class = new class implements HasHelp
    {
        use WithHelp;

        /** @var list<string> */
        protected static array $helpArticles = ['users', 'roles'];
    };

    expect($class::getHelpArticles('admin'))->toBe(['users', 'roles'])
        ->and($class::getHelpArticles('staff'))->toBe(['users', 'roles']);
});

it('prefers the panel entry of a map and falls back to the * entry', function (): void {
    $class = new class implements HasHelp
    {
        use WithHelp;

        /** @var array<string, list<string>> */
        protected static array $helpArticles = ['*' => ['users'], 'staff' => ['staff-users', 'users']];
    };

    expect($class::getHelpArticles('staff'))->toBe(['staff-users', 'users'])
        ->and($class::getHelpArticles('admin'))->toBe(['users'])
        ->and($class::getHelpArticles('portal'))->toBe(['users']);
});

it('answers nothing for a panel missing from a map without a * entry', function (): void {
    $class = new class implements HasHelp
    {
        use WithHelp;

        /** @var array<string, list<string>> */
        protected static array $helpArticles = ['admin' => ['users']];
    };

    expect($class::getHelpArticles('admin'))->toBe(['users'])
        ->and($class::getHelpArticles('staff'))->toBe([]);
});

it('answers nothing for an empty panel entry or an empty list', function (): void {
    $emptyEntry = new class implements HasHelp
    {
        use WithHelp;

        /** @var array<string, list<string>> */
        protected static array $helpArticles = ['admin' => []];
    };

    $emptyList = new class implements HasHelp
    {
        use WithHelp;

        /** @var list<string> */
        protected static array $helpArticles = [];
    };

    expect($emptyEntry::getHelpArticles('admin'))->toBe([])
        ->and($emptyList::getHelpArticles('admin'))->toBe([]);
});

it('answers nothing when the using class declares no property', function (): void {
    $class = new class implements HasHelp
    {
        use WithHelp;
    };

    expect($class::getHelpArticles('admin'))->toBe([]);
});

it('is a HasHelp only when the class implements the interface, not by using the trait alone', function (): void {
    $implementing = new class implements HasHelp
    {
        use WithHelp;

        /** @var list<string> */
        protected static array $helpArticles = ['users'];
    };

    $traitOnly = new class
    {
        use WithHelp;

        /** @var list<string> */
        protected static array $helpArticles = ['users'];
    };

    expect(is_a($implementing::class, HasHelp::class, true))->toBeTrue()
        ->and(is_a($traitOnly::class, HasHelp::class, true))->toBeFalse()
        ->and($traitOnly::getHelpArticles('admin'))->toBe(['users']);
});
