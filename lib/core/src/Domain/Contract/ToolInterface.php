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
 * Type aliases (`ToolArgs`, `ToolContext`, `ToolParametersSchema`) are
 * declared once in lib/core/phpstan.neon so every contract can name its
 * untyped arrays without repeating the shape. `...<array-key, mixed>` means
 * "known keys documented, extra keys tolerated" while tools migrate.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ToolInterface {

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
	 *
	 * @return ToolParametersSchema
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
	 * @param ToolArgs    $arguments Parsed and sanitized arguments matching the schema.
	 * @param ToolContext $context   Execution context: user_id, assistant_id, request,
	 *                               assistant_config, guest_request, agentic_loop, etc.
	 *
	 * @return mixed  Success: array with success/message/data keys.
	 *                Failure: error created via ErrorFactoryInterface.
	 *
	 * @see \Nvoos\Core\Domain\Contract\ToolResultInterface  For tools that return a typed result.
	 */
	public function execute( array $arguments = array(), array $context = array() ): mixed;
}
