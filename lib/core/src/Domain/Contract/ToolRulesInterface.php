<?php
/**
 * Tool execution rules interface.
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

/**
 * Optional interface for tools that define execution rules.
 *
 * Rules cover model requirements, parameter constraints, rate limits,
 * timeout constraints, and orchestration hints.
 */
interface ToolRulesInterface {

	/**
	 * @return array{
	 *     model_requirements?: array<string, mixed>,
	 *     parameter_constraints?: array<string, mixed>,
	 *     rate_limits?: array<string, mixed>,
	 *     timeout_constraints?: array<string, mixed>,
	 *     response_constraints?: array<string, mixed>,
	 *     dependencies?: array<string, mixed>,
	 *     orchestration_hints?: array<string, mixed>,
	 * }
	 */
	public function getToolRules(): array;
}
