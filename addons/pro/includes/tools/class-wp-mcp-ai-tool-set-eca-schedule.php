<?php
/**
 * Tool for setting advanced ECA scheduling.
 *
 * Allows AI assistants to set multiple sessions and recurring patterns
 * for Extra-Curricular Activities.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sets advanced scheduling with multiple sessions and recurring patterns for an ECA.
 */
class WP_MCP_AI_Tool_Set_ECA_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'set_eca_schedule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Set ECA Schedule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Set advanced scheduling with multiple sessions and recurring patterns for an Extra-Curricular Activity. Supports weekly, biweekly, and monthly recurrence with venue conflict detection.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'   => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to set schedule for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'sessions' => array(
					'type'        => 'array',
					'description' => __( 'Array of session objects defining the schedule (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'day'        => array(
								'type'        => 'string',
								'description' => __( 'Day of the week (required)', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
							),
							'start_time' => array(
								'type'        => 'string',
								'description' => __( 'Start time in HH:MM format (required)', 'mcp-ai-wpoos-pro' ),
							),
							'end_time'   => array(
								'type'        => 'string',
								'description' => __( 'End time in HH:MM format (required)', 'mcp-ai-wpoos-pro' ),
							),
							'venue'      => array(
								'type'        => 'string',
								'description' => __( 'Venue for this session', 'mcp-ai-wpoos-pro' ),
							),
							'recurrence' => array(
								'type'        => 'string',
								'description' => __( 'Recurrence pattern', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'weekly', 'biweekly', 'monthly' ),
								'default'     => 'weekly',
							),
						),
						'required'   => array( 'day', 'start_time', 'end_time' ),
					),
				),
			),
			'required'             => array( 'eca_id', 'sessions' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to set ECA schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		$eca_id   = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;
		$sessions = isset( $arguments['sessions'] ) ? $arguments['sessions'] : array();

		if ( ! $eca_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_id',
				__( 'ECA ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify ECA exists.
		$eca = get_post( $eca_id );
		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_eca',
				__( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! is_array( $sessions ) || empty( $sessions ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_sessions',
				__( 'At least one session is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$valid_days       = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$valid_recurrence = array( 'weekly', 'biweekly', 'monthly' );
		$saved_sessions   = array();
		$warnings         = array();

		foreach ( $sessions as $index => $session ) {
			$session_num = $index + 1;

			// Validate required fields.
			if ( empty( $session['day'] ) || empty( $session['start_time'] ) || empty( $session['end_time'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_session',
					sprintf(
						/* translators: %d: session number */
						__( 'Session %d is missing required fields (day, start_time, end_time).', 'mcp-ai-wpoos-pro' ),
						$session_num
					)
				);
			}

			$day        = sanitize_text_field( $session['day'] );
			$start_time = sanitize_text_field( $session['start_time'] );
			$end_time   = sanitize_text_field( $session['end_time'] );
			$venue      = isset( $session['venue'] ) ? sanitize_text_field( $session['venue'] ) : '';
			$recurrence = isset( $session['recurrence'] ) ? sanitize_key( $session['recurrence'] ) : 'weekly';

			if ( ! in_array( $day, $valid_days, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_day',
					sprintf(
						/* translators: %d: session number */
						__( 'Session %d has an invalid day.', 'mcp-ai-wpoos-pro' ),
						$session_num
					)
				);
			}

			if ( false === strtotime( $start_time ) || false === strtotime( $end_time ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_time',
					sprintf(
						/* translators: %d: session number */
						__( 'Session %d has invalid time format. Use HH:MM format.', 'mcp-ai-wpoos-pro' ),
						$session_num
					)
				);
			}

			if ( ! in_array( $recurrence, $valid_recurrence, true ) ) {
				$recurrence = 'weekly';
			}

			// Check for venue conflicts if venue is specified.
			if ( ! empty( $venue ) ) {
				$venue_conflicts = $this->check_venue_conflicts( $eca_id, $day, $start_time, $end_time, $venue );
				if ( ! empty( $venue_conflicts ) ) {
					$conflict_names = array();
					foreach ( $venue_conflicts as $conflict ) {
						$conflict_names[] = $conflict['eca_name'];
					}
					$warnings[] = sprintf(
						/* translators: 1: session number, 2: venue, 3: conflicting ECA names */
						__( 'Session %1$d: Venue "%2$s" has conflicts with: %3$s', 'mcp-ai-wpoos-pro' ),
						$session_num,
						$venue,
						implode( ', ', $conflict_names )
					);
				}
			}

			$saved_sessions[] = array(
				'day'        => $day,
				'start_time' => $start_time,
				'end_time'   => $end_time,
				'venue'      => $venue,
				'recurrence' => $recurrence,
			);
		}

		// Store sessions in post meta.
		update_post_meta( $eca_id, '_eca_sessions', $saved_sessions );

		// Update legacy fields from the first session for backwards compatibility.
		$first_session = $saved_sessions[0];
		update_post_meta( $eca_id, '_eca_day', $first_session['day'] );
		update_post_meta( $eca_id, '_eca_start_time', $first_session['start_time'] );
		update_post_meta( $eca_id, '_eca_end_time', $first_session['end_time'] );

		if ( ! empty( $first_session['venue'] ) ) {
			update_post_meta( $eca_id, '_eca_venue', $first_session['venue'] );
		}

		$result = array(
			'success'        => true,
			'eca_id'         => $eca_id,
			'eca_name'       => $eca->post_title,
			'sessions_saved' => count( $saved_sessions ),
			'sessions'       => $saved_sessions,
			'message'        => sprintf(
				/* translators: 1: number of sessions, 2: ECA name */
				__( '%1$d session(s) saved for %2$s.', 'mcp-ai-wpoos-pro' ),
				count( $saved_sessions ),
				$eca->post_title
			),
		);

		if ( ! empty( $warnings ) ) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}

	/**
	 * Check for venue conflicts on a given day and time.
	 *
	 * @param int    $eca_id     The ECA ID to exclude from conflict check.
	 * @param string $day        Day of the week.
	 * @param string $start_time Start time in HH:MM format.
	 * @param string $end_time   End time in HH:MM format.
	 * @param string $venue      Venue name to check.
	 * @return array Array of conflicting ECAs.
	 */
	private function check_venue_conflicts( $eca_id, $day, $start_time, $end_time, $venue ) {
		$query_args = array(
			'post_type'      => 'mcp_ai_eca',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'set_eca_schedule', 0, 1000 ) : 1000,
			'post__not_in'   => array( $eca_id ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'   => '_eca_day',
					'value' => $day,
				),
				array(
					'key'   => '_eca_venue',
					'value' => $venue,
				),
			),
		);

		$query       = new WP_Query( $query_args );
		$conflicts   = array();
		$check_start = strtotime( $start_time );
		$check_end   = strtotime( $end_time );

		foreach ( $query->posts as $eca_post ) {
			$eca_start = strtotime( get_post_meta( $eca_post->ID, '_eca_start_time', true ) );
			$eca_end   = strtotime( get_post_meta( $eca_post->ID, '_eca_end_time', true ) );

			if ( false === $eca_start || false === $eca_end ) {
				continue;
			}

			// Check for time overlap.
			if ( $check_start < $eca_end && $check_end > $eca_start ) {
				$conflicts[] = array(
					'eca_id'     => $eca_post->ID,
					'eca_name'   => $eca_post->post_title,
					'start_time' => get_post_meta( $eca_post->ID, '_eca_start_time', true ),
					'end_time'   => get_post_meta( $eca_post->ID, '_eca_end_time', true ),
				);
			}
		}

		return $conflicts;
	}
}
