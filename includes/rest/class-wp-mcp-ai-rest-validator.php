<?php
/**
 * REST API Request Validator
 *
 * Handles validation and sanitization of REST API requests for NV oOS plugin.
 * This class is part of the refactoring effort to separate concerns from the
 * monolithic REST controller class.
 *
 * @package WP_MCP_AI
 * @subpackage REST
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
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
	 * Default maximum byte size for a tool-result string before it is
	 * truncated when being re-injected into the prompt as a tool-role
	 * message. 64 KB is generous enough to carry large JSON payloads
	 * while preventing runaway context growth.
	 *
	 * Filterable via {@see 'wp_mcp_ai_tool_result_max_bytes'}.
	 *
	 * @since 1.8.0
	 * @var int
	 */
	const TOOL_RESULT_MAX_BYTES = 65536;

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
					__( 'The "%s" parameter must be an array.', 'mcp-ai-wpoos' ),
					$param
				),
				array( 'status' => 400 )
			);
		}

		if ( empty( $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'The "messages" array cannot be empty. At least one message is required.', 'mcp-ai-wpoos' ),
				array(
					'status'  => 400,
					'actions' => array(
						'provide_messages' => __( 'Include at least one message object with "role" and "content" properties.', 'mcp-ai-wpoos' ),
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
						__( 'Message at index %d must be an object/array.', 'mcp-ai-wpoos' ),
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
						__( 'Message at index %d is missing required "role" property.', 'mcp-ai-wpoos' ),
						$index
					),
					array(
						'status'  => 400,
						'actions' => array(
							'add_role' => __( 'Each message must include a "role" property with one of: "system", "user", "assistant", or "tool".', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Role VALUES are intentionally not enforced here: semantic role
			// validation (including the wp_mcp_ai_allowed_message_roles filter
			// that lets integrations register custom roles) lives in
			// sanitize_messages(), which runs immediately after validation.
			// Enforcing the enum here would reject custom roles before the
			// filter can see them and duplicate the sanitize-layer error codes.
			$role = $message['role'];

			// Validate content field (required for most roles).
			if ( ! isset( $message['content'] ) && 'assistant' !== $role ) {
				return new WP_Error(
					'rest_invalid_param',
					sprintf(
						/* translators: %d: message index */
						__( 'Message at index %d is missing required "content" property.', 'mcp-ai-wpoos' ),
						$index
					),
					array(
						'status'  => 400,
						'actions' => array(
							'add_content' => __( 'Each message must include a "content" property (string or array of content parts).', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Pairing semantics for tool messages (tool_call_id matching the
			// preceding assistant tool call) are intentionally NOT enforced here:
			// orphaned tool messages are silently discarded by
			// WP_MCP_AI_REST::filter_tool_messages_without_matching_calls() before
			// the payload reaches the provider. Rejecting them at the REST args
			// gate would turn a tolerated legacy payload into a hard 400.
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
					__( 'The "%s" parameter must be an array.', 'mcp-ai-wpoos' ),
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
						__( 'Attachment at index %d must be an object/array.', 'mcp-ai-wpoos' ),
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
						__( 'Attachment at index %d must include either "file_id" (integer) or "url" (string).', 'mcp-ai-wpoos' ),
						$index
					),
					array(
						'status'  => 400,
						'actions' => array(
							'provide_file_reference' => __( 'Each attachment must specify either a WordPress attachment ID via "file_id" or an external URL via "url".', 'mcp-ai-wpoos' ),
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
							__( 'Attachment at index %d has invalid "file_id". Must be a positive integer.', 'mcp-ai-wpoos' ),
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
						__( 'Attachment at index %d has invalid "url". Must be a valid URL.', 'mcp-ai-wpoos' ),
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
	public function validate_mcp_params( $value, $request, $param ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by REST API validation callback signature.
		// This validates the params object for MCP requests.
		// The structure depends on the method, so we validate based on that.
		$method = $request->get_param( 'method' );

		if ( ! $method ) {
			// Method will be validated by the endpoint args, so we just check if params is an object/array.
			if ( null !== $value && ! is_array( $value ) ) {
				return new WP_Error(
					'rest_invalid_param',
					__( 'The "params" parameter must be an object/array or null.', 'mcp-ai-wpoos' ),
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
						__( 'The "params" parameter must be an object for tools/call method.', 'mcp-ai-wpoos' ),
						array( 'status' => 400 )
					);
				}

				if ( ! isset( $value['name'] ) || ! is_string( $value['name'] ) ) {
					return new WP_Error(
						'rest_invalid_param',
						__( 'MCP tools/call requires a "name" parameter (string) specifying the tool to call.', 'mcp-ai-wpoos' ),
						array(
							'status'  => 400,
							'actions' => array(
								'provide_tool_name' => __( 'Include "params": {"name": "tool_slug", "arguments": {...}} in your request.', 'mcp-ai-wpoos' ),
							),
						)
					);
				}

				// Arguments is optional but must be an object if provided.
				if ( isset( $value['arguments'] ) && ! is_array( $value['arguments'] ) ) {
					return new WP_Error(
						'rest_invalid_param',
						__( 'The "arguments" parameter in tools/call must be an object.', 'mcp-ai-wpoos' ),
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
							__( 'The "params" parameter for %s method must be an object or null.', 'mcp-ai-wpoos' ),
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
				__( 'Messages must be provided as an array of role/content pairs.', 'mcp-ai-wpoos' ),
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
						__( 'The message role "%1$s" is not supported. Supported roles: %2$s.', 'mcp-ai-wpoos' ),
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

		if ( isset( $message['display'] ) && is_array( $message['display'] ) ) {
			$display = $this->sanitize_display_metadata( $message['display'] );
			if ( ! empty( $display ) ) {
				$metadata['display'] = $display;
			}
		}

		return $metadata;
	}

	/**
	 * Sanitize client display metadata attached to a message.
	 *
	 * Display metadata drives client-side rendering (bubble type, chart
	 * output, badge data) and is persisted with transcripts so conversations
	 * render identically when reloaded. Only known keys are retained and each
	 * value is sanitized for its type; scripts and event-handler attributes
	 * are stripped from chart HTML.
	 *
	 * @param array $display Raw display metadata.
	 * @return array Sanitized display metadata.
	 */
	public function sanitize_display_metadata( array $display ) {
		$clean = array();

		if ( isset( $display['bubbleType'] ) ) {
			$clean['bubbleType'] = sanitize_key( $display['bubbleType'] );
		}

		if ( isset( $display['text'] ) ) {
			$clean['text'] = wp_kses_post( (string) $display['text'] );
		}

		if ( isset( $display['message'] ) ) {
			$clean['message'] = wp_kses_post( (string) $display['message'] );
		}

		if ( isset( $display['chartHtml'] ) ) {
			$chart_html = wp_check_invalid_utf8( (string) $display['chartHtml'], true );
			$chart_html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $chart_html );
			$chart_html = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $chart_html );
			if ( '' !== $chart_html ) {
				$clean['chartHtml'] = $chart_html;
			}
		}

		if ( isset( $display['chartWidth'] ) ) {
			$clean['chartWidth'] = absint( $display['chartWidth'] );
		}

		if ( isset( $display['chartHeight'] ) ) {
			$clean['chartHeight'] = absint( $display['chartHeight'] );
		}

		if ( isset( $display['tool_calls'] ) && is_array( $display['tool_calls'] ) ) {
			$tool_calls = array();
			foreach ( $display['tool_calls'] as $tool_call ) {
				if ( ! is_array( $tool_call ) ) {
					continue;
				}

				$call = array();
				if ( isset( $tool_call['id'] ) ) {
					$call['id'] = sanitize_text_field( $tool_call['id'] );
				}
				if ( isset( $tool_call['type'] ) ) {
					$call['type'] = sanitize_text_field( $tool_call['type'] );
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
						$call['function'] = $function;
					}
				}
				if ( ! empty( $call ) ) {
					$tool_calls[] = $call;
				}
			}
			if ( ! empty( $tool_calls ) ) {
				$clean['tool_calls'] = $tool_calls;
			}
		}

		if ( isset( $display['usage'] ) && is_array( $display['usage'] ) ) {
			$usage = array();
			foreach ( $display['usage'] as $key => $value ) {
				$usage[ sanitize_key( $key ) ] = absint( $value );
			}
			if ( ! empty( $usage ) ) {
				$clean['usage'] = $usage;
			}
		}

		if ( isset( $display['cost'] ) && is_array( $display['cost'] ) ) {
			$cost = array();
			foreach ( $display['cost'] as $key => $value ) {
				$cost[ sanitize_key( $key ) ] = (float) $value;
			}
			if ( ! empty( $cost ) ) {
				$clean['cost'] = $cost;
			}
		}

		if ( isset( $display['capabilityFlags'] ) && is_array( $display['capabilityFlags'] ) ) {
			$flags = array_values(
				array_filter(
					array_unique(
						array_map( 'sanitize_key', array_map( 'strval', $display['capabilityFlags'] ) )
					)
				)
			);
			if ( ! empty( $flags ) ) {
				$clean['capabilityFlags'] = $flags;
			}
		}

		return $clean;
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
				__( 'Message content must be a string or an array of segments.', 'mcp-ai-wpoos' ),
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

			if ( in_array( $segment_type, array( 'text', 'input_text' ), true ) ) {
				// `input_text` is the legacy segment type; it is normalised to the
				// current `text` schema (documented compatibility behaviour).
				$text_content = isset( $item['text'] ) ? $item['text'] : '';
				$segment      = $attachments_helper->prepare_input_text_segment( $text_content );

				if ( '' !== $segment['text'] ) {
					$segments[] = $segment;
				}
			} elseif ( in_array( $segment_type, array( 'image_url', 'image_file', 'input_image', 'audio', 'file', 'input_file' ), true ) ) {
				$segment = $attachments_helper->prepare_input_attachment_segment( $item );

				// Propagate preparation errors (e.g. unknown file references,
				// forbidden attachments, unsupported MIME types) instead of
				// silently dropping the segment. Silently dropping produces a
				// misleading "invalid messages" error downstream and hides the
				// real failure from the client.
				if ( is_wp_error( $segment ) ) {
					return $segment;
				}

				$segments[] = $segment;
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

		$allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'ollama', 'lm_studio', 'cloudflare', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'embedded' ) );
		if ( ! is_array( $allowed_providers ) ) {
			$allowed_providers = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'ollama', 'lm_studio', 'cloudflare', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'embedded' );
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

		// Track whether system prompt came from request or assistant config for logging.
		$system_prompt_source = 'none';

		if ( isset( $options['system_prompt'] ) ) {
			$options['system_prompt'] = wp_kses_post( $options['system_prompt'] );
			$system_prompt_source     = 'request';
		}

		if ( empty( $options['system_prompt'] ) && ! empty( $assistant_config['system_prompt'] ) ) {
			$options['system_prompt'] = wp_kses_post( $assistant_config['system_prompt'] );
			$system_prompt_source     = 'assistant_config';
		}

		// Inject current date/time context to help AI models understand temporal context.
		// AI models have training cutoffs and need explicit current date information for accurate responses.
		if ( ! empty( $options['system_prompt'] ) ) {
			$current_date_context = sprintf(
				"\n\n---\n\n**Current Context Information:**\n- Current Date: %s\n- Current Year: %s\n- Current Time: %s UTC",
				gmdate( 'l, F j, Y' ),  // e.g., "Monday, February 3, 2026".
				gmdate( 'Y' ),           // e.g., "2026".
				gmdate( 'H:i:s' )       // e.g., "14:30:45".
			);

			/**
			 * Filter the current date context injected into system prompts.
			 *
			 * @param string $current_date_context  The date context string to inject.
			 * @param array  $options               Current options array.
			 * @param array  $assistant_config      Assistant configuration.
			 *
			 * @since 1.0.0
			 */
			$current_date_context = apply_filters( 'wp_mcp_ai_current_date_context', $current_date_context, $options, $assistant_config );

			// Only inject if not empty after filtering.
			if ( ! empty( $current_date_context ) ) {
				$options['system_prompt'] .= $current_date_context;
			}
		}

		// Comprehensive debug logging for system_prompt propagation across all providers.
		// This helps diagnose issues where assistant defaults may not be reaching the LLM.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			$log_data = array(
				'provider'                    => $provider,
				'system_prompt_source'        => $system_prompt_source,
				'has_system_prompt'           => isset( $options['system_prompt'] ),
				'system_prompt_empty'         => empty( $options['system_prompt'] ),
				'system_prompt_length'        => isset( $options['system_prompt'] ) ? strlen( (string) $options['system_prompt'] ) : 0,
				'system_prompt_preview'       => isset( $options['system_prompt'] ) ? substr( (string) $options['system_prompt'], 0, 150 ) . '...' : '',
				'assistant_id'                => isset( $assistant_config['ID'] ) ? $assistant_config['ID'] : null,
				'has_assistant_config_prompt' => ! empty( $assistant_config['system_prompt'] ),
				'config_prompt_length'        => ! empty( $assistant_config['system_prompt'] ) ? strlen( (string) $assistant_config['system_prompt'] ) : 0,
			);

			// Add warning if system prompt is missing despite assistant config having one.
			if ( empty( $options['system_prompt'] ) && ! empty( $assistant_config['system_prompt'] ) ) {
				$log_data['warning'] = 'Assistant config has system_prompt but it was not propagated to options';
			}

			WP_MCP_AI_Logger::log_event(
				'sanitize_options_system_prompt',
				'System prompt propagation in sanitize_options',
				$log_data
			);
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

		if ( isset( $options['corpus_name'] ) ) {
			$options['corpus_name'] = sanitize_text_field( $options['corpus_name'] );
		} elseif ( isset( $assistant_config['corpus_name'] ) && '' !== $assistant_config['corpus_name'] ) {
			$options['corpus_name'] = sanitize_text_field( $assistant_config['corpus_name'] );
		} else {
			$options['corpus_name'] = '';
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

		// Gemini extended thinking / reasoning budget.
		// When set, Gemini 2.5+ models will use thinkingConfig with the specified token budget.
		if ( isset( $options['thinking_budget_tokens'] ) ) {
			$budget = absint( $options['thinking_budget_tokens'] );
			if ( $budget > 0 ) {
				$options['thinking_budget_tokens'] = min( 24576, $budget );
			} else {
				unset( $options['thinking_budget_tokens'] );
			}
		} elseif ( 'gemini' === $provider ) {
			// Propagate the global Gemini thinking budget from settings when not overridden per-request.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$budget   = isset( $settings['gemini_thinking_budget_tokens'] ) ? absint( $settings['gemini_thinking_budget_tokens'] ) : 0;
			if ( $budget > 0 ) {
				$options['thinking_budget_tokens'] = $budget;
			}
		}

		// Remove 'stream' parameter if present - it's only used by SSE handler to determine.
		// response format (SSE vs JSON), not for AI provider clients which manage their own.
		// streaming behavior. This prevents the frontend's stream flag from being passed to
		// providers like LM Studio which explicitly disable streaming to prevent chunked responses.
		if ( isset( $options['stream'] ) ) {
			unset( $options['stream'] );
		}

		// --- Prompt Caching options ---
		// When the assistant has prompt caching enabled, inject cache_system_prompt
		// flag so provider clients (Anthropic) can add cache_control breakpoints.
		if ( ! empty( $assistant_config['prompt_caching'] ) ) {
			$options['cache_system_prompt'] = true;
		}

		// Generate a stable prompt_cache_key from assistant_id + system_prompt hash.
		// This routes requests with the same prefix to the same server for higher
		// cache hit rates on OpenAI, DeepSeek, and OpenRouter.
		if ( ! empty( $options['cache_system_prompt'] ) && ! empty( $options['system_prompt'] ) ) {
			$assistant_id = isset( $assistant_config['ID'] ) ? (int) $assistant_config['ID'] : 0;
			// Use the first 256 chars of system prompt as the stable prefix identifier.
			$prompt_prefix               = substr( $options['system_prompt'], 0, 256 );
			$options['prompt_cache_key'] = 'wp_mcp_ai_' . $assistant_id . '_' . md5( $prompt_prefix );
		}

		// Allow prompt cache retention to be overridden per-request or per-assistant.
		if ( ! empty( $options['prompt_cache_retention'] ) ) {
			$retention = sanitize_key( $options['prompt_cache_retention'] );
			if ( in_array( $retention, array( 'in_memory', '24h' ), true ) ) {
				$options['prompt_cache_retention'] = $retention;
			} else {
				unset( $options['prompt_cache_retention'] );
			}
		} elseif ( ! empty( $assistant_config['prompt_cache_retention'] ) ) {
			$retention = sanitize_key( $assistant_config['prompt_cache_retention'] );
			if ( in_array( $retention, array( 'in_memory', '24h' ), true ) ) {
				$options['prompt_cache_retention'] = $retention;
			}
		}

		// Apply prompt cache optimization: ensure messages are ordered for cache hits.
		// This is done in sanitize_options so all code paths benefit.
		if ( ! empty( $options['cache_system_prompt'] ) && class_exists( 'WP_MCP_AI_Prompt_Optimizer' ) ) {
			// Store original order context for logging.
			if ( ! empty( $options['system_prompt'] ) ) {
				$split = WP_MCP_AI_Prompt_Optimizer::split_system_prompt( $options['system_prompt'] );
				if ( ! empty( $split['dynamic_context'] ) ) {
					// Log that we detected dynamic context for cache optimization.
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'prompt_cache_split',
							'System prompt split for cache optimization',
							array(
								'static_core_length'     => strlen( $split['static_core'] ),
								'dynamic_context_length' => strlen( $split['dynamic_context'] ),
							)
						);
					}
				}
			}
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
	 * If the tool implements WP_MCP_AI_Tool_LLM_Sanitizer_Interface, its sanitize_for_llm
	 * method will be called to strip large base64 content before display.
	 *
	 * @param mixed                         $result        The tool result to sanitize.
	 * @param string                        $tool_name     The name of the tool.
	 * @param WP_MCP_AI_Tool_Interface|null $tool_instance Optional tool instance for interface-based sanitization.
	 * @return mixed Sanitized result.
	 */
	public function sanitize_tool_result_for_display( $result, $tool_name, $tool_instance = null ) {
		// If tool implements custom sanitization interface, use it first to strip base64 content.
		// This prevents large binary data from being sent to the chat client.
		if ( $tool_instance && $tool_instance instanceof WP_MCP_AI_Tool_LLM_Sanitizer_Interface ) {
			$result = $tool_instance->sanitize_for_llm( $result );
		}

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

		// Serialise to a string so the two safety layers below can work on
		// the exact bytes that will be placed in the prompt.
		$content = is_string( $result ) ? $result : (string) wp_json_encode( $result );

		// Layer 1: neutralise special tokens that could allow prompt injection.
		$content = $this->neutralise_tool_result_delimiters( $content );

		// Layer 2: cap byte size to prevent context exhaustion / runaway cost.
		$content = $this->truncate_tool_result_content( $content );

		return $content;
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
		// Remove verbose fields that add token cost without LLM value.
		$fields_to_remove = array( 'headers', 'raw', 'response', 'request', 'retrieved_at', 'fetched_at', 'user_agent' );

		foreach ( $fields_to_remove as $key ) {
			unset( $metadata[ $key ] );
		}

		$sanitized = array();

		foreach ( $metadata as $key => $value ) {
			$sanitized_key = sanitize_key( $key );

			if ( is_array( $value ) || is_object( $value ) ) {
				// Recursively clean nested metadata.
				$nested = $this->sanitize_metadata_for_llm( is_object( $value ) ? (array) $value : $value );
				if ( ! empty( $nested ) ) {
					$sanitized[ $sanitized_key ] = $nested;
				}
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

	/**
	 * Truncate a serialised tool-result string to a safe byte cap.
	 *
	 * Large tool results (e.g. a full-page web crawl, a raw database dump)
	 * can silently exhaust the model's context window or incur unexpected
	 * token costs. This helper caps the byte size and appends a visible
	 * truncation marker so the model knows the payload was cut.
	 *
	 * The cap is tunable per-site via the
	 * {@see 'wp_mcp_ai_tool_result_max_bytes'} filter.
	 *
	 * @since 1.8.0
	 *
	 * @param string $content Serialised tool-result string.
	 * @return string Potentially truncated string with marker appended.
	 */
	public function truncate_tool_result_content( $content ) {
		/**
		 * Maximum byte size for a single tool-result string injected into
		 * the prompt.
		 *
		 * @since 1.8.0
		 *
		 * @param int $max_bytes Byte cap. Default 65536 (64 KB).
		 */
		$max_bytes = (int) apply_filters( 'wp_mcp_ai_tool_result_max_bytes', self::TOOL_RESULT_MAX_BYTES );

		// Guard: never truncate to a nonsensical length.
		if ( $max_bytes < 256 ) {
			$max_bytes = 256;
		}

		if ( strlen( $content ) <= $max_bytes ) {
			return $content;
		}

		// Truncate and append a human- and model-readable marker.
		$marker  = ' [tool_result_truncated]';
		$allowed = $max_bytes - strlen( $marker );
		return substr( $content, 0, $allowed ) . $marker;
	}

	/**
	 * Strip special tokens that could be used to inject text outside the
	 * intended tool-result boundary (prompt injection / jailbreak vector).
	 *
	 * Models ingest tool-role messages as plain text; an attacker who
	 * controls the data returned by an external tool could embed special
	 * tokens (e.g. ChatML `<|im_start|>`, Llama `<|eot_id|>`) that some
	 * inference backends interpret as role-change signals, or XML-style
	 * markers that the prompt template uses to delimit tool output.
	 *
	 * Tokens are stripped rather than HTML-encoded because they are
	 * meaningless to the model's knowledge base and stripping is safer
	 * than forwarding a slightly modified token that could still confuse
	 * tokenisers.
	 *
	 * @since 1.8.0
	 *
	 * @param string $content Tool-result string to clean.
	 * @return string Cleaned string.
	 */
	public function neutralise_tool_result_delimiters( $content ) {
		// Null bytes — strip entirely; they break JSON encoding and can
		// confuse string-length calculations.
		$content = str_replace( "\x00", '', $content );

		// ChatML special tokens (OpenAI / tiktoken-based models).
		$content = str_replace(
			array(
				'<|im_start|>',
				'<|im_end|>',
				'<|endoftext|>',
				'<|fim_prefix|>',
				'<|fim_middle|>',
				'<|fim_suffix|>',
				'<|fim_pad|>',
			),
			'',
			$content
		);

		// Llama / Meta special tokens.
		$content = str_replace(
			array(
				'<|eot_id|>',
				'<|start_header_id|>',
				'<|end_header_id|>',
				'<|finetune_right_pad_id|>',
				'<s>',
				'</s>',
			),
			'',
			$content
		);

		// XML-style function / tool-call delimiters used in various prompt
		// templates (Anthropic XML style, open-source fine-tunes).
		$content = str_replace(
			array(
				'<tool_response>',
				'</tool_response>',
				'<function_calls>',
				'</function_calls>',
				'<invoke>',
				'</invoke>',
				'<tool_call>',
				'</tool_call>',
				'</tool_call>',
			),
			'',
			$content
		);

		return $content;
	}
}
