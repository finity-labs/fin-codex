<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use Closure;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Sources\SlugPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The slug field's rules. A slug is the article's whole path
 * ("users/reset-password"), so the pattern is the core's own segment rule
 * (SlugPath::isValidSegment()) repeated as one anchored expression: nothing
 * new is invented here, and a slug the editor accepts is a slug the file
 * source and the importer accept too.
 *
 * The parent check goes through the composite ContentSource rather than an
 * Article query, because a file-only parent is legitimate: the importer
 * leaves parent_id null for a child whose parent is still a file, the tree
 * is derived from the slug, and the core's relinkChildren() re-attaches the
 * row when the parent is imported later.
 */
final class SlugRules
{
    /** Kebab-case segments joined by slashes, anchored: "users", "users/reset-password". */
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?:\/[a-z0-9]+(?:-[a-z0-9]+)*)*$/';

    /**
     * The regex message comes from the field's validationMessages(); the
     * closure names the missing parent itself.
     *
     * The parent rule is wrapped in a closure that returns it: Filament
     * evaluates every Closure it is handed as a rule *factory* with its own
     * dependency injection, so a bare validation closure would be called with
     * $attribute and blow up before Laravel ever sees it.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return [
            'regex:'.self::PATTERN,
            static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! self::parentExists($value)) {
                    $fail((string) __('fin-codex::fin-codex.editor.validation.parent_missing', [
                        'parent' => (string) SlugPath::parentOf($value),
                    ]));
                }
            },
            // On edit, a section rename rewrites every descendant's slug
            // (ArticleWriter::renameDescendants), and each of those has to be
            // free too: the field's own unique rule only checks the parent.
            static fn (?Model $record): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                if (! is_string($value) || ! $record instanceof Article || $record->slug === $value) {
                    return;
                }

                $conflicts = self::descendantConflicts($record->slug, $value);

                if ($conflicts !== []) {
                    $fail((string) __('fin-codex::fin-codex.editor.validation.descendant_conflict', [
                        'descendant' => $record->slug.substr($conflicts[0], strlen($value)),
                        'slug' => $conflicts[0],
                    ]));
                }
            },
        ];
    }

    /**
     * The slugs the descendants of $oldSlug would take under $newSlug that
     * are already taken by another article, in slug order.
     *
     * @return list<string>
     */
    public static function descendantConflicts(string $oldSlug, string $newSlug): array
    {
        $renamed = Article::query()
            ->where('slug', 'like', $oldSlug.'/%')
            ->pluck('slug')
            ->map(static fn (string $slug): string => $newSlug.substr($slug, strlen($oldSlug)))
            ->all();

        if ($renamed === []) {
            return [];
        }

        return Article::query()
            ->whereIn('slug', $renamed)
            ->orderBy('slug')
            ->pluck('slug')
            ->all();
    }

    /** True for a root slug, or when some source knows the parent path. */
    public static function parentExists(string $slug): bool
    {
        $parent = SlugPath::parentOf($slug);

        return $parent === null || app(ContentSource::class)->findBySlug($parent) !== null;
    }

    /**
     * The slug suggested from a title. Str::slug() drops slashes, so a
     * suggestion is always a root segment; the admin types any prefix.
     */
    public static function suggest(?string $title): string
    {
        return Str::slug((string) $title);
    }
}
