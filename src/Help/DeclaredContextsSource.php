<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Help;

use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Data\ArticleData;
use FinityLabs\LinCodex\Data\ContextData;
use FinityLabs\LinCodex\Data\SearchDocument;
use FinityLabs\LinCodex\Data\SourceWarning;
use FinityLabs\LinCodex\Data\TreeNode;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Enums\SourceWarningKind;
use FinityLabs\LinCodex\Sources\ArticleSet;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Folds code-declared help into lin-codex as ordinary contexts. Wrapped
 * around the core's own ContentSource, it appends the panel-scoped synthetic
 * contexts of DeclaredContexts to every declared article that exists,
 * records their string forms on the article's meta under META_KEY, and adds
 * one SourceWarning per declared slug that has no article. Everything else
 * is the inner source's answer: undeclared articles pass through untouched,
 * the tree and the search documents are delegated because contexts influence
 * neither (and rebuilding an ArticleSet for the tree would drop its folder
 * groups), and findByContext() runs the core's ArticleSet over the decorated
 * map so the exact-match rules stay the core's.
 *
 * The decorator never filters, reorders or looks at the viewer: the core's
 * gate decides who may read an article, and ContextIndex orders and
 * de-duplicates the merged contexts.
 *
 * The inner source's all() and warnings() are read once per request and
 * kept until the request changes or an article, translation or context row
 * is written (FinCodexServiceProvider forgets the memo from the model
 * events). The core's own sources memoise nothing, and a panel page asks the
 * source from five places — the drawer, the coverage badge (twice, through
 * RouteCoverage), the warnings badge and the declared-slug check — so without
 * this every page render would hydrate the whole knowledge base five times.
 * Keyed on the request instance rather than a flag because this is a
 * singleton: Octane flushes it between requests, Testbench does not.
 *
 * META_KEY exists for two later consumers. Phase 5's editor lists the
 * synthetic contexts read-only as "declared in code", and any exporter that
 * is fed a decorated article must leave the key and the marked contexts out
 * of front matter (FrontMatterWriter writes every meta key). The core's own
 * ArticleExporter injects DatabaseSource directly, so codex:export never
 * sees them today.
 */
final class DeclaredContextsSource implements ContentSource
{
    public const META_KEY = 'fin-codex-declared';

    private ?Request $memoRequest = null;

    /** @var array<string, ArticleData>|null */
    private ?array $innerAll = null;

    /** @var list<SourceWarning>|null */
    private ?array $innerWarnings = null;

    public function __construct(
        private readonly ContentSource $inner,
        private readonly DeclaredContexts $declared,
        private readonly Container $app,
    ) {}

    /**
     * Drop the memo: the next read goes back to the inner source. Called from
     * the model events and by tests that change the docs tree mid-request.
     */
    public function forget(): void
    {
        $this->innerAll = null;
        $this->innerWarnings = null;
    }

    public function inner(): ContentSource
    {
        return $this->inner;
    }

    /**
     * @return array<string, ArticleData> keyed by slug, sorted by slug
     */
    public function all(): array
    {
        $all = $this->innerAll();

        foreach ($this->declared->contextsBySlug() as $slug => $contexts) {
            if (isset($all[$slug])) {
                $all[$slug] = $this->withDeclared($all[$slug], $contexts);
            }
        }

        return $all;
    }

    public function findBySlug(string $slug): ?ArticleData
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * @return list<TreeNode>
     */
    public function tree(): array
    {
        return $this->inner->tree();
    }

    /**
     * @return list<ArticleData>
     */
    public function findByContext(ContextType $type, string $key, ?string $panelId = null): array
    {
        return (new ArticleSet($this->all()))->findByContext($type, $key, $panelId);
    }

    /**
     * @return list<SearchDocument>
     */
    public function allForSearch(): array
    {
        return $this->inner->allForSearch();
    }

    /**
     * The inner warnings, then one per (class, panel, slug) declaration whose
     * slug no source knows. InvalidSlug renders as ":path: :detail", which
     * gives "App\...\UserResource: declares unknown article users for panel
     * admin". Checked against the inner map on every call: an article created
     * since the last call silences its warning.
     *
     * @return list<SourceWarning>
     */
    public function warnings(): array
    {
        $all = $this->innerAll();
        $warnings = $this->innerWarnings();

        foreach ($this->declared->declarations() as $declaration) {
            if (isset($all[$declaration->slug])) {
                continue;
            }

            $warnings[] = new SourceWarning(
                SourceWarningKind::InvalidSlug,
                $declaration->class,
                $declaration->slug,
                null,
                sprintf('declares unknown article %s for panel %s', $declaration->slug, $declaration->panelId),
            );
        }

        return $warnings;
    }

    /**
     * @return array<string, ArticleData>
     */
    private function innerAll(): array
    {
        $this->rememberRequest();

        return $this->innerAll ??= $this->inner->all();
    }

    /**
     * @return list<SourceWarning>
     */
    private function innerWarnings(): array
    {
        $this->rememberRequest();

        return $this->innerWarnings ??= $this->inner->warnings();
    }

    private function rememberRequest(): void
    {
        /** @var Request $request */
        $request = $this->app->make('request');

        if ($this->memoRequest !== $request) {
            $this->memoRequest = $request;
            $this->forget();
        }
    }

    /**
     * ArticleData is readonly with no with…() methods, so the article is
     * rebuilt from its public fields as named arguments; only contexts and
     * meta change, and a field the core adds later is carried along.
     *
     * @param  list<ContextData>  $declared
     */
    private function withDeclared(ArticleData $article, array $declared): ArticleData
    {
        return new ArticleData(...[
            ...get_object_vars($article),
            'contexts' => [...$article->contexts, ...$declared],
            'meta' => $article->meta + [self::META_KEY => array_map(static fn (ContextData $context): string => $context->toString(), $declared)],
        ]);
    }
}
