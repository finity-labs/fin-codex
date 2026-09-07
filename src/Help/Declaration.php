<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Help;

use FinityLabs\LinCodex\Data\ContextData;

/**
 * One article a class declares for one panel: the declaring class, the panel
 * it was asked for, the slug, its position in that class's answer (0 is the
 * best article) and the synthetic contexts that stand for it, all scoped to
 * that panel. The ContentSource decorator folds the contexts into the article;
 * Phase 5's editor lists the triple read-only as "declared in code".
 */
final readonly class Declaration
{
    /**
     * @param  class-string  $class
     * @param  list<ContextData>  $contexts
     */
    public function __construct(
        public string $class,
        public string $panelId,
        public string $slug,
        public int $position,
        public array $contexts,
    ) {}
}
