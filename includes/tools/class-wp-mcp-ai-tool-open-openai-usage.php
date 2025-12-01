<?php
/**
 * Tool returning a quick link to the OpenAI usage dashboard.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a link so administrators can review OpenAI usage analytics.
 */
class WP_MCP_AI_Tool_Open_OpenAI_Usage implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'open_openai_usage';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Open OpenAI Usage', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the URL for the OpenAI platform usage dashboard so administrators can review billing and quota details.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view the OpenAI usage link.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		return array(
			'summary'     => __( 'OpenAI Usage Dashboard', 'wp-mcp-ai' ),
			'label'       => __( 'OpenAI Usage Dashboard', 'wp-mcp-ai' ),
			'url'         => 'https://platform.openai.com/usage',
			'description' => __( 'Visit the OpenAI platform usage dashboard to review billing, quotas, and consumption analytics.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
