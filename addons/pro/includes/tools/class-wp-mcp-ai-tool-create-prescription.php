<?php
/**
 * Tool for creating prescriptions.
 *
 * Allows AI assistants to create new prescriptions for members.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new prescription.
 */
class WP_MCP_AI_Tool_Create_Prescription implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_prescription';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Prescription', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new prescription for a member, including medication name, dosage, frequency, prescriber, and date range.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this prescription is for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'medication'   => array(
					'type'        => 'string',
					'description' => __( 'Medication name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'dosage'       => array(
					'type'        => 'string',
					'description' => __( 'Dosage (e.g., "10mg", "2 tablets") (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'frequency'    => array(
					'type'        => 'string',
					'description' => __( 'Frequency (e.g., "twice daily", "every 6 hours") (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'prescriber'   => array(
					'type'        => 'string',
					'description' => __( 'Prescriber/doctor name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'start_date'   => array(
					'type'        => 'string',
					'description' => __( 'Start date (YYYY-MM-DD) (optional, defaults to today)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'     => array(
					'type'        => 'string',
					'description' => __( 'End date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'instructions' => array(
					'type'        => 'string',
					'description' => __( 'Detailed instructions or notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'pharmacy'     => array(
					'type'        => 'string',
					'description' => __( 'Pharmacy name or location (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'refills'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of refills available (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
			),
			'required'             => array( 'member_id', 'medication' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create prescriptions.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		$member_id  = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$medication = isset( $arguments['medication'] ) ? sanitize_text_field( $arguments['medication'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $medication ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_medication', __( 'Medication name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Optional fields.
		$dosage       = isset( $arguments['dosage'] ) ? sanitize_text_field( $arguments['dosage'] ) : '';
		$frequency    = isset( $arguments['frequency'] ) ? sanitize_text_field( $arguments['frequency'] ) : '';
		$prescriber   = isset( $arguments['prescriber'] ) ? sanitize_text_field( $arguments['prescriber'] ) : '';
		$start_date   = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : current_time( 'Y-m-d' );
		$end_date     = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$instructions = isset( $arguments['instructions'] ) ? sanitize_textarea_field( $arguments['instructions'] ) : '';
		$pharmacy     = isset( $arguments['pharmacy'] ) ? sanitize_text_field( $arguments['pharmacy'] ) : '';
		$refills      = isset( $arguments['refills'] ) ? absint( $arguments['refills'] ) : 0;

		// Validate date formats if provided.
		if ( ! empty( $start_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_start_date', __( 'Start date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! empty( $end_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_end_date', __( 'End date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create the prescription post.
		$prescription_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_prescription',
				'post_title'   => $medication,
				'post_content' => $instructions,
				'post_status'  => 'publish',
				'post_author'  => $current_user_id,
			),
			true
		);

		if ( is_wp_error( $prescription_id ) ) {
			return $prescription_id;
		}

		// Set prescription metadata.
		update_post_meta( $prescription_id, '_prescription_member_id', $member_id );
		update_post_meta( $prescription_id, '_prescription_start_date', $start_date );

		if ( $dosage ) {
			update_post_meta( $prescription_id, '_prescription_dosage', $dosage );
		}
		if ( $frequency ) {
			update_post_meta( $prescription_id, '_prescription_frequency', $frequency );
		}
		if ( $prescriber ) {
			update_post_meta( $prescription_id, '_prescription_prescriber', $prescriber );
		}
		if ( $end_date ) {
			update_post_meta( $prescription_id, '_prescription_end_date', $end_date );
		}
		if ( $pharmacy ) {
			update_post_meta( $prescription_id, '_prescription_pharmacy', $pharmacy );
		}
		if ( $refills ) {
			update_post_meta( $prescription_id, '_prescription_refills', $refills );
		}

		// Check if currently active.
		$today     = current_time( 'Y-m-d' );
		$is_active = ( ! $start_date || $start_date <= $today ) && ( ! $end_date || $end_date >= $today );

		// Build response.
		$prescription_data = array(
			'id'           => $prescription_id,
			'medication'   => $medication,
			'member_id'    => $member_id,
			'member_name'  => $member->post_title,
			'dosage'       => $dosage,
			'frequency'    => $frequency,
			'prescriber'   => $prescriber,
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'instructions' => $instructions,
			'pharmacy'     => $pharmacy,
			'refills'      => $refills,
			'is_active'    => $is_active,
		);

		return array(
			'success'      => true,
			'prescription' => $prescription_data,
			'message'      => sprintf(
				/* translators: 1: medication name, 2: member name */
				__( 'Prescription for "%1$s" created for %2$s.', 'mcp-ai-wpoos-pro' ),
				$medication,
				$member->post_title
			),
		);
	}
}
