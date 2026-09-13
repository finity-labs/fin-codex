<?php

declare(strict_types=1);

use FinityLabs\FinCodex\Commands\InstallCommand;
use FinityLabs\FinCodex\Pages\HelpCenter;
use FinityLabs\FinCodex\Pages\HelpCoverage;
use FinityLabs\FinCodex\Pages\HelpSettings;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Data\ContextData;
use FinityLabs\LinCodex\Data\SourceWarning;
use FinityLabs\LinCodex\Enums\SourceWarningKind;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Rendering\ArticlePath;
use FinityLabs\LinCodex\Rendering\Markdown\MarkdownPipeline;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinCodex\Sources\FilesystemSource;

/*
 * The starter articles the installer imports are Markdown files under
 * resources/docs, one folder per language. Read through the core's own file
 * source, so a file the core would refuse is caught here, not on a host's
 * first install.
 */

const FIN_CODEX_PUBLIC_STARTERS = ['account', 'account/signing-in', 'account/creating-an-account', 'account/forgotten-password', 'account/verifying-your-email'];

function finCodexStarterSet(): array
{
    config()->set('lin-codex.sources.filesystem.paths', [InstallCommand::starterDocsPath()]);
    app()->forgetInstance(FilesystemSource::class);

    $set = app(FilesystemSource::class)->set();

    return [$set->articles, $set->warnings()];
}

it('ships twelve starter articles in en, de and hu that the core reads without a warning', function (): void {
    [$articles, $warnings] = finCodexStarterSet();

    // Every language file carries the shared keys, so the set reads the same
    // whichever language the host makes its default; the core notes the
    // copies it ignored in the non-default files, and nothing else.
    $unexpected = array_values(array_filter($warnings, static fn (SourceWarning $warning): bool => $warning->kind !== SourceWarningKind::SharedKeyIgnored));

    expect($unexpected)->toBe([])
        ->and(array_keys($articles))->toBe(InstallCommand::starterSlugs())
        ->toBe(['account', 'account/creating-an-account', 'account/forgotten-password', 'account/signing-in', 'account/verifying-your-email', 'account/your-profile', 'help', 'help/coverage', 'help/help-center', 'help/help-in-code', 'help/settings', 'help/writing-articles']);

    foreach ($articles as $slug => $article) {
        expect($article)->toBeInstanceOf(ArticleData::class)
            ->and($article->locales())->toEqualCanonicalizing(InstallCommand::STARTER_LOCALES, "{$slug} is missing a language")
            // The account section and its guest pages are public, everything else authenticated.
            ->and($article->visibility)->toBe(in_array($slug, FIN_CODEX_PUBLIC_STARTERS, true) ? Visibility::Public : Visibility::Authenticated);

        foreach (InstallCommand::STARTER_LOCALES as $locale) {
            $translation = $article->translation($locale);

            expect($translation?->title)->not->toBeEmpty("{$slug} has no {$locale} title")
                ->and(trim((string) $translation?->body))->not->toBeEmpty();
        }
    }
});

it('attaches each starter article to the page it describes', function (): void {
    [$articles] = finCodexStarterSet();

    $contexts = fn (string $slug): array => array_map(static fn (ContextData $context): string => $context->toString(), $articles[$slug]->contexts);

    expect($contexts('help'))->toBe(['class:Filament\Pages\Dashboard'])
        ->and($contexts('help/writing-articles'))->toBe(['class:'.ArticleResource::class])
        ->and($contexts('help/coverage'))->toBe(['class:'.HelpCoverage::class])
        // The Help Center documents itself: this context is what the drawer's
        // "This page" tab shows there and what closes its own coverage row.
        ->and($contexts('help/help-center'))->toBe(['class:'.HelpCenter::class])
        ->and($contexts('help/settings'))->toBe(['class:'.HelpSettings::class])
        ->and($contexts('help/help-in-code'))->toBe(['class:'.ArticleResource::class])
        ->and($contexts('account/signing-in'))->toBe(['class:Filament\Auth\Pages\Login'])
        ->and($contexts('account/creating-an-account'))->toBe(['class:Filament\Auth\Pages\Register'])
        ->and($contexts('account/forgotten-password'))->toBe(['class:Filament\Auth\Pages\PasswordReset\RequestPasswordReset', 'class:Filament\Auth\Pages\PasswordReset\ResetPassword'])
        ->and($contexts('account/verifying-your-email'))->toBe(['class:Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt'])
        ->and($contexts('account/your-profile'))->toBe(['class:Filament\Auth\Pages\EditProfile']);
});

/*
 * The one article-to-article link in the set. The core's resolver leaves a
 * relative path it cannot resolve exactly as written, so a typo in
 * "help-center.md" would ship as literal text and nothing else would notice:
 * this renders each locale's Getting help body and asks for the stamp.
 */
it('resolves the Getting help link to the help center article', function (string $locale): void {
    [$articles] = finCodexStarterSet();

    $article = $articles['help'];

    $html = (new MarkdownPipeline)->render(
        (string) $article->translation($locale)?->body,
        $locale,
        // help comes from help/index.md, so it renders under help/index and
        // "help-center.md" resolves against the folder, not the top level.
        ArticlePath::renderSlug($article->slug, $article->isSection),
    )->html;

    expect($html)->toContain('data-codex-article="help/help-center"')
        ->not->toContain('help-center.md');
})->with(InstallCommand::STARTER_LOCALES);

it('reads the same pages from the files whichever language is the default', function (): void {
    $settings = app(CodexSettings::class);
    $settings->languages = [CodexSettings::languageEntry('hu'), CodexSettings::languageEntry('en')];
    $settings->default_locale = 'hu';
    $settings->save();

    [$articles] = finCodexStarterSet();

    expect(array_map(static fn (ContextData $context): string => $context->toString(), $articles['help/settings']->contexts))->toBe(['class:'.HelpSettings::class])
        ->and($articles['help']->order)->toBe(1)
        ->and($articles['help']->translation('hu')?->title)->toBe('Segítség kérése');
});
