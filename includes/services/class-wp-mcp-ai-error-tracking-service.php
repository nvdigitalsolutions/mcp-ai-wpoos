<?php
/**
 * Error Tracking Service for NV oOS.
 *
 * Centralized service for tracking, logging, and analyzing errors across the plugin.
 * Provides real-time error monitoring, rate calculation, and integration with
 * the Performance Monitor CCT.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Error Tracking Service class.
 */
class WP_MCP_AI_Error_Tracking_Service {

	/**
	 * Option key for storing recent errors.
	 */
	const ERRORS_OPTION = 'wp_mcp_ai_error_tracking';

	/**
	 * Transient key for error rate cache.
	 */
	const RATE_CACHE_KEY = 'wp_mcp_ai_error_rate_';

	/**
	 * Maximum number of errors to store.
	 */
	const MAX_STORED_ERRORS = 1000;

	/**
	 * Error retention period in seconds (7 days).
	 */
	const RETENTION_PERIOD = 604800;

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Error_Tracking_Service|null
	 */
	private static $instance = null;

	/**
	 * Settings repository instance
	 *
	 * @var WP_MCP_AI_Settings_Repository
	 */
	private static $settings_repository;

	/**
	 * Whether error tracking is enabled.
	 *
	 * @var bool
	 */
	private $enabled = true;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Error_Tracking_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get settings repository instance
	 *
	 * @return WP_MCP_AI_Settings_Repository Settings repository instance.
	 */
	private static function get_settings_repository() {
		if ( null === self::$settings_repository ) {
			self::$settings_repository = wp_mcp_ai_get_settings_repository();
		}
		return self::$settings_repository;
	}

	/**
	 * Set settings repository instance (for testing)
	 *
	 * @param WP_MCP_AI_Settings_Repository $repository Settings repository instance.
	 */
	public static function set_settings_repository( $repository ) {
		self::$settings_repository = $repository;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->enabled = apply_filters( 'wp_mcp_ai_error_tracking_enabled', true );

		if ( $this->enabled ) {
			$this->init_hooks();
		}
	}

