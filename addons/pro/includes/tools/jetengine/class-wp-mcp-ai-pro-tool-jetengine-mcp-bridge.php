<?php
/**
 * JetEngine MCP Bridge Tool
 *
 * Bridge tool that discovers and proxies JetEngine MCP Server tools.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge tool for JetEngine MCP Server.
 *
 * Dynamically discovers JetEngine's MCP tools via tools/list and allows
 * calling any of them. Provides a unified interface between NV oOS
 * assistants and JetEngine's native MCP server.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Tool_JetEngine_MCP_Bridge implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @return bool True if JetEngine 3.8+ MCP server is available.
	 */
	public static function is_available() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Compat' ) ) {
			return false;
		}
		return WP_MCP_AI_JetEngine_Compat::has_mcp_server();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'JetEngine MCP Bridge requires JetEngine 3.8+ with MCP Server enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'jetengine_mcp';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'JetEngine MCP Bridge', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Bridge to JetEngine 3.8+ MCP Server. Discover and call JetEngine\'s native MCP tools for managing site structures (CPTs, taxonomies, meta fields, relations) and accessing site context. Use discover_tools to list available tools, call_tool to execute any tool, or get_site_context for a quick site overview.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'    => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: discover_tools (list available MCP tools), call_tool (execute a specific tool), get_site_context (get site structure overview).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'discover_tools', 'call_tool', 'get_site_context' ),
				),
				'tool_name' => array(
					'type'        => 'string',
					'description' => __( 'Name of the JetEngine MCP tool to call. Required when action is call_tool.', 'mcp-ai-wpoos-pro' ),
				),
				'arguments' => array(
					'type'        => 'object',
					'description' => __( 'Arguments to pass to the MCP tool. Structure depends on the tool being called.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'write', 'requires-plugin', 'local-only' );
	}

	/**
	 * Get tool definition.
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => $this->get_name(),
			'description'         => $this->get_description(),
			'parameters'          => $this->get_parameters_schema(),
			'required_capability' => 'manage_options',
			'toolkit'             => 'jetengine_mcp_bridge',
			'risk_level'          => 'elevated',
			'capability_flags'    => $this->get_capability_flags(),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'insufficient_permissions', __( 'Requires manage_options capability.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		switch ( $action ) {
			case 'discover_tools':
				return $this->discover_tools( $client );

			case 'call_tool':
				$tool_name = isset( $arguments['tool_name'] ) ? sanitize_text_field( $arguments['tool_name'] ) : '';
				$tool_args = isset( $arguments['arguments'] ) ? $arguments['arguments'] : array();
				return $this->call_tool( $client, $tool_name, $tool_args );

			case 'get_site_context':
				return $this->get_site_context( $client );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action. Use discover_tools, call_tool, or get_site_context.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Discover available MCP tools.
	 *
	 * @param WP_MCP_AI_JetEngine_MCP_Client $client MCP client instance.
	 * @return array|WP_Error Tools list or error.
	 */
	private function discover_tools( $client ) {
		$result = $client->tools_list();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$tools = isset( $result['tools'] ) ? $result['tools'] : $result;

		return array(
			'success'     => true,
			'tools_count' => is_array( $tools ) ? count( $tools ) : 0,
			'tools'       => $tools,
		);
	}

	/**
	 * Call a specific MCP tool.
	 *
	 * @param WP_MCP_AI_JetEngine_MCP_Client $client    MCP client instance.
	 * @param string                         $tool_name Tool name.
	 * @param array                          $tool_args Tool arguments.
	 * @return array|WP_Error Tool result or error.
	 */
	private function call_tool( $client, $tool_name, $tool_args ) {
		if ( empty( $tool_name ) ) {
			return new WP_Error( 'missing_tool_name', __( 'tool_name is required for call_tool action.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $client->tools_call( $tool_name, $tool_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'   => true,
			'tool_name' => $tool_name,
			'result'    => $result,
		);
	}

	/**
	 * Get site context via the site_context MCP tool.
	 *
	 * @param WP_MCP_AI_JetEngine_MCP_Client $client MCP client instance.
	 * @return array|WP_Error Site context or error.
	 */
	private function get_site_context( $client ) {
		$result = $client->tools_call( 'site_context' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'context' => $result,
		);
	}

	/**
	 * Get MCP client instance.
	 *
	 * @return WP_MCP_AI_JetEngine_MCP_Client|WP_Error Client or error.
	 */
	private function get_client() {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_MCP_Client' ) ) {
			$client_file = defined( 'WP_MCP_AI_PRO_PATH' )
				? WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-jetengine-mcp-client.php'
				: '';

			if ( ! empty( $client_file ) && file_exists( $client_file ) ) {
				require_once $client_file;
			} else {
				return new WP_Error( 'mcp_client_missing', __( 'MCP client class is not available.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		return new WP_MCP_AI_JetEngine_MCP_Client();
	}
}
