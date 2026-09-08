<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Policies;

use FinityLabs\FinCodex\Policies\ArticlePolicy;

/**
 * A host's own policy, standing in for the class a real application writes at
 * {policyNamespace}\ArticlePolicy.
 *
 * It overrides nothing on purpose. The override tests class_alias() it into a
 * namespace no fixture panel names (FinCodexHostTest\Policies) and point one
 * panel's plugin there at runtime; an alias lives for the rest of the PHP
 * process, so a permissive subclass in a fixture namespace could redden a
 * later test that boots the same panel.
 */
class HostArticlePolicy extends ArticlePolicy {}
