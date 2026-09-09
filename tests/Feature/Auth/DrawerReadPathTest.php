<?php

use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Search\HelpSearchProvider;
use FinityLabs\FinCodex\Tests\Fixtures\Policies\DenyAllArticlePolicy;
use FinityLabs\FinCodex\Tests\Fixtures\Search\HostGlobalSearchProvider;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Livewire\HelpDrawer;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * AUTH-03, in one sentence: editor permissions decide who may WRITE help,
 * ArticleGate alone decides who may READ it, and the two must never be wired
 * together.
 *
 * A support agent with no rights over the article resource still opens the
 * drawer and reads every article their visibility allows. So a deny-everything
 * ArticlePolicy — the strictest thing a host can register — must change the
 * drawer, the help button's badge and the global-search Help category by
 * exactly nothing.
 *
 * The rows below compare two rendered lists rather than one list against a
 * hard-coded expectation, because a test that asserted "the drawer shows
 * users" would keep passing if it showed nothing on both sides. The bare run
 * goes first and its own list is asserted non-empty.
 */

/** A fixture user for the admin panel's web guard. */
function finCodexReadUser(string $email = 'reader@example.com'): User
{
    return User::create(['name' => 'Reader', 'email' => $email]);
}

/**
 * Three public articles on the admin dashboard, plus one members-only article
 * on the same screen. Seeded before the first request, so no memo needs
 * dropping for the first read.
 */
function finCodexReadSeed(): void
{
    foreach (['getting-started' => 'Getting started', 'shortcuts' => 'Shortcuts', 'widgets' => 'Widgets'] as $slug => $title) {
        Article::factory()->public()->published()
            ->withTranslation('en', ['title' => $title, 'body' => 'About '.$slug.'.'])
            ->withContext(ContextType::PageClass, Dashboard::class, 'admin')
            ->create(['slug' => $slug]);
    }

    Article::factory()->authenticated()->published()
        ->withTranslation('en', ['title' => 'Internal notes', 'body' => 'The internal body.'])
        ->withContext(ContextType::PageClass, Dashboard::class, 'admin')
        ->create(['slug' => 'internal-notes']);
}

/**
 * Every policy the container knows, dropped — including the one the service
 * provider registered at boot.
 *
 * Laravel's Gate has no public way to unmap a model, and "no policy at all" is
 * the only honest control for "a deny-everything policy changes nothing": it
 * is the state fin-codex shipped in before Phase 8.
 */
function finCodexReadForgetPolicies(): void
{
    $gate = Gate::getFacadeRoot();

    (new ReflectionProperty($gate, 'policies'))->setValue($gate, []);
}

/**
 * The slugs the drawer captured for the current page, in markup order.
 *
 * @return list<string>
 */
function finCodexReadSlugs(string $html): array
{
    preg_match_all('/data-codex-page-article="([^"]+)"/', $html, $matches);

    return $matches[1];
}

/** The page-article count the drawer wrapper reports. */
function finCodexReadCount(string $html): ?string
{
    preg_match('/data-codex-page-count="(\d+)"/', $html, $matches);

    return $matches[1] ?? null;
}

/** The number on the help button's badge, or null when it carries none. */
function finCodexReadBadge(string $html): ?string
{
    $badge = finCodexButtonBadge($html, 'admin');
    $matches = $badge === null ? [] : [null, (string) $badge];

    return $matches[1] ?? null;
}

/** The drawer, mounted for one page of the admin panel. */
function finCodexReadDrawer(): Testable
{
    return Livewire::test(HelpDrawer::class, [
        'pageClass' => Dashboard::class,
        'panelId' => 'admin',
        'guard' => 'web',
    ]);
}

/*
 * The control. Without it every row below could be green because the policy
 * was never in force at all.
 */
it('really does have the deny-everything policy in force', function (): void {
    $user = finCodexReadUser();
    $this->usesPanel('admin', $user);

    expect(ArticleResource::canViewAny())->toBeTrue();

    Gate::policy(Article::class, DenyAllArticlePolicy::class);

    expect(ArticleResource::canViewAny())->toBeFalse()
        ->and(Gate::allows('view', Article::factory()->create()))->toBeFalse();
});

