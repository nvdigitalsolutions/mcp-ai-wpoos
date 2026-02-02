<?php
/**
 * Intelligent mesh query tool with AI-powered routing.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allows AI assistants to query mesh network with intelligent peer selection.
 *
 * This tool uses AI-powered routing to automatically select the optimal peer
 * site based on current load, response times, and task complexity. It supports
 * automatic failover and retry logic for resilient distributed compute.
 */
class WP_MCP_AI_Tool_Query_Mesh_Intelligent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'query_mesh_intelligent';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Query Mesh (Intelligent Routing)', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Send a prompt to the mesh network with AI-powered peer selection and automatic failover. The system intelligently routes your request to the optimal peer site based on current load, response times, and task complexity.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prompt' => array(
					'type'        => 'string',
					'description' => __( 'The message or question to send to the mesh network. The system will automatically select the best peer site to handle your request.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'prompt' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to query the mesh network.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Check if mesh networking is enabled.
		if ( empty( $settings['enable_mesh'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mesh_disabled',
				__( 'Mesh networking is not enabled. Please enable it in Settings → NV oOS → Mesh Network.', 'mcp-ai-wpoos' )
			);
		}

		// Extract and validate prompt.
		$prompt = isset( $arguments['prompt'] ) ? trim( (string) $arguments['prompt'] ) : '';

		if ( '' === $prompt ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Please provide a prompt to send to the mesh network.', 'mcp-ai-wpoos' )
			);
		}

		// Get assistant ID from context.
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		if ( ! $assistant_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_assistant_id',
				__( 'Assistant ID is required for intelligent mesh routing.', 'mcp-ai-wpoos' )
			);
		}

		// Get the actual routing strategy configured for this assistant.
		$hub_config       = WP_MCP_AI_Mesh_Router::get_hub_config( $assistant_id );
		$routing_strategy = isset( $hub_config['routing_strategy'] ) ? $hub_config['routing_strategy'] : 'ai_optimized';

		// Use the mesh router for intelligent query with retry.
		$result = WP_MCP_AI_Mesh_Router::query_with_retry( $assistant_id, $prompt, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract the assistant's response from the result.
		$response_content = '';
		if ( isset( $result['choices'][0]['message']['content'] ) ) {
			$response_content = $result['choices'][0]['message']['content'];
		} elseif ( isset( $result['content'] ) ) {
			$response_content = $result['content'];
		}

		return array(
			'response' => $response_content,
			'metadata' => array(
				'routing_method' => $routing_strategy,
				'query_success'  => true,
			),
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

			'toolkit'               => 'research_discovery',

			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),

			'profession_tags'       => array( 'researcher', 'analyst', 'data_scientist' ),

			'risk_level'            => 'info',

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
