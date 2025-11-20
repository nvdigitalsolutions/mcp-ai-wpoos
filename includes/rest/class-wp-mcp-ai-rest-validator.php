<?php
/**
 * REST API Request Validator
 *
 * Handles validation and sanitization of REST API requests for WP oOS plugin.
 * This class is part of the refactoring effort to separate concerns from the
 * monolithic REST controller class.
 *
 * @package WP_MCP_AI
 * @subpackage REST
 * @since 1.0.0
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Request Validator Class
 *
 * Centralizes all validation and sanitization logic for REST API endpoints.
 * Extracted from WP_MCP_AI_REST class as part of Milestone 2 refactoring.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_REST_Validator {

	/**
	 * Validate messages array structure for chat endpoint.
	 *
	 * Validates that the messages parameter is:
	 * - An array
	 * - Not empty
	 * - Contains valid message objects with required fields
	 * - Each message has a valid role and content
	 *
	 * @param mixed           $value   The messages array to validate.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   The parameter name.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_messages_array( $value, $request, $param ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( 'The "%s" parameter must be an array.', 'wp-mcp-ai' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		if ( empty( $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'The "messages" array cannot be empty. At least one message is required.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_messages' => __( 'Include at least one message object with "role" and "content" properties.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		foreach ( $value as $index => $message ) {
			if ( ! is_array( $message ) ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: message index */
						__( 'Message at index %d must be an object/array.', 'wp-mcp-ai' ),
						$index
					),
					array( 'status' => 400 )
				);
			}

			// Validate role field.
			if ( ! isset( $message['role'] ) ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: message index */
						__( 'Message at index %d is missing required "role" property.', 'wp-mcp-ai' ),
						$index
					),
					array(
						'status'  => 400,
						'actions' => array(
							'add_role' => __( 'Each message must include a "role" property with one of: "system", "user", "assistant", or "tool".', 'wp-mcp-ai' ),
						),
					)
				);
			}

			$role        = $message['role'];
			$valid_roles = array( 'system', 'user', 'assistant', 'tool' );

			if ( ! in_array( $role, $valid_roles, true ) ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: 1: message index, 2: invalid role, 3: valid roles list */
						__( 'Message at index %1$d has invalid role "%2$s". Must be one of: %3$s', 'wp-mcp-ai' ),
						$index,
						$role,
						implode( ', ', $valid_roles )
					),
					array( 'status' => 400 )
				);
			}

			// Validate content field (required for most roles).
			if ( ! isset( $message['content'] ) && 'assistant' !== $role ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: message index */
						__( 'Message at index %d is missing required "content" property.', 'wp-mcp-ai' ),
						$index
					),
					array(
						'status'  => 400,
						'actions' => array(
							'add_content' => __( 'Each message must include a "content" property (string or array of content parts).', 'wp-mcp-ai' ),
						),
					)
				);
			}

			// Validate tool_call_id for tool messages.
			if ( 'tool' === $role && empty( $message['tool_call_id'] ) ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: message index */
						__( 'Tool message at index %d is missing required "tool_call_id" property.', 'wp-mcp-ai' ),
						$index
					),
					array(
						'status'  => 400,
						'actions' => array(
							'add_tool_call_id' => __( 'Messages with role "tool" must include a "tool_call_id" matching the assistant\'s tool call.', 'wp-mcp-ai' ),
						),
					)
				);
			}
		}

		return true;
	}

	/**
	 * Validate attachments array structure for chat endpoint.
	 *
	 * Validates that each attachment has either a file_id or url, and that
	 * they are properly formatted.
	 *
	 * @param mixed           $value   The attachments array to validate.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   The parameter name.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_attachments_array( $value, $request, $param ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: parameter name */
					__( 'The "%s" parameter must be an array.', 'wp-mcp-ai' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		foreach ( $value as $index => $attachment ) {
			if ( ! is_array( $attachment ) ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: attachment index */
						__( 'Attachment at index %d must be an object/array.', 'wp-mcp-ai' ),
						$index
					),
					array( 'status' => 400 )
				);
			}

			// Each attachment must have either file_id or url.
			$has_file_id = isset( $attachment['file_id'] ) && is_numeric( $attachment['file_id'] ) && absint( $attachment['file_id'] ) > 0;
			$has_url     = isset( $attachment['url'] ) && is_string( $attachment['url'] );

			if ( ! $has_file_id && ! $has_url ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: attachment index */
						__( 'Attachment at index %d must include either "file_id" (integer) or "url" (string).', 'wp-mcp-ai' ),
						$index
					),
					array(
						'status'  => 400,
						'actions' => array(
							'provide_file_reference' => __( 'Each attachment must specify either a WordPress attachment ID via "file_id" or an external URL via "url".', 'wp-mcp-ai' ),
						),
					)
				);
			}

			// Validate file_id if provided (additional check for clarity).
			if ( isset( $attachment['file_id'] ) ) {
				$file_id = absint( $attachment['file_id'] );
				if ( $file_id <= 0 ) {
					return new WP_Error(
						'rest_invalid_param',
						sprintf(
							/* translators: %d: attachment index */
							__( 'Attachment at index %d has invalid "file_id". Must be a positive integer.', 'wp-mcp-ai' ),
							$index
						),
						array( 'status' => 400 )
					);
				}
			}

			// Validate url if provided.
			if ( $has_url && ! filter_var( $attachment['url'], FILTER_VALIDATE_URL ) ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: attachment index */
						__( 'Attachment at index %d has invalid "url". Must be a valid URL.', 'wp-mcp-ai' ),
						$index
					),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Validate MCP JSON-RPC request structure.
	 *
	 * Validates the params object based on the MCP method being called.
	 * Different methods have different param requirements.
	 *
	 * @param mixed           $value   The request body to validate.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   The parameter name.
	 * @return bool|WP_Error True if valid, WP_Error if invalid.
	 */
	public function validate_mcp_params( $value, $request, $param ) {
		// This validates the params object for MCP requests.
		// The structure depends on the method, so we validate based on that.
		$method = $request->get_param( 'method' );

		if ( ! $method ) {
			// Method will be validated by the endpoint args, so we just check if params is an object/array.
			if ( null !== $value && ! is_array( $value ) ) {
				return new WP_Error(
					'rest_invalid_param',
					__( 'The "params" parameter must be an object/array or null.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
			return true;
		}

		// Validate params structure based on method.
		switch ( $method ) {
			case 'tools/call':
				if ( ! is_array( $value ) ) {
					return new WP_Error(
						'rest_invalid_param',
						__( 'The "params" parameter must be an object for tools/call method.', 'wp-mcp-ai' ),
						array( 'status' => 400 )
					);
				}

				if ( ! isset( $value['name'] ) || ! is_string( $value['name'] ) ) {
					return new WP_Error(
						'rest_invalid_param',
						__( 'MCP tools/call requires a "name" parameter (string) specifying the tool to call.', 'wp-mcp-ai' ),
						array(
							'status'  => 400,
							'actions' => array(
								'provide_tool_name' => __( 'Include "params": {"name": "tool_slug", "arguments": {...}} in your request.', 'wp-mcp-ai' ),
							),
						)
					);
				}

				// Arguments is optional but must be an object if provided.
				if ( isset( $value['arguments'] ) && ! is_array( $value['arguments'] ) ) {
					return new WP_Error(
						'rest_invalid_param',
						__( 'The "arguments" parameter in tools/call must be an object.', 'wp-mcp-ai' ),
						array( 'status' => 400 )
					);
				}
				break;

			case 'initialize':
			case 'tools/list':
			case 'resources/list':
			case 'prompts/list':
				// These methods accept optional params.
				if ( null !== $value && ! is_array( $value ) ) {
					return new WP_Error(
						'rest_invalid_param',
						sprintf(
							/* translators: %s: method name */
							__( 'The "params" parameter for %s method must be an object or null.', 'wp-mcp-ai' ),
							$method
						),
						array( 'status' => 400 )
					);
				}
				break;
		}

		return true;
	}

	/**
	 * Sanitize an array of messages from the client.
	 *
	 * Validates message structure, sanitizes content, and normalizes
	 * into the internal format expected by the system.
	 *
	 * @param array $messages Raw messages from client.
	 * @return array|WP_Error Sanitized messages array or WP_Error on failure.
	 */
	public function sanitize_messages( $messages ) {
		if ( ! is_array( $messages ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_messages',
				__( 'Messages must be provided as an array of role/content pairs.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$attachments_helper = new WP_MCP_AI_Message_Attachments();
		$sanitized          = array();

		$default_roles = array( 'user', 'assistant', 'system', 'tool' );
		$allowed_roles = apply_filters( 'wp_mcp_ai_allowed_message_roles', $default_roles );

		if ( ! is_array( $allowed_roles ) ) {
			$allowed_roles = $default_roles;
		}

		$allowed_roles = array_values(
			array_filter(
				array_unique(
					array_map( 'sanitize_key', $allowed_roles )
				)
			)
		);

		if ( empty( $allowed_roles ) ) {
			$allowed_roles = $default_roles;
		}

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$raw_role = isset( $message['role'] ) ? $message['role'] : '';
			$role     = sanitize_key( $raw_role );
			if ( empty( $role ) ) {
				continue;
			}

			if ( ! in_array( $role, $allowed_roles, true ) ) {
				$display_role = is_scalar( $raw_role ) ? (string) $raw_role : $role;
				$display_role = sanitize_text_field( $display_role );

				return new WP_Error(
					'wp_mcp_ai_invalid_message_role',
					sprintf(
						/* translators: 1: Provided role, 2: list of supported roles. */
						__( 'The message role "%1$s" is not supported. Supported roles: %2$s.', 'wp-mcp-ai' ),
						$display_role,
						implode( ', ', $allowed_roles )
					),
					array( 'status' => 400 )
				);
			}

			$content  = isset( $message['content'] ) ? $message['content'] : '';
			$segments = $this->sanitize_message_content( $content, $attachments_helper );

			if ( is_wp_error( $segments ) ) {
				return $segments;
			}

			$metadata = $this->sanitize_message_metadata( $message );

			if ( empty( $segments ) && empty( $metadata ) ) {
				continue;
			}

			$sanitized[] = array_merge(
				array(
					'role'    => $role,
					'content' => $segments,
				),
				$metadata
			);
		}

		// Return both sanitized messages and any extracted attachments.
		return array(
			'messages'    => $sanitized,
			'attachments' => array(), // Attachments are embedded in message content, not separate.
		);
	}

	/**
	 * Sanitize metadata fields from a message.
	 *
	 * Handles tool_calls, tool_call_id, and name fields.
	 *
	 * @param array $message The message array.
	 * @return array Sanitized metadata fields.
	 */
	public function sanitize_message_metadata( array $message ) {
		$metadata = array();

		if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
			$tool_calls = array();

			foreach ( $message['tool_calls'] as $tool_call ) {
				if ( ! is_array( $tool_call ) ) {
					continue;
				}

				$sanitized_call = array();

				if ( isset( $tool_call['id'] ) ) {
					$sanitized_call['id'] = sanitize_text_field( $tool_call['id'] );
				}

				if ( isset( $tool_call['type'] ) ) {
					$sanitized_call['type'] = sanitize_text_field( $tool_call['type'] );
				}

				if ( isset( $tool_call['function'] ) && is_array( $tool_call['function'] ) ) {
					$function = array();

					if ( isset( $tool_call['function']['name'] ) ) {
						$function['name'] = sanitize_text_field( $tool_call['function']['name'] );
					}

					if ( isset( $tool_call['function']['arguments'] ) ) {
						$function['arguments'] = wp_check_invalid_utf8( (string) $tool_call['function']['arguments'], true );
					}

					if ( ! empty( $function ) ) {
						$sanitized_call['function'] = $function;
					}
				}

				if ( isset( $tool_call['index'] ) ) {
					$sanitized_call['index'] = absint( $tool_call['index'] );
				}

				if ( ! empty( $sanitized_call ) ) {
					$tool_calls[] = $sanitized_call;
				}
			}

			if ( ! empty( $tool_calls ) ) {
				$metadata['tool_calls'] = $tool_calls;
			}
		}

		if ( isset( $message['tool_call_id'] ) ) {
			$metadata['tool_call_id'] = sanitize_text_field( $message['tool_call_id'] );
		}

		if ( isset( $message['name'] ) ) {
			$metadata['name'] = sanitize_text_field( $message['name'] );
		}

		return $metadata;
	}

	/**
	 * Sanitize the content of a single message and normalise into segments.
	 *
	 * Handles both string content and array-based content with segments.
	 *
	 * @param mixed                         $content             Raw content provided by the client.
	 * @param WP_MCP_AI_Message_Attachments $attachments_helper Attachment helper instance.
	 * @return array|WP_Error Array of segments or WP_Error on failure.
	 */
	public function sanitize_message_content( $content, WP_MCP_AI_Message_Attachments $attachments_helper ) {
		if ( $content instanceof \Traversable ) {
			$content = iterator_to_array( $content );
		}

		if ( is_object( $content ) ) {
			$content = (array) $content;
		}

		if ( is_string( $content ) || is_numeric( $content ) ) {
			$segment = $attachments_helper->prepare_input_text_segment( $content );

			return '' === $segment['text'] ? array() : array( $segment );
		}

		if ( empty( $content ) ) {
			return array();
		}

		if ( ! is_array( $content ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_message_content',
				__( 'Message content must be a string or an array of segments.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! wp_is_numeric_array( $content ) ) {
			$content = array( $content );
		}

		$segments = array();
		foreach ( $content as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$segment_type = isset( $item['type'] ) ? $item['type'] : '';

			if ( 'text' === $segment_type ) {
				$text_content = isset( $item['text'] ) ? $item['text'] : '';
				$segment      = $attachments_helper->prepare_input_text_segment( $text_content );

				if ( '' !== $segment['text'] ) {
					$segments[] = $segment;
				}
			} elseif ( in_array( $segment_type, array( 'image_url', 'image_file', 'audio', 'file' ), true ) ) {
				$segment = $attachments_helper->prepare_input_attachment_segment( $item );

				if ( ! is_wp_error( $segment ) ) {
					$segments[] = $segment;
				}
			}
		}

		return $segments;
	}

	/**
	 * Sanitize options array for chat requests.
	 *
	 * Handles provider, model, temperature, system_prompt, memory_files, etc.
	 *
	 * @param array $options         Raw options from request.
	 * @param array $assistant_config Assistant configuration.
	 * @return array Sanitized options.
	 */
	public function sanitize_options( $options, array $assistant_config ) {
		$options = is_array( $options ) ? $options : array();

		$provider = '';
		if ( isset( $options['provider'] ) ) {
			$provider = sanitize_key( $options['provider'] );
		}

		if ( empty( $provider ) && ! empty( $assistant_config['provider'] ) ) {
			$provider = sanitize_key( $assistant_config['provider'] );
		}

		if ( empty( $provider ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';
		}

		$allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' ) );
		if ( ! is_array( $allowed_providers ) ) {
			$allowed_providers = array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );
		}

		if ( ! in_array( $provider, $allowed_providers, true ) ) {
			$provider = 'openai';
		}

		$options['provider'] = $provider;

		if ( isset( $options['model'] ) ) {
			$options['model'] = sanitize_text_field( $options['model'] );
		}

		if ( empty( $options['model'] ) && ! empty( $assistant_config['model'] ) ) {
			$options['model'] = sanitize_text_field( $assistant_config['model'] );
		}

		$assistant_temperature = ( isset( $assistant_config['temperature'] ) && null !== $assistant_config['temperature'] )
			? floatval( $assistant_config['temperature'] )
			: null;

		$has_request_temperature = array_key_exists( 'temperature', $options );
		$raw_temperature         = $has_request_temperature ? $options['temperature'] : null;

		if ( $has_request_temperature && '' !== $raw_temperature && null !== $raw_temperature ) {
			$temperature = floatval( $raw_temperature );

			if ( ( $temperature < 0 || $temperature > 2 ) && null !== $assistant_temperature ) {
				$temperature = $assistant_temperature;
			}
		} elseif ( ! $has_request_temperature && null !== $assistant_temperature ) {
			$temperature = $assistant_temperature;
		} else {
			$temperature = null;
		}

		if ( null !== $temperature ) {
			$options['temperature'] = (float) max( 0, min( 2, $temperature ) );
		} elseif ( $has_request_temperature ) {
			unset( $options['temperature'] );
		}

		if ( isset( $options['system_prompt'] ) ) {
			$options['system_prompt'] = wp_kses_post( $options['system_prompt'] );
		}

		if ( empty( $options['system_prompt'] ) && ! empty( $assistant_config['system_prompt'] ) ) {
			$options['system_prompt'] = wp_kses_post( $assistant_config['system_prompt'] );
		}

		if ( isset( $options['memory_files'] ) ) {
			$options['memory_files'] = $this->sanitize_memory_files( $options['memory_files'] );
		} elseif ( ! empty( $assistant_config['memory_files'] ) ) {
			$options['memory_files'] = $this->sanitize_memory_files( $assistant_config['memory_files'] );
		} else {
			$options['memory_files'] = array();
		}

		if ( isset( $options['vector_store_id'] ) ) {
			$options['vector_store_id'] = sanitize_text_field( $options['vector_store_id'] );
		} elseif ( isset( $assistant_config['vector_store_id'] ) && '' !== $assistant_config['vector_store_id'] ) {
			$options['vector_store_id'] = sanitize_text_field( $assistant_config['vector_store_id'] );
		} else {
			$options['vector_store_id'] = '';
		}

		if ( isset( $options['max_tokens'] ) ) {
			$options['max_tokens'] = absint( $options['max_tokens'] );

			if ( $options['max_tokens'] <= 0 ) {
				unset( $options['max_tokens'] );
			}
		}

		if ( isset( $options['enable_web_search'] ) ) {
			$options['enable_web_search'] = (bool) $options['enable_web_search'];
		}

		// Remove 'stream' parameter if present - it's only used by SSE handler to determine
		// response format (SSE vs JSON), not for AI provider clients which manage their own
		// streaming behavior. This prevents the frontend's stream flag from being passed to
		// providers like LM Studio which explicitly disable streaming to prevent chunked responses.
		if ( isset( $options['stream'] ) ) {
			unset( $options['stream'] );
		}

		return $options;
	}

	/**
	 * Sanitize session key parameter.
	 *
	 * @param mixed $value The session key value.
	 * @return string Sanitized session key.
	 */
	public function sanitize_session_key_param( $value ) {
		if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
			return '';
		}

		$key = (string) $value;
		$key = preg_replace( '/[^a-zA-Z0-9_-]/', '', $key );

		// Use the same max length as the transcript recorder to ensure consistency.
		$max_length = 96;
		if ( class_exists( 'WP_MCP_AI_Chat_Transcript_Recorder' ) ) {
			$max_length = (int) WP_MCP_AI_Chat_Transcript_Recorder::MAX_SESSION_KEY_LENGTH;
		}

		if ( strlen( $key ) > $max_length ) {
			$key = substr( $key, 0, $max_length );
		}

		return $key;
	}

	/**
	 * Sanitize memory files array.
	 *
	 * @param mixed $files Raw memory files data.
	 * @return array Sanitized memory files array.
	 */
	public function sanitize_memory_files( $files ) {
		if ( ! is_array( $files ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( $files as $file ) {
			if ( is_numeric( $file ) ) {
				$file_id = absint( $file );
				if ( $file_id > 0 ) {
					$sanitized[] = $file_id;
				}
			} elseif ( is_array( $file ) && isset( $file['file_id'] ) ) {
				$file_id = absint( $file['file_id'] );
				if ( $file_id > 0 ) {
					$sanitized[] = $file_id;
				}
			}
		}

		return array_unique( $sanitized );
	}

	/**
	 * Sanitize tool result for display to end users.
	 *
	 * Removes sensitive data and formats output appropriately.
	 *
	 * @param mixed  $result    The tool result to sanitize.
	 * @param string $tool_name The name of the tool.
	 * @return mixed Sanitized result.
	 */
	public function sanitize_tool_result_for_display( $result, $tool_name ) {
		// Allow filtering of tool results before display.
		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_display', $result, $tool_name );
		$result = apply_filters( "wp_mcp_ai_sanitize_tool_result_display_{$tool_name}", $result );

		return $result;
	}

	/**
	 * Sanitize tool result for sending to LLM.
	 *
	 * Ensures the result is in the correct format and doesn't contain
	 * sensitive information that shouldn't be sent to the LLM.
	 *
	 * @param mixed                         $result           The tool result to sanitize.
	 * @param string                        $tool_name        The name of the tool.
	 * @param array                         $assistant_config Assistant configuration.
	 * @param WP_MCP_AI_Tool_Interface|null $tool_instance    Optional tool instance for interface-based sanitization.
	 * @return mixed Sanitized result.
	 */
	public function sanitize_tool_result_for_llm( $result, $tool_name = '', $assistant_config = array(), $tool_instance = null ) {
		// If tool implements custom sanitization interface, use it first.
		if ( $tool_instance && $tool_instance instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface ) {
			$result = $tool_instance->sanitize_for_llm( $result );
		}

		// Allow filtering of tool results before sending to LLM.
		$result = apply_filters( 'wp_mcp_ai_sanitize_tool_result_llm', $result, $tool_name, $assistant_config );

		if ( $tool_name ) {
			$result = apply_filters( "wp_mcp_ai_sanitize_tool_result_llm_{$tool_name}", $result, $assistant_config );
		}

		// If result is a complex object or array, ensure it's serializable.
		if ( is_array( $result ) || is_object( $result ) ) {
			$result = $this->sanitize_complex_data_for_llm( $result );
		}

		return $result;
	}

	/**
	 * Sanitize complex data structures for LLM consumption.
	 *
	 * Recursively processes arrays and objects to ensure they're safe
	 * and appropriate for LLM consumption.
	 *
	 * @param mixed $data The data to sanitize.
	 * @return mixed Sanitized data.
	 */
	protected function sanitize_complex_data_for_llm( $data ) {
		if ( is_array( $data ) ) {
			$sanitized = array();
			foreach ( $data as $key => $value ) {
				$sanitized_key = sanitize_key( $key );
				if ( is_array( $value ) || is_object( $value ) ) {
					$sanitized[ $sanitized_key ] = $this->sanitize_complex_data_for_llm( $value );
				} else {
					$sanitized[ $sanitized_key ] = $this->sanitize_scalar_for_llm( $value );
				}
			}
			return $sanitized;
		}

		if ( is_object( $data ) ) {
			return $this->sanitize_complex_data_for_llm( (array) $data );
		}

		return $this->sanitize_scalar_for_llm( $data );
	}

	/**
	 * Sanitize scalar values for LLM consumption.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return mixed Sanitized value.
	 */
	protected function sanitize_scalar_for_llm( $value ) {
		if ( is_string( $value ) ) {
			return wp_check_invalid_utf8( $value, true );
		}

		if ( is_numeric( $value ) ) {
			return $value;
		}

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( null === $value ) {
			return null;
		}

		// For other types, convert to string.
		return wp_check_invalid_utf8( (string) $value, true );
	}

	/**
	 * Sanitize metadata array for LLM consumption.
	 *
	 * @param array $metadata The metadata array.
	 * @return array Sanitized metadata.
	 */
	public function sanitize_metadata_for_llm( array $metadata ) {
		$sanitized = array();

		foreach ( $metadata as $key => $value ) {
			$sanitized_key = sanitize_key( $key );

			if ( is_array( $value ) || is_object( $value ) ) {
				$sanitized[ $sanitized_key ] = $this->sanitize_complex_data_for_llm( $value );
			} else {
				$sanitized[ $sanitized_key ] = $this->sanitize_scalar_for_llm( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize content array for LLM consumption.
	 *
	 * @param array $content The content array.
	 * @return array Sanitized content.
	 */
	public function sanitize_content_for_llm( array $content ) {
		$sanitized = array();

		foreach ( $content as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$sanitized_item = array();

			if ( isset( $item['type'] ) ) {
				$sanitized_item['type'] = sanitize_key( $item['type'] );
			}

			if ( isset( $item['text'] ) ) {
				$sanitized_item['text'] = wp_check_invalid_utf8( (string) $item['text'], true );
			}

			if ( isset( $item['url'] ) ) {
				$sanitized_item['url'] = esc_url_raw( $item['url'] );
			}

			if ( isset( $item['image_url'] ) && is_array( $item['image_url'] ) ) {
				$image_url = array();
				if ( isset( $item['image_url']['url'] ) ) {
					$image_url['url'] = esc_url_raw( $item['image_url']['url'] );
				}
				if ( isset( $item['image_url']['detail'] ) ) {
					$image_url['detail'] = sanitize_key( $item['image_url']['detail'] );
				}
				$sanitized_item['image_url'] = $image_url;
			}

			if ( ! empty( $sanitized_item ) ) {
				$sanitized[] = $sanitized_item;
			}
		}

		return $sanitized;
	}
}
