<?php
/**
 * Tool flow stage interface.
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
 * Optional interface for tools restricted to specific flow stages.
 *
 * Flow stages control when a tool can be invoked during an agentic workflow:
 *  - 'anytime': Any iteration (default)
 *  - 'start':   Only in the first iteration (iteration 0)
 *  - 'middle':  Only in intermediate iterations
 *  - 'end':     Only in the final iteration
 */
interface ToolFlowStageInterface {

	/**
	 * @return string[]  Eligible stage identifiers.
	 */
	public function getFlowStages(): array;
}
