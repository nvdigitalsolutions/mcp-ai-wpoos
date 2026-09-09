<?php
/**
 * wellness/checkups/class-wp-mcp-ai-tool-list-checkups.php (ecosystem port — Wave F4, healthcare wellness CRUD batch 3).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/checkups/class-wp-mcp-ai-tool-list-checkups.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`.
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
 * List checkups/appointments.
 */
class WP_MCP_AI_Tool_List_Checkups implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_checkups';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Checkups', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists checkups and appointments with optional filtering by member and status.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Filter by member ID (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'status'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by status (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'scheduled', 'completed', 'cancelled', 'no-show', '' ),
				),
				'per_page'  => array(
					'type'        => 'integer',
					'description' => __( 'Results per page (default: 20, max: 100)', 'nvoos-content-graph-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'      => array(
					'type'        => 'integer',
					'description' => __( 'Page number (default: 1)', 'nvoos-content-graph-pro' ),
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
			'post_type'             => 'mcp_ai_checkup',
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list checkups.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize inputs.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$status    = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		$per_page  = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page      = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		// Validate per_page.
		if ( $per_page < 1 || $per_page > 100 ) {
			$per_page = 20;
		}

		// Build query.
		$query_args = array(
			'post_type'      => 'mcp_ai_checkup',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_key'       => '_checkup_datetime',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
		);

		// Build meta query for filters.
		$meta_query = array( 'relation' => 'AND' );

		if ( $member_id ) {
			$meta_query[] = array(
				'key'   => '_checkup_member_id',
				'value' => $member_id,
			);
		}

		if ( $status ) {
			$meta_query[] = array(
				'key'   => '_checkup_status',
				'value' => $status,
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );

		$checkups = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$checkup_id = get_the_ID();

				// Get member info.
				$mid   = get_post_meta( $checkup_id, '_checkup_member_id', true );
				$mname = '';
				if ( $mid ) {
					$mem   = get_post( $mid );
					$mname = $mem ? $mem->post_title : '';
				}

				$checkups[] = array(
					'id'          => $checkup_id,
					'title'       => get_the_title(),
					'member_id'   => $mid,
					'member_name' => $mname,
					'datetime'    => get_post_meta( $checkup_id, '_checkup_datetime', true ),
					'provider'    => get_post_meta( $checkup_id, '_checkup_provider', true ),
					'location'    => get_post_meta( $checkup_id, '_checkup_location', true ),
					'type'        => get_post_meta( $checkup_id, '_checkup_type', true ),
					'status'      => get_post_meta( $checkup_id, '_checkup_status', true ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'    => true,
			'checkups'   => $checkups,
			'pagination' => array(
				'total'        => $query->found_posts,
				'total_pages'  => $query->max_num_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			),
		);
	}
}
