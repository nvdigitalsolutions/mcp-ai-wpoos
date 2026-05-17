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
		return __( 'Agent Client Protocol (ACP)', 'wp-mcp-ai' );
	}

	/**
	 * Render section description.
	 */
	public function render_description() {
		echo '<p>' . esc_html__( 'Configure the Agent Client Protocol (ACP) server. This allows external IDEs like Zed and JetBrains to connect to your WordPress assistants using the standardized ACP JSON-RPC format.', 'wp-mcp-ai' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Connection Endpoint:', 'wp-mcp-ai' ) . '</strong> <code>' . esc_url( rest_url( 'mcp-ai/v1/acp' ) ) . '</code></p>';
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
				'title'       => __( 'Enable ACP Server', 'wp-mcp-ai' ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, the /acp REST routes will be available and the site will advertise ACP capabilities via the .well-known/ai-peer endpoint.', 'wp-mcp-ai' ),
				'default'     => '1',
			),
			array(
				'id'          => 'acp_require_approval',
				'title'       => __( 'Require Tool Approval', 'wp-mcp-ai' ),
				'type'        => 'checkbox',
				'description' => __( 'If enabled, dangerous tool calls initiated from an ACP client will trigger the interactive session/request_permission flow in the IDE.', 'wp-mcp-ai' ),
				'default'     => '1',
			),
		);
	}
}
