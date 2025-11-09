<?php
/**
 * Resource Usage Tracking Integration
 *
 * Hooks into chat operations to record usage metrics for the orchestration layer.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resource Usage Tracking class.
 */
class WP_MCP_AI_Resource_Usage_Tracker {

	/**
	 * Start time for tracking execution.
	 *
	 * @var float
	 */
	private static $start_time;

	/**
	 * Assistant ID for current operation.
	 *
	 * @var int
	 */
	private static $assistant_id;

	/**
	 * Initialize the usage tracker.
	 */
	public static function init() {
		// Hook into chat request start (filter).
		add_filter( 'wp_mcp_ai_before_chat_request', array( __CLASS__, 'start_tracking' ), 10, 2 );

		// Hook into chat response completion (action).
		add_action( 'wp_mcp_ai_after_chat_response', array( __CLASS__, 'record_tracking' ), 10, 3 );
	}

	/**
	 * Start tracking a chat operation.
	 *
	 * @param mixed $data        Data being filtered.
	 * @param int   $assistant_id Assistant ID.
	 * @return mixed Unmodified data.
	 */
	public static function start_tracking( $data, $assistant_id ) {
		self::$start_time   = microtime( true );
		self::$assistant_id = $assistant_id;
		return $data;
	}

	/**
	 * Record tracking data after chat completion.
	 *
	 * @param int             $assistant_id Assistant ID.
	 * @param mixed           $response     Chat response data.
	 * @param WP_REST_Request $request      REST request object.
	 * @return mixed Unmodified response.
	 */
	public static function record_tracking( $assistant_id, $response, $request ) {
		if ( ! self::$start_time ) {
			return $response;
		}

		$execution_time = microtime( true ) - self::$start_time;

		// Extract token usage from response.
		$tokens_used = 0;
		if ( is_array( $response ) ) {
			if ( isset( $response['usage']['total_tokens'] ) ) {
				$tokens_used = $response['usage']['total_tokens'];
			} elseif ( isset( $response['token_usage']['total'] ) ) {
				$tokens_used = $response['token_usage']['total'];
			}
		}

		// Determine status.
		$status = 'success';
		if ( is_wp_error( $response ) ) {
			$status = 'error';
		} elseif ( is_array( $response ) && isset( $response['error'] ) ) {
			$status = 'error';
		}

		// Record usage.
		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$resource_manager->record_usage(
			array(
				'operation_type' => 'chat',
				'assistant_id'   => $assistant_id,
				'tokens_used'    => $tokens_used,
				'execution_time' => round( $execution_time, 2 ),
				'status'         => $status,
				'timestamp'      => time(),
			)
		);

		// Reset tracking.
		self::$start_time   = null;
		self::$assistant_id = null;

		return $response;
	}
}

// Initialize the usage tracker.
WP_MCP_AI_Resource_Usage_Tracker::init();
