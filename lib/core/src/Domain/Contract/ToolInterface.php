<?php
/**
 * Tool interface — the core contract that every AI tool must implement.
 *
 * Ported from the existing WP_MCP_AI_Tool_Interface with minor adaptations
 * for framework agnosticism:
 *  - Replaces WP_Error with ErrorFactoryInterface (via convention)
 *  - Replaces string capabilities with capability contract
 *  - Adds optional sub-interfaces for capability flags, rules, data contracts,
 *    flow stages, and LLM sanitization.
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Contract;

interface ToolInterface
{
    /**
     * Unique slug identifying the tool.
     *
     * Used in OpenAI function name fields, tool allow-lists, and logging.
     * Must be snake_case, globally unique within a tool registry.
     */
    public function getSlug(): string;

    /**
     * Human-readable name for display in admin UIs and logs.
     */
    public function getName(): string;

    /**
     * LLM-facing description of what the tool does and when to use it.
     *
     * This is injected into the system prompt as the function description
     * and directly influences the model's tool selection behavior.
     */
    public function getDescription(): string;

    /**
     * JSON Schema describing the tool's accepted parameters.
     *
     * Must follow the OpenAI function-calling schema format:
     *  - type: 'object'
     *  - properties: { param_name: { type, description, enum, ... } }
     *  - required: [ ... ]
     *  - additionalProperties: false
     */
    public function getParametersSchema(): array;

    /**
     * The capability/permission string required to execute this tool.
     *
     * Examples: 'edit_posts', 'manage_options', 'read', 'public'.
     * Return empty string for tools executable by anyone.
     */
    public function getRequiredCapability(): string;

    /**
     * Execute the tool with supplied arguments.
     *
     * @param array $arguments  Parsed and sanitized arguments matching the schema.
     * @param array $context    Execution context: user_id, assistant_id, request,
     *                          assistant_config, guest_request, agentic_loop, etc.
     *
     * @return mixed  Success: array with success/message/data keys.
     *                Failure: error created via ErrorFactoryInterface.
     */
    public function execute(array $arguments = [], array $context = []): mixed;
}

/**
 * Optional interface for tools that expose capability flags.
 *
 * Capability flags provide metadata to the orchestrator about tool
 * characteristics (read-only, write, async, external-api, cacheable, etc.).
 */
interface ToolCapabilityFlagsInterface
{
    /**
     * @return string[]  Array of capability flag strings.
     *
     * @see \Oos\Core\Domain\Entity\ToolCapabilityFlag  For the canonical flag list.
     */
    public function getCapabilityFlags(): array;
}

/**
 * Optional interface for tools that define execution rules.
 *
 * Rules cover model requirements, parameter constraints, rate limits,
 * timeout constraints, and orchestration hints.
 */
interface ToolRulesInterface
{
    /**
     * @return array{
     *     model_requirements?: array,
     *     parameter_constraints?: array,
     *     rate_limits?: array,
     *     timeout_constraints?: array,
     *     response_constraints?: array,
     *     dependencies?: array,
     *     orchestration_hints?: array,
     * }
     */
    public function getToolRules(): array;
}

/**
 * Optional interface for tools that declare what data they produce/consume.
 *
 * The produces/consumes hints allow the LLM to chain tool calls
 * autonomously (e.g., "get_post produces post_object, which
 * update_post_seo consumes").
 */
interface ToolDataContractInterface
{
    /**
     * @return array{
     *     produces?: string|null,
     *     consumes?: string|string[]|null,
     * }
     */
    public function getDataContract(): array;
}

/**
 * Optional interface for tools restricted to specific flow stages.
 *
 * Flow stages control when a tool can be invoked during an agentic workflow:
 *  - 'anytime': Any iteration (default)
 *  - 'start':   Only in the first iteration (iteration 0)
 *  - 'middle':  Only in intermediate iterations
 *  - 'end':     Only in the final iteration
 */
interface ToolFlowStageInterface
{
    /**
     * @return string[]  Eligible stage identifiers.
     */
    public function getFlowStages(): array;
}

/**
 * Optional interface for tools that sanitize results before sending to the LLM.
 *
 * The LLM sees a stripped version of tool results (no base64 blobs,
 * no raw HTTP responses). The chat client receives the full result.
 */
interface ToolLlmSanitizerInterface
{
    /**
     * Sanitize a tool result for LLM consumption.
     *
     * Strips verbose metadata, base64 content, duplicate raw API responses,
     * and truncates to a reasonable size.
     *
     * @param mixed $result  The raw tool execution result.
     * @return string        Sanitized text suitable for the LLM context window.
     */
    public function sanitizeForLlm(mixed $result): string;
}
