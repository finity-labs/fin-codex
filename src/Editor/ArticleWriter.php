<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use FinityLabs\LinCodex\Enums\ArticleFormat;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Enums\RevisionReason;
use FinityLabs\LinCodex\Enums\Visibility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\ArticleTranslation;
use FinityLabs\LinCodex\Revisions\RevisionManager;
use FinityLabs\LinCodex\Settings\CodexSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one write path of the editor. Every page and action that stores an
 * article goes through here, so there is exactly one place where the
 * article row, its translations and its contexts are written together: one
 * DB::transaction under RevisionManager::attributing(Manual, $userId), which
 * is what makes lin-codex's revision and search_text hooks record the panel
 * user and roll back with the rest when any part fails.
 *
 * Translations arrive as form state keyed by locale (the language tabs),
 * not as a relationship repeater: a tab is written when it has a title and
 * a body, an emptied non-default tab is deleted, and the default-locale tab
 * is required on every write. Contexts are a list in author order and are
 * replaced wholesale, sort_order being the position, mirroring the core's
 * ArticleImporter.
 *
 * The suite runs under Model::shouldBeStrict(), so split() pulls the
 * translations and contexts out of the data before Article::fill() (either
 * key would throw a MassAssignmentException) and only ATTRIBUTES reach
 * fill(): parent_id is derived from the slug by the core's saving hook,
 * meta and source_path belong to the file source, created_by and updated_by
 * come from the $userId argument. Nothing here decides who may read an
 * article; visibility is stored as given and lin-codex's gate reads it.
 */
final class ArticleWriter
{
    /**
     * Attributes form data may set. Everything else in $data is ignored:
     * parent_id (derived from the slug by the core), meta, source_path,
     * created_by and updated_by never come from a form.
     */
    public const ATTRIBUTES = ['slug', 'icon', 'sort_order', 'format', 'visibility', 'is_published', 'keywords', 'related'];

    public function __construct(
        private readonly RevisionManager $revisions,
        private readonly HtmlToMarkdown $converter,
    ) {}

    /**
     * @param  array<string, mixed>  $data  ATTRIBUTES keys (enum cases or their int backing values; Eloquent's enum cast accepts both) plus
     *                                      'translations' => array<string, array{title?: ?string, excerpt?: ?string, body?: ?string}> keyed by locale and
     *                                      'contexts' => list<array{panel_id?: ?string, type: string, key: string}> ('*' or null panel_id = any panel; type is a ContextType key: class|route|url)
     *
     * @throws InvalidArgumentException when the default-locale tab has no title or no body
     */
    public function create(array $data, ?int $userId): Article
    {
        [$attributes, $translations, $contexts] = $this->split($data);

        return DB::transaction(fn (): Article => $this->revisions->attributing(RevisionReason::Manual, $userId, function () use ($attributes, $translations, $contexts, $userId): Article {
            $article = new Article([...$attributes, 'created_by' => $userId, 'updated_by' => $userId]);
            $article->save();

            $this->writeTranslations($article, $translations);
            $this->replaceContexts($article, $contexts);

            return $article;
        }));
    }

    /**
     * @param  array<string, mixed>  $data  the create() shape
     *
     * @throws InvalidArgumentException when the default-locale tab has no title or no body
     */
    public function update(Article $article, array $data, ?int $userId): Article
    {
        [$attributes, $translations, $contexts] = $this->split($data);

        return DB::transaction(fn (): Article => $this->revisions->attributing(RevisionReason::Manual, $userId, function () use ($article, $attributes, $translations, $contexts, $userId): Article {
            $oldSlug = $article->slug;

            $article->fill([...$attributes, 'updated_by' => $userId])->save();

            $this->renameDescendants($article, $oldSlug);
            $this->writeTranslations($article, $translations);
            $this->replaceContexts($article, $contexts);

            return $article;
        }));
    }

