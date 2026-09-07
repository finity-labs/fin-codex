<?php

use FinityLabs\FinCodex\Coverage\SourceWarnings;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Data\SourceWarning;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Enums\SourceWarningKind;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Settings\CodexSettings;
use FinityLabs\LinCodex\Sources\FilesystemSource;
use Illuminate\Support\Str;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use Spatie\LaravelSettings\Models\SettingsProperty;

/*
 * WARN-01's read model. The fixture declarations of Plan 04-01 (admin
 * UserResource: users, user-roles; staff UserResource: staff-users, users;
 * EditUser: editing-users; Reports: reports, in both panels) produce a
 * standing set of InvalidSlug warnings for as long as those articles do not
 * exist, so the suite has a real warning generator without breaking anything.
 * Every count here is asserted against the source's own answer rather than
 * against a number, so a fixture change cannot silently make a row vacuous.
 */

/** Every slug the fixture panels declare in code. */
function finCodexWarningDeclaredSlugs(): array
{
    return ['users', 'user-roles', 'staff-users', 'editing-users', 'reports'];
}

function finCodexWarningArticle(string $slug): Article
{
    return Article::factory()->public()
        ->withTranslation('en', ['title' => Str::headline($slug), 'body' => 'About '.$slug.'.'])
        ->create(['slug' => $slug]);
}

/**
 * A docs tree with one article whose front matter carries a context string
 * that does not parse, so the file source contributes an InvalidContext
 * warning beside the declarations' InvalidSlug ones and grouping has two
 * kinds to group.
 */
function finCodexWarningDocs(): string
{
    $dir = sys_get_temp_dir().'/fin-codex-warning-docs';

    if (is_dir($dir)) {
        foreach ((array) glob($dir.'/en/*.md') as $file) {
            @unlink((string) $file);
        }
    }

    @mkdir($dir.'/en', 0777, true);

    file_put_contents($dir.'/en/guide.md', <<<'MD'
        ---
        visibility: public
        contexts:
          - "not a context at all"
        ---

        # Guide

        A guide.
        MD);

    config()->set('lin-codex.sources.filesystem.paths', [$dir]);

    app()->forgetInstance(FilesystemSource::class);

    forgetHelpMemo();

    return $dir;
}

/** A content source that cannot be read at all, the way a broken install reads. */
function finCodexWarningBrokenSource(): ContentSource
{
    return new class implements ContentSource
    {
        public function all(): array
        {
            throw MissingSettings::create(CodexSettings::class, ['default_locale'], 'loading');
        }

        public function findBySlug(string $slug): ?ArticleData
        {
            return null;
        }

        public function tree(): array
        {
            return [];
        }

        public function findByContext(ContextType $type, string $key, ?string $panelId = null): array
        {
            return [];
        }

        public function allForSearch(): array
        {
            return [];
        }

        public function warnings(): array
        {
            throw MissingSettings::create(CodexSettings::class, ['default_locale'], 'loading');
        }
    };
}

it('reports exactly what the content source reports', function (): void {
    $warnings = app(SourceWarnings::class);

    expect($warnings->count())->toBe(count(app(ContentSource::class)->warnings()))
        ->and($warnings->count())->toBeGreaterThan(0)
        ->and($warnings->all())->each->toBeInstanceOf(SourceWarning::class);
});

it('drops the warnings of a declared slug as soon as its article exists', function (): void {
    $before = app(SourceWarnings::class)->count();

    finCodexWarningArticle('users');
    finCodexWarningArticle('reports');

    forgetHelpMemo();

    $after = app(SourceWarnings::class);

    expect($after->count())->toBeLessThan($before)
        ->and($after->count())->toBe(count(app(ContentSource::class)->warnings()));
});

it('groups warnings under the core\'s own translated kind label, in case order', function (): void {
    finCodexWarningDocs();

    $warnings = app(SourceWarnings::class);
    $grouped = $warnings->grouped();

    // InvalidContext is case 5 and InvalidSlug case 8, so the file source's
    // unparseable context leads the declarations' unknown slugs.
    expect(array_column($grouped, 'key'))->toBe([
        SourceWarningKind::InvalidContext->key(),
        SourceWarningKind::InvalidSlug->key(),
    ])
        ->and($grouped[0]['label'])->toBe(SourceWarningKind::InvalidContext->label())
        ->and($grouped[1]['label'])->toBe(SourceWarningKind::InvalidSlug->label())
        ->and($grouped[0]['count'])->toBe(count($grouped[0]['lines']))
        ->and(array_sum(array_column($grouped, 'count')))->toBe($warnings->count())
        ->and($grouped[0]['lines'][0]['message'])->toBe($warnings->all()[0]->message())
        ->and($grouped[0]['lines'][0]['path'])->toContain('guide.md');
});

