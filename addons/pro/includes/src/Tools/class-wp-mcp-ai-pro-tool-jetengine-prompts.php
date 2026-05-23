<?php
/**
 * JetEngine Prompts Tool
 *
 * Discovers and retrieves JetEngine MCP prompt templates.
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
 * Tool for accessing JetEngine MCP prompt templates.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Tool_JetEngine_Prompts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Requires JetEngine 3.8+ with MCP Server enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'jetengine_prompts';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'JetEngine Prompts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Discover and retrieve JetEngine MCP prompt templates. Use list action to see available prompts, or get to render a specific prompt with arguments. Prompts can be used for AI-powered content generation, code review, and site management tasks.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Action: list (discover available prompts), get (render a specific prompt with arguments).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'get' ),
				),
				'name'      => array(
					'type'        => 'string',
					'description' => __( 'Prompt name. Required when action is get.', 'mcp-ai-wpoos-pro' ),
				),
				'arguments' => array(
					'type'        => 'object',
					'description' => __( 'Arguments to pass to the prompt template. Structure depends on the prompt being rendered.', 'mcp-ai-wpoos-pro' ),
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
		return array( 'pro', 'read-only', 'requires-plugin', 'local-only' );
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
			'risk_level'          => 'standard',
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

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		switch ( $action ) {
			case 'list':
				return $this->list_prompts( $client );

			case 'get':
				$name        = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
				$prompt_args = isset( $arguments['arguments'] ) ? $arguments['arguments'] : array();
				return $this->get_prompt( $client, $name, $prompt_args );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action. Use list or get.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * List available prompts.
	 *
	 * @param WP_MCP_AI_JetEngine_MCP_Client $client MCP client instance.
	 * @return array|WP_Error Prompts list or error.
	 */
	private function list_prompts( $client ) {
		$result = $client->prompts_list();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$prompts = isset( $result['prompts'] ) ? $result['prompts'] : $result;

		return array(
			'success'       => true,
			'prompts_count' => is_array( $prompts ) ? count( $prompts ) : 0,
			'prompts'       => $prompts,
		);
	}

	/**
	 * Get a specific prompt.
	 *
	 * @param WP_MCP_AI_JetEngine_MCP_Client $client      MCP client instance.
	 * @param string                         $name        Prompt name.
	 * @param array                          $prompt_args Prompt arguments.
	 * @return array|WP_Error Prompt content or error.
	 */
	private function get_prompt( $client, $name, $prompt_args ) {
		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', __( 'Prompt name is required for get action.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = $client->prompts_get( $name, $prompt_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'name'    => $name,
			'prompt'  => $result,
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
