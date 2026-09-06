<?php
/**
 * Markup-aware tool interface (Wave E6, sub-cluster 2).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Markup_Aware_Tool_Interface`
 * (`includes/markup/`): the byte-identical `needs_markup()` /
 * `consume_markup()` contract tools implement to interrupt the agentic
 * loop with a canvas elicitation and resume with validated W3C Web
 * Annotation data.
 *
 * Documented deviations:
 *  - Interface name/namespace — the AI addon's PSR-4 tree (decision D4:
 *    engine pieces fold into `nvoos-content-graph-ai`).
 *  - The docblock type hints resolve to this package's `MarkupRequest`
 *    / `MarkupResult` (the base interface references the base classes;
 *    the ported interceptor/REST seams accept either per install mode).
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\Markup
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\Markup;

/**
 * Tools implementing this interface get a chance to short-circuit the
 * agentic loop with a markup elicitation request before `execute()` is
 * called. When markup is submitted, `consume_markup()` is invoked with
 * the validated result and the original arguments, and its return value
 * becomes the tool's effective result.
 *
 * @since 1.1.0
 */
interface MarkupAwareToolInterface {

	/**
	 * Inspect arguments and decide whether the tool needs visual markup.
	 *
	 * Return null to proceed with normal execution. Return a populated
	 * MarkupRequest to interrupt the loop and ask the user to mark up an
	 * asset on screen.
	 *
	 * Implementations MUST be deterministic for the same input and MUST
	 * NOT have side effects (no DB writes, no API calls).
	 *
	 * @param array $arguments Tool arguments as the LLM provided them.
	 * @param array $context   Execution context (assistant_id, user_id, endpoint).
	 * @return MarkupRequest|null Request object, or null to proceed.
	 */
	public function needs_markup( array $arguments, array $context );

	/**
	 * Resume tool execution with validated markup data.
	 *
	 * Called once the user has submitted markup and the validator has
	 * accepted it. The implementation should merge the markup result
	 * into the original arguments and return the same shape it would
	 * have returned from `execute()`.
	 *
	 * @param array        $arguments Original tool arguments.
	 * @param MarkupResult $result    Validated markup result.
	 * @param array        $context   Execution context.
	 * @return mixed|\WP_Error Tool result.
	 */
	public function consume_markup( array $arguments, MarkupResult $result, array $context );
}
