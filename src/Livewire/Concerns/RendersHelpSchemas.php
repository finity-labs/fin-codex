<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Livewire\Concerns;

use Filament\Actions\Action;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\TextSize;
use FinityLabs\LinCodex\Data\TreeNode;
use FinityLabs\LinCodex\Search\SearchHit;
use FinityLabs\LinCodex\Search\SearchResult;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * The schema pieces the drawer and the Help Center page draw the same way:
 * the contents tree, the search hits, the breadcrumbs, the related section
 * and the "On this page" entries. One body for both surfaces, so a fix to
 * the tree lands on both at once — 0.5.1 had to apply the collapsible
 * article sections twice.
 *
 * What differs between the two is handed in through four hooks: how a link
 * to an article is built (a Livewire action in the drawer, a real anchor on
 * the page), the prefix of every section id, and the data attributes the
 * tests and the glue query on a tree entry and on a search hit. The prefix
 * must differ per surface: the drawer is mounted on every panel page, the
 * Help Center included, so a shared prefix would put two elements with one
 * id on that page and have the two trees remember one open state between
 * them.
 */
trait RendersHelpSchemas
{
    /** A link to one article, named by helpActionName() so two links to one article share a name. */
    abstract protected function helpLink(string $slug, string $label): Action;

    abstract protected function helpSectionIdPrefix(): string;

    /** The data attribute a tree entry carries, with the node slug as its value. */
    abstract protected function helpNodeAttribute(): string;

    /** The data attribute a search hit carries, with the hit slug as its value. */
    abstract protected function helpHitAttribute(): string;

    /**
     * Attributes the current article's tree entry carries beside
     * aria-current="page".
     *
     * @return array<string, string>
     */
    protected function helpActiveAttributes(): array
    {
        return [];
    }

    protected function helpActionName(string $slug): string
    {
        return 'open-'.Str::slug(str_replace('/', '-', $slug));
    }

    /**
     * The DOM id of one tree section — and, at the same time, the key its open
     * state is remembered under and what an expand-section event has to name
     * to reach it, because Section::id() feeds all three. Derived from the
     * node slug and nothing else, so it survives every re-render, and put
     * through Str::slug() because Filament strips a handful of characters out
     * of a custom id and a stripped id would no longer match the key.
     */
    protected function helpSectionId(string $slug): string
    {
        return $this->helpSectionIdPrefix().Str::slug(str_replace('/', '-', $slug));
    }

    /**
     * The tree: a node with children is a collapsible Section whether it is a
     * folder group or an article, and an article-rooted one keeps its own link
     * as the heading — the label opens the article, the chevron beside it
     * works the children. A node with no children is the link alone.
     *
     * The entry for $current is primary where every other entry is gray, and
     * it alone says it is the current page: colour on its own is not a
     * marker. The root article appears once, as the heading link.
     *
     * With $collapseDeep, anything below the top level comes up closed on a
     * first visit so a big library does not arrive as a wall; on every later
     * visit the browser's own remembered state wins, because every section
     * persists its open state under its id.
     *
     * @param  list<TreeNode>  $nodes
     *
     * @return list<Component>
     */
    protected function helpTreeComponents(array $nodes, ?string $current, bool $collapseDeep = false, int $depth = 0): array
    {
        $components = [];

        foreach ($nodes as $node) {
            if ($node->isGroup()) {
                $components[] = Section::make($node->label)
                    ->id($this->helpSectionId($node->slug))
                    ->compact()
                    ->collapsible()
                    ->persistCollapsed()
                    ->collapsed($collapseDeep && $depth > 0)
                    ->extraAttributes([$this->helpNodeAttribute() => $node->slug])
                    ->schema($this->helpTreeComponents($node->children, $current, $collapseDeep, $depth + 1));

                continue;
            }

            // merge: true throughout — a link may already carry attributes of
            // its own, and a bare call would replace them rather than add.
            $link = $this->helpLink($node->slug, $node->label)
                ->color($node->slug === $current ? 'primary' : 'gray')
                ->extraAttributes([$this->helpNodeAttribute() => $node->slug], merge: true);

            if ($node->slug === $current) {
                $link = $link->extraAttributes(['aria-current' => 'page', ...$this->helpActiveAttributes()], merge: true);
            }

            if ($node->children === []) {
                $components[] = Actions::make([$link]);

                continue;
            }

            // A Filament Action is Htmlable, so the whole link renders inside
            // the section's heading. The guard keeps a click on the label from
            // flipping the section as well as opening the article: Filament's
            // toggle listens on the element around the heading. An empty
            // string, never true — a true value renders as its own attribute
            // name, which Alpine would try to evaluate.
            $link = $link->extraAttributes(['x-on:click.stop' => ''], merge: true);

            $components[] = Section::make($link)
                // Not optional. Filament's own key closure would put the heading
                // through a string-typed helper, and an Action cannot be cast to
                // one; setting the key replaces the closure so it never runs.
                ->key($this->helpSectionId($node->slug).'::section')
                ->id($this->helpSectionId($node->slug))
                ->compact()
                ->collapsible()
                ->persistCollapsed()
                ->collapsed($collapseDeep && $depth > 0)
                ->extraAttributes([$this->helpNodeAttribute() => $node->slug])
                ->schema($this->helpTreeComponents($node->children, $current, $collapseDeep, $depth + 1));
        }

        return $components;
    }

