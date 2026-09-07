<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Help;

use FinityLabs\FinCodex\Panel\CurrentPage;
use FinityLabs\LinCodex\Auth\ArticleGate;
use FinityLabs\LinCodex\Auth\Viewer;
use FinityLabs\LinCodex\Auth\ViewerResolver;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Locale\LocaleResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Answers "the article's title in the reader's language, or null" for a
 * field hint. Null means the hint is not rendered: the slug has no article,
 * the article or an existing ancestor is unpublished, or the viewer may not
 * read it on the guard in play.
 *
 * It exists beside lin-codex's ArticleReader::read() because read() renders
 * the body; a form with ten hints needs ten titles and ten verdicts, not
 * ten Markdown renders. The article set and the viewer are resolved once
 * per request instance and every verdict is kept per (locale, slug), so the
 * cost is one ContentSource::all() per request, as the scoped binding and
 * the CurrentPage memo intend.
 *
 * The guard is the current panel's (CurrentPage), and outside a panel it is
 * null so ViewerResolver applies its own fallback: lin-codex.auth.guard,
 * then the application default. Nothing here decides visibility beyond
 * calling the core's ArticleGate; the locale fallback is LocaleResolver's.
 */
final class ArticleLookup
{
    private ?Request $memoRequest = null;

    /** @var array<string, ArticleData>|null */
    private ?array $all = null;

    private ?Viewer $viewer = null;

    /** @var array<string, string|null> locale|slug => title */
    private array $titles = [];

    public function __construct(
        private readonly Application $app,
        private readonly ContentSource $source,
        private readonly ViewerResolver $viewers,
        private readonly ArticleGate $gate,
        private readonly LocaleResolver $locales,
        private readonly CurrentPage $currentPage,
    ) {}

    /**
     * The article's title in the reader's language, or null when it does
     * not exist or the viewer may not read it.
     */
    public function title(string $slug): ?string
    {
        $this->rememberRequest();
        $key = $this->locales->resolve(null).'|'.$slug;

        if (array_key_exists($key, $this->titles)) {
            return $this->titles[$key];
        }

        return $this->titles[$key] = $this->resolveTitle($slug);
    }

    private function resolveTitle(string $slug): ?string
    {
        $all = $this->all ??= $this->source->all();
        $article = $all[$slug] ?? null;

        if ($article === null) {
            return null;
        }

        $viewer = $this->viewer ??= $this->viewers->resolve($this->currentPage->identity()->guard);

        if (! $this->gate->allows($article, $viewer, $all)) {
            return null;
        }

        return $this->locales->pick($article, $this->locales->resolve(null))?->translation->title;
    }

    /**
     * Memoised per request instance rather than behind a flag: the scoped
     * instance survives the in-process requests a test issues (Octane
     * flushes it, Testbench does not), so a different request object drops
     * the article set, the viewer and every verdict. Same reasoning as
     * CurrentPage.
     */
    private function rememberRequest(): void
    {
        /** @var Request $request */
        $request = $this->app->make('request');

        if ($this->memoRequest === $request) {
            return;
        }

        $this->memoRequest = $request;
        $this->all = null;
        $this->viewer = null;
        $this->titles = [];
    }
}
