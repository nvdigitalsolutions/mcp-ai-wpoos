<?php
/**
 * Tool returning a quick link to the OpenAI usage dashboard.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a link so administrators can review OpenAI usage analytics.
 */
class WP_MCP_AI_Tool_Open_OpenAI_Usage implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Open OpenAI Usage', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the URL for the OpenAI platform usage dashboard so administrators can review billing and quota details.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Getting the OpenAI usage dashboard URL to review billing, quotas, and consumption.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Local analytics; use openai_usage_analytics. Request-level logs; use open_openai_logs.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'open_openai_logs', 'openai_usage_analytics' ),
			'notes'           => __( 'Returns a URL only; no server-side API call is made. Requires manage_options.', 'mcp-ai-wpoos' ),
		);
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
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view the OpenAI usage link.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$summary_text = __( 'OpenAI Usage Dashboard', 'mcp-ai-wpoos' );

		return array(
			'message'     => $summary_text,
			'summary'     => $summary_text,
			'label'       => __( 'OpenAI Usage Dashboard', 'mcp-ai-wpoos' ),
			'url'         => 'https://platform.openai.com/usage',
			'description' => __( 'Visit the OpenAI platform usage dashboard to review billing, quotas, and consumption analytics.', 'mcp-ai-wpoos' ),
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'experimentation', 'peer_to_peer' ),

			'profession_tags'       => array( 'mlops_specialist', 'business_analyst' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // Returns an external URL for user navigation but makes no server-side HTTP calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