    /**
     * Append one context to an existing article, attributed to the panel
     * user. This is what the coverage page's attach action writes through.
     *
     * update() cannot do it: split() pulls `translations` out with a `[]`
     * default and writeTranslations() then refuses the write because the
     * default locale has no title, and even with a synthesized payload it
     * would delete and recreate every context the article already has. So
     * this is a narrow append rather than a second write path — a context row
     * is still never created outside this class.
     *
     * Returns false without writing when the identical (type, key, panel) row
     * is already there. A wider "any panel" row or a route: context that
     * overlaps an existing class: one is legitimate and is appended silently:
     * no overlap heuristics, because a false warning trains admins to ignore
     * warnings.
     *
     * sort_order is the current maximum plus one, so an article with no
     * contexts yet gets its first at 1. Only the order matters — the core
     * reads the rows by it and the next full save renumbers them from 0.
     *
     * @param  array{panel_id?: string|null, type: string, key: string}  $context
     */
    public function appendContext(Article $article, array $context, ?int $userId): bool
    {
        $type = ContextType::fromKey((string) $context['type']);
        $key = (string) $context['key'];
        $panel = $this->panelId($context['panel_id'] ?? null);

        return DB::transaction(fn (): bool => $this->revisions->attributing(RevisionReason::Manual, $userId, function () use ($article, $type, $key, $panel, $userId): bool {
            $exists = $article->contexts()
                ->where('type', $type)
                ->where('key', $key)
                ->when(
                    $panel === null,
                    fn ($query) => $query->whereNull('panel_id'),
                    fn ($query) => $query->where('panel_id', $panel),
                )
                ->exists();

            if ($exists) {
                return false;
            }

            $article->contexts()->create([
                'panel_id' => $panel,
                'type' => $type,
                'key' => $key,
                'sort_order' => (int) $article->contexts()->max('sort_order') + 1,
            ]);

            // The one attribute assignment: strict models throw on a stray
            // key, and this is what makes the write show up as the admin's.
            $article->fill(['updated_by' => $userId])->save();

            return true;
        }));
    }

    /**
     * Convert an HTML article to Markdown in one transaction: the format is
     * saved first under attributing(), so the core's updating hook records
     * one revision per translation carrying the original Html format and
     * the HTML body, authored by $userId; the converted bodies are then
     * saved under withoutRevisions(), because the translation hook would
     * otherwise record each HTML body a second time, labelled Markdown.
     * Each body save re-indexes search_text through the translation hook
     * (the format save already ran one pass through reindexTranslations();
     * two passes are accepted). A Markdown article is returned untouched.
     */
    public function convertToMarkdown(Article $article, ?int $userId): Article
    {
        if ($article->format === ArticleFormat::Markdown) {
            return $article;
        }

        return DB::transaction(fn (): Article => $this->revisions->attributing(RevisionReason::Manual, $userId, function () use ($article, $userId): Article {
            $article->fill(['format' => ArticleFormat::Markdown, 'updated_by' => $userId])->save();

            $this->revisions->withoutRevisions(function () use ($article): void {
                foreach ($article->translations()->get() as $translation) {
                    $translation->body = $this->converter->convert($translation->body);
                    $translation->save();
                }
            });

            return $article;
        }));
    }

    /**
     * Hard delete, the locked decision: the core cascades the translations,
     * contexts and revisions with the row, orphans the direct children
     * (parent_id null) and keeps the media files with article_id null. A
     * section is a slug prefix, not an owner, so no descendant is deleted
     * with it and no slug is rewritten.
     *
     * $keepChildrenHidden is the EDIT-10 reconciliation. Deleting an
     * authenticated article would otherwise hand its published, public
     * descendants to guests, because the folder group the slug leaves
     * behind hides nothing; with the flag on, the descendants
     * DeleteSummary counts as exposed are set to Authenticated in the same
     * transaction, before the row goes. The 05-07 modal defaults the flag
     * on and shows the note either way.
     *
     * No attributing() scope: a visibility change records no revision (only
     * a format change does) and the delete cascades the revisions anyway.
     */
    public function delete(Article $article, bool $keepChildrenHidden, ?int $userId): void
    {
        DB::transaction(function () use ($article, $keepChildrenHidden, $userId): void {
            if ($keepChildrenHidden) {
                foreach (DeleteSummary::exposedDescendants($article) as $descendant) {
                    $descendant->fill(['visibility' => Visibility::Authenticated, 'updated_by' => $userId])->save();
                }
            }

            $article->delete();
        });
    }

