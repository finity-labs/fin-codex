<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Resources;

use FinityLabs\FinCodex\Resources\ArticleResource;

/**
 * The shape of a host override: a subclass of the built-in resource, named
 * on the admin panel through FinCodexPlugin::articleResource(), so the option
 * is proven with a class that exists. Everything is inherited, including the
 * codex-articles slug and the pages (whose $resource still points at
 * ArticleResource, which is what a host gets unless it overrides getPages()).
 * The portal panel keeps the built-in class so both paths are covered.
 */
final class AdminHelpArticleResource extends ArticleResource {}
