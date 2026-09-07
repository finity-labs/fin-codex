<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Panel\Concerns;

use Filament\Facades\Filament;

/**
 * The panel user's id, narrowed to ?int.
 *
 * Filament::auth()->id() is mixed (a uuid-keyed host is legal), and every
 * fin-codex write path stores it in an int column, so the narrowing has to
 * happen somewhere. It happened in four places before this trait: both
 * resource pages, the files table and the revisions relation manager, which
 * cannot reach EditArticle::userId() because a relation manager is its own
 * Livewire component with no handle on the page that renders it.
 *
 * TranslationTabs::userId() is deliberately not folded in: it is static, and
 * a trait method cannot serve a static call site that resolves no instance.
 */
trait ResolvesPanelUser
{
    protected function panelUserId(): ?int
    {
        $id = Filament::auth()->id();

        return is_numeric($id) ? (int) $id : null;
    }
}
