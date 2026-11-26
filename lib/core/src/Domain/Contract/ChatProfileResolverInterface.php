<?php

/**
 * Resolves the chat profile governing one tool execution.
 *
 * The resolution is a **trust boundary**, so the interface is deliberately
 * narrow: the caller hands over the server-built execution context, and the
 * implementation answers with the profile. Implementations must read the
 * principal from `$context['user_id']` — set by the orchestrator from the
 * authenticated session — and must never adopt a client-supplied profile slug.
 * WordPress keeps the same rule in `WP_MCP_AI_Chat_Profile_Manager`: a client
 * may request a profile, but the server decides.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Security\ChatProfile;

/**
 * Profile resolution — the port the tool guard depends on.
 *
 * @since 1.4.0
 */
interface ChatProfileResolverInterface
{
    /**
     * Resolve the profile governing this execution.
     *
     * Must be total: an unresolved, missing or malformed preference yields the
     * default rather than an exception, because this runs on the hot path of
     * every tool call and a resolver fault must not become an outage.
     *
     * @param array<string, mixed> $context Execution context (`user_id`, …).
     */
    public function resolve(array $context): ChatProfile;

    /**
     * The site default, used when the principal has no personal selection.
     *
     * Exposed because a setter must know whether it is clearing an override
     * rather than storing one, and that answer must come from the same place
     * the resolver uses.
     */
    public function defaultProfile(): ChatProfile;

    /**
     * Whether the feature is enabled for this deployment.
     *
     * Disabled means "resolve to `write`", not "throw": disabling a feature
     * must be indistinguishable from never having configured it.
     */
    public function isEnabled(): bool;
}
