<?php

/**
 * Invokes a registered tool by slug.
 *
 * The retrieval harness drives three primitives (`recall_memory`,
 * `semantic_context_search`, `retrieve_agent_memory`). Those live in the core
 * tool registry on Laravel and in the WordPress registry inside the plugin, so
 * the harness depends on this one-method seam instead of either registry.
 *
 * Implementations return `null` for every failure mode — an unregistered tool,
 * a thrown exception, a provider error envelope — because the harness treats
 * all of them the same way: skip that source and keep going.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ToolInvokerInterface
{
    /**
     * Invoke a tool by slug.
     *
     * @param string               $slug      Tool slug.
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     * @return mixed Raw tool payload, or null when the tool is unavailable or failed.
     */
    public function invoke(string $slug, array $arguments, array $context): mixed;
}
