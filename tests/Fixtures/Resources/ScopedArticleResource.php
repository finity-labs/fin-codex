<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Resources;

use FinityLabs\FinCodex\Resources\ArticleResource;
use Illuminate\Database\Eloquent\Builder;

/**
 * A host override that changes behaviour, not only navigation: the list and
 * the edit page must honour this query, which only happens when the package
 * pages resolve their resource through the plugin.
 */
final class ScopedArticleResource extends ArticleResource
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('slug', 'like', 'scoped/%');
    }
}
