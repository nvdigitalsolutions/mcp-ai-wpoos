<?php
/**
 * Execution Logger — shared utility for autonomous session tool-call logging.
 *
 * Provides a single, consistent entry point for logging tool executions
 * to the mcp_execution_history CCT. All orchestration tools call this
 * instead of writing to the CCT directly.
 *
 * Gracefully degrades when the CCT is unavailable — logging failures
 * must never block the calling tool.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execution Logger class.
 */
class WP_MCP_AI_Execution_Logger {

	/**
	 * Log a tool execution to the execution history CCT.
	 *
	 * All parameters are optional except session_id and tool_name.
	 * The method degrades gracefully — logging failures are silent and
	 * never throw or return errors to the caller.
	 *
	 * @param array $args {
	 *     Execution log arguments.
	 *
	 *     @type string $session_id     Required. Session identifier.
	 *     @type int    $iteration      Optional. Iteration number (default: 0).
	 *     @type string $tool_name      Required. Name/slug of the executed tool.
	 *     @type bool   $success        Optional. Whether execution succeeded (default: true).
	 *     @type int    $duration_ms    Optional. Execution duration in milliseconds.
	 *     @type int    $tokens_used    Optional. Tokens consumed by this call.
	 *     @type string $input_summary  Optional. Summary of input args (max 500 chars).
	 *     @type string $output_summary Optional. Summary of output (max 500 chars).
	 *     @type string $error_message  Optional. Error message if execution failed.
	 * }
	 * @return bool True if logged successfully, false if CCT unavailable.
	 */
	public static function log_tool_call( array $args ) {
		if ( empty( $args['session_id'] ) || empty( $args['tool_name'] ) ) {
			return false;
		}

		// Only attempt logging if the CCT is available.
		if ( ! class_exists( 'WP_MCP_AI_Execution_History_CCT' ) ) {
			return false;
		}

		$handler = WP_MCP_AI_Execution_History_CCT::get_item_handler();

		if ( ! $handler ) {
			return false;
		}

		$item_data = array(
			'session_id'     => sanitize_text_field( $args['session_id'] ),
			'iteration'      => isset( $args['iteration'] ) ? absint( $args['iteration'] ) : 0,
			'tool_name'      => sanitize_text_field( $args['tool_name'] ),
			'success'        => isset( $args['success'] ) ? (bool) $args['success'] : true,
			'duration_ms'    => isset( $args['duration_ms'] ) ? absint( $args['duration_ms'] ) : 0,
			'tokens_used'    => isset( $args['tokens_used'] ) ? absint( $args['tokens_used'] ) : 0,
			'error_message'  => isset( $args['error_message'] ) ? sanitize_textarea_field( $args['error_message'] ) : '',
			'input_summary'  => isset( $args['input_summary'] ) ? self::truncate( sanitize_textarea_field( $args['input_summary'] ), 500 ) : '',
			'output_summary' => isset( $args['output_summary'] ) ? self::truncate( sanitize_textarea_field( $args['output_summary'] ), 500 ) : '',
			'executed_at'    => current_time( 'mysql' ),
		);

		try {
			$item_id = $handler->add_item( $item_data );
			return is_numeric( $item_id ) && $item_id > 0;
		} catch ( \Exception $e ) {
			// Logging must never throw.
			return false;
		}
	}

	/**
	 * Truncate a string to a maximum length, appending an ellipsis if needed.
	 *
	 * @param string $text     Input text.
	 * @param int    $max_chars Maximum characters.
	 * @return string Truncated text.
	 */
	private static function truncate( $text, $max_chars ) {
		if ( mb_strlen( $text ) <= $max_chars ) {
			return $text;
		}

		return mb_substr( $text, 0, $max_chars - 3 ) . '...';
	}
}
