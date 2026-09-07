<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use Closure;
use FinityLabs\LinCodex\Contracts\ContentSource;
use FinityLabs\LinCodex\Sources\SlugPath;
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
        ];
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
