<?php

use Filament\Pages\Dashboard;
use FinityLabs\FinCodex\Tests\Fixtures\User;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use Illuminate\Support\Facades\DB;

/*
 * What one panel page costs in knowledge-base reads. The drawer, the
 * coverage badge (through the core's RouteCoverage and its own read) and the
 * warnings badge all ask the content source on every page, and the core's
 * sources memoise nothing, so the decorated source keeps one reading per
 * request and drops it when an article, a translation or a context is
 * written.
 */

/** How many times the whole article table is loaded while $run runs. */
function finCodexArticleLoads(callable $run): int
{
    $loads = 0;

    DB::listen(function ($query) use (&$loads): void {
        if (str_starts_with($query->sql, 'select * from "codex_articles"')) {
            $loads++;
        }
    });

    $run();

    return $loads;
}

function finCodexCostArticle(string $slug, string $panel = 'admin'): Article
{
    return Article::factory()->public()->published()
        ->withTranslation('en', ['title' => ucfirst($slug), 'body' => "About {$slug}."])
        ->withContext(ContextType::PageClass, Dashboard::class, $panel)
        ->create(['slug' => $slug]);
}

it('hydrates the knowledge base at most twice for a plain panel page', function (): void {
    foreach (range(1, 20) as $i) {
        finCodexCostArticle("dash-{$i}");
    }

    $user = User::create(['name' => 'Tester', 'email' => 'cost@example.com']);

    // One reading of all() shared by the drawer, both badges and the declared
    // check; the second is the core's CompositeSource rebuilding its set for
    // warnings(), which it does not share with all().
    $loads = finCodexArticleLoads(fn () => $this->actingAs($user, 'web')->get('/admin')->assertOk());

    expect($loads)->toBeLessThanOrEqual(2);
});

it('reads the inner source once per request however many surfaces ask', function (): void {
    finCodexCostArticle('one');
    $source = app(ContentSource::class);

    $loads = finCodexArticleLoads(function () use ($source): void {
        $source->all();
        $source->all();
        $source->findBySlug('one');
        $source->findByContext(ContextType::PageClass, Dashboard::class, 'admin');
    });

    expect($loads)->toBe(1);
});

it('sees an article written or edited earlier in the same request', function (): void {
    finCodexCostArticle('one');
    $source = app(ContentSource::class);

    expect($source->findBySlug('late'))->toBeNull();

    finCodexCostArticle('late');

    expect($source->findBySlug('late'))->not->toBeNull();

    ArticleTranslation::query()->where('locale', 'en')->whereHas('article', fn ($q) => $q->where('slug', 'late'))->sole()
        ->fill(['title' => 'Renamed'])->save();

    expect($source->findBySlug('late')?->translation('en')?->title)->toBe('Renamed');

    Article::query()->where('slug', 'late')->sole()->delete();

    expect($source->findBySlug('late'))->toBeNull();
});
