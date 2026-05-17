<?php
/**
 * Settings section for ACP (Agent Client Protocol).
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
 * Settings section for ACP integration.
 */
class WP_MCP_AI_Section_ACP extends WP_MCP_AI_Settings_Section {

	/**
	 * Get section ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_acp_section';
	}

	/**
	 * Get section title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Agent Client Protocol (ACP)', 'mcp-ai-wpoos' );
	}

	/**
	 * Get tab ID.
	 *
	 * @return string
	 */
	public function get_tab() {
		return 'orchestration';
	}

	/**
	 * Render section description.
	 */
	public function render_description() {
		echo '<p>' . esc_html__( 'Configure the Agent Client Protocol (ACP) server. This allows external IDEs like Zed and JetBrains to connect to your WordPress assistants using the standardized ACP JSON-RPC format.', 'mcp-ai-wpoos' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Connection Endpoint:', 'mcp-ai-wpoos' ) . '</strong> <code>' . esc_url( rest_url( 'mcp-ai/v1/acp' ) ) . '</code></p>';
	}

	/**
	 * Get fields for this section.
	 *
	 * @return array
	 */
	public function get_fields() {
		return array(
			array(
				'id'          => 'enable_acp_server',
				'title'       => __( 'Enable ACP Server', 'mcp-ai-wpoos' ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, the /acp REST routes will be available and the site will advertise ACP capabilities via the .well-known/ai-peer endpoint.', 'mcp-ai-wpoos' ),
				'default'     => '1',
			),
			array(
				'id'          => 'acp_require_approval',
				'title'       => __( 'Require Tool Approval', 'mcp-ai-wpoos' ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, dangerous tool calls initiated from an ACP client will trigger the interactive session/request_permission flow in the IDE.', 'mcp-ai-wpoos' ),
				'default'     => '1',
			),
		);
	}

	/**
	 * Render the settings section content.
	 */
	public function render() {
		// The settings dashboard renderer handles field output.
	}
}