    /**
     * The search hits, the core's rate-limit line or its no-results line. A
     * hit carries three pieces of text: the title is the link, the section
     * path a small grey line under it, and the snippet at reading size.
     *
     * @return list<Component>
     */
    protected function helpSearchComponents(SearchResult $result): array
    {
        if ($result->rateLimited) {
            return [Text::make(__('lin-codex::lin-codex.ui.rate_limited', ['seconds' => $result->retryAfterSeconds]))->color('warning')];
        }

        if ($result->hits === []) {
            return [Text::make(__('lin-codex::lin-codex.ui.no_results'))->color('gray')];
        }

        return array_map(fn (SearchHit $hit): Group => Group::make([
            Actions::make([
                $this->helpLink($hit->slug, $hit->title)
                    ->extraAttributes([$this->helpHitAttribute() => $hit->slug], merge: true),
            ]),
            Text::make(implode(' › ', $hit->sectionPath))
                ->size(TextSize::ExtraSmall)
                ->color('gray')
                ->hidden($hit->sectionPath === []),
            // SnippetBuilder's output: everything escaped already, with <mark>
            // around the matched prefixes and nothing else. Escaping it again
            // would show the reader the tag.
            Text::make(new HtmlString($hit->snippet))->size(TextSize::Small),
        ]), $result->hits);
    }

    /**
     * @param  list<array{slug: string, title: string}>  $crumbs
     */
    protected function helpBreadcrumbs(array $crumbs): Actions
    {
        return Actions::make(array_map(
            fn (array $crumb): Action => $this->helpLink($crumb['slug'], $crumb['title'])->size('sm')->color('gray'),
            $crumbs,
        ));
    }

    /**
     * @param  list<array{slug: string, title: string}>  $related
     */
    protected function helpRelatedSection(array $related): Section
    {
        return Section::make(__('lin-codex::lin-codex.ui.related'))
            ->compact()
            ->schema([Actions::make(array_map(
                fn (array $entry): Action => $this->helpLink($entry['slug'], $entry['title']),
                $related,
            ))]);
    }

    /**
     * "On this page": one plain anchor per heading, with the ids the renderer
     * already wrote into the body, so the browser jumps and puts the heading
     * in the hash for free and a heading link can be copied out.
     *
     * @param  list<array{id: string, text: string}>  $toc
     *
     * @return list<Text>
     */
    protected function helpHeadingEntries(array $toc): array
    {
        return array_map(
            static fn (array $entry): Text => Text::make(new HtmlString('<a href="#'.e($entry['id']).'">'.e($entry['text']).'</a>'))->size(TextSize::Small),
            $toc,
        );
    }
}
