<?php
/**
 * Tool for listing medical records.
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
 * List medical records.
 */
class WP_MCP_AI_Tool_List_Medical_Records implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_medical_records';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Medical Records', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists medical records with optional filtering by member and record type.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Filter by member ID (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'record_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by record type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'lab-result', 'diagnosis', 'treatment', 'vaccination', 'imaging', 'procedure', 'hospitalization', '' ),
				),
				'per_page'    => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'        => array(
					'type'        => 'integer',
					'description' => __( 'Page number (default: 1)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
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
			'profession_tags'       => array( 'healthcare_provider', 'caregiver', 'patient' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list medical records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$member_id   = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$record_type = isset( $arguments['record_type'] ) ? sanitize_key( $arguments['record_type'] ) : '';
		$per_page    = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page        = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Validate per_page.
		if ( $per_page < 1 || $per_page > 100 ) {
			$per_page = 20;
		}

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_med_record',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Add member filter.
		if ( $member_id ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_medical_record_member_id',
					'value' => $member_id,
				),
			);
		}

		// Add taxonomy filter if provided.
		if ( $record_type ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_record_type',
					'field'    => 'slug',
					'terms'    => $record_type,
				),
			);
		}

		$query = new WP_Query( $query_args );

		$records = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$record_id = get_the_ID();

				// Get record type.
				$types = wp_get_object_terms( $record_id, 'mcp_ai_record_type', array( 'fields' => 'slugs' ) );
				$type  = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

				// Get member info.
				$mid   = get_post_meta( $record_id, '_medical_record_member_id', true );
				$mname = '';
				if ( $mid ) {
					$mem   = get_post( $mid );
					$mname = $mem ? $mem->post_title : '';
				}

				$records[] = array(
					'id'          => $record_id,
					'title'       => get_the_title(),
					'record_type' => $type,
					'member_id'   => $mid,
					'member_name' => $mname,
					'date'        => get_post_meta( $record_id, '_medical_record_date', true ),
					'provider'    => get_post_meta( $record_id, '_medical_record_provider', true ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'    => true,
			'records'    => $records,
			'pagination' => array(
				'total'        => $query->found_posts,
				'total_pages'  => $query->max_num_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			),
		);
	}
}
