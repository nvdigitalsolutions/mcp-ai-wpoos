<?php
/**
 * wellness/prescriptions/class-wp-mcp-ai-tool-get-prescription.php (ecosystem port — Wave F4, healthcare wellness CRUD batch 2).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/prescriptions/class-wp-mcp-ai-tool-get-prescription.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the relevance-search trait require resolves from the
 * addon's already-ported `src/traits/` copy).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a single prescription.
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
		return __( 'Get Prescription', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific prescription.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Prescription ID (required)', 'nvoos-content-graph-pro' ),
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
	public function get_required_capability() {
		return 'edit_posts';
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
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_prescription',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'pharmacist' ),
			'risk_level'            => 'info',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view prescriptions.', 'nvoos-content-graph-pro' ) );
		}

		// Validate prescription ID.
		$prescription_id = isset( $arguments['prescription_id'] ) ? absint( $arguments['prescription_id'] ) : 0;

		if ( ! $prescription_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Prescription ID is required.', 'nvoos-content-graph-pro' ) );
		}

		// Get prescription.
		$prescription = get_post( $prescription_id );

		if ( ! $prescription || 'mcp_ai_prescription' !== $prescription->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Prescription not found.', 'nvoos-content-graph-pro' ) );
		}

		// Get member info.
		$member_id   = get_post_meta( $prescription_id, '_prescription_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member      = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		return array(
			'success'      => true,
			'prescription' => array(
				'id'                 => $prescription_id,
				'medication_name'    => $prescription->post_title,
				'member_id'          => $member_id,
				'member_name'        => $member_name,
				'dosage'             => get_post_meta( $prescription_id, '_prescription_dosage', true ),
				'frequency'          => get_post_meta( $prescription_id, '_prescription_frequency', true ),
				'prescribing_doctor' => get_post_meta( $prescription_id, '_prescription_doctor', true ),
				'start_date'         => get_post_meta( $prescription_id, '_prescription_start_date', true ),
				'end_date'           => get_post_meta( $prescription_id, '_prescription_end_date', true ),
				'status'             => get_post_meta( $prescription_id, '_prescription_status', true ),
				'notes'              => $prescription->post_content,
				'refills_remaining'  => get_post_meta( $prescription_id, '_prescription_refills_remaining', true ),
				'rx_number'          => get_post_meta( $prescription_id, '_prescription_rx_number', true ),
				'ndc_code'           => get_post_meta( $prescription_id, '_prescription_ndc_code', true ),
				'route'              => get_post_meta( $prescription_id, '_prescription_route', true ),
				'quantity'           => get_post_meta( $prescription_id, '_prescription_quantity', true ),
				'quantity_unit'      => get_post_meta( $prescription_id, '_prescription_quantity_unit', true ),
				'indication'         => get_post_meta( $prescription_id, '_prescription_indication', true ),
				'pharmacy_name'      => get_post_meta( $prescription_id, '_prescription_pharmacy_name', true ),
				'pharmacy_phone'     => get_post_meta( $prescription_id, '_prescription_pharmacy_phone', true ),
				'created_at'         => $prescription->post_date,
				'modified_at'        => $prescription->post_modified,
			),
		);
	}
}
