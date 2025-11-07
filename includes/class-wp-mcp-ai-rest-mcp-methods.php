<?php
/**
 * MCP Protocol Handler Methods for WP_MCP_AI_REST.
 *
 * This file contains the Model Context Protocol (MCP) implementation
 * for the REST API controller.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for MCP protocol methods.
 */
trait WP_MCP_AI_REST_MCP_Methods {

	/**
	 * Handle MCP protocol requests using JSON-RPC 2.0 format.
	 *
	 * This endpoint implements the Model Context Protocol (MCP) specification,
	 * supporting JSON-RPC 2.0 messages for bidirectional communication.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_mcp_request( WP_REST_Request $request ) {
		$body = $request->get_body();

		if ( empty( $body ) ) {
			return $this->mcp_error_response( null, -32700, 'Parse error: Empty request body' );
		}

		$message = json_decode( $body, true );

		if ( null === $message || ! is_array( $message ) ) {
			return $this->mcp_error_response( null, -32700, 'Parse error: Invalid JSON' );
		}

		// Validate JSON-RPC 2.0 structure.
		if ( ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return $this->mcp_error_response(
				isset( $message['id'] ) ? $message['id'] : null,
				-32600,
				'Invalid Request: jsonrpc field must be "2.0"'
			);
		}

		if ( ! isset( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return $this->mcp_error_response(
				isset( $message['id'] ) ? $message['id'] : null,
				-32600,
				'Invalid Request: method field is required and must be a string'
			);
		}

		$method = $message['method'];
		$params = isset( $message['params'] ) ? $message['params'] : array();
		$id     = isset( $message['id'] ) ? $message['id'] : null;

		// Route to appropriate handler based on method.
		$result = $this->route_mcp_method( $method, $params, $request );

		if ( is_wp_error( $result ) ) {
			return $this->mcp_error_response(
				$id,
				$result->get_error_code() === 'wp_mcp_ai_method_not_found' ? -32601 : -32603,
				$result->get_error_message(),
				$result->get_error_data()
			);
		}

		// If this is a notification (no id), return 202 Accepted with no body.
		if ( null === $id ) {
			$response = new WP_REST_Response( null, 202 );
			$this->add_cors_headers( $response );
			return $response;
		}

		// Return successful JSON-RPC response.
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
		$this->add_cors_headers( $response );
		return $response;
	}

	/**
	 * Route MCP method to appropriate handler.
	 *
	 * @param string          $method  MCP method name.
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return mixed|WP_Error Result or error.
	 */
	protected function route_mcp_method( $method, $params, WP_REST_Request $request ) {
		switch ( $method ) {
			case 'initialize':
				return $this->mcp_initialize( $params, $request );

			case 'tools/list':
				return $this->mcp_tools_list( $params, $request );

			case 'tools/call':
				return $this->mcp_tools_call( $params, $request );

			case 'resources/list':
				return $this->mcp_resources_list( $params, $request );

			case 'prompts/list':
				return $this->mcp_prompts_list( $params, $request );

			default:
				return new WP_Error(
					'wp_mcp_ai_method_not_found',
					sprintf( 'Method not found: %s', $method ),
					array( 'status' => 404 )
				);
		}
	}

