<?php
/**
 * Health Check REST Endpoint for NV oOS.
 *
 * Provides a lightweight health-check endpoint for load balancers, Cloudways
 * monitoring, and Kubernetes liveness probes. Returns HTTP 200 with JSON
 * status when all critical subsystems are operational.
 *
 * Route: GET /wp-json/mcp-ai/v1/health
 *
 * @package   WP_MCP_AI
 * @since     1.1.37
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Health check REST controller.
 *
 * @since 1.1.37
 */
class WP_MCP_AI_REST_Health {

	/**
	 * Register the health check route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'mcp-ai/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'health_check' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Health check callback.
	 *
	 * Unauthenticated requests receive a lightweight liveness probe only
	 * ('status' => 'ok'/'degraded'). Detailed subsystem checks require a
	 * valid WordPress nonce or application password. This prevents information
	 * leakage about the server environment to unauthenticated callers.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public static function health_check( $request = null ) {
		$is_authenticated = is_user_logged_in() && current_user_can( 'manage_options' );

		if ( ! $is_authenticated ) {
			// Lightweight liveness check — no server details exposed.
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$db_ok = ( '1' === (string) $wpdb->get_var( 'SELECT 1' ) );

			return new WP_REST_Response(
				array( 'status' => $db_ok ? 'ok' : 'degraded' ),
				$db_ok ? 200 : 503
			);
		}

		$checks = array(
			'database' => self::check_database(),
			'php'      => self::check_php(),
		);

		// Add optional checks if the subsystems are available.
		if ( class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$checks['rabbitmq'] = self::check_rabbitmq();
		}

		$checks['cache_backend'] = self::check_cache_backend();
		$checks['queue']         = self::check_queue();

		// Determine overall status.
		$all_healthy = true;
		foreach ( $checks as $check ) {
			if ( isset( $check['status'] ) && 'healthy' !== $check['status'] && 'disabled' !== $check['status'] ) {
				$all_healthy = false;
				break;
			}
		}

		$response = array(
			'status'    => $all_healthy ? 'ok' : 'degraded',
			'timestamp' => time(),
			'version'   => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
			'checks'    => $checks,
		);

		$http_status = $all_healthy ? 200 : 503;

		return new WP_REST_Response( $response, $http_status );
	}

	/**
	 * Check database connectivity.
	 *
	 * @return array Check result.
	 */
	private static function check_database() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( 'SELECT 1' );

		if ( '1' === (string) $result ) {
			return array(
				'status'  => 'healthy',
				'message' => 'Database connection is healthy.',
			);
		}

		return array(
			'status'  => 'unhealthy',
			'message' => 'Database connection failed.',
		);
	}

	/**
	 * Check PHP environment.
	 *
	 * @return array Check result.
	 */
	private static function check_php() {
		return array(
			'status'        => 'healthy',
			'version'        => PHP_VERSION,
			'memory_limit'   => ini_get( 'memory_limit' ),
			'max_execution'  => ini_get( 'max_execution_time' ),
			'ext_amqp'       => extension_loaded( 'amqp' ),
			'ext_redis'      => extension_loaded( 'redis' ),
		);
	}

	/**
	 * Check RabbitMQ connectivity.
	 *
	 * @return array Check result.
	 */
	private static function check_rabbitmq() {
		try {
			$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
			$health = $client->health_check();

			if ( isset( $health['status'] ) && 'disabled' === $health['status'] ) {
				return array(
					'status'  => 'disabled',
					'message' => 'RabbitMQ integration is disabled.',
				);
			}

			if ( isset( $health['status'] ) && 'healthy' === $health['status'] ) {
				return array(
					'status'   => 'healthy',
					'message'  => 'RabbitMQ connection is healthy.',
					'host'     => isset( $health['connection']['host'] ) ? $health['connection']['host'] : 'unknown',
				);
			}

			return array(
				'status'  => 'unhealthy',
				'message' => isset( $health['error'] ) ? $health['error'] : 'RabbitMQ health check failed.',
			);
		} catch ( Exception $e ) {
			return array(
				'status'  => 'unhealthy',
				'message' => 'RabbitMQ check exception: ' . $e->getMessage(),
			);
		}
	}

	/**
	 * Check cache backend.
	 *
	 * @return array Check result.
	 */
	private static function check_cache_backend() {
		$using_ext_cache = wp_using_ext_object_cache();

		if ( $using_ext_cache ) {
			global $wp_object_cache;
			$backend = 'redis'; // Default assumption.

			if ( $wp_object_cache && method_exists( $wp_object_cache, 'redis_instance' ) ) {
				$backend = 'redis';
			} elseif ( $wp_object_cache && method_exists( $wp_object_cache, 'get_mc' ) ) {
				$backend = 'memcached';
			}

			return array(
				'status'  => 'healthy',
				'message' => 'Persistent object cache is active.',
				'backend' => $backend,
			);
		}

		return array(
			'status'  => 'degraded',
			'message' => 'No persistent object cache detected. Install Redis for optimal performance.',
			'backend' => 'database (wp_options)',
		);
	}

	/**
	 * Check queue subsystem health.
	 *
	 * @return array Check result.
	 */
	private static function check_queue() {
		$stats = array(
			'status'  => 'healthy',
			'message' => 'Queue subsystem is operational.',
		);

		if ( class_exists( 'WP_MCP_AI_Job_Queue_Manager' ) ) {
			$queue_stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();
			$stats['concurrent_queue'] = $queue_stats;
		}

		if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$dlq_stats = WP_MCP_AI_Dead_Letter_Queue::get_stats();
			$stats['dead_letter_queue'] = array(
				'total'     => $dlq_stats['total'],
				'active'    => $dlq_stats['active'],
				'dismissed' => $dlq_stats['dismissed'],
			);
		}

		return $stats;
	}
}
