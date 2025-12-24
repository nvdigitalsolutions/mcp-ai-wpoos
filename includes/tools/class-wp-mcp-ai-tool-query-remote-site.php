<?php
/**
 * Tool for querying remote WordPress sites in the mesh network.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allows AI assistants to query other WordPress sites running wp-mcp-ai.
 */
class WP_MCP_AI_Tool_Query_Remote_Site implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'query_remote_site';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Query Remote Site', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Send a prompt to a peer site in the mesh network and receive the response from its AI assistant.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'peer_name' => array(
					'type'        => 'string',
					'description' => __( 'The friendly name of the peer site as configured in mesh network settings.', 'wp-mcp-ai' ),
				),
				'prompt'    => array(
					'type'        => 'string',
					'description' => __( 'The message or question to send to the remote site.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'peer_name', 'prompt' ),
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
				__( 'You do not have permission to query remote sites.', 'wp-mcp-ai' )
			);
		}

		
		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}
$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Check if mesh networking is enabled.
		if ( empty( $settings['enable_mesh'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_mesh_disabled',
				__( 'Mesh networking is not enabled. Please enable it in Settings → WP oOS → Mesh Network.', 'wp-mcp-ai' )
			);
		}

		// Extract and validate arguments.
		$peer_name = isset( $arguments['peer_name'] ) ? trim( (string) $arguments['peer_name'] ) : '';
		$prompt    = isset( $arguments['prompt'] ) ? trim( (string) $arguments['prompt'] ) : '';

		if ( '' === $peer_name ) {
			return new WP_Error(
				'wp_mcp_ai_missing_peer_name',
				__( 'Please provide the name of the peer site to query.', 'wp-mcp-ai' )
			);
		}

		if ( '' === $prompt ) {
			return new WP_Error(
				'wp_mcp_ai_missing_prompt',
				__( 'Please provide a prompt to send to the remote site.', 'wp-mcp-ai' )
			);
		}

		// Find the peer site in settings.
		$peer_sites = isset( $settings['mesh_peer_sites'] ) && is_array( $settings['mesh_peer_sites'] )
			? $settings['mesh_peer_sites']
			: array();

		$peer = null;
		foreach ( $peer_sites as $site ) {
			if ( isset( $site['name'] ) && $site['name'] === $peer_name ) {
				$peer = $site;
				break;
			}
		}

		if ( ! $peer ) {
			return new WP_Error(
				'wp_mcp_ai_peer_not_found',
				sprintf(
					/* translators: %s: peer site name */
					__( 'Peer site "%s" not found in mesh network configuration.', 'wp-mcp-ai' ),
					$peer_name
				)
			);
		}

		// Validate peer configuration.
		$peer_url = isset( $peer['url'] ) ? trim( $peer['url'] ) : '';
		$peer_key = isset( $peer['api_key'] ) ? trim( $peer['api_key'] ) : '';

		if ( '' === $peer_url ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_peer_url',
				sprintf(
					/* translators: %s: peer site name */
					__( 'Peer site "%s" has no URL configured.', 'wp-mcp-ai' ),
					$peer_name
				)
			);
		}

		if ( '' === $peer_key ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_peer_key',
				sprintf(
					/* translators: %s: peer site name */
					__( 'Peer site "%s" has no API key configured.', 'wp-mcp-ai' ),
					$peer_name
				)
			);
		}

		// Build the chat endpoint URL.
		$endpoint_url = trailingslashit( $peer_url ) . 'wp-json/mcp-ai/v1/chat';

		// Prepare the request body.
		$body = array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		// Prepare the request headers.
		$headers = array(
			'Content-Type'         => 'application/json',
			'X-WP-MCP-AI-Mesh-Key' => $peer_key,
		);

		// Get timeout from settings.
		$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;
		$timeout = max( 30, $timeout ); // Minimum 30 seconds for remote queries.

		// Make the request.
		$response = wp_remote_post(
			$endpoint_url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
			)
		);

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_request_failed',
				sprintf(
					/* translators: 1: peer site name, 2: error message */
					__( 'Failed to connect to peer site "%1$s": %2$s', 'wp-mcp-ai' ),
					$peer_name,
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_data = json_decode( $body, true );
			$error_msg  = isset( $error_data['message'] ) ? $error_data['message'] : __( 'Unknown error', 'wp-mcp-ai' );

			return new WP_Error(
				'wp_mcp_ai_remote_error',
				sprintf(
					/* translators: 1: peer site name, 2: HTTP status code, 3: error message */
					__( 'Peer site "%1$s" returned error %2$d: %3$s', 'wp-mcp-ai' ),
					$peer_name,
					$status_code,
					$error_msg
				)
			);
		}

		// Parse and return the response.
		$data = json_decode( $body, true );

		if ( ! $data ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				sprintf(
					/* translators: %s: peer site name */
					__( 'Peer site "%s" returned an invalid response.', 'wp-mcp-ai' ),
					$peer_name
				)
			);
		}

		return array(
			'peer_name' => $peer_name,
			'response'  => $data,
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
