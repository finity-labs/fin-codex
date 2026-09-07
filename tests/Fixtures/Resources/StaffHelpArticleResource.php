<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Resources;

use FinityLabs\FinCodex\Resources\ArticleResource;

/**
 * The staff panel's override, a second host subclass on its own guard: the
 * same registration path as AdminHelpArticleResource, on a panel whose plugin
 * options are closures and whose navigation group and sort differ.
 */
final class StaffHelpArticleResource extends ArticleResource {}
