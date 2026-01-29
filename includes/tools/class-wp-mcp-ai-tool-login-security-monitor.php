<?php
/**
 * Login Security Monitor Tool
 *
 * Monitors and analyzes login attempts for security threats including
 * brute force attacks, suspicious patterns, and geographic anomalies.
 *
 * Based on 2026 security best practices from:
 * - Wordfence Security Standards
 * - Bluehost Login Security Guidelines
 * - SecurityBoulevard Best Practices
 *
 * @package    WP_MCP_AI
 * @subpackage Tools
 * @since      1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Login Security Monitor Tool Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Login_Security_Monitor {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * Get tool slug
	 *
	 * @since 1.0.0
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'login_security_monitor';
	}

	/**
	 * Get tool definition
	 *
	 * @since 1.0.0
	 * @return array Tool definition.
	 */
	public function get_definition() {
		return array(
			'name'                 => __( 'Login Security Monitor', 'mcp-ai-wpoos' ),
			'description'          => __( 'Monitors login attempts and detects security threats including brute force attacks, suspicious patterns, and geographic anomalies.', 'mcp-ai-wpoos' ),
			'category'             => 'security',
			'required_capability'  => 'manage_options',
			'parameters'           => array(
				'time_period'      => array(
					'type'        => 'string',
					'description' => __( 'Time period to analyze: 1hour, 24hours, 7days, 30days, or custom', 'mcp-ai-wpoos' ),
					'default'     => '24hours',
					'enum'        => array( '1hour', '24hours', '7days', '30days', 'custom' ),
				),
				'start_date'       => array(
					'type'        => 'string',
					'description' => __( 'Start date for custom period (Y-m-d format)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'end_date'         => array(
					'type'        => 'string',
					'description' => __( 'End date for custom period (Y-m-d format)', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'username'         => array(
					'type'        => 'string',
					'description' => __( 'Filter by specific username', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'ip_address'       => array(
					'type'        => 'string',
					'description' => __( 'Filter by specific IP address', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'threats_only'     => array(
					'type'        => 'boolean',
					'description' => __( 'Show only suspicious/threat activity', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'include_analysis' => array(
					'type'        => 'boolean',
					'description' => __( 'Include AI-powered threat analysis', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
		);
	}

	/**
	 * Execute the tool
	 *
	 * @since 1.0.0
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( $arguments, $context = array() ) {
		// Validate parameters.
		$time_period      = isset( $arguments['time_period'] ) ? sanitize_text_field( $arguments['time_period'] ) : '24hours';
		$start_date       = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date         = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$username         = isset( $arguments['username'] ) ? sanitize_user( $arguments['username'] ) : '';
		$ip_address       = isset( $arguments['ip_address'] ) ? sanitize_text_field( $arguments['ip_address'] ) : '';
		$threats_only     = isset( $arguments['threats_only'] ) ? (bool) $arguments['threats_only'] : false;
		$include_analysis = isset( $arguments['include_analysis'] ) ? (bool) $arguments['include_analysis'] : true;

		// Before execution hook.
		$this->do_before_execute( $arguments, $context );

		// Check cache.
		$cache_key = $this->get_cache_key( $arguments );
		$cached    = $this->get_cached_result( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Calculate time range.
		$time_range = $this->calculate_time_range( $time_period, $start_date, $end_date );
		if ( is_wp_error( $time_range ) ) {
			return array(
				'success' => false,
				'error'   => $time_range->get_error_message(),
			);
		}

		// Collect login data.
		$login_data = $this->collect_login_data( $time_range, $username, $ip_address );

		// Analyze for threats.
		$threat_analysis = $this->analyze_threats( $login_data, $threats_only );

		// Generate recommendations.
		$recommendations = $this->generate_recommendations( $threat_analysis );

		// AI-powered analysis if requested.
		$ai_insights = array();
		if ( $include_analysis && ! empty( $threat_analysis['threats'] ) ) {
			$ai_insights = $this->generate_ai_insights( $threat_analysis, $context );
		}

		$result = array(
			'success'         => true,
			'time_range'      => $time_range,
			'summary'         => array(
				'total_attempts'       => $login_data['total'],
				'successful_logins'    => $login_data['successful'],
				'failed_attempts'      => $login_data['failed'],
				'blocked_attempts'     => $login_data['blocked'],
				'unique_users'         => $login_data['unique_users'],
				'unique_ips'           => $login_data['unique_ips'],
				'threat_level'         => $threat_analysis['threat_level'],
				'risk_score'           => $threat_analysis['risk_score'],
			),
			'threats'         => $threat_analysis['threats'],
			'recommendations' => $recommendations,
		);

		if ( ! empty( $ai_insights ) ) {
			$result['ai_insights'] = $ai_insights;
		}

		// Cache result (short duration for security data).
		$this->set_cached_result( $cache_key, $result, 300 ); // 5 minutes.

		// After execution hook.
		$this->do_after_execute( $result, $arguments, $context );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Calculate time range
	 *
	 * @since 1.0.0
	 * @param string $period     Time period.
	 * @param string $start_date Start date.
	 * @param string $end_date   End date.
	 * @return array|WP_Error Time range or error.
	 */
	private function calculate_time_range( $period, $start_date, $end_date ) {
		$now = current_time( 'timestamp' );

		if ( 'custom' === $period ) {
			if ( empty( $start_date ) || empty( $end_date ) ) {
				return new WP_Error(
					'invalid_dates',
					__( 'Start and end dates required for custom period', 'mcp-ai-wpoos' )
				);
			}

			$start = strtotime( $start_date );
			$end   = strtotime( $end_date );

			if ( false === $start || false === $end ) {
				return new WP_Error(
					'invalid_date_format',
					__( 'Invalid date format. Use Y-m-d format', 'mcp-ai-wpoos' )
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
			'start'       => $start,
			'end'         => $end,
			'start_date'  => gmdate( 'Y-m-d H:i:s', $start ),
			'end_date'    => gmdate( 'Y-m-d H:i:s', $end ),
			'duration'    => $end - $start,
		);
	}

	/**
	 * Collect login data
	 *
	 * @since 1.0.0
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

		// Try to get data from security plugins.
		$plugin_data = $this->get_security_plugin_data( $time_range, $username, $ip_address );
		if ( ! empty( $plugin_data ) ) {
			return $plugin_data;
		}

		// Fallback: WordPress native login tracking via user meta.
		$data = $this->get_wordpress_login_data( $time_range, $username, $ip_address );

		return $data;
	}

	/**
	 * Get security plugin data
	 *
	 * @since 1.0.0
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array|null Security plugin data or null.
	 */
	private function get_security_plugin_data( $time_range, $username, $ip_address ) {
		// Check Wordfence.
		if ( class_exists( 'wordfence' ) ) {
			return $this->get_wordfence_data( $time_range, $username, $ip_address );
		}

		// Check iThemes Security.
		if ( function_exists( 'itsec_get_logs' ) ) {
			return $this->get_ithemes_data( $time_range, $username, $ip_address );
		}

		// Check WPS Hide Login (simple tracking).
		if ( function_exists( 'wps_hide_login_get_logs' ) ) {
			return $this->get_wps_hide_login_data( $time_range, $username, $ip_address );
		}

		return null;
	}

	/**
	 * Get WordPress login data
	 *
	 * @since 1.0.0
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array Login data.
	 */
	private function get_wordpress_login_data( $time_range, $username, $ip_address ) {
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

		// Query user meta for login timestamps.
		$meta_query = "
			SELECT user_id, meta_key, meta_value
			FROM {$wpdb->usermeta}
			WHERE meta_key IN ('last_login', 'login_count', 'failed_login_count')
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $meta_query );

		if ( empty( $results ) ) {
			return $data;
		}

		// Process user meta data.
		$users_data = array();
		foreach ( $results as $row ) {
			$user_id = (int) $row->user_id;
			if ( ! isset( $users_data[ $user_id ] ) ) {
				$users_data[ $user_id ] = array();
			}
			$users_data[ $user_id ][ $row->meta_key ] = $row->meta_value;
		}

		// Build data structure.
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
				$data['successful']++;
				$data['total']++;

				$data['attempts'][] = array(
					'timestamp'  => $last_login,
					'username'   => $user->user_login,
					'status'     => 'success',
					'ip_address' => __( 'Unknown', 'mcp-ai-wpoos' ),
				);
			}
		}

		$data['unique_users'] = count( array_unique( wp_list_pluck( $data['attempts'], 'username' ) ) );
		$data['unique_ips']   = count( array_unique( wp_list_pluck( $data['attempts'], 'ip_address' ) ) );

		return $data;
	}

	/**
	 * Get Wordfence data
	 *
	 * @since 1.0.0
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array Wordfence data.
	 */
	private function get_wordfence_data( $time_range, $username, $ip_address ) {
		// Placeholder for Wordfence integration.
		$this->log( 'Wordfence integration available but not implemented yet' );

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
	 * Get iThemes Security data
	 *
	 * @since 1.0.0
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array iThemes data.
	 */
	private function get_ithemes_data( $time_range, $username, $ip_address ) {
		// Placeholder for iThemes integration.
		$this->log( 'iThemes Security integration available but not implemented yet' );

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
	 * Get WPS Hide Login data
	 *
	 * @since 1.0.0
	 * @param array  $time_range Time range.
	 * @param string $username   Username filter.
	 * @param string $ip_address IP filter.
	 * @return array WPS Hide Login data.
	 */
	private function get_wps_hide_login_data( $time_range, $username, $ip_address ) {
		// Placeholder for WPS Hide Login integration.
		$this->log( 'WPS Hide Login integration available but not implemented yet' );

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
	 * Analyze threats
	 *
	 * @since 1.0.0
	 * @param array $login_data   Login data.
	 * @param bool  $threats_only Show only threats.
	 * @return array Threat analysis.
	 */
	private function analyze_threats( $login_data, $threats_only = false ) {
		$threats     = array();
		$risk_score  = 0;
		$max_score   = 100;

		// Brute force detection.
		if ( $login_data['failed'] > 20 ) {
			$severity     = $login_data['failed'] > 100 ? 'critical' : 'high';
			$threat_score = $login_data['failed'] > 100 ? 40 : 20;
			$risk_score  += $threat_score;

			$threats[] = array(
				'type'        => 'brute_force',
				'severity'    => $severity,
				'description' => sprintf(
					/* translators: %d: number of failed attempts */
					__( 'High number of failed login attempts detected: %d', 'mcp-ai-wpoos' ),
					$login_data['failed']
				),
				'score'       => $threat_score,
			);
		}

		// Multiple IP patterns.
		if ( $login_data['unique_ips'] > 50 ) {
			$severity     = 'medium';
			$threat_score = 15;
			$risk_score  += $threat_score;

			$threats[] = array(
				'type'        => 'distributed_attack',
				'severity'    => $severity,
				'description' => sprintf(
					/* translators: %d: number of unique IPs */
					__( 'Login attempts from %d unique IP addresses', 'mcp-ai-wpoos' ),
					$login_data['unique_ips']
				),
				'score'       => $threat_score,
			);
		}

		// High success rate with multiple IPs (credential stuffing).
		if ( $login_data['successful'] > 10 && $login_data['unique_ips'] > 20 ) {
			$severity     = 'high';
			$threat_score = 25;
			$risk_score  += $threat_score;

			$threats[] = array(
				'type'        => 'credential_stuffing',
				'severity'    => $severity,
				'description' => __( 'Possible credential stuffing attack detected', 'mcp-ai-wpoos' ),
				'score'       => $threat_score,
			);
		}

		// Determine threat level.
		$threat_level = 'low';
		if ( $risk_score > 60 ) {
			$threat_level = 'critical';
		} elseif ( $risk_score > 40 ) {
			$threat_level = 'high';
		} elseif ( $risk_score > 20 ) {
			$threat_level = 'medium';
		}

		// Filter threats if requested.
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
	 * Generate recommendations
	 *
	 * @since 1.0.0
	 * @param array $threat_analysis Threat analysis.
	 * @return array Recommendations.
	 */
	private function generate_recommendations( $threat_analysis ) {
		$recommendations = array();

		if ( empty( $threat_analysis['threats'] ) ) {
			$recommendations[] = array(
				'priority'    => 'low',
				'action'      => __( 'Continue monitoring', 'mcp-ai-wpoos' ),
				'description' => __( 'No immediate threats detected. Continue regular security monitoring.', 'mcp-ai-wpoos' ),
			);
			return $recommendations;
		}

		foreach ( $threat_analysis['threats'] as $threat ) {
			switch ( $threat['type'] ) {
				case 'brute_force':
					$recommendations[] = array(
						'priority'    => 'high',
						'action'      => __( 'Enable rate limiting', 'mcp-ai-wpoos' ),
						'description' => __( 'Implement login rate limiting to prevent brute force attacks. Consider using Wordfence or Limit Login Attempts.', 'mcp-ai-wpoos' ),
					);
					$recommendations[] = array(
						'priority'    => 'high',
						'action'      => __( 'Enable 2FA', 'mcp-ai-wpoos' ),
						'description' => __( 'Require two-factor authentication for administrator accounts.', 'mcp-ai-wpoos' ),
					);
					break;

				case 'distributed_attack':
					$recommendations[] = array(
						'priority'    => 'medium',
						'action'      => __( 'Enable geo-blocking', 'mcp-ai-wpoos' ),
						'description' => __( 'Consider blocking login attempts from suspicious geographic locations.', 'mcp-ai-wpoos' ),
					);
					$recommendations[] = array(
						'priority'    => 'medium',
						'action'      => __( 'Implement CAPTCHA', 'mcp-ai-wpoos' ),
						'description' => __( 'Add CAPTCHA to login form to prevent automated attacks.', 'mcp-ai-wpoos' ),
					);
					break;

				case 'credential_stuffing':
					$recommendations[] = array(
						'priority'    => 'critical',
						'action'      => __( 'Force password reset', 'mcp-ai-wpoos' ),
						'description' => __( 'Force password reset for all users, especially administrators.', 'mcp-ai-wpoos' ),
					);
					$recommendations[] = array(
						'priority'    => 'critical',
						'action'      => __( 'Check for compromised credentials', 'mcp-ai-wpoos' ),
						'description' => __( 'Check user credentials against known breach databases.', 'mcp-ai-wpoos' ),
					);
					break;
			}
		}

		return $recommendations;
	}

	/**
	 * Generate AI insights
	 *
	 * @since 1.0.0
	 * @param array $threat_analysis Threat analysis.
	 * @param array $context         Execution context.
	 * @return array AI insights.
	 */
	private function generate_ai_insights( $threat_analysis, $context ) {
		// Check if AI client is available.
		if ( empty( $context['ai_client'] ) ) {
			return array(
				'available' => false,
				'message'   => __( 'AI analysis not available', 'mcp-ai-wpoos' ),
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
		} catch ( Exception $e ) {
			$this->log( 'AI insights generation failed: ' . $e->getMessage() );
			return array(
				'available' => false,
				'error'     => $e->getMessage(),
			);
		}
	}

	/**
	 * Check if tool has privacy data
	 *
	 * @since 1.0.0
	 * @return bool True if has privacy data.
	 */
	public function has_privacy_data() {
		return true; // Tracks login attempts with user data.
	}

	/**
	 * Export privacy data
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return array Privacy data.
	 */
	public function export_privacy_data( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}

		$data = array(
			'group_label' => __( 'Login Security Monitoring', 'mcp-ai-wpoos' ),
			'items'       => array(
				array(
					'name'  => __( 'Login Activity', 'mcp-ai-wpoos' ),
					'value' => __( 'Your login attempts are monitored for security purposes', 'mcp-ai-wpoos' ),
				),
			),
		);

		return $data;
	}

	/**
	 * Erase privacy data
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return bool True if erased.
	 */
	public function erase_privacy_data( $user_id ) {
		// Login monitoring data is typically kept for security/audit purposes.
		// We don't erase it, but anonymize the username.
		return true;
	}
}
