<?php
/**
 * wellness/medical-records/class-wp-mcp-ai-tool-get-medical-record.php (ecosystem port — Wave F4, healthcare wellness CRUD batch 2).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/medical-records/class-wp-mcp-ai-tool-get-medical-record.php` for the
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
 * Get a single medical record.
 */
class WP_MCP_AI_Tool_Get_Medical_Record implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_medical_record';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Medical Record', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific medical record.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'record_id' => array(
					'type'        => 'integer',
					'description' => __( 'Medical record ID (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'record_id' ),
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
			'post_type'             => 'mcp_ai_med_record',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'caregiver' ),
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view medical records.', 'nvoos-content-graph-pro' ) );
		}

		// Validate record ID.
		$record_id = isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0;

		if ( ! $record_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Record ID is required.', 'nvoos-content-graph-pro' ) );
		}

		// Get record.
		$record = get_post( $record_id );

		if ( ! $record || 'mcp_ai_med_record' !== $record->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Medical record not found.', 'nvoos-content-graph-pro' ) );
		}

		// Get record type.
		$types = wp_get_object_terms( $record_id, 'mcp_ai_record_type', array( 'fields' => 'slugs' ) );
		$type  = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

		// Get member info.
		$member_id   = get_post_meta( $record_id, '_medical_record_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member      = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		return array(
			'success' => true,
			'record'  => array(
				'id'          => $record_id,
				'title'       => $record->post_title,
				'record_type' => $type,
				'member_id'   => $member_id,
				'member_name' => $member_name,
				'date'        => get_post_meta( $record_id, '_medical_record_date', true ),
				'provider'    => get_post_meta( $record_id, '_medical_record_provider', true ),
				'details'     => $record->post_content,
				'notes'       => $record->post_excerpt,
				'created_at'  => $record->post_date,
				'modified_at' => $record->post_modified,
			),
		);
	}
}
