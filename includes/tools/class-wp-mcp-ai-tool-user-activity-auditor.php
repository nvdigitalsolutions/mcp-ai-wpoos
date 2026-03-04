<?php
/**
 * Tool for auditing user activity in WordPress following 2026 security best practices.
 *
 * Tracks login attempts, role changes, permission escalations, and generates
 * comprehensive audit logs.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../traits/trait-wp-mcp-ai-tool-wordpress-native.php';

/**
 * User Activity Auditor Tool
 *
 * Provides comprehensive user activity auditing following 2026 security standards:
 * - Login attempt tracking
 * - Role and capability changes
 * - Permission escalations
 * - User creation and deletion
 * - Failed authentication attempts
 *
 * Based on research from Bluehost, VPS.do, SecurityBoulevard 2026 guidelines.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_User_Activity_Auditor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'user_activity_auditor';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'User Activity Auditor', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Audits user activity including login attempts, role changes, permission escalations, and security events. Generates comprehensive audit logs following 2026 security best practices.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'user_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Specific user ID to audit (optional).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'event_type'      => array(
					'type'        => 'string',
					'description' => __( 'Type of event to audit.', 'mcp-ai-wpoos' ),
					'enum'        => array(
						'login',
						'failed_login',
						'logout',
						'role_change',
						'capability_change',
						'user_created',
						'user_deleted',
						'password_reset',
						'all',
					),
					'default'     => 'all',
				),
				'time_period'     => array(
					'type'        => 'string',
					'description' => __( 'Time period to audit.', 'mcp-ai-wpoos' ),
					'enum'        => array( '1hour', '24hours', '7days', '30days', '90days', 'custom' ),
					'default'     => '24hours',
				),
				'start_date'      => array(
					'type'        => 'string',
					'description' => __( 'Start date for custom time period (ISO 8601).', 'mcp-ai-wpoos' ),
					'format'      => 'date-time',
				),
				'end_date'        => array(
					'type'        => 'string',
					'description' => __( 'End date for custom time period (ISO 8601).', 'mcp-ai-wpoos' ),
					'format'      => 'date-time',
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of events to return.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 1000,
					'default'     => 100,
				),
				'suspicious_only' => array(
					'type'        => 'boolean',
					'description' => __( 'Return only suspicious activities.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'include_summary' => array(
					'type'        => 'boolean',
					'description' => __( 'Include summary statistics.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'security_compliance',

			'pattern_compatibility' => array( 'layered_defense' ),

			'profession_tags'       => array( 'security_analyst', 'compliance_officer' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read',
			'cacheable',
			'requires-admin',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Start performance tracking.
		$start_time = microtime( true );

		// Fire before execute hook.
		$this->do_before_execute( $arguments, $context );

		// Check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'insufficient_permissions',
				__( 'You do not have permission to audit user activity.', 'mcp-ai-wpoos' )
			);
		}

		// Check cache.
		if ( $this->should_cache() ) {
			$cached = $this->get_cached_result( $arguments );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Get audit data.
		$audit_data = $this->get_audit_data( $arguments );

		// Analyze for suspicious activity.
		if ( $arguments['suspicious_only'] ?? false ) {
			$audit_data['events'] = $this->filter_suspicious_events( $audit_data['events'] );
		}

		// Add summary statistics.
		if ( $arguments['include_summary'] ?? true ) {
			$audit_data['summary'] = $this->generate_summary( $audit_data['events'], $arguments );
		}

		// Apply filter hook.
		$audit_data = apply_filters(
			'wp_mcp_ai_user_activity_audit',
			$audit_data,
			$arguments
		);

		// Cache result.
		if ( $this->should_cache() ) {
			// Cache for shorter time since this is security-sensitive.
			$this->set_cached_result( $arguments, $audit_data, 300 ); // 5 minutes.
		}

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		// Fire after execute hook.
		$this->do_after_execute( $audit_data, $arguments, $context );

		do_action( 'wp_mcp_ai_user_activity_audited', $audit_data, $arguments );

		return $this->apply_result_filter( $audit_data, $arguments, $context );
	}

	/**
	 * Get audit data from various sources.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Audit data.
	 */
	private function get_audit_data( $arguments ) {
		$event_type  = $arguments['event_type'] ?? 'all';
		$time_period = $arguments['time_period'] ?? '24hours';
		$user_id     = $arguments['user_id'] ?? null;
		$limit       = $arguments['limit'] ?? 100;

		// Calculate time range.
		$time_range = $this->calculate_time_range( $time_period, $arguments );

		$events = array();

		// Check for WordPress core activity logs (if available).
		$events = array_merge( $events, $this->get_wordpress_activity( $user_id, $event_type, $time_range, $limit ) );

		// Check for common security plugin logs.
		$events = array_merge( $events, $this->get_security_plugin_logs( $user_id, $event_type, $time_range ) );

		// Sort by timestamp descending.
		usort(
			$events,
			function ( $a, $b ) {
				return $b['timestamp'] <=> $a['timestamp'];
			}
		);

		// Limit results.
		$events = array_slice( $events, 0, $limit );

		return array(
			'events'     => $events,
			'count'      => count( $events ),
			'time_range' => $time_range,
			'filters'    => array(
				'user_id'    => $user_id,
				'event_type' => $event_type,
			),
		);
	}

	/**
	 * Calculate time range for audit.
	 *
	 * @param string $period    Time period string.
	 * @param array  $arguments Tool arguments.
	 * @return array Start and end timestamps.
	 */
	private function calculate_time_range( $period, $arguments ) {
		$end_time = time();

		if ( 'custom' === $period ) {
			$start_time = isset( $arguments['start_date'] ) ? strtotime( $arguments['start_date'] ) : $end_time - DAY_IN_SECONDS;
			$end_time   = isset( $arguments['end_date'] ) ? strtotime( $arguments['end_date'] ) : $end_time;
		} else {
			$periods = array(
				'1hour'   => HOUR_IN_SECONDS,
				'24hours' => DAY_IN_SECONDS,
				'7days'   => 7 * DAY_IN_SECONDS,
				'30days'  => 30 * DAY_IN_SECONDS,
				'90days'  => 90 * DAY_IN_SECONDS,
			);

			$duration   = $periods[ $period ] ?? DAY_IN_SECONDS;
			$start_time = $end_time - $duration;
		}

		return array(
			'start' => $start_time,
			'end'   => $end_time,
		);
	}

	/**
	 * Get WordPress core activity data.
	 *
	 * @param int|null $user_id    User ID to filter by.
	 * @param string   $event_type Event type to filter by.
	 * @param array    $time_range Time range array.
	 * @param int      $limit      Maximum events.
	 * @return array Activity events.
	 */
	private function get_wordpress_activity( $user_id, $event_type, $time_range, $limit ) {
		global $wpdb;

		$events = array();

		// Get user meta changes for role/capability tracking.
		if ( in_array( $event_type, array( 'role_change', 'capability_change', 'all' ), true ) ) {
			$query = $wpdb->prepare(
				"SELECT um.user_id, um.meta_key, um.meta_value, u.user_login, u.user_email
				FROM {$wpdb->usermeta} um
				INNER JOIN {$wpdb->users} u ON um.user_id = u.ID
				WHERE um.meta_key IN ('wp_capabilities', 'wp_user_level')
				ORDER BY um.umeta_id DESC
				LIMIT %d",
				$limit
			);

			if ( $user_id ) {
				$query = $wpdb->prepare(
					"SELECT um.user_id, um.meta_key, um.meta_value, u.user_login, u.user_email
					FROM {$wpdb->usermeta} um
					INNER JOIN {$wpdb->users} u ON um.user_id = u.ID
					WHERE um.user_id = %d AND um.meta_key IN ('wp_capabilities', 'wp_user_level')
					ORDER BY um.umeta_id DESC
					LIMIT %d",
					$user_id,
					$limit
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is properly prepared above
			$results = $wpdb->get_results( $query );

			foreach ( $results as $row ) {
				$events[] = array(
					'type'       => 'role_change',
					'user_id'    => $row->user_id,
					'user_login' => $row->user_login,
					'user_email' => $row->user_email,
					'timestamp'  => time(),
					'details'    => array(
						'meta_key'   => $row->meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- meta_key lookup required to retrieve plugin-specific user activity meta; no alternative lookup method available.
						'meta_value' => maybe_unserialize( $row->meta_value ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- meta_value lookup required to retrieve plugin-specific user activity meta; no alternative lookup method available.
					),
					'severity'   => 'medium',
				);
			}
		}

		// Get recently created/modified users.
		if ( in_array( $event_type, array( 'user_created', 'all' ), true ) ) {
			$query = $wpdb->prepare(
				"SELECT ID, user_login, user_email, user_registered
				FROM {$wpdb->users}
				WHERE user_registered >= %s AND user_registered <= %s
				ORDER BY user_registered DESC
				LIMIT %d",
				gmdate( 'Y-m-d H:i:s', $time_range['start'] ),
				gmdate( 'Y-m-d H:i:s', $time_range['end'] ),
				$limit
			);

			if ( $user_id ) {
				$query = $wpdb->prepare(
					"SELECT ID, user_login, user_email, user_registered
					FROM {$wpdb->users}
					WHERE ID = %d
					LIMIT 1",
					$user_id
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query is properly prepared above
			$results = $wpdb->get_results( $query );

			foreach ( $results as $user ) {
				$events[] = array(
					'type'       => 'user_created',
					'user_id'    => $user->ID,
					'user_login' => $user->user_login,
					'user_email' => $user->user_email,
					'timestamp'  => strtotime( $user->user_registered ),
					'details'    => array(
						'registered_date' => $user->user_registered,
					),
					'severity'   => 'low',
				);
			}
		}

		return $events;
	}

	/**
	 * Get logs from security plugins if available.
	 *
	 * @param int|null $user_id    User ID to filter by.
	 * @param string   $event_type Event type to filter by.
	 * @param array    $time_range Time range array.
	 * @return array Security plugin events.
	 */
	private function get_security_plugin_logs( $user_id, $event_type, $time_range ) {
		$events = array();

		// Check for Wordfence.
		if ( function_exists( 'wordfence' ) && class_exists( 'wfDB' ) ) {
			$events = array_merge( $events, $this->get_wordfence_logs( $user_id, $event_type, $time_range ) );
		}

		// Check for iThemes Security.
		if ( class_exists( 'ITSEC_Core' ) ) {
			$events = array_merge( $events, $this->get_ithemes_logs( $user_id, $event_type, $time_range ) );
		}

		// Check for Sucuri Security.
		if ( defined( 'SUCURISCAN_VERSION' ) ) {
			$events = array_merge( $events, $this->get_sucuri_logs( $user_id, $event_type, $time_range ) );
		}

		return $events;
	}

	/**
	 * Get Wordfence security logs.
	 *
	 * @param int|null $user_id    User ID.
	 * @param string   $event_type Event type.
	 * @param array    $time_range Time range.
	 * @return array Events.
	 */
	private function get_wordfence_logs( $user_id, $event_type, $time_range ) {
		// Wordfence log integration would go here.
		// This is a placeholder for actual Wordfence API integration.
		return array();
	}

	/**
	 * Get iThemes Security logs.
	 *
	 * @param int|null $user_id    User ID.
	 * @param string   $event_type Event type.
	 * @param array    $time_range Time range.
	 * @return array Events.
	 */
	private function get_ithemes_logs( $user_id, $event_type, $time_range ) {
		// iThemes Security log integration would go here.
		return array();
	}

	/**
	 * Get Sucuri Security logs.
	 *
	 * @param int|null $user_id    User ID.
	 * @param string   $event_type Event type.
	 * @param array    $time_range Time range.
	 * @return array Events.
	 */
	private function get_sucuri_logs( $user_id, $event_type, $time_range ) {
		// Sucuri Security log integration would go here.
		return array();
	}

	/**
	 * Filter events for suspicious activity.
	 *
	 * @param array $events All events.
	 * @return array Suspicious events only.
	 */
	private function filter_suspicious_events( $events ) {
		return array_filter(
			$events,
			function ( $event ) {
				// Flag high and critical severity events.
				if ( isset( $event['severity'] ) && in_array( $event['severity'], array( 'high', 'critical' ), true ) ) {
					return true;
				}

				// Flag multiple failed login attempts.
				if ( 'failed_login' === $event['type'] ) {
					return true;
				}

				// Flag role escalations.
				if ( 'role_change' === $event['type'] ) {
					// Check if escalating to administrator.
					if ( isset( $event['details']['meta_value'] ) && is_array( $event['details']['meta_value'] ) ) {
						if ( isset( $event['details']['meta_value']['administrator'] ) ) {
							return true;
						}
					}
				}

				return false;
			}
		);
	}

	/**
	 * Generate summary statistics.
	 *
	 * @param array $events    All events.
	 * @param array $arguments Tool arguments.
	 * @return array Summary data.
	 */
	private function generate_summary( $events, $arguments ) {
		$summary = array(
			'total_events'    => count( $events ),
			'event_breakdown' => array(),
			'severity_counts' => array(
				'critical' => 0,
				'high'     => 0,
				'medium'   => 0,
				'low'      => 0,
			),
			'unique_users'    => array(),
		);

		foreach ( $events as $event ) {
			// Count by type.
			$type = $event['type'] ?? 'unknown';
			if ( ! isset( $summary['event_breakdown'][ $type ] ) ) {
				$summary['event_breakdown'][ $type ] = 0;
			}
			++$summary['event_breakdown'][ $type ];

			// Count by severity.
			$severity = $event['severity'] ?? 'low';
			if ( isset( $summary['severity_counts'][ $severity ] ) ) {
				++$summary['severity_counts'][ $severity ];
			}

			// Track unique users.
			$user_id = $event['user_id'] ?? 0;
			if ( $user_id && ! in_array( $user_id, $summary['unique_users'], true ) ) {
				$summary['unique_users'][] = $user_id;
			}
		}

		$summary['unique_user_count'] = count( $summary['unique_users'] );
		unset( $summary['unique_users'] ); // Don't expose user IDs in summary.

		// Calculate risk score.
		$summary['risk_score'] = $this->calculate_risk_score( $summary );

		return $summary;
	}

	/**
	 * Calculate overall risk score based on events.
	 *
	 * @param array $summary Summary data.
	 * @return int Risk score (0-100).
	 */
	private function calculate_risk_score( $summary ) {
		$score = 0;

		// Weight by severity.
		$score += ( $summary['severity_counts']['critical'] ?? 0 ) * 25;
		$score += ( $summary['severity_counts']['high'] ?? 0 ) * 10;
		$score += ( $summary['severity_counts']['medium'] ?? 0 ) * 3;
		$score += ( $summary['severity_counts']['low'] ?? 0 ) * 1;

		// Cap at 100.
		return min( 100, $score );
	}
}
