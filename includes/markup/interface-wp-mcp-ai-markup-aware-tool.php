<?php
/**
 * Optional interface for tools that can elicit visual markup from the user.
 *
 * A tool that implements this interface declares it can interrupt the
 * agentic loop, hand back an editable canvas widget to the chat client,
 * and resume execution once the user submits structured markup data.
 *
 * The pattern aligns with MCP Elicitation (spec 2025-06-18 / 2025-11-25)
 * and uses the W3C Web Annotation Data Model as the interchange format.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface WP_MCP_AI_Markup_Aware_Tool_Interface
 *
 * Tools implementing this interface get a chance to short-circuit the
 * agentic loop with a markup elicitation request before `execute()` is
 * called. When markup is submitted, `consume_markup()` is invoked with
 * the validated result and the original arguments, and its return value
 * becomes the tool's effective result.
 *
 * @since 1.3.0
 */
interface WP_MCP_AI_Markup_Aware_Tool_Interface {

	/**
	 * Inspect arguments and decide whether the tool needs visual markup.
	 *
	 * Return null to proceed with normal execution. Return a populated
	 * WP_MCP_AI_Markup_Request to interrupt the loop and ask the user
	 * to mark up an asset on screen.
	 *
	 * Implementations MUST be deterministic for the same input and MUST
	 * NOT have side effects (no DB writes, no API calls).
	 *
	 * @param array $arguments Tool arguments as the LLM provided them.
	 * @param array $context   Execution context (assistant_id, user_id, endpoint).
	 * @return WP_MCP_AI_Markup_Request|null Request object, or null to proceed.
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
	 * @param array                   $arguments Original tool arguments.
	 * @param WP_MCP_AI_Markup_Result $result    Validated markup result.
	 * @param array                   $context   Execution context.
	 * @return mixed|WP_Error Tool result.
	 */
	public function consume_markup( array $arguments, WP_MCP_AI_Markup_Result $result, array $context );
}
