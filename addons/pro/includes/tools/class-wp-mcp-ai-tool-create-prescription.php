<?php
/**
 * Tool for creating prescriptions.
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
		return __( 'Creates a new prescription for a member with medication details, dosage, and schedule information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this prescription belongs to (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'medication_name'    => array(
					'type'        => 'string',
					'description' => __( 'Medication name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'dosage'             => array(
					'type'        => 'string',
					'description' => __( 'Dosage amount and unit (e.g., "10mg", "2 tablets") (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'frequency'          => array(
					'type'        => 'string',
					'description' => __( 'Frequency of dosage (e.g., "twice daily", "every 6 hours") (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'prescribing_doctor' => array(
					'type'        => 'string',
					'description' => __( 'Name of prescribing doctor (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'start_date'         => array(
					'type'        => 'string',
					'description' => __( 'Prescription start date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'           => array(
					'type'        => 'string',
					'description' => __( 'Prescription end date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'Prescription status (optional, defaults to active)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'completed', 'discontinued', 'expired' ),
					'default'     => 'active',
				),
				'notes'              => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or instructions (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'refills_remaining'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of refills remaining (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
			),
			'required'             => array( 'member_id', 'medication_name', 'dosage', 'frequency' ),
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
		$member_id       = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$medication_name = isset( $arguments['medication_name'] ) ? sanitize_text_field( $arguments['medication_name'] ) : '';
		$dosage          = isset( $arguments['dosage'] ) ? sanitize_text_field( $arguments['dosage'] ) : '';
		$frequency       = isset( $arguments['frequency'] ) ? sanitize_text_field( $arguments['frequency'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $medication_name ) {
			return new WP_Error( 'wp_mcp_ai_missing_medication', __( 'Medication name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $dosage ) {
			return new WP_Error( 'wp_mcp_ai_missing_dosage', __( 'Dosage is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $frequency ) {
			return new WP_Error( 'wp_mcp_ai_missing_frequency', __( 'Frequency is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize optional fields.
		$prescribing_doctor = isset( $arguments['prescribing_doctor'] ) ? sanitize_text_field( $arguments['prescribing_doctor'] ) : '';
		$start_date         = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date           = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$status             = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'active';
		$notes              = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';
		$refills_remaining  = isset( $arguments['refills_remaining'] ) ? absint( $arguments['refills_remaining'] ) : 0;

		// Validate dates.
		if ( $start_date && ! $this->validate_date( $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid start date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $end_date && ! $this->validate_date( $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid end date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create prescription post.
		$post_data = array(
			'post_type'    => 'mcp_ai_prescription',
			'post_title'   => $medication_name,
			'post_content' => $notes,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$prescription_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $prescription_id ) ) {
			return $prescription_id;
		}

		// Save prescription metadata.
		update_post_meta( $prescription_id, '_prescription_member_id', $member_id );
		update_post_meta( $prescription_id, '_prescription_medication_name', $medication_name );
		update_post_meta( $prescription_id, '_prescription_dosage', $dosage );
		update_post_meta( $prescription_id, '_prescription_frequency', $frequency );
		update_post_meta( $prescription_id, '_prescription_status', $status );

		if ( $prescribing_doctor ) {
			update_post_meta( $prescription_id, '_prescription_doctor', $prescribing_doctor );
		}

		if ( $start_date ) {
			update_post_meta( $prescription_id, '_prescription_start_date', $start_date );
		}

		if ( $end_date ) {
			update_post_meta( $prescription_id, '_prescription_end_date', $end_date );
		}

		if ( $refills_remaining > 0 ) {
			update_post_meta( $prescription_id, '_prescription_refills_remaining', $refills_remaining );
		}

		return array(
			'success'         => true,
			'message'         => __( 'Prescription created successfully.', 'mcp-ai-wpoos-pro' ),
			'prescription_id' => $prescription_id,
			'prescription'    => array(
				'id'                 => $prescription_id,
				'member_id'          => $member_id,
				'medication_name'    => $medication_name,
				'dosage'             => $dosage,
				'frequency'          => $frequency,
				'prescribing_doctor' => $prescribing_doctor,
				'start_date'         => $start_date,
				'end_date'           => $end_date,
				'status'             => $status,
				'notes'              => $notes,
				'refills_remaining'  => $refills_remaining,
				'created_at'         => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Validate date format (YYYY-MM-DD).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
