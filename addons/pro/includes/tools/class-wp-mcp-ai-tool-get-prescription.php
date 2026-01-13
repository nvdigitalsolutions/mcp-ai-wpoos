<?php
/**
 * Tool for getting single prescription details.
 *
 * Allows AI assistants to retrieve detailed information about a specific prescription.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets detailed information for a single prescription.
 */
class WP_MCP_AI_Tool_Get_Prescription implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_prescription';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Prescription', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific prescription, including all dosage, frequency, and instruction details.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prescription_id' => array(
					'type'        => 'integer',
					'description' => __( 'Prescription ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'prescription_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view prescriptions.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$prescription_id = isset( $arguments['prescription_id'] ) ? absint( $arguments['prescription_id'] ) : 0;

		if ( ! $prescription_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_prescription_id', __( 'Prescription ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify prescription exists.
		$prescription = get_post( $prescription_id );
		if ( ! $prescription || 'mcp_ai_prescription' !== $prescription->post_type ) {
			return new WP_Error( 'wp_mcp_ai_prescription_not_found', __( 'Prescription not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get member info.
		$member_id   = get_post_meta( $prescription_id, '_prescription_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member      = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		// Check if currently active.
		$start     = get_post_meta( $prescription_id, '_prescription_start_date', true );
		$end       = get_post_meta( $prescription_id, '_prescription_end_date', true );
		$today     = current_time( 'Y-m-d' );
		$is_active = ( ! $start || $start <= $today ) && ( ! $end || $end >= $today );

		// Build prescription data.
		$prescription_data = array(
			'id'           => $prescription_id,
			'medication'   => $prescription->post_title,
			'member_id'    => $member_id,
			'member_name'  => $member_name,
			'dosage'       => get_post_meta( $prescription_id, '_prescription_dosage', true ),
			'frequency'    => get_post_meta( $prescription_id, '_prescription_frequency', true ),
			'prescriber'   => get_post_meta( $prescription_id, '_prescription_prescriber', true ),
			'start_date'   => $start,
			'end_date'     => $end,
			'instructions' => $prescription->post_content,
			'pharmacy'     => get_post_meta( $prescription_id, '_prescription_pharmacy', true ),
			'refills'      => get_post_meta( $prescription_id, '_prescription_refills', true ),
			'is_active'    => $is_active,
			'created_at'   => $prescription->post_date,
			'modified_at'  => $prescription->post_modified,
			'author_id'    => absint( $prescription->post_author ),
		);

		return array(
			'success'      => true,
			'prescription' => $prescription_data,
		);
	}
}