	/**
	 * Initialize WordPress hooks.
	 */
	private function init_hooks() {
		// Hook into WordPress error handling.
		add_action( 'wp_mcp_ai_error', array( $this, 'track_error' ), 10, 3 );

		// Clean up old errors daily.
		add_action( 'wp_mcp_ai_cleanup_old_errors', array( $this, 'cleanup_old_errors' ) );

		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_old_errors' ) ) {
			$cleanup_timestamp = time();
			wp_schedule_event( $cleanup_timestamp, 'daily', 'wp_mcp_ai_cleanup_old_errors' );

			// Record cleanup cron job in cron manager for visibility.
			if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
				WP_MCP_AI_Cron_Manager::record_job(
					'wp_mcp_ai_cleanup_old_errors',
					array(),
					'daily',
					$cleanup_timestamp,
					0 // System-created job.
				);
			}
		}
	}

	/**
	 * Track an error.
	 *
	 * @param string $component Component where error occurred.
	 * @param string $message   Error message.
	 * @param array  $context   Additional context data.
	 * @return int Error ID.
	 */
	public function track_error( $component, $message, $context = array() ) {
		if ( ! $this->enabled ) {
			return 0;
		}

		$error_id = uniqid( 'err_', true );

		$error = array(
			'id'          => $error_id,
			'component'   => sanitize_key( $component ),
			'message'     => sanitize_text_field( $message ),
			'context'     => $context,
			'timestamp'   => current_time( 'timestamp' ),
			'user_id'     => get_current_user_id(),
			'ip_address'  => $this->get_client_ip(),
			'request_uri' => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
		);

		// Store the error.
		$this->store_error( $error );

		// Invalidate error rate cache.
		$this->invalidate_rate_cache( $component );

		// Log to WordPress error log if WP_DEBUG is enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[WP_MCP_AI] %s: %s', $component, $message ) );
		}

		// Allow other plugins to react to errors.
		do_action( 'wp_mcp_ai_error_tracked', $error_id, $component, $message, $context );

		return $error_id;
	}

	/**
	 * Store an error in the database.
	 *
	 * @param array $error Error data.
	 */
	private function store_error( $error ) {
		$errors = self::get_settings_repository()->get( 'error_tracking', array() );

		if ( ! is_array( $errors ) ) {
			$errors = array();
		}

		// Add new error.
		$errors[] = $error;

		// Keep only recent errors.
		if ( count( $errors ) > self::MAX_STORED_ERRORS ) {
			$errors = array_slice( $errors, -self::MAX_STORED_ERRORS );
		}

		self::get_settings_repository()->update( 'error_tracking', $errors );
	}

	/**
	 * Get error rate for a component.
	 *
	 * @param string $component   Component name.
	 * @param int    $time_period Time period in seconds (default: 3600 = 1 hour).
	 * @param int    $total_requests Total number of requests in the period.
	 * @return float Error rate as percentage.
	 */
	public function get_error_rate( $component, $time_period = 3600, $total_requests = null ) {
		// Check cache first.
		$cache_key = self::RATE_CACHE_KEY . $component . '_' . $time_period;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && null === $total_requests ) {
			return floatval( $cached );
		}

		$errors      = $this->get_errors_by_component( $component, $time_period );
		$error_count = count( $errors );

		// If total_requests is not provided, estimate from stored data.
		if ( null === $total_requests ) {
			$total_requests = $this->estimate_total_requests( $component, $time_period );
		}

		$error_rate = 0.0;
		if ( $total_requests > 0 ) {
			$error_rate = ( $error_count / $total_requests ) * 100;
		}

		// Cache for 5 minutes.
		set_transient( $cache_key, $error_rate, 300 );

		return $error_rate;
	}

	/**
	 * Get errors by component.
	 *
	 * @param string $component   Component name.
	 * @param int    $time_period Time period in seconds.
	 * @return array Array of errors.
	 */
	public function get_errors_by_component( $component, $time_period = 3600 ) {
		$errors = self::get_settings_repository()->get( 'error_tracking', array() );

		if ( ! is_array( $errors ) ) {
			return array();
		}

		$cutoff_time = current_time( 'timestamp' ) - $time_period;
		$filtered    = array();

		foreach ( $errors as $error ) {
			if ( isset( $error['component'] ) && $error['component'] === $component ) {
				if ( isset( $error['timestamp'] ) && $error['timestamp'] >= $cutoff_time ) {
					$filtered[] = $error;
				}
			}
		}

		return $filtered;
	}

	/**
	 * Get all recent errors.
	 *
	 * @param int $limit Maximum number of errors to return.
	 * @param int $time_period Time period in seconds (default: all).
	 * @return array Array of errors.
	 */
	public function get_recent_errors( $limit = 50, $time_period = null ) {
		$errors = self::get_settings_repository()->get( 'error_tracking', array() );

		if ( ! is_array( $errors ) ) {
			return array();
		}

		// Filter by time period if specified.
		if ( null !== $time_period ) {
			$cutoff_time = current_time( 'timestamp' ) - $time_period;
			$errors      = array_filter(
				$errors,
				function ( $error ) use ( $cutoff_time ) {
					return isset( $error['timestamp'] ) && $error['timestamp'] >= $cutoff_time;
				}
			);
		}

		// Sort by timestamp descending.
		usort(
			$errors,
			function ( $a, $b ) {
				$a_time = isset( $a['timestamp'] ) ? $a['timestamp'] : 0;
				$b_time = isset( $b['timestamp'] ) ? $b['timestamp'] : 0;
				return $b_time - $a_time;
			}
		);

		return array_slice( $errors, 0, $limit );
	}

	/**
	 * Get error statistics by component.
	 *
	 * @param int $time_period Time period in seconds.
	 * @return array Statistics array.
	 */
	public function get_error_statistics( $time_period = 3600 ) {
		$errors = self::get_settings_repository()->get( 'error_tracking', array() );

		if ( ! is_array( $errors ) ) {
			return array();
		}

		$cutoff_time = current_time( 'timestamp' ) - $time_period;
		$stats       = array();

		foreach ( $errors as $error ) {
			if ( isset( $error['timestamp'] ) && $error['timestamp'] >= $cutoff_time ) {
				$component = isset( $error['component'] ) ? $error['component'] : 'unknown';

				if ( ! isset( $stats[ $component ] ) ) {
					$stats[ $component ] = array(
						'count'           => 0,
						'first_seen'      => $error['timestamp'],
						'last_seen'       => $error['timestamp'],
						'unique_messages' => array(),
					);
				}

				++$stats[ $component ]['count'];
				$stats[ $component ]['last_seen'] = max( $stats[ $component ]['last_seen'], $error['timestamp'] );

				if ( isset( $error['message'] ) ) {
					$stats[ $component ]['unique_messages'][ $error['message'] ] = true;
				}
			}
		}

		// Convert unique messages to count.
		foreach ( $stats as $component => $data ) {
			$stats[ $component ]['unique_message_count'] = count( $data['unique_messages'] );
			unset( $stats[ $component ]['unique_messages'] );
		}

		return $stats;
	}

	/**
	 * Estimate total requests for a component.
	 *
	 * This is a helper method to estimate requests when not explicitly tracked.
	 * Can be overridden by filters.
	 *
	 * @param string $component   Component name.
	 * @param int    $time_period Time period in seconds.
	 * @return int Estimated request count.
	 */
	private function estimate_total_requests( $component, $time_period ) {
		// Default estimation based on component type.
		$estimates = array(
			'rest_api'      => 100,  // Per hour baseline.
			'chat_ui'       => 50,
			'mcp_core'      => 200,
			'elementor'     => 30,
			'cpt_assistant' => 20,
			'cpt_ai_peer'   => 20,
		);

		$base_estimate = isset( $estimates[ $component ] ) ? $estimates[ $component ] : 50;

		// Scale based on time period (base is per hour).
		$hourly_rate = $time_period / 3600;
		$estimated   = absint( $base_estimate * $hourly_rate );

		// Allow filtering for custom estimation.
		return apply_filters( 'wp_mcp_ai_estimate_total_requests', $estimated, $component, $time_period );
	}

	/**
	 * Clean up old errors beyond retention period.
	 */
	public function cleanup_old_errors() {
		$errors = self::get_settings_repository()->get( 'error_tracking', array() );

		if ( ! is_array( $errors ) ) {
			return;
		}

		$cutoff_time = current_time( 'timestamp' ) - self::RETENTION_PERIOD;
		$filtered    = array();

		foreach ( $errors as $error ) {
			if ( isset( $error['timestamp'] ) && $error['timestamp'] >= $cutoff_time ) {
				$filtered[] = $error;
			}
		}

		self::get_settings_repository()->update( 'error_tracking', $filtered );
	}

	/**
	 * Invalidate error rate cache for a component.
	 *
	 * @param string $component Component name.
	 */
	private function invalidate_rate_cache( $component ) {
		$time_periods = array( 3600, 86400, 604800 ); // 1 hour, 1 day, 1 week.

		foreach ( $time_periods as $period ) {
			$cache_key = self::RATE_CACHE_KEY . $component . '_' . $period;
			delete_transient( $cache_key );
		}
	}

	/**
	 * Get client IP address.
	 *
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip = '';

		// Check various headers in order of preference.
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				break;
			}
		}

		return $ip;
	}

	/**
	 * Clear all tracked errors.
	 *
	 * @return bool True on success.
	 */
	public function clear_all_errors() {
		self::get_settings_repository()->delete( 'error_tracking' );

		// Clear all rate caches.
		$components = array( 'rest_api', 'chat_ui', 'mcp_core', 'elementor', 'cpt_assistant', 'cpt_ai_peer' );
		foreach ( $components as $component ) {
			$this->invalidate_rate_cache( $component );
		}

		return true;
	}

	/**
	 * Record error with performance metrics.
	 *
	 * This is a convenience method that both tracks the error and
	 * can optionally store it in the Performance Monitor CCT.
	 *
	 * @param string $component Component name.
	 * @param string $message   Error message.
	 * @param array  $context   Error context.
	 * @param bool   $store_in_cct Whether to store in Performance Monitor CCT.
	 * @return int Error ID.
	 */
	public function record_error_with_metrics( $component, $message, $context = array(), $store_in_cct = false ) {
		$error_id = $this->track_error( $component, $message, $context );

		if ( $store_in_cct && class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			$error_rate = $this->get_error_rate( $component, 3600 );
			$errors     = $this->get_errors_by_component( $component, 3600 );

			$metrics = array(
				'error_rate'    => $error_rate,
				'total_errors'  => count( $errors ),
				'error_message' => $message,
			);

			// Store as a monitoring test result.
			WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
				'monitoring',
				$component,
				false,
				$metrics,
				array(
					'error_id'      => $error_id,
					'error_context' => $context,
				)
			);
		}

		return $error_id;
	}
}

// Initialize the error tracking service.
add_action(
	'plugins_loaded',
	function () {
		WP_MCP_AI_Error_Tracking_Service::get_instance();
	},
	5
);
