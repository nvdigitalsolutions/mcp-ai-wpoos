<?php
/**
 * wellness/allergies/class-wp-mcp-ai-tool-list-allergies.php (ecosystem port — Wave F4, healthcare wellness CRUD batch 3).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/allergies/class-wp-mcp-ai-tool-list-allergies.php` for the
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
 * List allergies.
 */
class WP_MCP_AI_Tool_List_Allergies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'list_allergies';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'List Allergies', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Lists allergies with optional filtering by member and severity.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
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
				'severity'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by severity (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'mild', 'moderate', 'severe', '' ),
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
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_allergy',
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
	 * Check if tool is available.
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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list allergies.', 'nvoos-content-graph-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$severity  = isset( $arguments['severity'] ) ? sanitize_key( $arguments['severity'] ) : '';
		$per_page  = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page      = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		if ( $per_page < 1 || $per_page > 100 ) {
			$per_page = 20;
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_allergy',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $member_id ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_allergy_member_id',
					'value' => $member_id,
				),
			);
		}

		if ( $severity ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'mcp_ai_allergy_severity',
					'field'    => 'slug',
					'terms'    => $severity,
				),
			);
		}

		$query = new WP_Query( $query_args );

		$allergies = array();
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$allergy_id = get_the_ID();

				$severities = wp_get_object_terms( $allergy_id, 'mcp_ai_allergy_severity', array( 'fields' => 'slugs' ) );
				$sev        = ! empty( $severities ) && ! is_wp_error( $severities ) ? $severities[0] : '';

				$mid   = get_post_meta( $allergy_id, '_allergy_member_id', true );
				$mname = '';
				if ( $mid ) {
					$mem   = get_post( $mid );
					$mname = $mem ? $mem->post_title : '';
				}

				$allergies[] = array(
					'id'             => $allergy_id,
					'allergen'       => get_the_title(),
					'member_id'      => $mid,
					'member_name'    => $mname,
					'severity'       => $sev,
					'reactions'      => get_post_meta( $allergy_id, '_allergy_reactions', true ),
					'diagnosed_date' => get_post_meta( $allergy_id, '_allergy_diagnosed_date', true ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'    => true,
			'allergies'  => $allergies,
			'pagination' => array(
				'total'        => $query->found_posts,
				'total_pages'  => $query->max_num_pages,
				'current_page' => $page,
				'per_page'     => $per_page,
			),
		);
	}
}
