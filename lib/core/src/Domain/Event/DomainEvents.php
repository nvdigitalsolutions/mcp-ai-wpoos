<?php
/**
 * Domain events for the oOS AI orchestration core.
 *
 * These replace WordPress action hooks (do_action) with typed PSR-14
 * domain events. Each event carries structured context that subscribers
 * can inspect without parsing string hook names.
 *
 * All events are immutable — subscribers receive them for observation
 * and may modify mutable properties where documented.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Event;

/**
 * Fired before a tool is executed.
 *
 * Replaces: do_action('wp_mcp_ai_before_tool_execution', ...)
 */
final class BeforeToolExecution
{
    public function __construct(
        public readonly string $toolSlug,
        public readonly array $arguments,
        public readonly array $context,
        public readonly float $startedAtMicros,
    ) {}
}

/**
 * Fired after a tool completes execution.
 *
 * Replaces: do_action('wp_mcp_ai_after_tool_execution', ...)
 */
final class AfterToolExecution
{
    public function __construct(
        public readonly string $toolSlug,
        public readonly array $arguments,
        public readonly array $context,
        public readonly mixed $result,
        public readonly bool $isError,
        public readonly float $durationMs,
    ) {}
}

/**
 * Fired before a chat request is sent to the LLM.
 *
 * Replaces: do_action('wp_mcp_ai_before_chat_request', ...)
 */
final class BeforeChatRequest
{
    public function __construct(
        public readonly int $assistantId,
        public array $messages,
        public array $options,
        public readonly array $authContext,
    ) {}
}

/**
 * Fired after a chat response is received from the LLM.
 *
 * Replaces: do_action('wp_mcp_ai_after_chat_response', ...)
 */
final class AfterChatResponse
{
    public function __construct(
        public readonly int $assistantId,
        public readonly array $response,
        public readonly array $requestContext,
        public readonly float $durationMs,
    ) {}
}

/**
 * Fired when a single agentic-loop iteration completes.
 *
 * Replaces: do_action('wp_mcp_ai_agentic_iteration_complete', ...)
 */
final class AgenticIterationComplete
{
    public function __construct(
        public readonly int $iteration,
        public readonly int $assistantId,
    ) {}
}

/**
 * Fired after the full agentic loop finishes (all iterations done).
 *
 * Replaces: do_action('wp_mcp_ai_agentic_loop_completed', ...)
 */
final class AgenticLoopCompleted
{
    public function __construct(
        public readonly int $totalIterations,
        public readonly int $assistantId,
        public readonly array $toolResults,
        public readonly bool $limitReached,
    ) {}
}

/**
 * Fired when cost data is calculated for a chat response.
 *
 * Replaces: do_action('wp_mcp_ai_cost_calculated', ...)
 */
final class CostCalculated
{
    public function __construct(
        public readonly array $costData,
        public readonly int $assistantId,
        public readonly int $userId,
        public readonly array $response,
    ) {}
}

/**
 * Fired when a tool is registered with the registry.
 *
 * Replaces: do_action('wp_mcp_ai_register_tools', ...)
 */
final class ToolsRegistered
{
    /**
     * @param string[] $toolSlugs
     */
    public function __construct(
        public readonly array $toolSlugs,
    ) {}
}
