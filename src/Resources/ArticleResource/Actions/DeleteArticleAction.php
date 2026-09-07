<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Actions;

use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Editor\DeleteSummary;
use FinityLabs\FinCodex\Resources\ArticleResource\Pages\EditArticle;
use FinityLabs\LinCodex\Models\Article;
use FinityLabs\LinCodex\Models\Media;
use FinityLabs\LinCodex\Sources\SlugPath;
use Illuminate\Contracts\View\View;

/**
 * Deleting an article, with its consequences spelled out first.
 *
 * The locked decision is a hard delete and an informative modal: the core
 * keeps every descendant (a section is a slug prefix, not an owner), orphans
 * the direct children and leaves the media files behind with no article. The
 * modal is where the admin finds that out — which articles lose this parent
 * and where each of them lands, and which files stop belonging to anything.
 *
 * One consequence is not merely informative. Deleting an *authenticated*
 * article leaves its published, public descendants under a folder group, and
 * a folder group hides nothing, so guests can suddenly read them. EDIT-10
 * says that must not happen by accident, and the note alone would not stop
 * it. The keep_hidden checkbox is the reconciliation: it appears only when
 * the situation applies, it is on by default, and ArticleWriter::delete()
 * flips exactly DeleteSummary::exposedDescendants() to Authenticated inside
 * the delete transaction. Unticking it is the admin saying they meant to
 * publish those children to the world.
 *
 * The delete itself never happens here: using() replaces DeleteAction's own
 * model delete with ArticleWriter's transaction, which stays the only place
 * an article row is removed.
 */
final class DeleteArticleAction
{
    public static function make(): DeleteAction
    {
        return DeleteAction::make()
            ->modalContent(static fn (Article $record): View => view('fin-codex::editor.delete-summary', self::summary($record)))
            ->schema(static fn (Article $record): array => DeleteSummary::for($record)->exposesPublicChildren
                ? [
                    Checkbox::make('keep_hidden')
                        ->label(__('fin-codex::fin-codex.editor.delete.keep_hidden'))
                        ->default(true),
                ]
                : [])
            ->using(static function (Article $record, array $data, EditArticle $livewire): bool {
                app(ArticleWriter::class)->delete($record, (bool) ($data['keep_hidden'] ?? true), $livewire->userId());

                return true;
            })
            ->successRedirectUrl(static fn (EditArticle $livewire): string => $livewire->getResourceUrl('index'));
    }

    /**
     * Everything the modal prints, resolved here rather than in the view:
     * the Blade file is loops and conditionals only, so the sentence a
     * descendant gets is decided where PHPStan can see it.
     *
     * @return array{isEmpty: bool, children: list<array{slug: string, where: string}>, media: list<array{id: int, label: string}>, exposes: bool}
     */
    private static function summary(Article $record): array
    {
        $summary = DeleteSummary::for($record);

        return [
            'isEmpty' => $summary->isEmpty(),
            'children' => array_map(static fn (array $descendant): array => [
                'slug' => $descendant['slug'],
                'where' => $descendant['direct']
                    ? (string) __('fin-codex::fin-codex.editor.delete.moves_to', ['group' => $record->slug])
                    : (string) __('fin-codex::fin-codex.editor.delete.stays', ['parent' => (string) SlugPath::parentOf($descendant['slug'])]),
            ], $summary->descendants),
            'media' => array_map(static fn (Media $media): array => [
                'id' => $media->id,
                'label' => $media->name.' ('.$media->path.')',
            ], $summary->media),
            'exposes' => $summary->exposesPublicChildren,
        ];
    }
}