it('never ships a kind label of its own', function (): void {
    // Duplicating a core enum's labels is banned; SourceWarningKind::label()
    // already reads lin-codex's en, de and hu files.
    $found = [];

    foreach ((array) glob(dirname(__DIR__, 3).'/resources/lang/*/fin-codex.php') as $file) {
        if (str_contains((string) file_get_contents((string) $file), 'source_warning')) {
            $found[] = basename(dirname((string) $file));
        }
    }

    expect($found)->toBe([]);
});

it('follows the locale through the core\'s own translation', function (): void {
    finCodexWarningDocs();

    $english = app(SourceWarnings::class)->grouped()[0];

    app()->setLocale('de');
    forgetHelpMemo();

    $german = app(SourceWarnings::class)->grouped()[0];

    expect($german['label'])->toBe(SourceWarningKind::InvalidContext->label())
        ->and($german['label'])->not->toBe($english['label'])
        ->and($german['lines'][0]['message'])->not->toBe($english['lines'][0]['message'])
        ->and($german['lines'][0]['message'])->toContain('verworfen');
});

it('reads the source once per request and re-reads once the memo is dropped', function (): void {
    $warnings = app(SourceWarnings::class);
    $first = $warnings->all();

    expect(app(SourceWarnings::class))->toBe($warnings)
        ->and($warnings->all())->toBe($first);

    finCodexWarningArticle('users');

    expect($warnings->all())->toBe($first);

    forgetHelpMemo();

    expect(app(SourceWarnings::class)->count())->toBeLessThan(count($first));
});

it('reports nothing at all on a healthy installation', function (): void {
    foreach (finCodexWarningDeclaredSlugs() as $slug) {
        finCodexWarningArticle($slug);
    }

    forgetHelpMemo();

    $warnings = app(SourceWarnings::class);

    expect($warnings->count())->toBe(0)
        ->and($warnings->all())->toBe([])
        ->and($warnings->grouped())->toBe([]);
});

it('still reports when the settings group has never been seeded', function (): void {
    SettingsProperty::query()->where('group', 'lin-codex')->delete();
    app()->forgetInstance(CodexSettings::class);
    forgetHelpMemo();

    expect(app(SourceWarnings::class)->count())->toBe(count(app(ContentSource::class)->warnings()));
});

it('returns nothing instead of breaking every panel page when the source cannot be read', function (): void {
    forgetHelpMemo();
    app()->instance(ContentSource::class, finCodexWarningBrokenSource());

    $warnings = app(SourceWarnings::class);

    expect($warnings->all())->toBe([])
        ->and($warnings->count())->toBe(0)
        ->and($warnings->grouped())->toBe([]);
});

it('ships the phase\'s lang keys in every locale it supports', function (): void {
    expect(__('fin-codex::fin-codex.coverage.navigation'))->not->toBe('')
        ->and(__('fin-codex::fin-codex.coverage.navigation'))->not->toContain('fin-codex::')
        ->and(trans_choice('fin-codex::fin-codex.warnings.heading', 3, ['count' => 3]))->toContain('3')
        ->and(trans_choice('fin-codex::fin-codex.warnings.heading', 1, ['count' => 1]))->toContain('1');

    $english = [
        __('fin-codex::fin-codex.coverage.navigation'),
        trans_choice('fin-codex::fin-codex.warnings.heading', 3, ['count' => 3]),
        __('fin-codex::fin-codex.search.category'),
    ];

    app()->setLocale('de');

    $german = [
        __('fin-codex::fin-codex.coverage.navigation'),
        trans_choice('fin-codex::fin-codex.warnings.heading', 3, ['count' => 3]),
        __('fin-codex::fin-codex.search.category'),
    ];

    expect($german[0])->not->toBe($english[0])
        ->and($german[1])->not->toBe($english[1])
        ->and($german[1])->toContain('3')
        ->and($german[2])->not->toBe($english[2]);
});
