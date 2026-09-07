<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Resources\ArticleResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use FinityLabs\FinCodex\Editor\ArticleWriter;
use FinityLabs\FinCodex\Editor\ContextPicker;
use FinityLabs\FinCodex\Editor\MediaRecorder;
use FinityLabs\FinCodex\Panel\Concerns\ResolvesPanelUser;
use FinityLabs\FinCodex\Resources\ArticleResource;
use FinityLabs\FinCodex\Resources\ArticleResource\Actions\PreviewAction;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\ContextsRepeater;
use FinityLabs\FinCodex\Resources\ArticleResource\Schemas\TranslationTabs;
use FinityLabs\LinCodex\Enums\ContextType;
use FinityLabs\LinCodex\Models\Article;
use Illuminate\Database\Eloquent\Model;

/**
 * Creating an article. The page validates and hands the form state to
 * ArticleWriter, which writes the row, its language tabs and its contexts in
 * one transaction attributed to the panel user; nothing is written here.
 *
 * After the create the admin lands on the edit page, where the media, the
 * revisions and the preview live.
 */
final class CreateArticle extends CreateRecord
{
    use ResolvesPanelUser;

    protected static string $resource = ArticleResource::class;

    /** The language tab the admin is looking at; Tabs::livewireProperty() writes it. */
    public ?string $activeLocale = null;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(ArticleWriter::class)->create($data, $this->userId());
    }

    /**
     * The contexts repeater keeps `key` and `url` apart while the admin is
     * picking; the writer wants one key per row, in drag order.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $rows = $data['contexts'] ?? [];
        $data['contexts'] = ContextsRepeater::dehydrate(is_array($rows) ? array_values($rows) : []);

        return $data;
    }

    /**
     * Images uploaded while the article did not exist yet have no article id
     * on their codex_media row; the record they belong to is only known now.
     * Rows no body mentions stay orphans, and nothing is deleted here.
     */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof Article) {
            app(MediaRecorder::class)->linkOrphans($record);
        }
    }

    protected function afterFill(): void
    {
        $this->activeLocale ??= TranslationTabs::languages()['default'];
    }

    /**
     * The coverage page's "Write article" arrives as a query string —
     * ?context_type=class&context_key=App\Filament\Resources\UserResource&panel=admin&title=Users
     * — because a URL is shareable, survives a refresh and the back button,
     * and is assertable in a test without reaching into session state.
     *
     * The defaults are filled FIRST and the prefill merged on top: Schema::fill()
     * only hydrates a component's default when it is handed null, so filling
     * straight from an array would silently drop `format`, `is_published` and
     * `visibility`. `contexts` is replaced outright rather than merged, because
     * a repeater's raw state is a keyed map and a merge would add a row beside
     * whatever is there instead of being the row.
     *
     * Both hooks are called by hand because this replaces CreateRecord's own
     * fillForm(), and afterFill() is what picks the language tab.
     */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill();

        $prefill = $this->prefillFromQuery();

        if ($prefill !== []) {
            $state = array_replace_recursive($this->form->getRawState(), ['translations' => $prefill['translations']]);
            $state['contexts'] = $prefill['contexts'];

            $this->form->fill($state);
        }

        $this->callHook('afterFill');
    }

    /**
     * The query string as form state, validated against the picker.
     *
     * An unknown key is dropped rather than seeded: a Filament Select carries
     * an implicit Rule::in() over its options, so a context key the picker
     * does not offer would open a form that refuses to save with a message
     * the admin cannot act on. A `url:` context is never prefilled — no
     * coverage row knows one.
     *
     * @return array{translations: array<string, array<string, string>>, contexts: list<array<string, string|null>>}|array{}
     */
    private function prefillFromQuery(): array
    {
        $request = request();
        $type = (string) $request->query('context_type', '');

        if (! in_array($type, [ContextType::PageClass->key(), ContextType::Route->key()], true)) {
            return [];
        }

        $picker = app(ContextPicker::class);
        $panel = (string) $request->query('panel', ContextPicker::ANY_PANEL);

        // An id nobody registered widens to "any panel" instead of failing:
        // the row is still worth writing an article for.
        if ($panel !== ContextPicker::ANY_PANEL && ! array_key_exists($panel, $picker->panels())) {
            $panel = ContextPicker::ANY_PANEL;
        }

        $scope = $panel === ContextPicker::ANY_PANEL ? null : $panel;
        $key = (string) $request->query('context_key', '');
        $title = mb_substr(trim((string) $request->query('title', '')), 0, 255);

        $offered = $type === ContextType::PageClass->key() ? $picker->classKeys($scope) : $picker->routeKeys($scope);

        if (! array_key_exists($key, $offered)) {
            Notification::make()
                ->warning()
                ->title(__('fin-codex::fin-codex.coverage.prefill.dropped'))
                ->body(__('fin-codex::fin-codex.coverage.prefill.dropped_body', ['page' => $title === '' ? $key : $title]))
                ->send();

            return [];
        }

        return [
            'translations' => $title === '' ? [] : [TranslationTabs::languages()['default'] => ['title' => $title]],
            'contexts' => [['panel_id' => $panel, 'type' => $type, 'key' => $key, 'url' => '']],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return self::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    /**
     * Preview only. Convert and delete need a record, so they live on the
     * edit page the create redirects to.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [PreviewAction::make()];
    }

    /** The panel user's id, or null for a panel without an authenticated user. Public: the header actions attribute their writes to it. */
    public function userId(): ?int
    {
        return $this->panelUserId();
    }
}
