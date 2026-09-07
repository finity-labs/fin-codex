<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Panel;

/**
 * What lin-codex is told about the current page, derived once per request by
 * CurrentPage and read by the help button and the drawer mount alike. On
 * resource pages pageClass() is the resource class, because list, create, edit
 * and view share one article; every other page is identified by its own class.
 * A null livewireClass means the request is not a page render: a Livewire
 * update, a closure route or a plain controller.
 */
final readonly class PageIdentity
{
    public function __construct(
        /** The page component class from the route; null on update requests and non-page routes. */
        public ?string $livewireClass,
        /** static::getResource() when the page is a Filament\Resources\Pages\Page, otherwise null. */
        public ?string $resourceClass,
        /** Filament::getCurrentPanel()?->getId(). */
        public ?string $panelId,
        /** Filament::getCurrentPanel()?->getAuthGuard(). */
        public ?string $guard,
        /** Whether the page renders on the simple layout (login, register, password reset and friends). */
        public bool $isSimplePage,
    ) {}

    public static function none(): self
    {
        return new self(null, null, null, null, false);
    }

    /**
     * The identity handed to lin-codex: the resource class on resource pages,
     * the page class elsewhere.
     */
    public function pageClass(): ?string
    {
        return $this->resourceClass ?? $this->livewireClass;
    }

    public function hasPage(): bool
    {
        return $this->livewireClass !== null;
    }

    public function hasPanel(): bool
    {
        return $this->panelId !== null;
    }
}
