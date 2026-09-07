<?php

declare(strict_types=1);

namespace FinityLabs\FinCodex\Tests\Fixtures\Policies;

use FinityLabs\FinCodex\Policies\ArticlePolicy;

/**
 * A host's own policy, standing in for the class a real application writes at
 * {policyNamespace}\ArticlePolicy.
 *
 * It overrides nothing on purpose. The override test class_alias()es it onto
 * Staff\Policies\ArticlePolicy, and an alias lives for the rest of the PHP
 * process — so a permissive subclass cannot redden a later test that happens
 * to run with the staff panel current.
 */
class HostArticlePolicy extends ArticlePolicy {}
