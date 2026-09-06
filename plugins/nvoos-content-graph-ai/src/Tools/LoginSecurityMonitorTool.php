<?php
/**
 * Login Security Monitor tool (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Tool_Login_Security_Monitor — byte-identical slug, schema,
 * error codes, envelope, threat heuristics, and recommendations;
 * per-mode hook/cache seams via WordPressNativeTrait).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Monitors login attempts and detects security threats including brute
 * force attacks, suspicious patterns, and geographic anomalies.
 */
class LoginSecurityMonitorTool extends AbstractAiTool {

	use WordPressNativeTrait;

	public function getSlug(): string {
		return 'login_security_monitor';
	}

	public function getName(): string {
		return __( 'Login Security Monitor', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Monitors login attempts and detects security threats including brute force attacks, suspicious patterns, and geographic anomalies.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'time_period'      => array(
					'type'        => 'string',
					'description' => __( 'Time period to analyze: 1hour, 24hours, 7days, 30days, or custom', 'nvoos-content-graph-ai' ),
					'default'     => '24hours',
					'enum'        => array( '1hour', '24hours', '7days', '30days', 'custom' ),
				),
				'start_date'       => array(
					'type'        => 'string',
					'description' => __( 'Start date for custom period (Y-m-d format)', 'nvoos-content-graph-ai' ),
					'required'    => false,
				),
				'end_date'         => array(
					'type'        => 'string',
					'description' => __( 'End date for custom period (Y-m-d format)', 'nvoos-content-graph-ai' ),
					'required'    => false,
				),
				'username'         => array(
					'type'        => 'string',
					'description' => __( 'Filter by specific username', 'nvoos-content-graph-ai' ),
					'required'    => false,
				),
				'ip_address'       => array(
					'type'        => 'string',
					'description' => __( 'Filter by specific IP address', 'nvoos-content-graph-ai' ),
					'required'    => false,
				),
				'threats_only'     => array(
					'type'        => 'boolean',
					'description' => __( 'Show only suspicious/threat activity', 'nvoos-content-graph-ai' ),
					'default'     => false,
				),
				'include_analysis' => array(
					'type'        => 'boolean',
					'description' => __( 'Include AI-powered threat analysis', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'manage_options';
	}

	public function getCapabilityFlags(): array {
		return array( 'requires-capability', 'read-only', 'local-only', 'cacheable' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view login security data.', 'nvoos-content-graph-ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Validate parameters (two-gate: sanitize at entry).
		$time_period      = isset( $arguments['time_period'] ) ? sanitize_text_field( $arguments['time_period'] ) : '24hours';
		$start_date       = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date         = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$username         = isset( $arguments['username'] ) ? sanitize_user( $arguments['username'] ) : '';
		$ip_address       = isset( $arguments['ip_address'] ) ? sanitize_text_field( $arguments['ip_address'] ) : '';
		$threats_only     = isset( $arguments['threats_only'] ) ? (bool) $arguments['threats_only'] : false;
		$include_analysis = isset( $arguments['include_analysis'] ) ? (bool) $arguments['include_analysis'] : true;

		// Before execution hook (base-identical).
		$intercepted = $this->do_before_execute( $arguments, $context );
		if ( null !== $intercepted ) {
			return $intercepted;
		}

		// Check cache (base-identical key format).
		$cached = $this->get_cached_result( $arguments );
		if ( false !== $cached ) {
			return $cached;
		}

		$time_range = $this->calculate_time_range( $time_period, $start_date, $end_date );
		if ( is_wp_error( $time_range ) ) {
			return $time_range;
		}

		$login_data = $this->collect_login_data( $time_range, $username, $ip_address );

		$threat_analysis = $this->analyze_threats( $login_data, $threats_only );

		$recommendations = $this->generate_recommendations( $threat_analysis );

		$ai_insights = array();
		if ( $include_analysis && ! empty( $threat_analysis['threats'] ) ) {
			$ai_insights = $this->generate_ai_insights( $threat_analysis, $context );
		}

		$result = array(
			'success'         => true,
			'time_range'      => $time_range,
			'summary'         => array(
				'total_attempts'    => $login_data['total'],
				'successful_logins' => $login_data['successful'],
				'failed_attempts'   => $login_data['failed'],
				'blocked_attempts'  => $login_data['blocked'],
				'unique_users'      => $login_data['unique_users'],
				'unique_ips'        => $login_data['unique_ips'],
				'threat_level'      => $threat_analysis['threat_level'],
				'risk_score'        => $threat_analysis['risk_score'],
			),
			'threats'         => $threat_analysis['threats'],
			'recommendations' => $recommendations,
		);

		if ( ! empty( $ai_insights ) ) {
			$result['ai_insights'] = $ai_insights;
		}

		// Cache result (short duration for security data).
		$this->set_cached_result( $arguments, $result, 300 ); // 5 minutes.

		// After execution hook (base-identical).
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Calculate time range (base-identical).
	 *
	 * @param string $period     Time period.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array|\WP_Error Time range or error.
	 */
	private function calculate_time_range( $period, $start_date, $end_date ) {
		$now = time();

		if ( 'custom' === $period ) {
			if ( empty( $start_date ) || empty( $end_date ) ) {
				return new \WP_Error(
					'invalid_dates',
					__( 'Start and end dates required for custom period', 'nvoos-content-graph-ai' )
				);
			}

			$start = strtotime( $start_date );
			$end   = strtotime( $end_date );

			if ( false === $start || false === $end ) {
				return new \WP_Error(
					'invalid_date_format',
					__( 'Invalid date format. Use Y-m-d format', 'nvoos-content-graph-ai' )
				);
			}
		} else {
			$intervals = array(
				'1hour'   => HOUR_IN_SECONDS,
				'24hours' => DAY_IN_SECONDS,
				'7days'   => WEEK_IN_SECONDS,
				'30days'  => MONTH_IN_SECONDS,
			);

			$interval = isset( $intervals[ $period ] ) ? $intervals[ $period ] : DAY_IN_SECONDS;
			$start    = $now - $interval;
			$end      = $now;
		}

		return array(
			'start'      => $start,
			'end'        => $end,
			'start_date' => gmdate( 'Y-m-d H:i:s', $start ),
			'end_date'   => gmdate( 'Y-m-d H:i:s', $end ),
			'duration'   => $end - $start,
		);
	}

	/**
	 * Collect login data from security plugins or WordPress user meta.
	 *
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array Login data.
	 */
	private function collect_login_data( $time_range, $username = '', $ip_address = '' ) {
		$data = array(
			'total'        => 0,
			'successful'   => 0,
			'failed'       => 0,
			'blocked'      => 0,
			'unique_users' => 0,
			'unique_ips'   => 0,
			'attempts'     => array(),
		);

		$plugin_data = $this->get_security_plugin_data( $time_range, $username, $ip_address );
		if ( ! empty( $plugin_data ) ) {
			return $plugin_data;
		}

		return $this->get_wordpress_login_data( $time_range, $username, $ip_address );
	}

	/**
	 * Get security plugin data (Wordfence → iThemes → WPS Hide Login).
	 *
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array|null Security plugin data or null.
	 */
	private function get_security_plugin_data( $time_range, $username, $ip_address ) {
		if ( class_exists( 'wordfence' ) ) {
			return $this->get_wordfence_data( $time_range, $username, $ip_address );
		}

		if ( function_exists( 'itsec_get_logs' ) ) {
			return $this->get_ithemes_data( $time_range, $username, $ip_address );
		}

		if ( function_exists( 'wps_hide_login_get_logs' ) ) {
			return $this->get_wps_hide_login_data( $time_range, $username, $ip_address );
		}

		return null;
	}

	/**
	 * Get WordPress native login data from user meta (base-identical).
	 *
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array Login data.
	 */
	private function get_wordpress_login_data( $time_range, $username, $ip_address ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameters reserved for future implementation.
		global $wpdb;

		$data = array(
			'total'        => 0,
			'successful'   => 0,
			'failed'       => 0,
			'blocked'      => 0,
			'unique_users' => 0,
			'unique_ips'   => 0,
			'attempts'     => array(),
		);

		$meta_query = "
			SELECT user_id, meta_key, meta_value
			FROM {$wpdb->usermeta}
			WHERE meta_key IN ('last_login', 'login_count', 'failed_login_count')
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Hardcoded SQL with no user input; security audit requires real-time data.
		$results = $wpdb->get_results( $meta_query );

		if ( empty( $results ) ) {
			return $data;
		}

		$users_data = array();
		foreach ( $results as $row ) {
			$user_id = (int) $row->user_id;
			if ( ! isset( $users_data[ $user_id ] ) ) {
				$users_data[ $user_id ] = array();
			}
			$users_data[ $user_id ][ $row->meta_key ] = $row->meta_value;
		}

		foreach ( $users_data as $user_id => $user_meta ) {
			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			if ( ! empty( $username ) && $user->user_login !== $username ) {
				continue;
			}

			$last_login = isset( $user_meta['last_login'] ) ? (int) $user_meta['last_login'] : 0;

			if ( $last_login >= $time_range['start'] && $last_login <= $time_range['end'] ) {
				++$data['successful'];
				++$data['total'];

				$data['attempts'][] = array(
					'timestamp'  => $last_login,
					'username'   => $user->user_login,
					'status'     => 'success',
					'ip_address' => __( 'Unknown', 'nvoos-content-graph-ai' ),
				);
			}
		}

		$data['unique_users'] = count( array_unique( wp_list_pluck( $data['attempts'], 'username' ) ) );
		$data['unique_ips']   = count( array_unique( wp_list_pluck( $data['attempts'], 'ip_address' ) ) );

		return $data;
	}

	/**
	 * Get Wordfence login data (base-identical query and shaping).
	 *
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array Wordfence data.
	 */
	private function get_wordfence_data( $time_range, $username, $ip_address ) {
		global $wpdb;

		$data = array(
			'total'        => 0,
			'successful'   => 0,
			'failed'       => 0,
			'blocked'      => 0,
			'unique_users' => 0,
			'unique_ips'   => 0,
			'attempts'     => array(),
		);

		$table_name = $wpdb->prefix . 'wfLogins';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return $data;
		}

		$where_clauses = array( 'ctime BETWEEN %d AND %d' );
		$query_params  = array( $time_range['start'], $time_range['end'] );

		if ( ! empty( $username ) ) {
			$where_clauses[] = 'username = %s';
			$query_params[]  = $username;
		}

		if ( ! empty( $ip_address ) ) {
			$where_clauses[] = 'IP = %s';
			$query_params[]  = $ip_address;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are in dynamic $where_sql built from hardcoded strings; spread operator used for values.
			$wpdb->prepare( 'SELECT ctime, fail, username, IP, blocked FROM `' . esc_sql( $table_name ) . "` WHERE {$where_sql} ORDER BY ctime DESC LIMIT 500", ...$query_params ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return $data;
		}

		foreach ( $rows as $row ) {
			++$data['total'];

			$is_failed  = ! empty( $row['fail'] );
			$is_blocked = ! empty( $row['blocked'] );

			if ( $is_blocked ) {
				++$data['blocked'];
			} elseif ( $is_failed ) {
				++$data['failed'];
			} else {
				++$data['successful'];
			}

			$data['attempts'][] = array(
				'timestamp'  => (int) $row['ctime'],
				'username'   => sanitize_text_field( $row['username'] ),
				'status'     => $is_blocked ? 'blocked' : ( $is_failed ? 'failed' : 'success' ),
				'ip_address' => sanitize_text_field( isset( $row['IP'] ) ? $row['IP'] : '' ),
			);
		}

		$data['unique_users'] = count( array_unique( wp_list_pluck( $data['attempts'], 'username' ) ) );
		$data['unique_ips']   = count( array_unique( array_filter( wp_list_pluck( $data['attempts'], 'ip_address' ) ) ) );

		return $data;
	}

	/**
	 * Get iThemes Security data (base-identical log API + table fallback).
	 *
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array iThemes Security data.
	 */
	private function get_ithemes_data( $time_range, $username, $ip_address ) {
		global $wpdb;

		$data = array(
			'total'        => 0,
			'successful'   => 0,
			'failed'       => 0,
			'blocked'      => 0,
			'unique_users' => 0,
			'unique_ips'   => 0,
			'attempts'     => array(),
		);

		if ( class_exists( 'ITSEC_Log' ) && method_exists( 'ITSEC_Log', 'get_logs' ) ) {
			$args = array(
				'module'      => 'brute-force',
				'after_time'  => gmdate( 'Y-m-d H:i:s', $time_range['start'] ),
				'before_time' => gmdate( 'Y-m-d H:i:s', $time_range['end'] ),
			);
			if ( ! empty( $username ) ) {
				$args['username'] = $username;
			}
			$logs = ITSEC_Log::get_logs( $args );
			foreach ( (array) $logs as $log ) {
				$log_ip   = isset( $log['remote_ip'] ) ? sanitize_text_field( $log['remote_ip'] ) : '';
				$log_user = isset( $log['username'] ) ? sanitize_text_field( $log['username'] ) : '';

				if ( ! empty( $ip_address ) && $log_ip !== $ip_address ) {
					continue;
				}

				++$data['total'];
				$status     = isset( $log['type'] ) ? sanitize_text_field( $log['type'] ) : 'info';
				$is_blocked = 'critical' === $status;

				$raw_data  = isset( $log['data'] ) ? $log['data'] : '';
				$log_data  = is_string( $raw_data ) ? json_decode( $raw_data, true ) : array();
				$log_data  = is_array( $log_data ) ? $log_data : array();
				$is_failed = 'error' === $status || isset( $log_data['fail'] );

				if ( $is_blocked ) {
					++$data['blocked'];
				} elseif ( $is_failed ) {
					++$data['failed'];
				} else {
					++$data['successful'];
				}
				$data['attempts'][] = array(
					'timestamp'  => isset( $log['timestamp'] ) ? strtotime( $log['timestamp'] ) : 0,
					'username'   => $log_user,
					'status'     => $is_blocked ? 'blocked' : ( $is_failed ? 'failed' : 'success' ),
					'ip_address' => $log_ip,
				);
			}
			$data['unique_users'] = count( array_unique( wp_list_pluck( $data['attempts'], 'username' ) ) );
			$data['unique_ips']   = count( array_unique( array_filter( wp_list_pluck( $data['attempts'], 'ip_address' ) ) ) );
			return $data;
		}

		$table_name = $wpdb->prefix . 'itsec_logs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return $data;
		}

		$start_date = gmdate( 'Y-m-d H:i:s', $time_range['start'] );
		$end_date   = gmdate( 'Y-m-d H:i:s', $time_range['end'] );

		$where_clauses = array( 'timestamp BETWEEN %s AND %s' );
		$query_params  = array( $start_date, $end_date );

		if ( ! empty( $username ) ) {
			$where_clauses[] = 'username = %s';
			$query_params[]  = $username;
		}

		if ( ! empty( $ip_address ) ) {
			$where_clauses[] = 'remote_ip = %s';
			$query_params[]  = $ip_address;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are in dynamic $where_sql built from hardcoded strings; spread operator used for values.
			$wpdb->prepare( 'SELECT timestamp, type, username, remote_ip, module FROM `' . esc_sql( $table_name ) . "` WHERE {$where_sql} ORDER BY timestamp DESC LIMIT 500", ...$query_params ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return $data;
		}

		foreach ( $rows as $row ) {
			++$data['total'];

			$status     = sanitize_text_field( isset( $row['type'] ) ? $row['type'] : 'info' );
			$is_blocked = 'critical' === $status;
			$is_failed  = 'error' === $status;

			if ( $is_blocked ) {
				++$data['blocked'];
			} elseif ( $is_failed ) {
				++$data['failed'];
			} else {
				++$data['successful'];
			}

			$data['attempts'][] = array(
				'timestamp'  => isset( $row['timestamp'] ) ? strtotime( $row['timestamp'] ) : 0,
				'username'   => sanitize_text_field( isset( $row['username'] ) ? $row['username'] : '' ),
				'status'     => $is_blocked ? 'blocked' : ( $is_failed ? 'failed' : 'success' ),
				'ip_address' => sanitize_text_field( isset( $row['remote_ip'] ) ? $row['remote_ip'] : '' ),
			);
		}

		$data['unique_users'] = count( array_unique( wp_list_pluck( $data['attempts'], 'username' ) ) );
		$data['unique_ips']   = count( array_unique( array_filter( wp_list_pluck( $data['attempts'], 'ip_address' ) ) ) );

		return $data;
	}

	/**
	 * Get WPS Hide Login data (no per-attempt log — base-identical).
	 *
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array Empty login data.
	 */
	private function get_wps_hide_login_data( $time_range, $username, $ip_address ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Parameters reserved; WPS Hide Login does not log individual login attempts.
		// WPS Hide Login changes the login URL but does not track attempts.
		$this->log( 'info', 'WPS Hide Login detected; using WordPress native login data as WPS Hide Login does not track login attempts directly' );

		return array(
			'total'        => 0,
			'successful'   => 0,
			'failed'       => 0,
			'blocked'      => 0,
			'unique_users' => 0,
			'unique_ips'   => 0,
			'attempts'     => array(),
		);
	}

	/**
	 * Analyze threats (base-identical heuristics and scoring).
	 *
	 * @param array $login_data   Login data.
	 * @param bool  $threats_only Show only threats.
	 * @return array Threat analysis.
	 */
	private function analyze_threats( $login_data, $threats_only = false ) {
		$threats    = array();
		$risk_score = 0;
		$max_score  = 100;

		if ( $login_data['failed'] > 20 ) {
			$severity     = $login_data['failed'] > 100 ? 'critical' : 'high';
			$threat_score = $login_data['failed'] > 100 ? 40 : 20;
			$risk_score  += $threat_score;

			$threats[] = array(
				'type'        => 'brute_force',
				'severity'    => $severity,
				'description' => sprintf(
					/* translators: %d: number of failed attempts */
					__( 'High number of failed login attempts detected: %d', 'nvoos-content-graph-ai' ),
					$login_data['failed']
				),
				'score'       => $threat_score,
			);
		}

		if ( $login_data['unique_ips'] > 50 ) {
			$severity     = 'medium';
			$threat_score = 15;
			$risk_score  += $threat_score;

			$threats[] = array(
				'type'        => 'distributed_attack',
				'severity'    => $severity,
				'description' => sprintf(
					/* translators: %d: number of unique IPs */
					__( 'Login attempts from %d unique IP addresses', 'nvoos-content-graph-ai' ),
					$login_data['unique_ips']
				),
				'score'       => $threat_score,
			);
		}

		if ( $login_data['successful'] > 10 && $login_data['unique_ips'] > 20 ) {
			$severity     = 'high';
			$threat_score = 25;
			$risk_score  += $threat_score;

			$threats[] = array(
				'type'        => 'credential_stuffing',
				'severity'    => $severity,
				'description' => __( 'Possible credential stuffing attack detected', 'nvoos-content-graph-ai' ),
				'score'       => $threat_score,
			);
		}

		$threat_level = 'low';
		if ( $risk_score > 60 ) {
			$threat_level = 'critical';
		} elseif ( $risk_score > 40 ) {
			$threat_level = 'high';
		} elseif ( $risk_score > 20 ) {
			$threat_level = 'medium';
		}

		if ( $threats_only && empty( $threats ) ) {
			$threats = array();
		}

		return array(
			'threats'      => $threats,
			'threat_level' => $threat_level,
			'risk_score'   => min( $risk_score, $max_score ),
		);
	}

	/**
	 * Generate recommendations (base-identical).
	 *
	 * @param array $threat_analysis Threat analysis.
	 * @return array Recommendations.
	 */
	private function generate_recommendations( $threat_analysis ) {
		$recommendations = array();

		if ( empty( $threat_analysis['threats'] ) ) {
			$recommendations[] = array(
				'priority'    => 'low',
				'action'      => __( 'Continue monitoring', 'nvoos-content-graph-ai' ),
				'description' => __( 'No immediate threats detected. Continue regular security monitoring.', 'nvoos-content-graph-ai' ),
			);
			return $recommendations;
		}

		foreach ( $threat_analysis['threats'] as $threat ) {
			switch ( $threat['type'] ) {
				case 'brute_force':
					$recommendations[] = array(
						'priority'    => 'high',
						'action'      => __( 'Enable rate limiting', 'nvoos-content-graph-ai' ),
						'description' => __( 'Implement login rate limiting to prevent brute force attacks. Consider using Wordfence or Limit Login Attempts.', 'nvoos-content-graph-ai' ),
					);
					$recommendations[] = array(
						'priority'    => 'high',
						'action'      => __( 'Enable 2FA', 'nvoos-content-graph-ai' ),
						'description' => __( 'Require two-factor authentication for administrator accounts.', 'nvoos-content-graph-ai' ),
					);
					break;

				case 'distributed_attack':
					$recommendations[] = array(
						'priority'    => 'medium',
						'action'      => __( 'Enable geo-blocking', 'nvoos-content-graph-ai' ),
						'description' => __( 'Consider blocking login attempts from suspicious geographic locations.', 'nvoos-content-graph-ai' ),
					);
					$recommendations[] = array(
						'priority'    => 'medium',
						'action'      => __( 'Implement CAPTCHA', 'nvoos-content-graph-ai' ),
						'description' => __( 'Add CAPTCHA to login form to prevent automated attacks.', 'nvoos-content-graph-ai' ),
					);
					break;

				case 'credential_stuffing':
					$recommendations[] = array(
						'priority'    => 'critical',
						'action'      => __( 'Force password reset', 'nvoos-content-graph-ai' ),
						'description' => __( 'Force password reset for all users, especially administrators.', 'nvoos-content-graph-ai' ),
					);
					$recommendations[] = array(
						'priority'    => 'critical',
						'action'      => __( 'Check for compromised credentials', 'nvoos-content-graph-ai' ),
						'description' => __( 'Check user credentials against known breach databases.', 'nvoos-content-graph-ai' ),
					);
					break;
			}
		}

		return $recommendations;
	}

	/**
	 * Generate AI insights (base-identical contract; context ai_client).
	 *
	 * @param array $threat_analysis Threat analysis.
	 * @param array $context         Execution context.
	 * @return array AI insights.
	 */
	private function generate_ai_insights( $threat_analysis, $context ) {
		if ( empty( $context['ai_client'] ) ) {
			return array(
				'available' => false,
				'message'   => __( 'AI analysis not available', 'nvoos-content-graph-ai' ),
			);
		}

		$ai_client = $context['ai_client'];

		$prompt = sprintf(
			"Analyze the following login security threats and provide actionable insights:\n\nThreat Level: %s\nRisk Score: %d/100\n\nThreats:\n%s\n\nProvide:\n1. Detailed threat assessment\n2. Immediate action items\n3. Long-term security improvements",
			$threat_analysis['threat_level'],
			$threat_analysis['risk_score'],
			wp_json_encode( $threat_analysis['threats'], JSON_PRETTY_PRINT )
		);

		try {
			$response = $ai_client->generate_completion(
				array(
					'prompt'      => $prompt,
					'max_tokens'  => 500,
					'temperature' => 0.3,
				)
			);

			return array(
				'available' => true,
				'analysis'  => $response['content'] ?? '',
			);
		} catch ( \Exception $e ) {
			$this->log( 'error', 'AI insights generation failed: ' . $e->getMessage() );
			return array(
				'available' => false,
				'error'     => $e->getMessage(),
			);
		}
	}

	/**
	 * Whether the tool holds privacy-relevant user data (base-identical).
	 *
	 * @return bool True.
	 */
	public function has_privacy_data() {
		return true;
	}

	/**
	 * Export privacy data (base-identical).
	 *
	 * @param int $user_id User ID.
	 * @return array Privacy data.
	 */
	public function export_privacy_data( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		return array(
			'group_label' => __( 'Login Security Monitoring', 'nvoos-content-graph-ai' ),
			'items'       => array(
				array(
					'name'  => __( 'Login Activity', 'nvoos-content-graph-ai' ),
					'value' => __( 'Your login attempts are monitored for security purposes', 'nvoos-content-graph-ai' ),
				),
			),
		);
	}

	/**
	 * Erase privacy data (base-identical: retained for security/audit).
	 *
	 * @param int $user_id User ID.
	 * @return bool True.
	 */
	public function erase_privacy_data( $user_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Base-identical no-op; login monitoring data is retained for security/audit purposes.
		return true;
	}
}
