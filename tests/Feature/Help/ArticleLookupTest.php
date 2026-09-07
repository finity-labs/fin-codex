<?php

use FinityLabs\FinCodex\Help\ArticleLookup;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Http\Request;

/*
 * The request-scoped lookup behind every field hint: "the article's title
 * in the reader's language, or null". Every verdict here is the core's
 * (ArticleGate for visibility, LocaleResolver for the title, ViewerResolver
 * for the guard); the rows prove the lookup hands the right guard over (the
 * current panel's inside a panel, the lin-codex config guard and then the
 * app default outside one) and resolves the article set once per request.
 */

function finCodexLookup(): ArticleLookup
{
    return app(ArticleLookup::class);
}

function finCodexLookupUser(string $email): User
{
    return User::create(['name' => 'Member', 'email' => $email]);
}

/**
 * @param  list<string>  $codes
 */
function finCodexLookupUseLanguages(array $codes): void
{
    $settings = app(CodexSettings::class);
    $settings->languages = array_map([CodexSettings::class, 'languageEntry'], $codes);
    $settings->default_locale = 'en';
    $settings->save();
}

it('answers the title in the reader\'s language through the core fallback', function (): void {
    Article::factory()->public()
        ->withTranslation('en', ['title' => 'Users', 'body' => 'How users work.'])
        ->withTranslation('de', ['title' => 'Benutzer', 'body' => 'So funktionieren Benutzer.'])
        ->create(['slug' => 'users']);
    $this->usesPanel('admin', finCodexLookupUser('web@example.com'));
    forgetHelpMemo();

    expect(finCodexLookup()->title('users'))->toBe('Users');

    finCodexLookupUseLanguages(['en', 'de']);
    app()->setLocale('de');
    forgetHelpMemo();

    expect(finCodexLookup()->title('users'))->toBe('Benutzer');

    app()->setLocale('hu');
    forgetHelpMemo();

    expect(finCodexLookup()->title('users'))->toBe('Users');
});

it('answers null for a slug without an article or an unpublished one', function (): void {
    $this->usesPanel('admin', finCodexLookupUser('web@example.com'));
    forgetHelpMemo();

    expect(finCodexLookup()->title('nope'))->toBeNull();

    Article::factory()->unpublished()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    forgetHelpMemo();

    expect(finCodexLookup()->title('users'))->toBeNull();
});

it('answers null when an existing ancestor is unpublished', function (): void {
    Article::factory()->unpublished()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    Article::factory()->public()->withTranslation('en', ['title' => 'Roles'])->create(['slug' => 'users/roles']);
    $this->usesPanel('admin', finCodexLookupUser('web@example.com'));
    forgetHelpMemo();

    expect(finCodexLookup()->title('users/roles'))->toBeNull();
});

it('answers the title when the existing ancestor is readable', function (): void {
    Article::factory()->public()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    Article::factory()->public()->withTranslation('en', ['title' => 'Roles'])->create(['slug' => 'users/roles']);
    $this->usesPanel('admin', finCodexLookupUser('web@example.com'));
    forgetHelpMemo();

    expect(finCodexLookup()->title('users/roles'))->toBe('Roles');
});

it('uses the current panel guard', function (string $panel, ?string $signInGuard, ?string $expected): void {
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    $user = finCodexLookupUser('member@example.com');

    $this->usesPanel($panel);

    if ($signInGuard !== null) {
        $this->actingAs($user, $signInGuard);
    }

    forgetHelpMemo();

    expect(finCodexLookup()->title('users'))->toBe($expected);
})->with([
    'admin, guest' => ['admin', null, null],
    'admin, signed in on web (the panel guard)' => ['admin', 'web', 'Users'],
    'staff, signed in on web (the other guard)' => ['staff', 'web', null],
    'staff, signed in on staff (the panel guard)' => ['staff', 'staff', 'Users'],
]);

it('falls back to the lin-codex config guard and then the default guard outside a panel', function (): void {
    Article::factory()->authenticated()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    $user = finCodexLookupUser('member@example.com');
    forgetHelpMemo();

    expect(finCodexLookup()->title('users'))->toBeNull();

    $this->actingAs($user, 'web');
    forgetHelpMemo();

    expect(finCodexLookup()->title('users'))->toBe('Users');

    config(['lin-codex.auth.guard' => 'staff']);
    forgetHelpMemo();

    // Resolving the staff viewer must not shouldUse() it: the app default
    // stays web (actingAs() itself would move it, so this is asserted first).
    expect(finCodexLookup()->title('users'))->toBeNull()
        ->and(config('auth.defaults.guard'))->toBe('web');

    $this->actingAs($user, 'staff');
    forgetHelpMemo();

    expect(finCodexLookup()->title('users'))->toBe('Users');
});

it('resolves the article set once per request and again for a new request', function (): void {
    Article::factory()->public()->withTranslation('en', ['title' => 'Users'])->create(['slug' => 'users']);
    $this->usesPanel('admin', finCodexLookupUser('web@example.com'));

    app()->forgetInstance(ContentSource::class);
    app()->extend(ContentSource::class, fn (ContentSource $inner): ContentSource => new class($inner) implements ContentSource
    {
        public static int $allCalls = 0;

        public function __construct(private readonly ContentSource $inner) {}

        public function all(): array
        {
            self::$allCalls++;

            return $this->inner->all();
        }

        public function findBySlug(string $slug): ?ArticleData
        {
            return $this->inner->findBySlug($slug);
        }

        public function tree(): array
        {
            return $this->inner->tree();
        }

        public function findByContext(ContextType $type, string $key, ?string $panelId = null): array
        {
            return $this->inner->findByContext($type, $key, $panelId);
        }

        public function allForSearch(): array
        {
            return $this->inner->allForSearch();
        }

        public function warnings(): array
        {
            return $this->inner->warnings();
        }
    });
    forgetHelpMemo();

    $counter = app(ContentSource::class);
    $lookup = finCodexLookup();

    expect($lookup->title('users'))->toBe('Users')
        ->and($lookup->title('other'))->toBeNull()
        ->and($counter::$allCalls)->toBe(1);

    app()->instance('request', Request::create('/next'));

    expect($lookup->title('users'))->toBe('Users')
        ->and($counter::$allCalls)->toBe(2);
});
