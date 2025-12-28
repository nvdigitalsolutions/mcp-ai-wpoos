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
			$response->header( 'Content-Type', 'application/json; charset=utf-8' );
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
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );
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
					sprintf(
						/* translators: %s: method name */
						__( 'MCP method not found: %s', 'wp-mcp-ai' ),
						$method
					),
					array(
						'status'  => 404,
						'actions' => array(
							'check_method' => __( 'Verify the method name is spelled correctly and supported by this server.', 'wp-mcp-ai' ),
							'list_methods' => __( 'Supported methods: initialize, tools/list, tools/call, resources/list, prompts/list', 'wp-mcp-ai' ),
						),
					)
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

		$response = array(
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
				'name'    => 'NV oOS',
				'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
			),
			'instructions'    => $instructions,
		);

		/**
		 * Filter to optionally include tools in the initialize response.
		 *
		 * Some MCP clients (like OpenAI Agent Builder) expect to see tool information
		 * immediately after initialization without making a separate tools/list call.
		 * This filter allows including tool information directly in the initialize response
		 * for better compatibility with such clients.
		 *
		 * @since 1.1.0
		 *
		 * @param bool            $include_tools Whether to include tools in initialize response.
		 * @param array           $params        Initialize method parameters.
		 * @param WP_REST_Request $request       REST request instance.
		 */
		$include_tools = apply_filters( 'wp_mcp_ai_initialize_include_tools', true, $params, $request );

		if ( $include_tools ) {
			// Get tools using the same logic as tools/list for consistency.
			$tools_result = $this->mcp_tools_list( $params, $request );

			if ( ! is_wp_error( $tools_result ) && isset( $tools_result['tools'] ) ) {
				$response['tools'] = $tools_result['tools'];
			}
		}

		return $response;
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
			try {
				$schema = $tool->get_parameters_schema();

				// Validate that the schema is a valid array.
				if ( ! is_array( $schema ) ) {
					WP_MCP_AI_Logger::log_event(
						'error',
						'Tool returned invalid schema in MCP tools/list',
						array(
							'tool_slug'   => $tool->get_slug(),
							'schema_type' => gettype( $schema ),
						)
					);
					continue;
				}

				$mcp_tools[] = array(
					'name'        => $tool->get_slug(),
					'description' => $tool->get_description(),
					'inputSchema' => $schema,
				);
			} catch ( Exception $e ) {
				// Log the error and skip this tool.
				WP_MCP_AI_Logger::log_event(
					'error',
					'Tool schema generation failed in MCP tools/list',
					array(
						'tool_slug' => $tool->get_slug(),
						'error'     => $e->getMessage(),
						'trace'     => $e->getTraceAsString(),
					)
				);
				continue;
			} catch ( Error $e ) {
				// Catch PHP 7+ errors as well.
				WP_MCP_AI_Logger::log_event(
					'error',
					'Tool schema generation failed in MCP tools/list with PHP Error',
					array(
						'tool_slug' => $tool->get_slug(),
						'error'     => $e->getMessage(),
						'trace'     => $e->getTraceAsString(),
					)
				);
				continue;
			}
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
				__( 'Missing required parameter: name. MCP tools/call requires a tool name to execute.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_tool_name' => __( 'Include the "name" parameter in your tools/call request params with the slug of the tool you want to execute.', 'wp-mcp-ai' ),
						'list_available'    => __( 'Call the tools/list method first to see available tools and their names.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$tool_name = sanitize_text_field( $params['name'] );

		// Validate arguments is an object/array if provided.
		if ( isset( $params['arguments'] ) && ! is_array( $params['arguments'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'The "arguments" parameter must be an object/array.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'fix_arguments_type' => __( 'Ensure the "arguments" field contains a JSON object with key-value pairs for the tool parameters.', 'wp-mcp-ai' ),
					),
				)
			);
		}

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

		// Guard against missing 'result' key.
		if ( ! isset( $data['result'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_tool_response',
				__( 'Tool response missing required "result" key. This is an internal error.', 'wp-mcp-ai' ),
				array(
					'status'  => 500,
					'actions' => array(
						'report_issue' => __( 'This is likely a bug in the tool implementation. Please report this to the plugin administrator.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$tool_result = $data['result'];

		// Check if tool already returned MCP-compatible structured content.
		if ( $this->is_mcp_content_array( $tool_result ) ) {
			// Tool already returned properly structured MCP content - use it directly.
			return array( 'content' => $tool_result );
		}

		// Convert tool result to MCP text content.
		$text_content = $this->convert_to_text_content( $tool_result );

		if ( is_wp_error( $text_content ) ) {
			return $text_content;
		}

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text_content,
				),
			),
		);
	}

	/**
	 * Check if a value is a valid MCP content array.
	 *
	 * MCP content is an array of content items, where each item has a 'type' field.
	 * Valid types include: 'text', 'image', 'resource', 'embedded_resource'.
	 *
	 * @param mixed $value Value to check.
	 * @return bool True if value is a valid MCP content array.
	 */
	protected function is_mcp_content_array( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		// Empty arrays are not valid MCP content.
		if ( empty( $value ) ) {
			return false;
		}

		// Check if this is a numeric array (content items).
		if ( ! isset( $value[0] ) ) {
			return false;
		}

		// All items must have a 'type' field.
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['type'] ) ) {
				return false;
			}

			$type = $item['type'];

			// Validate known MCP content types and their required fields.
			switch ( $type ) {
				case 'text':
					if ( ! isset( $item['text'] ) ) {
						return false;
					}
					break;
				case 'image':
					if ( ! isset( $item['data'] ) && ! isset( $item['url'] ) ) {
						return false;
					}
					break;
				case 'resource':
					if ( ! isset( $item['resource'] ) ) {
						return false;
					}
					break;
				case 'embedded_resource':
					if ( ! isset( $item['resource'] ) ) {
						return false;
					}
					break;
				default:
					// Unknown type - could be valid for future MCP versions.
					// Allow it but log for monitoring (if logger is available).
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'mcp_unknown_content_type',
							'Unknown MCP content type encountered',
							array( 'type' => $type )
						);
					}
					break;
			}
		}

		return true;
	}

	/**
	 * Convert a tool result to text content for MCP.
	 *
	 * @param mixed $tool_result The tool result to convert.
	 * @return string|WP_Error Text content or error.
	 */
	protected function convert_to_text_content( $tool_result ) {
		// Handle string results directly.
		if ( is_string( $tool_result ) ) {
			return $tool_result;
		}

		// Handle scalar values (int, float, bool, null).
		if ( is_scalar( $tool_result ) || is_null( $tool_result ) ) {
			$text_content = wp_json_encode( $tool_result );
			if ( false === $text_content ) {
				return new WP_Error(
					'wp_mcp_ai_encoding_failed',
					sprintf(
						/* translators: %s: data type */
						__( 'Failed to encode scalar tool result of type: %s', 'wp-mcp-ai' ),
						gettype( $tool_result )
					),
					array(
						'status'  => 500,
						'actions' => array(
							'check_result' => __( 'This is an internal error. The tool returned data that could not be encoded.', 'wp-mcp-ai' ),
						),
					)
				);
			}
			return $text_content;
		}

		// Handle arrays and objects - encode as JSON.
		if ( is_array( $tool_result ) || is_object( $tool_result ) ) {
			// Try pretty printing first for better readability.
			$text_content = wp_json_encode( $tool_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( false === $text_content ) {
				// Fallback to basic encoding.
				$text_content = wp_json_encode( $tool_result );

				if ( false === $text_content ) {
					// Encoding failed completely - return structured error.
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_error(
							'Failed to JSON encode tool result',
							array(
								'type'      => gettype( $tool_result ),
								'is_array'  => is_array( $tool_result ),
								'is_object' => is_object( $tool_result ),
							)
						);
					}

					return new WP_Error(
						'wp_mcp_ai_encoding_failed',
						__( 'Unable to encode tool result to JSON. The tool may have returned circular references or invalid data.', 'wp-mcp-ai' ),
						array(
							'status'  => 500,
							'actions' => array(
								'check_tool'   => __( 'This is likely a bug in the tool implementation. Check if the tool is returning circular references or non-serializable data.', 'wp-mcp-ai' ),
								'report_issue' => __( 'Please report this to the plugin administrator with the tool name you were trying to execute.', 'wp-mcp-ai' ),
							),
						)
					);
				}
			}

			return $text_content;
		}

		// Unexpected type - return error.
		return new WP_Error(
			'wp_mcp_ai_invalid_result_type',
			sprintf(
				/* translators: %s: data type */
				__( 'Tool result has unexpected type: %s', 'wp-mcp-ai' ),
				gettype( $tool_result )
			),
			array(
				'status'  => 500,
				'actions' => array(
					'report_issue' => __( 'This is an internal error. The tool returned an unexpected data type. Please report this to the plugin administrator.', 'wp-mcp-ai' ),
				),
			)
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
						if ( ! $file_id ) {
							continue;
						}

						$attachment = get_post( $file_id );

						// Validate that the post exists and is an attachment.
						if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
							continue;
						}

						// Get attachment URL and skip if unavailable.
						$attachment_url = wp_get_attachment_url( $file_id );
						if ( false === $attachment_url || empty( $attachment_url ) ) {
							continue;
						}

						$resources[] = array(
							'uri'         => $attachment_url,
							'name'        => get_the_title( $attachment ),
							'description' => get_post_field( 'post_excerpt', $attachment ),
							'mimeType'    => get_post_mime_type( $attachment ),
						);
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
				'post_type'              => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,  // Performance: Skip counting total rows.
				'update_post_term_cache' => false, // Performance: Skip term cache.
				'update_post_meta_cache' => true,  // Keep meta cache for configs.
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
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );
		$this->add_cors_headers( $response );
		return $response;
	}

	/**
	 * Add CORS headers to MCP responses for OpenAI Agent Builder compatibility.
	 *
	 * By default, allows all origins for maximum compatibility. Can be restricted
	 * via the 'wp_mcp_ai_cors_allow_origin' filter for production environments.
	 *
	 * Supports MCP 2024-11-05 specification requirements including:
	 * - GET requests for SSE/Streamable HTTP
	 * - Session management via Mcp-Session-Id header
	 * - Accept header for content negotiation
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
		$response->header( 'Access-Control-Allow-Headers', 'Authorization, Content-Type, X-WP-Nonce, X-WP-MCP-AI-Mesh-Key, X-WP-MCP-AI-Guest, Accept, Mcp-Session-Id' );
		$response->header( 'Access-Control-Expose-Headers', 'Mcp-Session-Id' );
		$response->header( 'Access-Control-Max-Age', '3600' );
	}
}
