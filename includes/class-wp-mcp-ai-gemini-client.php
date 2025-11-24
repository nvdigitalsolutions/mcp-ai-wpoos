<?php
/**
 * Gemini API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a wrapper around Gemini's generateContent endpoint.
 */
class WP_MCP_AI_Gemini_Client {
	const API_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/**
	 * Retrieve the configured API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		return isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
	}

	/**
	 * Perform a chat completion request against Gemini.
	 *
	 * @param array $messages Message payload to send to Gemini.
	 * @param array $options  Additional options (model, temperature, tools, timeout).
	 * @return array|WP_Error
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_gemini_api_key', __( 'No Gemini API key has been configured.', 'wp-mcp-ai' ) );
		}

		$model = $this->resolve_model( $options );

		if ( empty( $model ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_gemini_model', __( 'No Gemini model has been configured.', 'wp-mcp-ai' ) );
		}

		$payload = $this->build_payload( $messages, $options );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$endpoint = sprintf( self::API_ENDPOINT, rawurlencode( $model ) );
		$url      = add_query_arg( 'key', rawurlencode( $api_key ), $endpoint );

		$request_args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $this->resolve_timeout( $options ),
		);

		WP_MCP_AI_Logger::log_event( 'gemini_request', 'Sending request to Gemini.', array( 'payload' => $this->obfuscate_request_for_log( $payload ) ) );

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Gemini request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_http_error',
				__( 'The Gemini API request failed to complete.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$body     = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $body, true );
		$json_err = json_last_error();

		if ( JSON_ERROR_NONE !== $json_err ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode Gemini response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Gemini API returned malformed JSON.', 'wp-mcp-ai' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from Gemini.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'Gemini returned an error response.',
				array(
					'code' => $code,
					'body' => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_api_error',
				$error_message,
				array(
					'status' => $code,
					'body'   => $decoded,
				)
			);
		}

		$normalized = $this->normalize_response( $decoded );

		WP_MCP_AI_Logger::log_event( 'gemini_response', 'Gemini request completed.', array( 'response' => $normalized ) );

		return $normalized;
	}

	/**
	 * Resolve the model identifier for the request.
	 *
	 * @param array $options Request options.
	 * @return string
	 */
	protected function resolve_model( array $options ) {
		if ( ! empty( $options['model'] ) ) {
			return sanitize_text_field( $options['model'] );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		if ( ! empty( $settings['default_gemini_model'] ) ) {
			return $settings['default_gemini_model'];
		}

		return 'gemini-1.5-flash';
	}

	/**
	 * Build the request payload sent to Gemini.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Request options.
	 * @return array|WP_Error
	 */
	protected function build_payload( array $messages, array $options ) {
		if ( empty( $messages ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_messages', __( 'No chat messages were provided for the request.', 'wp-mcp-ai' ) );
		}

		$contents         = array();
		$system_fragments = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
			$content = isset( $message['content'] ) ? $message['content'] : array();

			if ( 'system' === $role ) {
				$system_fragments = array_merge( $system_fragments, $this->normalize_segments_to_text( $content ) );
				continue;
			}

			$text_segments = $this->normalize_segments_to_text( $content );

			if ( empty( $text_segments ) ) {
				continue;
			}

			$gemini_role = 'user';
			if ( 'assistant' === $role ) {
				$gemini_role = 'model';
			}

			$parts = array();
			foreach ( $text_segments as $segment_text ) {
				$parts[] = array( 'text' => $segment_text );
			}

			$contents[] = array(
				'role'  => $gemini_role,
				'parts' => $parts,
			);
		}

		if ( ! empty( $options['system_prompt'] ) ) {
			$system_fragments[] = wp_kses_post( $options['system_prompt'] );
		}

		if ( ! empty( $options['memory_documents'] ) && is_array( $options['memory_documents'] ) ) {
			$system_fragments = array_merge( $system_fragments, $this->build_memory_fragments( $options['memory_documents'] ) );
		}

		$payload = array(
			'contents' => array_values( $contents ),
		);

		if ( ! empty( $system_fragments ) ) {
			$system_parts = array();
			foreach ( $system_fragments as $fragment ) {
				$fragment = trim( $fragment );
				if ( '' === $fragment ) {
					continue;
				}
				$system_parts[] = array( 'text' => $fragment );
			}

			if ( ! empty( $system_parts ) ) {
				$payload['system_instruction'] = array( 'parts' => $system_parts );
			}
		}

		if ( array_key_exists( 'temperature', $options ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
			$payload['generationConfig'] = array(
				'temperature' => (float) $options['temperature'],
			);
		}

		if ( ! empty( $options['tools'] ) && is_array( $options['tools'] ) ) {
			$translated_tools = $this->translate_tools( $options['tools'] );
			if ( ! empty( $translated_tools ) ) {
				$payload['tools'] = $translated_tools;
			}
		}

		return $payload;
	}

	/**
	 * Normalise segments to text fragments.
	 *
	 * @param mixed $segments Message segments.
	 * @return array
	 */
	protected function normalize_segments_to_text( $segments ) {
		if ( is_string( $segments ) || is_numeric( $segments ) ) {
			$text = trim( wp_kses_post( (string) $segments ) );

			return '' === $text ? array() : array( $text );
		}

		if ( ! is_array( $segments ) ) {
			return array();
		}

		$fragments = array();

		foreach ( $segments as $segment ) {
			if ( is_string( $segment ) || is_numeric( $segment ) ) {
				$text = trim( wp_kses_post( (string) $segment ) );
				if ( '' !== $text ) {
					$fragments[] = $text;
				}
				continue;
			}

			if ( ! is_array( $segment ) ) {
				continue;
			}

			$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : 'text';

			switch ( $type ) {
				case 'input_text':
				case 'text':
					$text = '';
					if ( isset( $segment['text'] ) ) {
						$text = (string) $segment['text'];
					} elseif ( isset( $segment['content'] ) ) {
						$text = (string) $segment['content'];
					}
					$text = trim( wp_kses_post( $text ) );
					if ( '' !== $text ) {
						$fragments[] = $text;
					}
					break;

				case 'input_image':
					$label = __( '[Image attachment]', 'wp-mcp-ai' );
					if ( isset( $segment['caption'] ) && '' !== $segment['caption'] ) {
						$label = '[Image: ' . sanitize_text_field( $segment['caption'] ) . ']';
					} elseif ( isset( $segment['image_url']['url'] ) && '' !== $segment['image_url']['url'] ) {
						$label = '[Image: ' . esc_url_raw( $segment['image_url']['url'] ) . ']';
					}
					$fragments[] = $label;
					break;

				case 'input_file':
					$label = __( '[File attachment]', 'wp-mcp-ai' );
					if ( isset( $segment['display_name'] ) && '' !== $segment['display_name'] ) {
						$label = '[File: ' . sanitize_text_field( $segment['display_name'] ) . ']';
					}
					$fragments[] = $label;
					break;

				case 'tool_result':
					$result_text = $this->render_tool_result_text( $segment );
					if ( '' !== $result_text ) {
						$fragments[] = $result_text;
					}
					break;

				default:
					if ( isset( $segment['text'] ) && '' !== $segment['text'] ) {
						$fragments[] = trim( wp_kses_post( (string) $segment['text'] ) );
					}
					break;
			}
		}

		return $fragments;
	}

	/**
	 * Convert tool result segment into plain text.
	 *
	 * @param array $segment Tool result segment.
	 * @return string
	 */
	protected function render_tool_result_text( array $segment ) {
		if ( isset( $segment['output'] ) ) {
			if ( is_string( $segment['output'] ) ) {
				return $segment['output'];
			} elseif ( is_array( $segment['output'] ) ) {
				$parts = $this->normalize_segments_to_text( $segment['output'] );
				if ( ! empty( $parts ) ) {
					return implode( "\n\n", $parts );
				}
			} elseif ( is_object( $segment['output'] ) ) {
				return wp_json_encode( $segment['output'] );
			}
		}

		if ( isset( $segment['content'] ) ) {
			$parts = $this->normalize_segments_to_text( $segment['content'] );
			if ( ! empty( $parts ) ) {
				return implode( "\n\n", $parts );
			}
		}

		return '';
	}

	/**
	 * Translate OpenAI-style tool definitions into Gemini declarations.
	 *
	 * @param array $tools Tool definitions.
	 * @return array
	 */
	protected function translate_tools( array $tools ) {
		$declarations = array();

		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			$type = isset( $tool['type'] ) ? sanitize_key( $tool['type'] ) : '';
			if ( 'function' !== $type ) {
				continue;
			}

			if ( empty( $tool['function'] ) || ! is_array( $tool['function'] ) ) {
				continue;
			}

			$function = $tool['function'];
			$name     = isset( $function['name'] ) ? sanitize_text_field( $function['name'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$declaration = array( 'name' => $name );

			if ( ! empty( $function['description'] ) ) {
				$declaration['description'] = sanitize_text_field( $function['description'] );
			}

			if ( isset( $function['parameters'] ) && is_array( $function['parameters'] ) ) {
				$declaration['parameters'] = $function['parameters'];
			}

			$declarations[] = $declaration;
		}

		if ( empty( $declarations ) ) {
			return array();
		}

		return array(
			array(
				'functionDeclarations' => $declarations,
			),
		);
	}

	/**
	 * Build memory document fragments used in the system instruction.
	 *
	 * @param array $documents Memory documents.
	 * @return array
	 */
	protected function build_memory_fragments( array $documents ) {
		$fragments = array();

		foreach ( $documents as $document ) {
			if ( empty( $document['chunks'] ) || ! is_array( $document['chunks'] ) ) {
				continue;
			}

			$title = isset( $document['title'] ) && '' !== $document['title'] ? sanitize_text_field( $document['title'] ) : __( 'Document', 'wp-mcp-ai' );

			$chunks = array_values( array_filter( array_map( 'strval', $document['chunks'] ) ) );

			$parts      = count( $chunks );
			$part_index = 0;

			foreach ( $chunks as $chunk ) {
				++$part_index;

				$label = $title;
				if ( $parts > 1 ) {
					/* translators: %1$s: document title, %2$d: chunk number. */
					$label = sprintf( __( '%1$s (Part %2$d)', 'wp-mcp-ai' ), $title, $part_index );
				}

				/* translators: %1$s: document title, %2$s: extracted text snippet. */
				$fragments[] = sprintf( __( 'Reference document "%1$s": %2$s', 'wp-mcp-ai' ), $label, $chunk );
			}
		}

		return $fragments;
	}

	/**
	 * Resolve the timeout for the request.
	 *
	 * @param array $options Request options.
	 * @return int
	 */
	protected function resolve_timeout( array $options ) {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;

		if ( isset( $options['timeout'] ) && $options['timeout'] ) {
			$timeout = max( 5, absint( $options['timeout'] ) );
		}

		return max( 5, $timeout );
	}

	/**
	 * Convert a Gemini response to an OpenAI-style structure for downstream compatibility.
	 *
	 * @param array $response Decoded Gemini response.
	 * @return array
	 */
	protected function normalize_response( array $response ) {
		$choices = array();

		if ( isset( $response['candidates'] ) && is_array( $response['candidates'] ) ) {
			foreach ( $response['candidates'] as $index => $candidate ) {
				$message    = array( 'role' => 'assistant' );
				$segments   = array();
				$tool_calls = array();

				if ( isset( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ) {
					foreach ( $candidate['content']['parts'] as $part ) {
						if ( isset( $part['text'] ) ) {
							$segments[] = array(
								'type' => 'text',
								'text' => (string) $part['text'],
							);
							continue;
						}

						if ( isset( $part['functionCall'] ) && is_array( $part['functionCall'] ) ) {
							$tool_call = $this->convert_function_call_to_tool_call( $part['functionCall'], $index );
							if ( $tool_call ) {
								$tool_calls[] = $tool_call;
							}
						}

						if ( isset( $part['functionResponse'] ) && is_array( $part['functionResponse'] ) ) {
							$segments[] = array(
								'type' => 'text',
								'text' => $this->render_function_response_text( $part['functionResponse'] ),
							);
						}
					}
				}

				if ( ! empty( $segments ) ) {
					$message['content'] = $segments;
				}

				if ( ! empty( $tool_calls ) ) {
					$message['tool_calls'] = $tool_calls;
				}

				$choice = array(
					'index'   => $index,
					'message' => $message,
				);

				if ( isset( $candidate['finishReason'] ) ) {
					$choice['finish_reason'] = $candidate['finishReason'];
				}

				$choices[] = $choice;
			}
		}

		$normalized = array(
			'choices'  => $choices,
			'provider' => 'gemini',
		);

		if ( isset( $response['usageMetadata'] ) && is_array( $response['usageMetadata'] ) ) {
			$usage = array();

			if ( isset( $response['usageMetadata']['promptTokenCount'] ) ) {
				$usage['prompt_tokens'] = (int) $response['usageMetadata']['promptTokenCount'];
			}

			if ( isset( $response['usageMetadata']['candidatesTokenCount'] ) ) {
				$usage['completion_tokens'] = (int) $response['usageMetadata']['candidatesTokenCount'];
			}

			if ( ! empty( $usage ) ) {
				$normalized['usage'] = $usage;
			}
		}

		return $normalized;
	}

	/**
	 * Convert a Gemini function call into an OpenAI-style tool call payload.
	 *
	 * @param array $function_call Gemini function call payload.
	 * @param int   $index         Candidate index.
	 * @return array|null
	 */
	protected function convert_function_call_to_tool_call( array $function_call, $index ) {
		$name = isset( $function_call['name'] ) ? sanitize_text_field( $function_call['name'] ) : '';

		if ( '' === $name ) {
			return null;
		}

		$args = '{}';
		if ( isset( $function_call['args'] ) && is_array( $function_call['args'] ) ) {
			$args = wp_json_encode( $function_call['args'] );
		}

		return array(
			'id'       => sprintf( 'gemini-function-%d-%s', $index, uniqid() ),
			'type'     => 'function',
			'function' => array(
				'name'      => $name,
				'arguments' => $args,
			),
		);
	}

	/**
	 * Render a Gemini function response into text.
	 *
	 * @param array $function_response Gemini function response payload.
	 * @return string
	 */
	protected function render_function_response_text( array $function_response ) {
		if ( isset( $function_response['name'] ) && isset( $function_response['response'] ) ) {
			$rendered = sprintf( '%s: %s', sanitize_text_field( $function_response['name'] ), wp_json_encode( $function_response['response'] ) );

			return $rendered;
		}

		if ( isset( $function_response['text'] ) ) {
			return (string) $function_response['text'];
		}

		return '';
	}

	/**
	 * Remove large payloads from the logged request.
	 *
	 * @param array $payload Request payload.
	 * @return array
	 */
	protected function obfuscate_request_for_log( array $payload ) {
		if ( isset( $payload['contents'] ) ) {
			$payload['contents'] = '[redacted]';
		}

		if ( isset( $payload['system_instruction'] ) ) {
			$payload['system_instruction'] = '[redacted]';
		}

		return $payload;
	}
}
