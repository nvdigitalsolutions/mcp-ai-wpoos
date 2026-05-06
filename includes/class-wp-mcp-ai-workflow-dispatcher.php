<?php
/**
 * Workflow Dispatcher — pluggable executor entry point.
 *
 * Provides a single entry point — `WP_MCP_AI_Workflow_Dispatcher::dispatch()` —
 * that the trigger CPT, replay tool, and any future workflow consumer can call
 * without hard-binding to `WP_MCP_AI_Workflow_Engine_V2`. Third-party executors
 * (notably the Pro Workflow Builder, which uses string-keyed workflow IDs and
 * a client-driven execution model) can register themselves through the
 * `wp_mcp_ai_workflow_executor` filter.
 *
 * Filter contract:
 *
 *   add_filter( 'wp_mcp_ai_workflow_executor', function ( $result, $workflow_id, $input, $context ) {
 *     // Return null to defer to the default executor.
 *     // Return an array {success,run_id,message,...} or a WP_Error to handle.
 *     return $result;
 *   }, 10, 4 );
 *
 * Default executor: when no filter handles the workflow, fall back to
 * `WP_MCP_AI_Workflow_Engine_V2::execute()` if available.
 *
 * @package   WP_MCP_AI
 * @since     2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pluggable workflow executor entry point.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Workflow_Dispatcher {

	/**
	 * Dispatch a workflow execution to the first registered executor.
	 *
	 * @since 2.3.0
	 *
	 * @param int|string $workflow_id Workflow identifier (int post ID for base, string slug for Pro).
	 * @param array      $input       Runtime input.
	 * @param array      $context     Execution context.
	 * @return array|WP_Error {
	 *   @type bool        $success
	 *   @type string|int  $run_id
	 *   @type string      $message
	 * }
	 */
	public static function dispatch( $workflow_id, $input = array(), $context = array() ) {
		/**
		 * Filter to plug in a custom workflow executor.
		 *
		 * Return null to defer to the default executor (Engine V2).
		 * Return an array or WP_Error to take ownership of this dispatch.
		 *
		 * @since 2.3.0
		 *
		 * @param array|WP_Error|null $result      Executor result, or null to defer.
		 * @param int|string          $workflow_id Workflow identifier.
		 * @param array               $input       Runtime input.
		 * @param array               $context     Execution context.
		 */
		$result = apply_filters( 'wp_mcp_ai_workflow_executor', null, $workflow_id, $input, $context );

		if ( null !== $result ) {
			return $result;
		}

		// Default executor — Engine V2 if available and enabled.
		if ( class_exists( 'WP_MCP_AI_Workflow_Engine_V2' )
			&& WP_MCP_AI_Workflow_Engine_V2::is_enabled()
		) {
			return WP_MCP_AI_Workflow_Engine_V2::execute(
				absint( $workflow_id ),
				is_array( $input ) ? $input : array(),
				is_array( $context ) ? $context : array()
			);
		}

		return new WP_Error(
			'no_workflow_executor',
			__( 'No workflow executor is registered for this workflow.', 'mcp-ai-wpoos' )
		);
	}
}
