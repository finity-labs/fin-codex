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
 * de-duplicates the merged contexts. all() is rebuilt on every call because
 * database articles change between requests; the rebuild touches only the
 * declared slugs, so it costs O(declarations), not O(articles).
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

    public function __construct(
        private readonly ContentSource $inner,
        private readonly DeclaredContexts $declared,
    ) {}

    public function inner(): ContentSource
    {
        return $this->inner;
    }

    /**
     * @return array<string, ArticleData> keyed by slug, sorted by slug
     */
    public function all(): array
    {
        $all = $this->inner->all();

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
        $all = $this->inner->all();
        $warnings = $this->inner->warnings();

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
