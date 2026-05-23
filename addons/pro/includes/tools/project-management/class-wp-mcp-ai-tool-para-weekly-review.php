<?php
/**
 * Tool: para_weekly_review
 *
 * Returns the PARA weekly-review summary: dormant areas, dormant resources,
 * and archive candidates. Backed by the lifecycle service's daily sweep.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Weekly review summary.
 */
class WP_MCP_AI_Tool_PARA_Weekly_Review implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'para_weekly_review';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'PARA: Weekly Review', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate the PARA weekly-review summary. Returns: areas with no recent activity, resources unreferenced for 90+ days, and archive candidates (completed/cancelled projects not yet archived). Useful for the AI assistant to drive the user through a weekly review.', 'mcp-ai-wpoos-pro' );
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
				'force_refresh' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, regenerate the summary instead of using the cached daily sweep.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) && WP_MCP_AI_PARA_Taxonomy::is_enabled();
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to run a weekly review.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! empty( $arguments['force_refresh'] ) ) {
			delete_transient( 'wp_mcp_ai_para_review_summary' );
			WP_MCP_AI_PARA_Lifecycle::run_sweep();
		}
		return array(
			'success' => true,
			'summary' => WP_MCP_AI_PARA_Lifecycle::get_weekly_review_summary(),
		);
	}
}
