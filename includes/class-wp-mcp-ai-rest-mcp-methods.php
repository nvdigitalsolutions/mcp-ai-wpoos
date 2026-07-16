<?php
/**
 * MCP Protocol Handler Methods for WP_MCP_AI_REST.
 *
 * This file contains the Model Context Protocol (MCP) implementation
 * for the REST API controller.
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

		if ( null === $message ) {
			return $this->mcp_error_response( null, -32700, 'Parse error: Invalid JSON' );
		}

		// Handle session management via Mcp-Session-Id header.
		$session_id = $request->get_header( 'Mcp-Session-Id' );

		// JSON-RPC batching: if the decoded body is a sequential array, process each message.
		if ( is_array( $message ) && isset( $message[0] ) ) {
			return $this->handle_mcp_batch( $message, $request, $session_id );
		}

		if ( ! is_array( $message ) ) {
			return $this->mcp_error_response( null, -32700, 'Parse error: Invalid JSON' );
		}

		$response = $this->process_single_mcp_message( $message, $request );

		// Attach session header to response if applicable.
		if ( $response instanceof WP_REST_Response ) {
			$this->attach_session_header( $response, $session_id );
		}

		return $response;
	}

	/**
	 * Handle a JSON-RPC batch request per MCP 2024-11-05 specification.
	 *
	 * Processes an array of JSON-RPC messages and returns an array of responses.
	 * Notifications (messages without an id) are processed but produce no response element.
	 *
	 * @since 2.3.0
	 *
	 * @param array           $messages   Array of JSON-RPC message arrays.
	 * @param WP_REST_Request $request    REST request instance.
	 * @param string|null     $session_id Optional session identifier.
	 * @return WP_REST_Response
	 */
	protected function handle_mcp_batch( array $messages, WP_REST_Request $request, $session_id ) {
		if ( empty( $messages ) ) {
			return $this->mcp_error_response( null, -32600, 'Invalid Request: Empty batch array' );
		}

		/**
		 * Filter the maximum number of messages allowed in a single JSON-RPC batch.
		 *
		 * @since 2.3.0
		 *
		 * @param int $max_batch_size Maximum batch size. Default 20.
		 */
		$max_batch_size = apply_filters( 'wp_mcp_ai_max_batch_size', 20 );

		if ( count( $messages ) > $max_batch_size ) {
			return $this->mcp_error_response(
				null,
				-32600,
				sprintf(
					/* translators: %d: maximum batch size */
					__( 'Invalid Request: Batch too large. Maximum %d messages allowed.', 'mcp-ai-wpoos' ),
					$max_batch_size
				)
			);
		}

		$results = array();

		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) ) {
				$results[] = array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32600,
						'message' => 'Invalid Request: Each batch element must be a JSON object',
					),
				);
				continue;
			}

			$resp = $this->process_single_mcp_message( $msg, $request );

			// Notifications return 202 with null data — skip them in batch results.
			if ( $resp instanceof WP_REST_Response && 202 === $resp->get_status() ) {
				continue;
			}

			if ( $resp instanceof WP_REST_Response ) {
				$results[] = $resp->get_data();
			}
		}

		// If all messages were notifications, return 202 with no body.
		if ( empty( $results ) ) {
			$response = new WP_REST_Response( null, 202 );
			$response->header( 'Content-Type', 'application/json; charset=utf-8' );
			$this->add_cors_headers( $response );
			$this->attach_session_header( $response, $session_id );
			return $response;
		}

		$response = new WP_REST_Response( $results, 200 );
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );
		$this->add_cors_headers( $response );
		$this->attach_session_header( $response, $session_id );
		return $response;
	}

	/**
	 * Process a single JSON-RPC message.
	 *
	 * Validates the message structure and routes it to the appropriate handler.
	 *
	 * @since 2.3.0
	 *
	 * @param array           $message JSON-RPC message.
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response
	 */
	protected function process_single_mcp_message( array $message, WP_REST_Request $request ) {
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

			case 'ping':
				return $this->mcp_ping();

			case 'tools/list':
				return $this->mcp_tools_list( $params, $request );

			case 'tools/call':
				return $this->mcp_tools_call( $params, $request );

			case 'resources/list':
				return $this->mcp_resources_list( $params, $request );

			case 'resources/read':
				return $this->mcp_resources_read( $params, $request );

			case 'prompts/list':
				return $this->mcp_prompts_list( $params, $request );

			case 'prompts/get':
				return $this->mcp_prompts_get( $params, $request );

			case 'completion/complete':
				return $this->mcp_completion_complete( $params, $request );

			case 'logging/setLevel':
				return $this->mcp_logging_set_level( $params );

			case 'notifications/cancelled':
				return $this->mcp_notifications_cancelled( $params );

			case 'notifications/initialized':
				return $this->mcp_notifications_initialized( $params );

			default:
				return new WP_Error(
					'wp_mcp_ai_method_not_found',
					sprintf(
						/* translators: %s: method name */
						__( 'MCP method not found: %s', 'mcp-ai-wpoos' ),
						$method
					),
					array(
						'status'  => 404,
						'actions' => array(
							'check_method' => __( 'Verify the method name is spelled correctly and supported by this server.', 'mcp-ai-wpoos' ),
							'list_methods' => __( 'Supported methods: initialize, ping, tools/list, tools/call, resources/list, resources/read, prompts/list, prompts/get, completion/complete, logging/setLevel, notifications/cancelled, notifications/initialized', 'mcp-ai-wpoos' ),
						),
					)
				);
		}
	}

	/**
	 * Handle MCP initialize request.
	 *
	 * When an assistant_id is provided, the response carries the assistant's
	 * system prompt, professional role context, model preferences, and knowledge
	 * base references — turning every NV oOS assistant into a fully-scoped,
	 * personality-aware MCP server.
	 *
	 * @since 1.0.0
	 * @since 2.4.0 Added assistant_id resolution for scoped instructions and modelPreferences.
	 *
	 * @param array           $params  Method parameters. Accepts optional 'assistant_id'.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array Initialize result payload.
	 */
	protected function mcp_initialize( $params, WP_REST_Request $request ) {
		// Resolve assistant identity from params, token scope, and team routing.
		$assistant_id = 0;
		if ( isset( $params['assistant_id'] ) ) {
			$assistant_id = absint( $params['assistant_id'] );
		}
		$assistant_id = $this->resolve_assistant_id( $assistant_id );
		$scoped_id    = $this->apply_token_assistant_scope( $assistant_id );

		if ( ! is_wp_error( $scoped_id ) ) {
			$assistant_id = $scoped_id;
		}

		// Build instructions: assistant-scoped when available, generic site-level otherwise.
		if ( $assistant_id && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
			$instructions     = $this->build_assistant_instructions( $assistant_config, $assistant_id );
		} else {
			$site_name = get_bloginfo( 'name' );
			$site_desc = get_bloginfo( 'description' );

			if ( ! empty( $site_desc ) ) {
				$instructions = sprintf(
					/* translators: 1: site name, 2: site description */
					__( 'This is a WordPress site (%1$s). %2$s. You can use the available tools to interact with WordPress content, users, and functionality.', 'mcp-ai-wpoos' ),
					$site_name,
					$site_desc
				);
			} else {
				$instructions = sprintf(
					/* translators: %s: site name */
					__( 'This is a WordPress site (%s). You can use the available tools to interact with WordPress content, users, and functionality.', 'mcp-ai-wpoos' ),
					$site_name
				);
			}
		}

		$response = array(
			'protocolVersion' => '2024-11-05',
			'capabilities'    => array(
				'tools'       => array( 'listChanged' => true ),
				'resources'   => array(
					'subscribe'   => false,
					'listChanged' => true,
				),
				'prompts'     => array( 'listChanged' => true ),
				'completions' => new stdClass(),
				'logging'     => new stdClass(),
			),
			'serverInfo'      => array(
				'name'    => $assistant_id ? get_the_title( $assistant_id ) : 'NV oOS',
				'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
			),
			'instructions'    => $instructions,
		);

		// Include model preferences when the assistant has them configured.
		// This is a community extension supported by Zed, Claude Desktop, and Cursor.
		if ( $assistant_id && ! empty( $assistant_config['model'] ) ) {
			$model_prefs = array();
			if ( ! empty( $assistant_config['model'] ) ) {
				$model_prefs['model'] = $assistant_config['model'];
			}
			if ( null !== $assistant_config['temperature'] ) {
				$model_prefs['temperature'] = $assistant_config['temperature'];
			}
			if ( ! empty( $model_prefs ) ) {
				$response['modelPreferences'] = $model_prefs;
			}
		}

		/**
		 * Filter the instructions returned in the MCP initialize response.
		 *
		 * Allows plugins and integrators to enrich or override the system
		 * prompt delivered to MCP clients at connection time.
		 *
		 * @since 2.4.0
		 *
		 * @param string $instructions  The assembled instructions string.
		 * @param int    $assistant_id  Resolved assistant post ID (0 when generic).
		 * @param array  $assistant_config Full assistant configuration (empty when generic).
		 */
		$response['instructions'] = apply_filters(
			'wp_mcp_ai_mcp_initialize_instructions',
			$response['instructions'],
			$assistant_id,
			$assistant_id ? $assistant_config : array()
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

			// OAuth 2.0 discovery (MCP Authorization Specification 2025-06-18).
			// Advertise OAuth metadata so clients like Codex and Claude Desktop
			// can offer a browser-based login flow.
		if ( class_exists( 'WP_MCP_AI_OAuth_Server' ) ) {
			$response['_meta'] = array(
				'oauth' => WP_MCP_AI_OAuth_Server::get_instance()->get_protected_resource_metadata(),
			);
		}

			return $response;
	}

	/**
	 * Build complete MCP instructions from assistant configuration.
	 *
	 * Layering order (each subsequent layer is appended when present):
	 * 1. System prompt from post meta (already assembled by get_assistant_configuration
	 *    with primary roles and skills injected).
	 * 2. Model and configuration notes for client awareness.
	 * 3. Knowledge base references (vector store, preferred datasets).
	 *
	 * @since 2.4.0
	 *
	 * @param array $assistant_config Full assistant configuration from get_assistant_configuration().
	 * @param int   $assistant_id     Assistant post ID for reading additional meta.
	 * @return string Complete MCP system prompt for the initialize handshake.
	 */
	protected function build_assistant_instructions( array $assistant_config, $assistant_id ) {
		unset( $assistant_id ); // Reserved for future use (per-assistant instruction customisation).
		$instructions = '';

		// 1. System prompt — the canonical personality definition.
		if ( ! empty( $assistant_config['system_prompt'] ) ) {
			$instructions = $assistant_config['system_prompt'];
		}

		// 2. Model and configuration notes.
		$config_notes = array();
		if ( ! empty( $assistant_config['model'] ) ) {
			$config_notes[] = sprintf(
				/* translators: %s: model identifier */
				__( 'Model: %s', 'mcp-ai-wpoos' ),
				$assistant_config['model']
			);
		}
		if ( null !== $assistant_config['temperature'] ) {
			$config_notes[] = sprintf(
				/* translators: %s: temperature value */
				__( 'Temperature: %s', 'mcp-ai-wpoos' ),
				$assistant_config['temperature']
			);
		}
		if ( ! empty( $config_notes ) ) {
			$instructions .= "\n\n---\n\n## " . __( 'Configuration', 'mcp-ai-wpoos' ) . "\n\n";
			$instructions .= implode( "\n", $config_notes );
		}

		// 3. Knowledge base references.
		$kb_notes = array();
		if ( ! empty( $assistant_config['vector_store_id'] ) ) {
			$kb_notes[] = sprintf(
				/* translators: %s: vector store identifier */
				__( 'Vector store: %s', 'mcp-ai-wpoos' ),
				$assistant_config['vector_store_id']
			);
		}
		if ( ! empty( $assistant_config['preferred_datasets'] ) && is_array( $assistant_config['preferred_datasets'] ) ) {
			$kb_notes[] = sprintf(
				/* translators: %s: comma-separated dataset names */
				__( 'Preferred datasets: %s', 'mcp-ai-wpoos' ),
				implode( ', ', $assistant_config['preferred_datasets'] )
			);
		}
		if ( ! empty( $kb_notes ) ) {
			$instructions .= "\n\n---\n\n## " . __( 'Knowledge Base', 'mcp-ai-wpoos' ) . "\n\n";
			$instructions .= implode( "\n", $kb_notes );
		}

		return $instructions;
	}

	/**
	 * Handle MCP tools/list request.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error
	 */
	protected function mcp_tools_list( $params, WP_REST_Request $request ) {
		unset( $request ); // Required by MCP protocol method signature.
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

				$tool_entry = array(
					'name'        => $tool->get_slug(),
					'description' => $tool->get_description(),
					'inputSchema' => $schema,
				);

				// Add MCP annotations from capability flags (MCP 2024-11-05).
				$annotations = $this->build_tool_annotations( $tool );
				if ( ! empty( $annotations ) ) {
					$tool_entry['annotations'] = $annotations;
				}

				$mcp_tools[] = $tool_entry;
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
				__( 'Missing required parameter: name. MCP tools/call requires a tool name to execute.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_tool_name' => __( 'Include the "name" parameter in your tools/call request params with the slug of the tool you want to execute.', 'mcp-ai-wpoos' ),
						'list_available'    => __( 'Call the tools/list method first to see available tools and their names.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		$tool_name = sanitize_text_field( $params['name'] );

		// Validate arguments is an object/array if provided.
		if ( isset( $params['arguments'] ) && ! is_array( $params['arguments'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'The "arguments" parameter must be an object/array.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'fix_arguments_type' => __( 'Ensure the "arguments" field contains a JSON object with key-value pairs for the tool parameters.', 'mcp-ai-wpoos' ),
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
				__( 'Tool response missing required "result" key. This is an internal error.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 500,
					'actions' => array(
						'report_issue' => __( 'This is likely a bug in the tool implementation. Please report this to the plugin administrator.', 'mcp-ai-wpoos' ),
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
						__( 'Failed to encode scalar tool result of type: %s', 'mcp-ai-wpoos' ),
						gettype( $tool_result )
					),
					array(
						'status'  => 500,
						'actions' => array(
							'check_result' => __( 'This is an internal error. The tool returned data that could not be encoded.', 'mcp-ai-wpoos' ),
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
						__( 'Unable to encode tool result to JSON. The tool may have returned circular references or invalid data.', 'mcp-ai-wpoos' ),
						array(
							'status'  => 500,
							'actions' => array(
								'check_tool'   => __( 'This is likely a bug in the tool implementation. Check if the tool is returning circular references or non-serializable data.', 'mcp-ai-wpoos' ),
								'report_issue' => __( 'Please report this to the plugin administrator with the tool name you were trying to execute.', 'mcp-ai-wpoos' ),
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
				__( 'Tool result has unexpected type: %s', 'mcp-ai-wpoos' ),
				gettype( $tool_result )
			),
			array(
				'status'  => 500,
				'actions' => array(
					'report_issue' => __( 'This is an internal error. The tool returned an unexpected data type. Please report this to the plugin administrator.', 'mcp-ai-wpoos' ),
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
		unset( $request ); // Required by MCP protocol method signature.
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
	 * Handle MCP resources/read request.
	 *
	 * Returns the content of a specific resource identified by URI.
	 * Only serves files that are in the assistant's memory_files allowlist.
	 *
	 * @since 2.2.0
	 *
	 * @param array           $params  Method parameters. Must include 'uri'.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error MCP contents response or error.
	 */
	protected function mcp_resources_read( $params, WP_REST_Request $request ) {
		if ( ! isset( $params['uri'] ) || ! is_string( $params['uri'] ) || '' === $params['uri'] ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: uri. Provide the URI of the resource to read.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_uri'    => __( 'Include the "uri" parameter in your resources/read request.', 'mcp-ai-wpoos' ),
						'list_resources' => __( 'Call resources/list first to discover available resource URIs.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		$uri = esc_url_raw( $params['uri'] );

		// Resolve assistant scope same as resources/list.
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

		if ( ! $assistant_id ) {
			return new WP_Error(
				'wp_mcp_ai_no_assistant',
				__( 'No assistant context available. Provide an assistant_id or authenticate with an assistant-scoped token.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_assistant' => __( 'Include "assistant_id" in the request params or use an assistant-scoped bearer token.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		$assistant_post = $this->validate_assistant_access( $assistant_id );

		if ( is_wp_error( $assistant_post ) ) {
			return $assistant_post;
		}

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		// Validate the URI is in the assistant's memory_files allowlist.
		$memory_files = isset( $config['memory_files'] ) && is_array( $config['memory_files'] )
			? $config['memory_files']
			: array();

		$matched_file_id = 0;

		foreach ( $memory_files as $file_id ) {
			$file_id = absint( $file_id );
			if ( ! $file_id ) {
				continue;
			}

			$attachment_url = wp_get_attachment_url( $file_id );
			if ( false !== $attachment_url && $attachment_url === $uri ) {
				$matched_file_id = $file_id;
				break;
			}
		}

		if ( ! $matched_file_id ) {
			return new WP_Error(
				'wp_mcp_ai_resource_not_found',
				__( 'The requested resource URI was not found among this assistant\'s memory files.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 404,
					'actions' => array(
						'check_uri'      => __( 'Verify the URI matches one returned by resources/list.', 'mcp-ai-wpoos' ),
						'list_resources' => __( 'Call resources/list to see available resources for this assistant.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		$attachment = get_post( $matched_file_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_resource_not_found',
				__( 'The attachment for this resource no longer exists.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$mime_type = get_post_mime_type( $attachment );
		$file_path = get_attached_file( $matched_file_id );

		// Security: Validate the file path is within ABSPATH.
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_resource_unavailable',
				__( 'The resource file is not available on disk.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$real_path    = realpath( $file_path );
		$real_abspath = realpath( ABSPATH );

		if ( false === $real_path || false === $real_abspath || 0 !== strpos( $real_path, $real_abspath ) ) {
			return new WP_Error(
				'wp_mcp_ai_resource_unavailable',
				__( 'The resource file path is outside the allowed directory.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Enforce 1 MB size limit.
		$max_size  = 1048576; // 1 MB.
		$file_size = filesize( $file_path );

		if ( false === $file_size || $file_size > $max_size ) {
			return new WP_Error(
				'wp_mcp_ai_resource_too_large',
				__( 'The resource file exceeds the maximum allowed size of 1 MB.', 'mcp-ai-wpoos' ),
				array(
					'status' => 413,
					'size'   => $file_size,
				)
			);
		}

		// Determine if this is a text-based or binary MIME type.
		$text_mime_prefixes = array( 'text/', 'application/json', 'application/xml', 'application/javascript', 'application/x-yaml', 'application/csv' );
		$is_text            = false;

		foreach ( $text_mime_prefixes as $prefix ) {
			if ( 0 === strpos( $mime_type, $prefix ) ) {
				$is_text = true;
				break;
			}
		}

		$contents = array();

		if ( $is_text ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local attachment file, not a URL.
			$file_contents = file_get_contents( $file_path );

			if ( false === $file_contents ) {
				return new WP_Error(
					'wp_mcp_ai_resource_read_failed',
					__( 'Failed to read the resource file.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			$contents[] = array(
				'uri'      => $uri,
				'mimeType' => $mime_type,
				'text'     => $file_contents,
			);
		} else {
			// Binary content: base64-encode it.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local attachment file, not a URL.
			$file_contents = file_get_contents( $file_path );

			if ( false === $file_contents ) {
				return new WP_Error(
					'wp_mcp_ai_resource_read_failed',
					__( 'Failed to read the resource file.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			$contents[] = array(
				'uri'      => $uri,
				'mimeType' => $mime_type,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by MCP protocol for binary resource content.
				'blob'     => base64_encode( $file_contents ),
			);
		}

		/**
		 * Filters the resources/read response contents before returning.
		 *
		 * @since 2.2.0
		 *
		 * @param array           $contents     MCP contents array.
		 * @param string          $uri          Requested resource URI.
		 * @param int             $assistant_id Assistant ID.
		 * @param WP_REST_Request $request      REST request instance.
		 */
		$contents = apply_filters( 'wp_mcp_ai_resources_read', $contents, $uri, $assistant_id, $request );

		return array( 'contents' => $contents );
	}

	/**
	 * Handle MCP prompts/list request.
	 *
	 * @param array           $params  Method parameters.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array
	 */
	protected function mcp_prompts_list( $params, WP_REST_Request $request ) {
		unset( $params, $request ); // Required by MCP protocol method signature.
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
				$default_arguments = array(
					array(
						'name'        => 'context',
						'description' => __( 'Additional context or instructions to incorporate when rendering this prompt.', 'mcp-ai-wpoos' ),
						'required'    => false,
					),
				);

				/**
				 * Filters the arguments for an individual prompt in prompts/list.
				 *
				 * @since 2.2.0
				 *
				 * @param array   $arguments    Prompt arguments schema.
				 * @param WP_Post $post         Assistant post object.
				 */
				$arguments = apply_filters( 'wp_mcp_ai_prompt_arguments', $default_arguments, $post );

				$prompts[] = array(
					'name'        => $post->post_name,
					'description' => get_the_title( $post ),
					'arguments'   => $arguments,
				);
			}
		}

		return array( 'prompts' => $prompts );
	}

	/**
	 * Handle MCP prompts/get request.
	 *
	 * Returns the rendered content of a specific prompt template identified by name.
	 * Each prompt corresponds to a published assistant and its system prompt.
	 *
	 * @since 2.2.0
	 *
	 * @param array           $params  Method parameters. Must include 'name'.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error MCP prompt response or error.
	 */
	protected function mcp_prompts_get( $params, WP_REST_Request $request ) {
		if ( ! isset( $params['name'] ) || ! is_string( $params['name'] ) || '' === $params['name'] ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: name. Provide the name (slug) of the prompt to retrieve.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_name' => __( 'Include the "name" parameter matching an assistant slug from prompts/list.', 'mcp-ai-wpoos' ),
						'list_prompts' => __( 'Call prompts/list first to discover available prompt names.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		$name = sanitize_title( $params['name'] );

		// Look up the assistant by post_name (slug).
		$query = new WP_Query(
			array(
				'post_type'              => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'            => 'publish',
				'name'                   => $name,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
			)
		);

		if ( ! $query->have_posts() ) {
			return new WP_Error(
				'wp_mcp_ai_prompt_not_found',
				sprintf(
					/* translators: %s: prompt name */
					__( 'Prompt not found: %s', 'mcp-ai-wpoos' ),
					$name
				),
				array(
					'status'  => 404,
					'actions' => array(
						'check_name'   => __( 'Verify the prompt name matches a published assistant slug.', 'mcp-ai-wpoos' ),
						'list_prompts' => __( 'Call prompts/list to see available prompts.', 'mcp-ai-wpoos' ),
					),
				)
			);
		}

		$post          = $query->posts[0];
		$config        = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $post->ID );
		$system_prompt = isset( $config['system_prompt'] ) ? $config['system_prompt'] : '';

		// If a context argument was provided, append it to the system prompt content.
		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$context   = isset( $arguments['context'] ) ? sanitize_textarea_field( $arguments['context'] ) : '';

		if ( ! empty( $context ) ) {
			$system_prompt .= "\n\n" . $context;
		}

		$prompt_content = array(
			'description' => get_the_title( $post ),
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $system_prompt,
					),
				),
			),
		);

		/**
		 * Filters the prompts/get response before returning.
		 *
		 * Allows Pro/integrations to enrich the prompt with additional
		 * messages, context, or transformed content.
		 *
		 * @since 2.2.0
		 *
		 * @param array           $prompt_content Prompt response with description and messages.
		 * @param WP_Post         $post           Assistant post object.
		 * @param array           $arguments      Request arguments.
		 * @param WP_REST_Request $request        REST request instance.
		 */
		$prompt_content = apply_filters( 'wp_mcp_ai_prompts_get', $prompt_content, $post, $arguments, $request );

		return $prompt_content;
	}

	/**
	 * Handle MCP ping request.
	 *
	 * Returns an empty result object per the MCP specification.
	 * Used by clients to verify the server is alive and responsive.
	 *
	 * @since 2.3.0
	 *
	 * @return array Empty result object.
	 */
	protected function mcp_ping() {
		return new stdClass();
	}

	/**
	 * Handle MCP completion/complete request.
	 *
	 * Provides argument autocompletion for tool parameters and prompt arguments.
	 * This enables MCP clients to offer tab-completion and suggestions.
	 *
	 * @since 2.3.0
	 *
	 * @param array           $params  Method parameters including 'ref' and 'argument'.
	 * @param WP_REST_Request $request REST request instance.
	 * @return array|WP_Error Completion result with values array.
	 */
	protected function mcp_completion_complete( $params, WP_REST_Request $request ) {
		unset( $request ); // Required by MCP protocol method signature.
		if ( ! isset( $params['ref'] ) || ! is_array( $params['ref'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: ref. Must include type and name.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $params['argument'] ) || ! is_array( $params['argument'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: argument. Must include name and value.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$ref      = $params['ref'];
		$argument = $params['argument'];

		$ref_type = isset( $ref['type'] ) ? sanitize_text_field( $ref['type'] ) : '';
		$ref_name = isset( $ref['name'] ) ? sanitize_text_field( $ref['name'] ) : '';
		$arg_name = isset( $argument['name'] ) ? sanitize_text_field( $argument['name'] ) : '';
		$arg_val  = isset( $argument['value'] ) ? sanitize_text_field( $argument['value'] ) : '';

		$values   = array();
		$has_more = false;

		if ( 'ref/tool' === $ref_type ) {
			$values = $this->complete_tool_argument( $ref_name, $arg_name, $arg_val );
		} elseif ( 'ref/prompt' === $ref_type ) {
			$values = $this->complete_prompt_argument( $ref_name, $arg_name, $arg_val );
		}

		// Cap returned values at 100 per MCP spec recommendation.
		if ( count( $values ) > 100 ) {
			$values   = array_slice( $values, 0, 100 );
			$has_more = true;
		}

		/**
		 * Filter the completion result before returning.
		 *
		 * @since 2.3.0
		 *
		 * @param array  $result   The completion result array.
		 * @param array  $ref      The reference object (type, name).
		 * @param array  $argument The argument object (name, value).
		 * @param string $ref_type Resolved reference type.
		 * @param string $ref_name Resolved reference name.
		 */
		$result = apply_filters(
			'wp_mcp_ai_mcp_completion_complete',
			array(
				'completion' => array(
					'values'  => $values,
					'hasMore' => $has_more,
					'total'   => count( $values ),
				),
			),
			$ref,
			$argument,
			$ref_type,
			$ref_name
		);

		return $result;
	}

	/**
	 * Complete a tool argument based on the tool's parameter schema.
	 *
	 * Examines enum values and generates suggestions matching the partial input.
	 *
	 * @since 2.3.0
	 *
	 * @param string $tool_name Tool slug.
	 * @param string $arg_name  Argument name.
	 * @param string $arg_value Partial value to complete.
	 * @return array Array of completion value strings.
	 */
	protected function complete_tool_argument( $tool_name, $arg_name, $arg_value ) {
		$tool = $this->registry->get_tool( $tool_name );
		if ( ! $tool ) {
			return array();
		}

		$schema = $tool->get_parameters_schema();
		if ( ! is_array( $schema ) || ! isset( $schema['properties'][ $arg_name ] ) ) {
			return array();
		}

		$prop = $schema['properties'][ $arg_name ];

		// If the property has an enum, filter by partial match.
		if ( isset( $prop['enum'] ) && is_array( $prop['enum'] ) ) {
			$matches      = array();
			$arg_value_lc = strtolower( $arg_value );
			foreach ( $prop['enum'] as $candidate ) {
				$candidate_str = (string) $candidate;
				if ( '' === $arg_value || 0 === strpos( strtolower( $candidate_str ), $arg_value_lc ) ) {
					$matches[] = $candidate_str;
				}
			}
			return $matches;
		}

		// For boolean types, suggest true/false.
		if ( isset( $prop['type'] ) && 'boolean' === $prop['type'] ) {
			$booleans = array( 'true', 'false' );
			if ( '' === $arg_value ) {
				return $booleans;
			}
			return array_values(
				array_filter(
					$booleans,
					function ( $b ) use ( $arg_value ) {
						return 0 === strpos( $b, strtolower( $arg_value ) );
					}
				)
			);
		}

		return array();
	}

	/**
	 * Complete a prompt argument.
	 *
	 * For prompts (assistants), the only completable reference is the prompt name itself.
	 *
	 * @since 2.3.0
	 *
	 * @param string $prompt_name Prompt (assistant) slug.
	 * @param string $arg_name    Argument name.
	 * @param string $arg_value   Partial value to complete.
	 * @return array Array of completion value strings.
	 */
	protected function complete_prompt_argument( $prompt_name, $arg_name, $arg_value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassAfterLastUsed -- Reserved for future argument completions.
		// Currently prompts don't have completable arguments beyond the name itself.
		// Return matching assistant slugs if arg_name is empty (completing the prompt name).
		if ( empty( $arg_name ) || 'name' === $arg_name ) {
			$assistants = get_posts(
				array(
					'post_type'      => WP_MCP_AI_Assistant_CPT::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			$matches      = array();
			$arg_value_lc = strtolower( $arg_value );
			foreach ( $assistants as $assistant ) {
				$slug = $assistant->post_name;
				if ( '' === $arg_value || 0 === strpos( strtolower( $slug ), $arg_value_lc ) ) {
					$matches[] = $slug;
				}
			}
			return $matches;
		}

		return array();
	}

	/**
	 * Handle MCP logging/setLevel request.
	 *
	 * Allows MCP clients to set the server's logging level for the current session.
	 * Accepts standard MCP log levels: debug, info, notice, warning, error, critical,
	 * alert, emergency.
	 *
	 * @since 2.3.0
	 *
	 * @param array $params Method parameters. Must include 'level'.
	 * @return array|WP_Error Empty result on success.
	 */
	protected function mcp_logging_set_level( $params ) {
		if ( ! isset( $params['level'] ) || ! is_string( $params['level'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: level', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$valid_levels = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' );
		$level        = strtolower( sanitize_text_field( $params['level'] ) );

		if ( ! in_array( $level, $valid_levels, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				sprintf(
					/* translators: %s: comma-separated list of valid log levels */
					__( 'Invalid log level. Must be one of: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $valid_levels )
				),
				array( 'status' => 400 )
			);
		}

		/**
		 * Fires when an MCP client sets the logging level.
		 *
		 * Plugins can hook into this to adjust their logging verbosity.
		 *
		 * @since 2.3.0
		 *
		 * @param string $level The requested log level.
		 */
		do_action( 'wp_mcp_ai_mcp_logging_set_level', $level );

		return new stdClass();
	}

	/**
	 * Handle MCP notifications/cancelled notification.
	 *
	 * Processes a client's request to cancel a previously-issued request.
	 * Per MCP spec, this is a notification (no response expected).
	 *
	 * @since 2.3.0
	 *
	 * @param array $params Notification parameters. Should include 'requestId' and optionally 'reason'.
	 * @return array Empty result (notification response handled by caller).
	 */
	protected function mcp_notifications_cancelled( $params ) {
		$request_id = isset( $params['requestId'] ) ? sanitize_text_field( $params['requestId'] ) : '';
		$reason     = isset( $params['reason'] ) ? sanitize_text_field( $params['reason'] ) : '';

		/**
		 * Fires when an MCP client cancels a request.
		 *
		 * Plugins can hook into this to abort long-running operations.
		 *
		 * @since 2.3.0
		 *
		 * @param string $request_id The ID of the request to cancel.
		 * @param string $reason     Optional reason for cancellation.
		 */
		do_action( 'wp_mcp_ai_mcp_request_cancelled', $request_id, $reason );

		if ( ! empty( $request_id ) ) {
			WP_MCP_AI_Logger::log_event(
				'info',
				'MCP request cancelled by client',
				array(
					'request_id' => $request_id,
					'reason'     => $reason,
				)
			);
		}

		return new stdClass();
	}

	/**
	 * Handle MCP notifications/initialized notification.
	 *
	 * Standard MCP 2024-11-05 notification sent by the client after
	 * receiving the initialize response. Acknowledges the handshake
	 * is complete and the server may begin sending requests.
	 *
	 * Per spec this is a notification (no response expected).
	 *
	 * @since 2.6.0
	 *
	 * @param array $params Notification parameters (unused).
	 * @return stdClass Empty result.
	 */
	protected function mcp_notifications_initialized( $params ) {
		WP_MCP_AI_Logger::log_event(
			'debug',
			'MCP client initialized',
			array( 'params' => $params )
		);

		return new stdClass();
	}

	/**
	 * Build MCP tool annotations from the tool's capability flags.
	 *
	 * Maps the plugin's WP_MCP_AI_Tool_Capability_Flags_Interface flags
	 * to the MCP 2024-11-05 tool annotation format.
	 *
	 * @since 2.3.0
	 *
	 * @param object $tool Tool instance (may implement WP_MCP_AI_Tool_Capability_Flags_Interface).
	 * @return array Annotation key-value pairs, empty if tool has no flags.
	 */
	protected function build_tool_annotations( $tool ) {
		if ( ! ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) ) {
			return array();
		}

		$flags = $tool->get_capability_flags();

		if ( ! is_array( $flags ) || empty( $flags ) ) {
			return array();
		}

		$annotations = array();

		// Map capability flags to MCP annotation fields.
		$annotations['readOnlyHint'] = in_array( 'read-only', $flags, true );

		// destructiveHint: true if tool is write/state-changing and NOT marked read-only.
		$is_write = in_array( 'write', $flags, true ) || in_array( 'state-changing', $flags, true );
		if ( $is_write ) {
			$annotations['destructiveHint'] = ! in_array( 'reversible', $flags, true );
		}

		// idempotentHint from idempotent flag.
		if ( in_array( 'idempotent', $flags, true ) ) {
			$annotations['idempotentHint'] = true;
		}

		// openWorldHint: true if tool calls external APIs.
		if ( in_array( 'external-api', $flags, true ) || in_array( 'network-dependent', $flags, true ) ) {
			$annotations['openWorldHint'] = true;
		} elseif ( in_array( 'local-only', $flags, true ) ) {
			$annotations['openWorldHint'] = false;
		}

		/**
		 * Filter the MCP annotations for a tool.
		 *
		 * @since 2.3.0
		 *
		 * @param array  $annotations MCP annotation key-value pairs.
		 * @param object $tool        Tool instance.
		 * @param array  $flags       Raw capability flags array.
		 */
		return apply_filters( 'wp_mcp_ai_tool_annotations', $annotations, $tool, $flags );
	}

	/**
	 * Attach Mcp-Session-Id header to a response.
	 *
	 * If no session ID was provided by the client, generates a new one.
	 * Session state is stored as a WordPress transient for reconnection support.
	 *
	 * @since 2.3.0
	 *
	 * @param WP_REST_Response $response   Response object.
	 * @param string|null      $session_id Existing session ID from client, or null for new session.
	 */
	protected function attach_session_header( $response, $session_id ) {
		if ( empty( $session_id ) ) {
			// Generate a new session ID on initialize or first request.
			// Use wp_generate_password with no special chars for URL/header-safe IDs.
			$session_id = 'sess_' . bin2hex( random_bytes( 16 ) );

			// Store minimal session metadata as a transient (1 hour TTL).
			set_transient(
				'wp_mcp_ai_session_' . $session_id,
				array(
					'created'   => time(),
					'user_id'   => get_current_user_id(),
					'last_seen' => time(),
				),
				HOUR_IN_SECONDS
			);
		} else {
			// Update last_seen timestamp for existing session.
			$session_data = get_transient( 'wp_mcp_ai_session_' . $session_id );
			if ( is_array( $session_data ) ) {
				$session_data['last_seen'] = time();
				set_transient( 'wp_mcp_ai_session_' . $session_id, $session_data, HOUR_IN_SECONDS );
			}
		}

		$response->header( 'Mcp-Session-Id', $session_id );
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
			- 32700 === $code ? 400 : ( -32601 === $code ? 404 : 500 )
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
	public function add_cors_headers( $response ) {
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
