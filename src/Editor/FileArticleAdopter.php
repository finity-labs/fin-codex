<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Editor;

use FinityLabs\FinCodex\Auth\ArticleAbility;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Sync\ArticleImporter;
use FinityLabs\LinCodex\Sync\ImportOptions;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

/**
 * Adopting one file article into the database: the service behind the
 * "Import and edit" action on the "From files" tab.
 *
 * It writes nothing itself. lin-codex's ArticleImporter is the only path
 * that creates the row, so every hook the core owns runs — parent_id derived
 * from the slug, search_text filled, source_path stored docs-relative — and
 * the whole article sits in the importer's own transaction under
 * attributing(RevisionReason::Import, $userId), which is where created_by,
 * updated_by and any revision get the panel user's id.
 *
 * force is never set. A slug that already has a row is reported as skipped
 * and this class hands that row back, so pressing the action twice opens the
 * article the first press created instead of overwriting the admin's edits
 * with the file. Re-importing from file on top of an existing article is a
 * separate, destructive operation (EXT-03), not this one.
 *
 * A child of a file-only parent is imported with parent_id null: "users" is
 * still a file when "users/roles" lands. The tree, the drawer and the gate
 * are slug-derived, so nothing is broken, and the column fills in on its own
 * when the parent is imported (the core's relinkChildren()). Documented, not
 * worked around.
 *
 * This is also where the `import` ability is enforced, rather than only on the
 * buttons that reach it. The two ->authorize() closures on the files tab and
 * the coverage row hide a button nobody may press, which is the UX; the guard
 * below is what makes the rule true — a third call site added later cannot
 * import around it, and since adoption is the only way a file-only article
 * becomes editable, `import` gates opening one for editing too. A user who
 * cannot import cannot cause an import by any route.
 */
final class FileArticleAdopter
{
    public function __construct(private readonly ArticleImporter $importer) {}

    /**
     * Import one file slug with the panel user, or find the row that already
     * exists.
     *
     * @throws AuthorizationException when the current user may not import.
     *                                The message is for a developer reading a log: both buttons
     *                                are already hidden, so nobody reaches this through the panel
     * @throws RuntimeException when the importer reports a failure for the
     *                          slug (the message is one "{locale}:{slug}: {reason}" line per
     *                          failure) or when no row exists afterwards, which is what an
     *                          unknown slug looks like: the importer skips it silently
     */
    public function adopt(string $slug, ?int $userId): Article
    {
        if (! ArticleAbility::allows('import')) {
            throw new AuthorizationException(sprintf(
                'Importing the file article "%s" requires the import ability on the article model.',
                $slug,
            ));
        }

        $report = $this->importer->import(new ImportOptions(only: [$slug], userId: $userId));

        if ($report->hasFailures()) {
            $failures = $report->failures();

            throw new RuntimeException(implode("\n", array_map(
                static fn (string $key, string $reason): string => $key.': '.$reason,
                array_keys($failures),
                array_values($failures),
            )));
        }

        // Re-read rather than trust the report: a row someone else imported
        // between the two statements is still the row to open.
        $article = Article::query()->where('slug', $slug)->first();

        if ($article === null) {
            throw new RuntimeException(sprintf('No file article with the slug "%s" was found.', $slug));
        }

        return $article;
    }
}
