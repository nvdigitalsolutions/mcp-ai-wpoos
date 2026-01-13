<?php
/**
 * Tool for getting medication schedule for a member.
 *
 * Provides daily medication schedule based on active prescriptions.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets daily medication schedule for a member.
 */
class WP_MCP_AI_Tool_Get_Medication_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_medication_schedule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Medication Schedule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves the daily medication schedule for a member, listing all currently active prescriptions with their dosages, frequencies, and timing information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member ID to get medication schedule for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'date'      => array(
					'type'        => 'string',
					'description' => __( 'Date for schedule (YYYY-MM-DD) (optional, defaults to today)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
			),
			'required'             => array( 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
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
		return ! empty( $settings['enable_health_wellness_management'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view medication schedules.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$date      = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate date format.
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get active prescriptions for this member.
		$query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_prescription',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_prescription_member_id',
						'value' => $member_id,
					),
					array(
						'relation' => 'AND',
						array(
							'key'     => '_prescription_start_date',
							'value'   => $date,
							'compare' => '<=',
							'type'    => 'DATE',
						),
						array(
							'relation' => 'OR',
							array(
								'key'     => '_prescription_end_date',
								'value'   => $date,
								'compare' => '>=',
								'type'    => 'DATE',
							),
							array(
								'key'     => '_prescription_end_date',
								'compare' => 'NOT EXISTS',
							),
						),
					),
				),
			)
		);

		$medications = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$prescription_id = get_the_ID();

				$medications[] = array(
					'id'           => $prescription_id,
					'medication'   => get_the_title(),
					'dosage'       => get_post_meta( $prescription_id, '_prescription_dosage', true ),
					'frequency'    => get_post_meta( $prescription_id, '_prescription_frequency', true ),
					'prescriber'   => get_post_meta( $prescription_id, '_prescription_prescriber', true ),
					'start_date'   => get_post_meta( $prescription_id, '_prescription_start_date', true ),
					'end_date'     => get_post_meta( $prescription_id, '_prescription_end_date', true ),
					'instructions' => get_the_content(),
					'pharmacy'     => get_post_meta( $prescription_id, '_prescription_pharmacy', true ),
					'refills'      => get_post_meta( $prescription_id, '_prescription_refills', true ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'      => true,
			'member_id'    => $member_id,
			'member_name'  => $member->post_title,
			'date'         => $date,
			'medications'  => $medications,
			'total_active' => count( $medications ),
			'message'      => sprintf(
				/* translators: 1: number of medications, 2: member name, 3: date */
				__( '%1$d active medication(s) for %2$s on %3$s.', 'mcp-ai-wpoos-pro' ),
				count( $medications ),
				$member->post_title,
				$date
			),
		);
	}
}
