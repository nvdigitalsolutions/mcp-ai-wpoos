<?php
/**
 * Site Health: Connection Pool Health Check
 *
 * Reports MySQL connection pool saturation, queue depth across all
 * transports, and RabbitMQ connection status through the WordPress
 * Site Health API.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Health Connection Pool class.
 *
 * Adds three Site Health tests:
 *   - MySQL connection pool saturation (Threads_connected / max_connections).
 *   - Queue depth across DB, Action Scheduler, and RabbitMQ transports.
 *   - RabbitMQ connection health (when WP_MCP_AI_RabbitMQ_Client is loaded).
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Site_Health_Connection_Pool {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'site_health_tests', array( __CLASS__, 'add_tests' ) );
	}

	/**
	 * Add connection pool tests to the Site Health direct tests.
	 *
	 * @param array $tests Existing tests keyed by type ('direct' or 'async').
	 * @return array Modified tests.
	 */
	public static function add_tests( $tests ) {
		$tests['direct']['wp_mcp_ai_mysql_connections'] = array(
			'label' => __( 'NV oOS — MySQL Connection Pool', 'mcp-ai-wpoos' ),
			'test'  => array( __CLASS__, 'test_mysql_connections' ),
		);

		$tests['direct']['wp_mcp_ai_queue_depth'] = array(
			'label' => __( 'NV oOS — Queue Depth', 'mcp-ai-wpoos' ),
			'test'  => array( __CLASS__, 'test_queue_depth' ),
		);

		if ( class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			$tests['direct']['wp_mcp_ai_rabbitmq_health'] = array(
				'label' => __( 'NV oOS — RabbitMQ Health', 'mcp-ai-wpoos' ),
				'test'  => array( __CLASS__, 'test_rabbitmq_health' ),
			);
		}

		return $tests;
	}

	// ─── Test: MySQL Connection Pool ─────────────────────────────────

	/**
	 * Test MySQL connection pool saturation.
	 *
	 * Queries SHOW STATUS for Threads_connected and @@max_connections.
	 * Reports critical (>80 %), recommended (>50 %), or good (≤50 %).
	 *
	 * @return array Site Health test result.
	 */
	public static function test_mysql_connections() {
		global $wpdb;

		$threads         = null;
		$max_connections = 151;

		// Try to get current thread count.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL system variables, not table data.
		$rows = $wpdb->get_results( "SHOW STATUS LIKE 'Threads_connected'", ARRAY_A );
		if ( is_array( $rows ) && ! empty( $rows ) ) {
			$threads = isset( $rows[0]['Value'] ) ? (int) $rows[0]['Value'] : null;
		}

		// Try to get max connections.
		$max_row = $wpdb->get_row( 'SELECT @@max_connections AS max_conn', ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( is_array( $max_row ) && isset( $max_row['max_conn'] ) ) {
			$max_connections = (int) $max_row['max_conn'];
		}

		// Degrade gracefully when permission or performance_schema is unavailable.
		if ( null === $threads ) {
			return array(
				'label'       => __( 'MySQL connection pool status cannot be determined', 'mcp-ai-wpoos' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Performance', 'mcp-ai-wpoos' ),
					'color' => 'orange',
				),
				'description' => sprintf(
					'<p>%s</p>',
					__( 'Could not query MySQL connection status. Ensure the database user has the PROCESS privilege or performance_schema access.', 'mcp-ai-wpoos' )
				),
				'test'        => 'wp_mcp_ai_mysql_connections',
			);
		}

		$usage_pct = $max_connections > 0
			? round( ( $threads / $max_connections ) * 100, 1 )
			: 0;

		if ( $usage_pct > 80 ) {
			$status = 'critical';
			$color  = 'red';
		} elseif ( $usage_pct > 50 ) {
			$status = 'recommended';
			$color  = 'orange';
		} else {
			$status = 'good';
			$color  = 'blue';
		}

		return array(
			'label'       => sprintf(
				/* translators: 1=current connections, 2=max connections, 3=percentage */
				__( 'MySQL connections: %1$d of %2$d (%3$s%%)', 'mcp-ai-wpoos' ),
				$threads,
				$max_connections,
				$usage_pct
			),
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'mcp-ai-wpoos' ),
				'color' => $color,
			),
			'description' => self::render_connection_advice( $threads, $max_connections, $usage_pct ),
			'test'        => 'wp_mcp_ai_mysql_connections',
		);
	}

	/**
	 * Render connection pool advice based on current saturation level.
	 *
	 * @param int   $threads    Current connected threads.
	 * @param int   $max        Maximum allowed connections.
	 * @param float $usage_pct  Usage percentage.
	 * @return string HTML advice block.
	 */
	private static function render_connection_advice( $threads, $max, $usage_pct ) {
		$lines = array();

		$lines[] = sprintf(
			'<p>%s</p>',
			sprintf(
				/* translators: 1=current threads, 2=max connections, 3=percentage */
				__( 'Your MySQL server has %1$d active connections out of %2$d maximum (%3$s%%).', 'mcp-ai-wpoos' ),
				$threads,
				$max,
				$usage_pct
			)
		);

		if ( $usage_pct > 80 ) {
			$lines[] = sprintf(
				'<p><strong>%s</strong></p>',
				__( 'Connection pool is critically saturated. Recommended actions:', 'mcp-ai-wpoos' )
			);
			$lines[] = '<ul>';
			$lines[] = '<li>' . esc_html__( 'Enable RabbitMQ to offload job processing from the database.', 'mcp-ai-wpoos' ) . '</li>';
			$lines[] = '<li>' . esc_html__( 'Install Redis Object Cache to reduce database read load.', 'mcp-ai-wpoos' ) . '</li>';
			$lines[] = '<li>' . esc_html__( 'Increase MySQL max_connections in your server settings.', 'mcp-ai-wpoos' ) . '</li>';
			$lines[] = '<li>' . esc_html__( 'Enable the dedicated queue worker daemon (--daemon mode) to reduce per-request connection overhead.', 'mcp-ai-wpoos' ) . '</li>';
			$lines[] = '</ul>';
		} elseif ( $usage_pct > 50 ) {
			$lines[] = sprintf(
				'<p>%s</p>',
				__( 'Connection pool usage is moderate. Monitor for growth, especially during peak traffic.', 'mcp-ai-wpoos' )
			);
		} else {
			// Healthy: usage at or below 50% (including the 0-1% corner case).
			$lines[] = sprintf(
				'<p>%s</p>',
				__( 'Connection pool usage is healthy.', 'mcp-ai-wpoos' )
			);
		}

		return implode( "\n", $lines );
	}

	// ─── Test: Queue Depth ──────────────────────────────────────────

	/**
	 * Test queue depth across all transport layers.
	 *
	 * Checks pending jobs in the DB queue, Action Scheduler, and
	 * RabbitMQ (when available). Flags any transport whose backlog
	 * exceeds reasonable thresholds.
	 *
	 * @return array Site Health test result.
	 */
	public static function test_queue_depth() {
		global $wpdb;

		$issues = array();

		// ── DB queue (mcp_ai_concurrent_jobs) ────────────────────
		$db_table = $wpdb->prefix . 'mcp_ai_concurrent_jobs';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from constant; custom plugin table.
		$db_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$db_table
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$db_pending = 0;
		if ( $db_exists ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = $wpdb->get_var(
				"SELECT COUNT(*) FROM {$db_table} WHERE status = 'pending'"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$db_pending = $count ? (int) $count : 0;
		}

		if ( $db_pending > 50 ) {
			$issues[] = sprintf(
				/* translators: %d: number of pending jobs */
				__( '%d pending jobs in DB queue — consider enabling RabbitMQ or increasing batch size.', 'mcp-ai-wpoos' ),
				$db_pending
			);
		}

		// ── Action Scheduler ─────────────────────────────────────
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$as_pending = as_get_scheduled_actions(
				array(
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'group'    => 'wp_mcp_ai',
					'per_page' => 101, // Get one extra to see if we're over threshold.
				)
			);
			$as_count   = is_array( $as_pending ) ? count( $as_pending ) : 0;

			if ( $as_count > 100 ) {
				$issues[] = sprintf(
					/* translators: %d: number of pending actions */
					__( '%d pending Action Scheduler jobs in the wp_mcp_ai group.', 'mcp-ai-wpoos' ),
					$as_count
				);
			}
		}

		// ── RabbitMQ ─────────────────────────────────────────────
		if ( class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			try {
				$rmq = WP_MCP_AI_RabbitMQ_Client::get_instance();
				if ( $rmq->is_available() ) {
					$stats = $rmq->get_queue_stats();
					foreach ( $stats['queues'] ?? array() as $name => $info ) {
						if ( ! empty( $info['messages'] ) && (int) $info['messages'] > 100 ) {
							$issues[] = sprintf(
								/* translators: 1=queue name, 2=message count */
								__( 'RabbitMQ queue "%1$s" has %2$d messages.', 'mcp-ai-wpoos' ),
								$name,
								$info['messages']
							);
						}
					}
				}
			} catch ( Exception $e ) {
				// Non-critical — queue stats are informational only.
				unset( $e );
			}
		}

		// ── Build result ─────────────────────────────────────────
		$description_parts = array();

		$description_parts[] = sprintf(
			'<p>%s</p>',
			sprintf(
				/* translators: %d: pending DB jobs */
				__( 'DB queue: %d pending jobs.', 'mcp-ai-wpoos' ),
				$db_pending
			)
		);

		if ( empty( $issues ) ) {
			$description_parts[] = sprintf(
				'<p>%s</p>',
				__( 'No transport queues are backed up. The system is processing jobs promptly.', 'mcp-ai-wpoos' )
			);

			return array(
				'label'       => __( 'Queue depths are healthy', 'mcp-ai-wpoos' ),
				'status'      => 'good',
				'badge'       => array(
					'label' => __( 'Performance', 'mcp-ai-wpoos' ),
					'color' => 'blue',
				),
				'description' => implode( "\n", $description_parts ),
				'test'        => 'wp_mcp_ai_queue_depth',
			);
		}

		$description_parts[] = '<p>' . implode( '</p><p>', array_map( 'esc_html', $issues ) ) . '</p>';

		return array(
			'label'       => __( 'Queue depth requires attention', 'mcp-ai-wpoos' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'mcp-ai-wpoos' ),
				'color' => 'orange',
			),
			'description' => implode( "\n", $description_parts ),
			'test'        => 'wp_mcp_ai_queue_depth',
		);
	}

	// ─── Test: RabbitMQ Health ─────────────────────────────────────

	/**
	 * Test RabbitMQ connection health.
	 *
	 * Delegates to WP_MCP_AI_RabbitMQ_Client::health_check() and
	 * reports the connection status, host, and port.
	 *
	 * @return array Site Health test result.
	 */
	public static function test_rabbitmq_health() {
		if ( ! class_exists( 'WP_MCP_AI_RabbitMQ_Client' ) ) {
			return array(
				'label'       => __( 'RabbitMQ client is not available', 'mcp-ai-wpoos' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Performance', 'mcp-ai-wpoos' ),
					'color' => 'gray',
				),
				'description' => '<p>' . esc_html__( 'The RabbitMQ client class could not be loaded.', 'mcp-ai-wpoos' ) . '</p>',
				'test'        => 'wp_mcp_ai_rabbitmq_health',
			);
		}

		try {
			$client = WP_MCP_AI_RabbitMQ_Client::get_instance();
			$health = $client->health_check();

			if ( 'disabled' === $health['status'] ) {
				return array(
					'label'       => __( 'RabbitMQ is disabled', 'mcp-ai-wpoos' ),
					'status'      => 'good',
					'badge'       => array(
						'label' => __( 'Performance', 'mcp-ai-wpoos' ),
						'color' => 'gray',
					),
					'description' => '<p>' . esc_html__( 'RabbitMQ integration is not enabled. Consider enabling it for better async job processing on Cloudways.', 'mcp-ai-wpoos' ) . '</p>',
					'test'        => 'wp_mcp_ai_rabbitmq_health',
				);
			}

			if ( 'extension_missing' === $health['status'] ) {
				return array(
					'label'       => __( 'PHP AMQP extension is missing', 'mcp-ai-wpoos' ),
					'status'      => 'recommended',
					'badge'       => array(
						'label' => __( 'Performance', 'mcp-ai-wpoos' ),
						'color' => 'orange',
					),
					'description' => '<p>' . esc_html__( 'The PHP AMQP extension is not loaded. Enable RabbitMQ on your Cloudways server to install this extension.', 'mcp-ai-wpoos' ) . '</p>',
					'test'        => 'wp_mcp_ai_rabbitmq_health',
				);
			}

			if ( 'healthy' === $health['status'] ) {
				$host_port = ( isset( $health['connection']['host'] ) ? $health['connection']['host'] : 'unknown' )
					. ':' . ( isset( $health['connection']['port'] ) ? $health['connection']['port'] : '5672' );

				$dedicated = get_option( 'wp_mcp_ai_queue_worker_dedicated', false );
				$heartbeat = get_transient( 'wp_mcp_ai_queue_worker_heartbeat' );

				$extra = '';
				if ( $dedicated ) {
					$extra .= '<p>' . esc_html__( 'Dedicated queue worker: Configured.', 'mcp-ai-wpoos' ) . '</p>';
					if ( false !== $heartbeat ) {
						$ago    = human_time_diff( (int) $heartbeat, time() );
						$extra .= '<p>' . sprintf(
							/* translators: %s: human-readable time ago */
							esc_html__( 'Last worker heartbeat: %s ago.', 'mcp-ai-wpoos' ),
							$ago
						) . '</p>';
					} else {
						$extra .= '<p>' . esc_html__( 'No worker heartbeat detected — ensure the queue worker binary is running.', 'mcp-ai-wpoos' ) . '</p>';
					}
				} else {
					$extra .= '<p>' . esc_html__( 'Dedicated queue worker: Not configured. Action Scheduler will be used as fallback.', 'mcp-ai-wpoos' ) . '</p>';
				}

				return array(
					'label'       => __( 'RabbitMQ is connected and healthy', 'mcp-ai-wpoos' ),
					'status'      => 'good',
					'badge'       => array(
						'label' => __( 'Performance', 'mcp-ai-wpoos' ),
						'color' => 'blue',
					),
					'description' => '<p>' . sprintf(
						/* translators: %s: host:port */
						esc_html__( 'Connected to RabbitMQ at %s.', 'mcp-ai-wpoos' ),
						esc_html( $host_port )
					) . '</p>' . $extra,
					'test'        => 'wp_mcp_ai_rabbitmq_health',
				);
			}

			// Connection failed or unknown status.
			$error_msg = isset( $health['error'] ) ? $health['error'] : $health['status'];

			return array(
				'label'       => __( 'RabbitMQ connection issue', 'mcp-ai-wpoos' ),
				'status'      => 'critical',
				'badge'       => array(
					'label' => __( 'Performance', 'mcp-ai-wpoos' ),
					'color' => 'red',
				),
				'description' => '<p>' . sprintf(
					/* translators: %s: error or status message */
					esc_html__( 'RabbitMQ status: %s.', 'mcp-ai-wpoos' ),
					esc_html( $error_msg )
				) . '</p><p>' . esc_html__( 'Check RabbitMQ settings in NV oOS → Orchestration → RabbitMQ.', 'mcp-ai-wpoos' ) . '</p>',
				'test'        => 'wp_mcp_ai_rabbitmq_health',
			);
		} catch ( Exception $e ) {
			return array(
				'label'       => __( 'RabbitMQ health check failed', 'mcp-ai-wpoos' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Performance', 'mcp-ai-wpoos' ),
					'color' => 'orange',
				),
				'description' => '<p>' . esc_html( $e->getMessage() ) . '</p>',
				'test'        => 'wp_mcp_ai_rabbitmq_health',
			);
		}
	}
}
