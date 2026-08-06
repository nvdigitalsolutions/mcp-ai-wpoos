<?php
/**
 * Tool for listing task templates.
 *
 * Allows AI assistants to list available task templates with optional
 * search filtering.
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
 * Lists task templates with optional search filtering.
 */
class WP_MCP_AI_Tool_List_Task_Templates implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_task_templates';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Task Templates', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List available task templates with optional search filtering. Templates contain reusable task checklists that can be instantiated into projects.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'search' => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter templates by title (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of templates to return (default: 20, max: 100)', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
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
			'toolkit'               => 'project_management',
			'post_type'             => 'mcp_task_template',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'developer', 'team_lead' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
		);
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
		return ! empty( $settings['enable_project_management'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list task templates.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build query args.
		$limit = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 100 ) : 20;

		$query_args = array(
			'post_type'      => 'mcp_task_template',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Search filter.
		if ( ! empty( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		$query     = new WP_Query( $query_args );
		$templates = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$template_id = get_the_ID();

				$task_count = get_post_meta( $template_id, '_template_task_count', true );
				$category   = get_post_meta( $template_id, '_template_category', true );

				// Count checkbox items in content.
				$content       = get_the_content();
				$checkbox_ct   = preg_match_all( '/^\s*-\s*\[[ xX]\]\s*.+$/m', $content );
				$display_count = $checkbox_ct > 0 ? $checkbox_ct : ( absint( $task_count ) ? absint( $task_count ) : 0 );

				$templates[] = array(
					'id'          => $template_id,
					'title'       => get_the_title(),
					'description' => get_the_excerpt() ? get_the_excerpt() : '',
					'category'    => $category ? $category : '',
					'task_count'  => $display_count,
					'created_at'  => get_the_date( 'c' ),
					'updated_at'  => get_the_modified_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'   => true,
			'count'     => count( $templates ),
			'total'     => $query->found_posts,
			'templates' => $templates,
		);
	}
}
