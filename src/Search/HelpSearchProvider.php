<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Search;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Filament\GlobalSearch\Providers\Contracts\GlobalSearchProvider;
use FinityLabs\FinCodex\FinCodexPlugin;
use FinityLabs\LinCodex\Auth\ViewerResolver;
use FinityLabs\LinCodex\Rendering\ArticlePath;
use FinityLabs\LinCodex\Search\Searcher;
use FinityLabs\LinCodex\Search\SearchHit;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

/**
 * The panel's own global search provider with a Help category appended.
 *
 * It wraps rather than replaces: whatever the panel had — Filament's default
 * or a host's own class — keeps producing its categories in its own order,
 * and help is added at the end. An admin is usually hunting for their own
 * data, and appending never reorders what a host arranged with
 * getGlobalSearchSort().
 *
 * The wrapper does NOT try to make the search field appear. Filament only
 * renders the field when some RESOURCE answers canGloballySearch(), so a
 * panel with nothing searchable stays as it was — it had no field before
 * fin-codex and gets none after. Making the article resource searchable to
 * force the field is exactly the wrong fix: the default provider would then
 * query the model directly and produce a second, ungated category.
 *
 * Nothing here reads the help tables. Every hit comes from lin-codex's
 * Searcher, which gates the corpus with the viewer before it matches and
 * rate-limits before it reads, so the dropdown can never show an article the
 * signed-in admin may not open.
 */
final class HelpSearchProvider implements GlobalSearchProvider
{
    /**
     * One dropdown row's worth. `lin-codex.search.limit` is tuned for a
     * full-height drawer, not for one category sharing a dropdown with the
     * host's.
     */
    private const LIMIT = 5;

    public function __construct(private readonly GlobalSearchProvider $inner) {}

    /** The provider this one wraps; the boot-time guard reads it to stay idempotent. */
    public function inner(): GlobalSearchProvider
    {
        return $this->inner;
    }

    public function getResults(string $query): ?GlobalSearchResults
    {
        $results = $this->inner->getResults($query);
        $panel = Filament::getCurrentOrDefaultPanel();

        // The container extender is application-wide, so a panel without the
        // plugin can reach this. FinCodexPlugin::get() would throw there
        // (Panel::getPlugin() throws a LogicException), hence hasPlugin() first.
        if ($results === null || $panel === null || ! $panel->hasPlugin('fin-codex')) {
            return $results;
        }

        $plugin = $panel->getPlugin('fin-codex');

        if (! $plugin instanceof FinCodexPlugin || ! $plugin->hasGlobalSearch()) {
            return $results;
        }

        $viewer = app(ViewerResolver::class)->resolve($panel->getAuthGuard());
        $search = app(Searcher::class)->search($query, $viewer, null, self::LIMIT);

        // A throttled search adds nothing: GlobalSearchResult requires a URL,
        // so an "error row" would be a fake result, and it would appear while
        // the admin is reading the host's results anyway.
        if ($search->rateLimited || $search->hits === []) {
            return $results;
        }

        return $results->category(
            (string) __('fin-codex::fin-codex.search.category'),
            array_map($this->row(...), $search->hits),
        );
    }

    private function row(SearchHit $hit): GlobalSearchResult
    {
        // details is a LIST, so Filament renders the value with no <dt> label.
        // The snippet is deliberately unused: details are escaped, its <mark>
        // markup would show as text, and it is too long for a dropdown row.
        return new GlobalSearchResult(
            title: $hit->title,
            url: ArticlePath::href($hit->slug),
            details: $hit->sectionPath === [] ? [] : [implode(' › ', $hit->sectionPath)],
            actions: [$this->openHere($hit)],
        );
    }

    /**
     * GS-02's "open here". The row itself navigates to the help center, like
     * every other global search result; this action opens the drawer in place
     * instead. It is attached unconditionally: the search field renders in the
     * topbar or the sidebar, neither of which exists on a SimplePage, so a
     * help result cannot appear where the drawer is absent — and the Alpine
     * handler checks for [data-codex-drawer] anyway.
     *
     * A filled url() makes isLivewireClickHandlerEnabled() false, so no
     * wire:click is emitted. That matters: Filament\Livewire\GlobalSearch has
     * no InteractsWithActions, so a server-side action() would have nothing to
     * mount against.
     */
    private function openHere(SearchHit $hit): Action
    {
        $detail = Js::from(['slug' => $hit->slug])->toHtml();

        return Action::make('codex-search-'.Str::slug(str_replace('/', '-', $hit->slug)))
            ->label(__('fin-codex::fin-codex.search.open_here'))
            ->url(ArticlePath::href($hit->slug))
            ->alpineClickHandler("if (document.querySelector('[data-codex-drawer]')) { \$event.preventDefault(); window.dispatchEvent(new CustomEvent('codex:open', { detail: {$detail} })) }");
    }
}
