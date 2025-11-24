<?php
/**
 * OpenAI API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a small wrapper around OpenAI's Chat Completions HTTP endpoint.
 */
class WP_MCP_AI_OpenAI_Client {
	const CHAT_COMPLETIONS_ENDPOINT = 'https://api.openai.com/v1/chat/completions';
	const RESPONSES_ENDPOINT        = 'https://api.openai.com/v1/responses';

	/**
	 * Retrieve the configured API key.
	 *
	 * @return string
	 */
	public function get_api_key() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		return isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
	}

	/**
	 * Perform a chat completion request.
	 *
	 * @param array $messages Message payload to send to OpenAI.
	 * @param array $options  Additional options (model, temperature, tools, timeout).
	 * @return array|WP_Error
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_api_key', __( 'No OpenAI API key has been configured.', 'wp-mcp-ai' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$model    = ! empty( $options['model'] ) ? $options['model'] : $settings['default_model'];
		$timeout  = ! empty( $options['timeout'] ) ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
		$timeout  = max( 5, $timeout );
		$payload  = array(
			'model' => $model,
		);

		$should_use_responses_api = $this->should_use_responses_api( $messages, $options );
		$chat_messages            = $this->normalise_messages_for_payload( $messages );

		if ( $should_use_responses_api ) {
			$payload['input'] = $this->prepare_responses_input( $messages, $chat_messages );
		} else {
			$payload['messages'] = $chat_messages;
		}

		$message_key = $should_use_responses_api ? 'input' : 'messages';

		if ( empty( $payload[ $message_key ] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_messages', __( 'No chat messages were provided for the request.', 'wp-mcp-ai' ) );
		}

		if ( isset( $options['temperature'] ) && null !== $options['temperature'] && '' !== $options['temperature'] ) {
			$temperature            = floatval( $options['temperature'] );
			$payload['temperature'] = max( 0, min( 2, $temperature ) );
		}

		$system_messages = array();

		if ( ! empty( $options['system_prompt'] ) ) {
			$system_messages[] = array(
				'role'    => 'system',
				'content' => array(
					array(
						'type' => 'text',
						'text' => (string) $options['system_prompt'],
					),
				),
			);
		}

		$memory_messages = $this->build_memory_messages_from_options( $options );

		if ( ! empty( $memory_messages ) ) {
			$system_messages = array_merge( $system_messages, $memory_messages );
		}

		if ( ! empty( $system_messages ) ) {
			$payload[ $message_key ] = array_merge( $system_messages, $payload[ $message_key ] );
		}

		if ( ! empty( $options['tools'] ) ) {
			$payload['tools'] = array_values( $options['tools'] );
		}

		if ( $should_use_responses_api && ! empty( $options['attachments'] ) && is_array( $options['attachments'] ) ) {
			$payload['attachments'] = array_values( $options['attachments'] );
		}

		if ( ! empty( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
			$payload['response_format'] = $options['response_format'];
		}

		$request_args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $timeout,
		);

		WP_MCP_AI_Logger::log_event( 'openai_request', 'Sending request to OpenAI.', array( 'payload' => $this->obfuscate_request_for_log( $payload ) ) );

		$endpoint = $should_use_responses_api ? self::RESPONSES_ENDPOINT : self::CHAT_COMPLETIONS_ENDPOINT;
		$response = wp_remote_post( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'OpenAI request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_http_error',
				__( 'The OpenAI API request failed to complete.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code     = wp_remote_retrieve_response_code( $response );
		$body     = wp_remote_retrieve_body( $response );
		$decoded  = json_decode( $body, true );
		$json_err = json_last_error();

		if ( JSON_ERROR_NONE !== $json_err ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The OpenAI API returned malformed JSON.', 'wp-mcp-ai' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			WP_MCP_AI_Logger::log_error(
				'OpenAI returned an error response.',
				array(
					'code' => $code,
					'body' => $decoded,
				)
			);

			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from OpenAI.', 'wp-mcp-ai' );

			return new WP_Error(
				'wp_mcp_ai_api_error',
				$message,
				array(
					'status' => $code,
					'body'   => $decoded,
				)
			);
		}

		if ( $should_use_responses_api ) {
			$decoded = $this->convert_responses_result_to_chat_completion( $decoded );
		}

		WP_MCP_AI_Logger::log_event( 'openai_response', 'OpenAI request completed.', array( 'response' => $decoded ) );

		return $decoded;
	}

	/**
	 * Prepare chat messages for the OpenAI Chat Completions payload.
	 *
	 * The REST layer represents text-only messages as arrays of segments so
	 * attachments and tool calls can be normalised consistently. Older OpenAI
	 * models (for example, gpt-3.5-turbo) only accept plain strings for the
	 * `content` field which causes those requests to fail. To remain compatible
	 * we collapse text-only segment arrays back into strings while preserving
	 * multimodal payloads that rely on structured segments.
	 *
	 * @param array $messages Sanitised chat messages.
	 * @return array
	 */
	protected function normalise_messages_for_payload( array $messages ) {
		$normalised = array();

		foreach ( $messages as $message ) {
			if ( ! isset( $message['content'] ) || ! is_array( $message['content'] ) ) {
				$normalised[] = $message;
				continue;
			}

			$segments = array_values( $message['content'] );

			if ( empty( $segments ) ) {
				$message['content'] = '';
				$normalised[]       = $message;
				continue;
			}

			$all_text   = true;
			$text_parts = array();

			foreach ( $segments as $segment ) {
				if ( ! is_array( $segment ) ) {
					$all_text = false;
					break;
				}

				$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

				if ( 'text' !== $type ) {
					$all_text = false;
					break;
				}

				$text_parts[] = isset( $segment['text'] ) ? (string) $segment['text'] : '';
			}

			if ( $all_text ) {
				$text_parts         = array_filter(
					$text_parts,
					static function ( $part ) {
						return '' !== trim( $part );
					}
				);
				$message['content'] = implode( "\n\n", $text_parts );
			} else {
				$message['content'] = $segments;
			}

			$normalised[] = $message;
		}

		return $normalised;
	}

	/**
	 * Build additional system messages from memory documents.
	 *
	 * @param array $options Chat request options.
	 * @return array
	 */
	protected function build_memory_messages_from_options( array $options ) {
		if ( empty( $options['memory_documents'] ) || ! is_array( $options['memory_documents'] ) ) {
			return array();
		}

		$messages = array();

		foreach ( $options['memory_documents'] as $document ) {
			if ( empty( $document['chunks'] ) || ! is_array( $document['chunks'] ) ) {
				continue;
			}

			$title      = isset( $document['title'] ) && '' !== $document['title'] ? $document['title'] : __( 'Document', 'wp-mcp-ai' );
			$chunks     = array_values( array_filter( array_map( 'strval', $document['chunks'] ) ) );
			$parts      = count( $chunks );
			$part_index = 0;

			foreach ( $chunks as $chunk ) {
				++$part_index;

				$label = $title;

				if ( $parts > 1 ) {
					/* translators: %1$s: document title, %2$d: chunk number. */
					$label = sprintf( __( '%1$s (Part %2$d)', 'wp-mcp-ai' ), $title, $part_index );
				}

				$messages[] = array(
					'role'    => 'system',
					'content' => array(
						array(
							'type' => 'text',
							/* translators: %1$s: document title, %2$s: extracted text snippet. */
							'text' => sprintf( __( 'Reference document "%1$s": %2$s', 'wp-mcp-ai' ), $label, $chunk ),
						),
					),
				);
			}
		}

		return $messages;
	}

	/**
	 * Remove large message payloads when logging requests.
	 *
	 * @param array $payload The payload that will be logged.
	 * @return array
	 */
	protected function obfuscate_request_for_log( array $payload ) {
		$message_key = null;

		if ( isset( $payload['messages'] ) && is_array( $payload['messages'] ) ) {
			$message_key = 'messages';
		} elseif ( isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
			$message_key = 'input';
		}

		if ( null !== $message_key ) {
			$trimmed_messages = array();
			foreach ( $payload[ $message_key ] as $message ) {
				if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
					$trimmed_segments = array();

					foreach ( $message['content'] as $segment ) {
						if ( ! is_array( $segment ) ) {
							continue;
						}

						$segment_copy = $segment;
						$type         = isset( $segment['type'] ) ? $segment['type'] : '';

						if ( in_array( $type, array( 'text', 'input_text' ), true ) && isset( $segment['text'] ) ) {
							$content              = (string) $segment['text'];
							$length               = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
							$slice                = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 200 ) : substr( $content, 0, 200 );
							$segment_copy['text'] = $slice . ( $length > 200 ? '…' : '' );
						}

						if ( 'input_image' === $type && isset( $segment['image_url']['url'] ) ) {
							$segment_copy['image_url']['url'] = esc_url_raw( $segment['image_url']['url'] );
						}

						if ( 'input_image' === $type && isset( $segment['image_file']['file_id'] ) ) {
							$segment_copy['image_file'] = array( 'file_id' => $segment['image_file']['file_id'] );
						}

						if ( 'input_file' === $type && isset( $segment['file_id'] ) ) {
							$segment_copy = array(
								'type'    => 'input_file',
								'file_id' => $segment['file_id'],
							);

							if ( isset( $segment['display_name'] ) ) {
								$segment_copy['display_name'] = $segment['display_name'];
							}
						}

						$trimmed_segments[] = $segment_copy;
					}

					$message['content'] = $trimmed_segments;
				} elseif ( isset( $message['content'] ) ) {
					$content            = (string) $message['content'];
					$length             = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
					$slice              = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 200 ) : substr( $content, 0, 200 );
					$message['content'] = $slice . ( $length > 200 ? '…' : '' );
				}

				$trimmed_messages[] = $message;
			}
			$payload[ $message_key ] = $trimmed_messages;
		}

		if ( isset( $payload['attachments'] ) && is_array( $payload['attachments'] ) ) {
			$scrubbed = array();

			foreach ( $payload['attachments'] as $attachment ) {
				if ( ! is_array( $attachment ) ) {
					continue;
				}

				if ( isset( $attachment['data'] ) ) {
					$attachment['data'] = '[redacted]';
				}

				$scrubbed[] = $attachment;
			}

			$payload['attachments'] = $scrubbed;
		}

		return $payload;
	}

	/**
	 * Determine whether the OpenAI Responses API should be used for the request.
	 *
	 * @param array $messages Sanitized chat messages.
	 * @param array $options  Prepared request options.
	 * @return bool
	 */
	protected function should_use_responses_api( array $messages, array $options ) {
		if ( ! empty( $options['attachments'] ) && is_array( $options['attachments'] ) ) {
			return true;
		}

		foreach ( $messages as $message ) {
			if ( empty( $message['content'] ) || ! is_array( $message['content'] ) ) {
				continue;
			}

			foreach ( $message['content'] as $segment ) {
				if ( ! is_array( $segment ) ) {
					continue;
				}

				$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

				if ( isset( $segment['file_id'] ) ) {
					return true;
				}

				if ( isset( $segment['image_file']['file_id'] ) ) {
					return true;
				}

				if ( strpos( $type, 'input_' ) === 0 && ( isset( $segment['file_id'] ) || isset( $segment['image_file'] ) ) ) {
					return true;
				}

				if ( 'input_file' === $type ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Prepare the payload used when calling the Responses API.
	 *
	 * @param array $original_messages Original chat messages.
	 * @param array $normalised_messages Messages after normalisation.
	 * @return array
	 */
	protected function prepare_responses_input( array $original_messages, array $normalised_messages ) {
		$prepared = array();

		foreach ( $normalised_messages as $index => $message ) {
			$entry = $message;

			$original_content = isset( $original_messages[ $index ]['content'] ) ? $original_messages[ $index ]['content'] : null;

			if ( isset( $entry['content'] ) && ! is_array( $entry['content'] ) ) {
				$content_string   = (string) $entry['content'];
				$entry['content'] = array(
					array(
						'type' => 'text',
						'text' => $content_string,
					),
				);
			} elseif ( isset( $entry['content'] ) && is_array( $entry['content'] ) ) {
				$entry['content'] = array_values( $entry['content'] );
			} elseif ( is_array( $original_content ) ) {
				$entry['content'] = array_values( $original_content );
			} else {
				$entry['content'] = array();
			}

			$prepared[] = $entry;
		}

		return $prepared;
	}

	/**
	 * Convert a Responses API result into a shape that matches chat completions.
	 *
	 * @param array $response Raw Responses API payload.
	 * @return array
	 */
	protected function convert_responses_result_to_chat_completion( array $response ) {
		if ( isset( $response['choices'] ) && is_array( $response['choices'] ) ) {
			return $response;
		}

		$choices = array();

		if ( isset( $response['output'] ) && is_array( $response['output'] ) ) {
			foreach ( $response['output'] as $index => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$choices[] = array(
					'index'         => $index,
					'message'       => array(
						'role'    => isset( $item['role'] ) ? sanitize_key( $item['role'] ) : 'assistant',
						'content' => $this->extract_text_from_response_content( isset( $item['content'] ) ? $item['content'] : array() ),
					),
					'finish_reason' => isset( $item['finish_reason'] ) ? $item['finish_reason'] : 'stop',
				);
			}
		}

		if ( empty( $choices ) && isset( $response['output_text'] ) ) {
			$choices[] = array(
				'index'         => 0,
				'message'       => array(
					'role'    => 'assistant',
					'content' => (string) $response['output_text'],
				),
				'finish_reason' => 'stop',
			);
		}

		if ( empty( $choices ) ) {
			return $response;
		}

		$response['choices'] = $choices;

		return $response;
	}

	/**
	 * Extract a plain text representation from a Responses API content payload.
	 *
	 * @param mixed $content Content payload from the Responses API.
	 * @return string
	 */
	protected function extract_text_from_response_content( $content ) {
		if ( is_string( $content ) ) {
			return $content;
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		$text_segments = array();

		foreach ( $content as $segment ) {
			if ( is_string( $segment ) ) {
				$text_segments[] = $segment;
				continue;
			}

			if ( ! is_array( $segment ) ) {
				continue;
			}

			$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

			if ( isset( $segment['text'] ) && in_array( $type, array( 'output_text', 'text', 'input_text' ), true ) ) {
				$text_segments[] = (string) $segment['text'];
				continue;
			}

			if ( isset( $segment['content'] ) && is_string( $segment['content'] ) ) {
				$text_segments[] = $segment['content'];
			}
		}

		$text_segments = array_filter(
			$text_segments,
			static function ( $part ) {
				return '' !== trim( $part );
			}
		);

		return implode( "\n\n", $text_segments );
	}
}