	/**
	 * Handle MCP initialize request.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array
	 */
	protected function mcp_initialize( $params, WP_REST_Request $request ) {
		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' );
		
		// Build instructions dynamically based on site info.
		if ( ! empty( $site_desc ) ) {
			$instructions = sprintf(
				/* translators: 1: site name, 2: site description */
				__( 'This is a WordPress site (%1$s). %2$s. You can use the available tools to interact with WordPress content, users, and functionality.', 'wp-mcp-ai' ),
				$site_name,
				$site_desc
			);
		} else {
			$instructions = sprintf(
				/* translators: %s: site name */
				__( 'This is a WordPress site (%s). You can use the available tools to interact with WordPress content, users, and functionality.', 'wp-mcp-ai' ),
				$site_name
			);
		}

		return array(
			'protocolVersion' => '2024-11-05',
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => true ),
				'resources' => array(
					'subscribe'   => false,
					'listChanged' => true,
				),
				'prompts'   => array( 'listChanged' => true ),
			),
			'serverInfo'      => array(
				'name'    => 'WP oOS',
				'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
			),
			'instructions'    => $instructions,
		);
	}

	/**
	 * Handle MCP tools/list request.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error
	 */
	protected function mcp_tools_list( $params, WP_REST_Request $request ) {
		$assistant_id = 0;

		// Check if assistant_id is provided in params.
		if ( isset( $params['assistant_id'] ) ) {
			$assistant_id = absint( $params['assistant_id'] );
		}

		$assistant_id = $this->resolve_assistant_id( $assistant_id );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;

		if ( ! $assistant_id ) {
			// Return all tools if no assistant specified.
			$tools = $this->registry->get_tools();
		} else {
			// Get tools allowed for this assistant.
			$assistant_post = $this->validate_assistant_access( $assistant_id );

			if ( is_wp_error( $assistant_post ) ) {
				return $assistant_post;
			}

			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
			$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

			$tools = array();
			foreach ( $allowed_tools as $tool_slug ) {
				$tool = $this->registry->get_tool( $tool_slug );
				if ( $tool ) {
					$tools[] = $tool;
				}
			}
		}

		// Convert tools to MCP format.
		$mcp_tools = array();
		foreach ( $tools as $tool ) {
			$schema = $tool->get_parameters_schema();

			$mcp_tools[] = array(
				'name'        => $tool->get_slug(),
				'description' => $tool->get_description(),
				'inputSchema' => $schema,
			);
		}

		return array( 'tools' => $mcp_tools );
	}

	/**
	 * Handle MCP tools/call request.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error
	 */
	protected function mcp_tools_call( $params, WP_REST_Request $request ) {
		if ( ! isset( $params['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				'Missing required parameter: name',
				array( 'status' => 400 )
			);
		}

		$tool_name = sanitize_text_field( $params['name'] );
		$arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();

		// Use existing tool execution infrastructure.
		$request->set_param( 'tool', $tool_name );
		$request->set_param( 'arguments', $arguments );

		if ( isset( $params['assistant_id'] ) ) {
			$request->set_param( 'assistant_id', absint( $params['assistant_id'] ) );
		}

		$result = $this->handle_tool_request( $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Convert REST response to MCP format.
		$data = $result instanceof WP_REST_Response ? $result->get_data() : $result;

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => is_string( $data['result'] ) ? $data['result'] : wp_json_encode( $data['result'] ),
				),
			),
		);
	}

	/**
	 * Handle MCP resources/list request.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error
	 */
	protected function mcp_resources_list( $params, WP_REST_Request $request ) {
		$assistant_id = 0;

		if ( isset( $params['assistant_id'] ) ) {
			$assistant_id = absint( $params['assistant_id'] );
		}

		$assistant_id = $this->resolve_assistant_id( $assistant_id );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;

		$resources = array();

		if ( $assistant_id ) {
			$assistant_post = $this->validate_assistant_access( $assistant_id );

			if ( ! is_wp_error( $assistant_post ) ) {
				$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

				// Add memory files as resources.
				if ( isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ) {
					foreach ( $config['memory_files'] as $file_id ) {
						$file_id = absint( $file_id );
						if ( $file_id ) {
							$attachment = get_post( $file_id );
							if ( $attachment ) {
								$resources[] = array(
									'uri'         => wp_get_attachment_url( $file_id ),
									'name'        => get_the_title( $attachment ),
									'description' => get_post_field( 'post_excerpt', $attachment ),
									'mimeType'    => get_post_mime_type( $attachment ),
								);
							}
						}
					}
				}
			}
		}

		return array( 'resources' => $resources );
	}

	/**
	 * Handle MCP prompts/list request.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array
	 */
	protected function mcp_prompts_list( $params, WP_REST_Request $request ) {
		$prompts = array();

		// Get all assistants as prompts.
		$query = new WP_Query(
			array(
				'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post->ID );

				$prompts[] = array(
					'name'        => $post->post_name,
					'description' => get_the_title( $post ),
					'arguments'   => array(),
				);
			}
		}

		return array( 'prompts' => $prompts );
	}

	/**
	 * Create a JSON-RPC error response.
	 *
	 * @param mixed  $id      Request ID or null.
	 * @param int    $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Additional error data.
	 * @return WP_REST_Response
	 */
	protected function mcp_error_response( $id, $code, $message, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( null !== $data ) {
			$error['data'] = $data;
		}

		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => $error,
			),
			$code === -32700 ? 400 : ( $code === -32601 ? 404 : 500 )
		);
		$this->add_cors_headers( $response );
		return $response;
	}

	/**
	 * Add CORS headers to MCP responses for OpenAI Agent Builder compatibility.
	 *
	 * By default, allows all origins for maximum compatibility. Can be restricted
	 * via the 'wp_mcp_ai_cors_allow_origin' filter for production environments.
	 *
	 * @param WP_REST_Response $response Response object to modify.
	 */
	protected function add_cors_headers( $response ) {
		/**
		 * Filter the Access-Control-Allow-Origin header value.
		 *
		 * By default set to '*' for maximum compatibility with external AI services.
		 * For production, consider restricting to specific trusted domains.
		 *
		 * @param string $origin The origin value to allow. Default '*'.
		 *
		 * @example
		 * // Restrict to specific domain:
		 * add_filter( 'wp_mcp_ai_cors_allow_origin', function() {
		 *     return 'https://api.openai.com';
		 * } );
		 *
		 * // Allow multiple domains (requires additional logic):
		 * add_filter( 'wp_mcp_ai_cors_allow_origin', function() {
		 *     $allowed = array( 'https://api.openai.com', 'https://api.anthropic.com' );
		 *     $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
		 *     return in_array( $origin, $allowed, true ) ? $origin : '';
		 * } );
		 */
		$allow_origin = apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );

		$response->header( 'Access-Control-Allow-Origin', $allow_origin );
		$response->header( 'Access-Control-Allow-Methods', 'GET, POST, OPTIONS' );
		$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce, X-WP-MCP-AI-Mesh-Key, X-WP-MCP-AI-Guest' );
		$response->header( 'Access-Control-Max-Age', '3600' );
	}
}
