<?php
/**
 * Tool for getting registration timeline in the Regulatory Registration system.
 *
 * Allows AI assistants to calculate milestones and deadlines for registrations.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets registration timeline with milestones.
 */
class WP_MCP_AI_Tool_Get_Registration_Timeline implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_registration_timeline';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Registration Timeline', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculates milestones, deadlines, and progress for a registration. Provides expected timeline based on country-specific processing times.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id' => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID to get timeline for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'registration_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
			'idempotent',           // Can be called multiple times safely with same result.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( $arguments, $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['registration_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$registration_id = absint( $arguments['registration_id'] );

		// Verify registration exists.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Registration not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get registration details.
		$country           = get_post_meta( $registration_id, 'country', true );
		$submission_date   = get_post_meta( $registration_id, 'submission_date', true );
		$approval_date     = get_post_meta( $registration_id, 'approval_date', true );
		$expiry_date       = get_post_meta( $registration_id, 'expiry_date', true );
		$registration_type = get_post_meta( $registration_id, 'registration_type', true );

		// Get expected timeline for country.
		$expected_timeline = $this->get_expected_timeline( $country, $registration_type );

		// Calculate milestones.
		$milestones = $this->calculate_milestones( $registration, $submission_date, $expected_timeline );

		// Calculate progress.
		$progress = $this->calculate_progress( $milestones );

		// Calculate time remaining/elapsed.
		$time_analysis = $this->analyze_time( $submission_date, $approval_date, $expiry_date, $expected_timeline );

		return array(
			'success'           => true,
			'registration_id'   => $registration_id,
			'country'           => $country,
			'registration_type' => $registration_type,
			'expected_timeline' => $expected_timeline,
			'milestones'        => $milestones,
			'progress'          => $progress,
			'time_analysis'     => $time_analysis,
			'dates'             => array(
				'created'    => $registration->post_date,
				'submission' => $submission_date,
				'approval'   => $approval_date,
				'expiry'     => $expiry_date,
			),
		);
	}

	/**
	 * Get expected timeline for a country.
	 *
	 * @param string $country Country code.
	 * @param string $registration_type Registration type.
	 * @return array Expected timeline in days.
	 */
	private function get_expected_timeline( $country, $registration_type ) {
		// Default timelines (in days).
		$default_timeline = array(
			'new'       => array(
				'preparation' => 30,
				'submission'  => 1,
				'review'      => 90,
				'queries'     => 30,
				'approval'    => 14,
				'total'       => 165,
			),
			'renewal'   => array(
				'preparation' => 14,
				'submission'  => 1,
				'review'      => 60,
				'queries'     => 14,
				'approval'    => 7,
				'total'       => 96,
			),
			'variation' => array(
				'preparation' => 14,
				'submission'  => 1,
				'review'      => 45,
				'queries'     => 14,
				'approval'    => 7,
				'total'       => 81,
			),
		);

		// Country-specific timelines.
		$country_timelines = array(
			'LK' => array( // Sri Lanka NMRA.
				'new'     => array(
					'preparation' => 45,
					'submission'  => 1,
					'review'      => 120,
					'queries'     => 30,
					'approval'    => 14,
					'total'       => 210,
				),
				'renewal' => array(
					'preparation' => 21,
					'submission'  => 1,
					'review'      => 90,
					'queries'     => 14,
					'approval'    => 7,
					'total'       => 133,
				),
			),
			'AE' => array( // UAE MOHAP.
				'new' => array(
					'preparation' => 30,
					'submission'  => 1,
					'review'      => 60,
					'queries'     => 21,
					'approval'    => 7,
					'total'       => 119,
				),
			),
			'SA' => array( // Saudi SFDA.
				'new' => array(
					'preparation' => 30,
					'submission'  => 1,
					'review'      => 90,
					'queries'     => 30,
					'approval'    => 14,
					'total'       => 165,
				),
			),
		);

		$type          = ! empty( $registration_type ) ? $registration_type : 'new';
		$country_upper = strtoupper( $country );

		if ( isset( $country_timelines[ $country_upper ][ $type ] ) ) {
			return $country_timelines[ $country_upper ][ $type ];
		} elseif ( isset( $country_timelines[ $country_upper ]['new'] ) ) {
			return $country_timelines[ $country_upper ]['new'];
		}

		return $default_timeline[ $type ] ?? $default_timeline['new'];
	}

	/**
	 * Calculate milestones based on submission date.
	 *
	 * @param WP_Post $registration Registration post.
	 * @param string  $submission_date Submission date.
	 * @param array   $expected_timeline Expected timeline.
	 * @return array Milestones.
	 */
	private function calculate_milestones( $registration, $submission_date, $expected_timeline ) {
		$milestones = array();
		$base_date  = ! empty( $submission_date ) ? strtotime( $submission_date ) : strtotime( $registration->post_date );

		$milestones[] = array(
			'name'        => 'Preparation',
			'status'      => ! empty( $submission_date ) ? 'completed' : 'in_progress',
			'start_date'  => $registration->post_date,
			'target_date' => gmdate( 'Y-m-d', strtotime( $registration->post_date . ' +' . $expected_timeline['preparation'] . ' days' ) ),
			'actual_date' => $submission_date,
			'days'        => $expected_timeline['preparation'],
		);

		$review_start = $base_date;
		$milestones[] = array(
			'name'        => 'Under Review',
			'status'      => ! empty( $submission_date ) ? 'in_progress' : 'pending',
			'start_date'  => $submission_date,
			'target_date' => ! empty( $submission_date ) ? gmdate( 'Y-m-d', strtotime( '+' . $expected_timeline['review'] . ' days', $review_start ) ) : null,
			'actual_date' => null,
			'days'        => $expected_timeline['review'],
		);

		$approval_date = get_post_meta( $registration->ID, 'approval_date', true );
		$milestones[]  = array(
			'name'        => 'Approval',
			'status'      => ! empty( $approval_date ) ? 'completed' : 'pending',
			'start_date'  => null,
			'target_date' => ! empty( $submission_date ) ? gmdate( 'Y-m-d', strtotime( '+' . ( $expected_timeline['review'] + $expected_timeline['approval'] ) . ' days', $review_start ) ) : null,
			'actual_date' => $approval_date,
			'days'        => $expected_timeline['approval'],
		);

		return $milestones;
	}

	/**
	 * Calculate progress percentage.
	 *
	 * @param array $milestones Milestones array.
	 * @return array Progress information.
	 */
	private function calculate_progress( $milestones ) {
		$completed = 0;
		$total     = count( $milestones );

		foreach ( $milestones as $milestone ) {
			if ( 'completed' === $milestone['status'] ) {
				++$completed;
			}
		}

		$percentage = $total > 0 ? round( ( $completed / $total ) * 100, 2 ) : 0;

		return array(
			'completed_milestones' => $completed,
			'total_milestones'     => $total,
			'percentage'           => $percentage,
			'current_phase'        => $this->get_current_phase( $milestones ),
		);
	}

	/**
	 * Get current phase.
	 *
	 * @param array $milestones Milestones array.
	 * @return string Current phase.
	 */
	private function get_current_phase( $milestones ) {
		foreach ( $milestones as $milestone ) {
			if ( 'in_progress' === $milestone['status'] ) {
				return $milestone['name'];
			}
		}

		// Check if all completed.
		$all_completed = true;
		foreach ( $milestones as $milestone ) {
			if ( 'completed' !== $milestone['status'] ) {
				$all_completed = false;
				break;
			}
		}

		return $all_completed ? 'Completed' : 'Pending Start';
	}

	/**
	 * Analyze time.
	 *
	 * @param string $submission_date Submission date.
	 * @param string $approval_date Approval date.
	 * @param string $expiry_date Expiry date.
	 * @param array  $expected_timeline Expected timeline.
	 * @return array Time analysis.
	 */
	private function analyze_time( $submission_date, $approval_date, $expiry_date, $expected_timeline ) {
		$analysis = array();

		if ( ! empty( $submission_date ) ) {
			$submitted_timestamp               = strtotime( $submission_date );
			$days_since_submission             = floor( ( time() - $submitted_timestamp ) / DAY_IN_SECONDS );
			$analysis['days_since_submission'] = $days_since_submission;

			if ( empty( $approval_date ) ) {
				$expected_approval_date                = gmdate( 'Y-m-d', strtotime( '+' . $expected_timeline['total'] . ' days', $submitted_timestamp ) );
				$analysis['expected_approval_date']    = $expected_approval_date;
				$analysis['days_to_expected_approval'] = floor( ( strtotime( $expected_approval_date ) - time() ) / DAY_IN_SECONDS );
			}
		}

		if ( ! empty( $approval_date ) ) {
			$approved_timestamp              = strtotime( $approval_date );
			$analysis['days_since_approval'] = floor( ( time() - $approved_timestamp ) / DAY_IN_SECONDS );

			if ( ! empty( $submission_date ) ) {
				$processing_time                      = floor( ( $approved_timestamp - strtotime( $submission_date ) ) / DAY_IN_SECONDS );
				$analysis['actual_processing_time']   = $processing_time;
				$analysis['expected_processing_time'] = $expected_timeline['total'];
				$analysis['processing_time_variance'] = $processing_time - $expected_timeline['total'];
			}
		}

		if ( ! empty( $expiry_date ) ) {
			$expiry_timestamp           = strtotime( $expiry_date );
			$days_to_expiry             = floor( ( $expiry_timestamp - time() ) / DAY_IN_SECONDS );
			$analysis['days_to_expiry'] = $days_to_expiry;
			$analysis['expiry_date']    = $expiry_date;

			if ( $days_to_expiry < 0 ) {
				$analysis['expiry_status'] = 'expired';
			} elseif ( $days_to_expiry <= 90 ) {
				$analysis['expiry_status'] = 'expiring_soon';
			} else {
				$analysis['expiry_status'] = 'valid';
			}
		}

		return $analysis;
	}
}