    /**
     * Rewrite the slug prefix of every descendant after a section rename,
     * inside the caller's transaction. Runs after the parent save, so
     * Article::saved (relinkChildren) has already orphaned the direct
     * children through the query builder; each descendant is then re-read
     * fresh, which makes the parent_id its own saving hook assigns dirty
     * again (a row loaded before the parent save would keep the old value
     * in memory, assign the same value and write nothing). Top-down by slug
     * length so every child finds its renamed parent. The LIKE prefix is
     * safe because slugs only contain [a-z0-9/-]: users-guide does not
     * match users/%. A rename writes no revision (the slug is not
     * revisioned) and leaves search_text alone (the slug is not indexed).
     */
    private function renameDescendants(Article $article, string $oldSlug): void
    {
        if ($oldSlug === $article->slug) {
            return;
        }

        $slugs = Article::query()
            ->where('slug', 'like', $oldSlug.'/%')
            ->orderByRaw('length(slug)')
            ->orderBy('slug')
            ->pluck('slug')
            ->all();

        foreach ($slugs as $slug) {
            $row = Article::query()->where('slug', $slug)->firstOrFail();
            $row->slug = $article->slug.substr((string) $slug, strlen($oldSlug));
            $row->save();
        }
    }

    /**
     * Pull the nested form state out of the data before fill() sees it and
     * keep only the whitelisted attributes.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function split(array $data): array
    {
        $translations = Arr::pull($data, 'translations', []);
        $contexts = Arr::pull($data, 'contexts', []);

        return [Arr::only($data, self::ATTRIBUTES), $translations, array_values($contexts)];
    }

    /**
     * Write the language tabs. The default-locale tab is validated before
     * anything is touched; a complete tab is saved (a missing body key keeps
     * the stored body, which is how an HTML article's read-only body
     * arrives; a null excerpt clears the excerpt), an incomplete non-default
     * tab that exists is deleted, anything else is skipped. firstOrNew()
     * loads one row, so strict lazy-loading never trips, and the
     * translation hook's own $translation->article read is a single-model
     * lazy load, which strict mode allows.
     *
     * @param  array<string, array<string, mixed>>  $tabs
     *
     * @throws InvalidArgumentException when the default-locale tab has no title or no body
     */
    private function writeTranslations(Article $article, array $tabs): void
    {
        $default = app(CodexSettings::class)->default_locale;
        $defaultRow = $this->translationRow($article, $default);

        if (! $this->isComplete($tabs[$default] ?? [], $defaultRow)) {
            throw new InvalidArgumentException(sprintf('The %s translation needs a title and a body.', $default));
        }

        foreach ($tabs as $locale => $tab) {
            $locale = (string) $locale;
            $row = $locale === $default ? $defaultRow : $this->translationRow($article, $locale);

            if ($this->isComplete($tab, $row)) {
                $row->fill(array_intersect_key($tab, array_flip(['title', 'excerpt', 'body'])))->save();

                continue;
            }

            if ($row->exists && $locale !== $default) {
                $row->delete();
            }
        }
    }

    private function translationRow(Article $article, string $locale): ArticleTranslation
    {
        return ArticleTranslation::query()->firstOrNew(['article_id' => $article->id, 'locale' => $locale]);
    }

    /**
     * A tab is complete with a title and a body; a tab without a body key
     * counts as complete when the row already has one.
     *
     * @param  array<string, mixed>  $tab
     */
    private function isComplete(array $tab, ArticleTranslation $row): bool
    {
        if (! filled($tab['title'] ?? null)) {
            return false;
        }

        if (array_key_exists('body', $tab)) {
            return filled($tab['body']);
        }

        return $row->exists;
    }

    /**
     * Delete and recreate the contexts in array order, sort_order being the
     * position. Model deletes rather than a query on the relation, so a
     * future core hook on ArticleContext would run.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceContexts(Article $article, array $rows): void
    {
        foreach ($article->contexts()->get() as $context) {
            $context->delete();
        }

        foreach ($rows as $position => $row) {
            $article->contexts()->create([
                'panel_id' => $this->panelId($row['panel_id'] ?? null),
                'type' => ContextType::fromKey((string) $row['type']),
                'key' => (string) $row['key'],
                'sort_order' => $position,
            ]);
        }
    }

    /**
     * '*', '' and null all mean "any panel", stored as null.
     */
    private function panelId(mixed $panel): ?string
    {
        $panel = $panel === null ? '' : (string) $panel;

        return in_array($panel, ['', '*'], true) ? null : $panel;
    }
}