it('renders the same drawer and the same badge with a deny-everything policy as with none at all', function (): void {
    finCodexReadSeed();
    $user = finCodexReadUser();

    // No policy anywhere — the state this package shipped in before Phase 8.
    finCodexReadForgetPolicies();
    forgetHelpMemo();

    expect(Gate::getPolicyFor(Article::class))->toBeNull();

    $bare = $this->actingAs($user, 'web')->get('/admin')->assertOk()->getContent();

    Gate::policy(Article::class, DenyAllArticlePolicy::class);
    forgetHelpMemo();

    expect(Gate::allows('viewAny', Article::class))->toBeFalse();

    $denied = $this->actingAs($user, 'web')->get('/admin')->assertOk()->getContent();

    // The bare list is asserted non-empty first, so "identical" cannot mean
    // "empty on both sides".
    expect(finCodexReadSlugs($bare))->not->toBeEmpty()
        ->and(finCodexReadCount($bare))->not->toBeNull()
        ->and(finCodexReadBadge($bare))->not->toBeNull()
        ->and(finCodexReadSlugs($denied))->toBe(finCodexReadSlugs($bare))
        ->and(finCodexReadCount($denied))->toBe(finCodexReadCount($bare))
        ->and(finCodexReadBadge($denied))->toBe(finCodexReadBadge($bare));
});

/*
 * The gate still answers, in both directions, while the policy says no to
 * everything. A members-only article is hidden from a guest and shown to a
 * signed-in member — the policy has no vote either way.
 */
it('keeps ArticleGate in charge of a members-only article under the deny-everything policy', function (): void {
    finCodexReadSeed();
    Gate::policy(Article::class, DenyAllArticlePolicy::class);

    finCodexReadDrawer()
        ->assertSeeHtml('data-codex-page-article="getting-started"')
        ->assertDontSeeHtml('data-codex-page-article="internal-notes"')
        ->call('open', 'internal-notes')
        ->assertSee(__('lin-codex::lin-codex.ui.not_found'))
        ->assertDontSee('The internal body.');

    $this->actingAs(finCodexReadUser('member@example.com'), 'web');
    forgetHelpMemo();

    finCodexReadDrawer()
        ->assertSeeHtml('data-codex-page-article="internal-notes"')
        ->call('open', 'internal-notes')
        ->assertDontSee(__('lin-codex::lin-codex.ui.not_found'))
        ->assertSee('The internal body.');
});

it('returns the same global-search help hits with a deny-everything policy as with none at all', function (): void {
    finCodexReadSeed();
    $user = finCodexReadUser();

    // The staff panel is the one whose plugin has globalSearch() on.
    $this->usesPanel('staff', $user);

    $category = (string) __('fin-codex::fin-codex.search.category');

    $titles = static function () use ($category): array {
        $results = (new HelpSearchProvider(new HostGlobalSearchProvider))->getResults('start');
        $hits = $results?->getCategories()->get($category) ?? [];

        return array_map(
            static fn ($result): string => (string) $result->title,
            is_array($hits) ? $hits : $hits->all(),
        );
    };

    finCodexReadForgetPolicies();
    forgetHelpMemo();

    $bare = $titles();

    Gate::policy(Article::class, DenyAllArticlePolicy::class);
    forgetHelpMemo();

    expect(Gate::allows('viewAny', Article::class))->toBeFalse()
        ->and($bare)->not->toBeEmpty()
        ->and($titles())->toBe($bare);
});

/*
 * The structural half. The three read-path directories must stay free of both
 * gate call forms, so the rows above cannot start passing for the wrong reason
 * one day. Matching is on the CODE forms: HelpMount's docblock names
 * ArticleGate in prose, which is a description of the delegation rather than a
 * check.
 */
it('has no policy check anywhere on the read path', function (string $directory): void {
    $root = dirname(__DIR__, 3).'/src/'.$directory;
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    expect($files)->not->toBeEmpty();

    // Collected rather than asserted one by one, so a failure names every
    // offender at once instead of stopping at the first.
    $offenders = [];

    foreach ($files as $path => $contents) {
        foreach (['Gate::', '->authorize(', 'ArticleAbility'] as $token) {
            if (str_contains($contents, $token)) {
                $offenders[] = basename($path).' contains '.$token;
            }
        }
    }

    expect($offenders)->toBe([]);
})->with(['Panel', 'Help', 'Search']);
