<?php
/**
 * Ollama API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a wrapper around Ollama's chat completion and model listing endpoints.
 */
class WP_MCP_AI_Ollama_Client {

	/**
	 * Retrieve the configured Ollama endpoint URL.
	 *
	 * @return string
	 */
	public function get_endpoint_url() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$url = isset( $settings['ollama_endpoint_url'] ) ? $settings['ollama_endpoint_url'] : '';

		if ( empty( $url ) ) {
			$url = 'http://localhost:11434';
		}

		return untrailingslashit( $url );
	}

	/**
	 * Retrieve the configured Ollama model.
	 *
	 * @return string
	 */
	public function get_model() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		return isset( $settings['ollama_model'] ) ? $settings['ollama_model'] : '';
	}

	/**
	 * Test the connection to the Ollama instance.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		$endpoint_url = $this->get_endpoint_url();

		if ( empty( $endpoint_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_ollama_endpoint',
				__( 'No Ollama endpoint URL has been configured.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$url = $endpoint_url . '/api/tags';

		$request_args = array(
			'timeout' => 10,
		);

		WP_MCP_AI_Logger::log_event( 'ollama_test_connection', 'Testing Ollama connection.', array( 'url' => $url ) );

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Ollama connection test failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Ollama connection test failed to complete.', 'wp-mcp-ai' ),
				__( 'Ollama', 'wp-mcp-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			WP_MCP_AI_Logger::log_error(
				'Ollama returned an error response.',
				array( 'code' => $code )
			);

			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'Ollama returned an unexpected response.', 'wp-mcp-ai' ),
				array( 'status' => $code )
			);
		}

		WP_MCP_AI_Logger::log_event( 'ollama_test_connection', 'Ollama connection successful.' );

		return array(
			'success' => true,
			'message' => __( 'Successfully connected to Ollama instance.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * List available models from the Ollama instance.
	 *
	 * @return array|WP_Error
	 */
	public function list_models() {
		$endpoint_url = $this->get_endpoint_url();

		if ( empty( $endpoint_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_ollama_endpoint',
				__( 'No Ollama endpoint URL has been configured.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$url = $endpoint_url . '/api/tags';

		$request_args = array(
			'timeout' => 10,
		);

		WP_MCP_AI_Logger::log_event( 'ollama_list_models', 'Fetching models from Ollama.', array( 'url' => $url ) );

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Ollama model listing failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Ollama model listing request failed to complete.', 'wp-mcp-ai' ),
				__( 'Ollama', 'wp-mcp-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$decoded  = json_decode( $body, true );
		$json_err = json_last_error();

		if ( JSON_ERROR_NONE !== $json_err ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode Ollama response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Ollama API returned malformed JSON.', 'wp-mcp-ai' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from Ollama.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'Ollama returned an error response.',
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

		$models = array();

		if ( isset( $decoded['models'] ) && is_array( $decoded['models'] ) ) {
			foreach ( $decoded['models'] as $model ) {
				if ( isset( $model['name'] ) ) {
					$models[] = array(
						'name'       => $model['name'],
						'size'       => isset( $model['size'] ) ? $model['size'] : 0,
						'modified'   => isset( $model['modified_at'] ) ? $model['modified_at'] : '',
						'digest'     => isset( $model['digest'] ) ? $model['digest'] : '',
						'family'     => isset( $model['details']['family'] ) ? $model['details']['family'] : '',
						'format'     => isset( $model['details']['format'] ) ? $model['details']['format'] : '',
						'parameters' => isset( $model['details']['parameter_size'] ) ? $model['details']['parameter_size'] : '',
					);
				}
			}
		}

		WP_MCP_AI_Logger::log_event( 'ollama_list_models', 'Ollama models retrieved.', array( 'count' => count( $models ) ) );

		return $models;
	}

	/**
	 * Perform a chat completion request against Ollama.
	 *
	 * @param array $messages Message payload to send to Ollama.
	 * @param array $options  Additional options (model, temperature, tools, timeout).
	 * @return array|WP_Error
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$endpoint_url = $this->get_endpoint_url();

		if ( empty( $endpoint_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_ollama_endpoint',
				__( 'No Ollama endpoint URL has been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_ollama_endpoint' => __( 'Add an Ollama endpoint URL in the WP MCP AI settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$model = $this->resolve_model( $options );

		if ( empty( $model ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_ollama_model',
				__( 'No Ollama model has been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_ollama_model' => __( 'Choose an Ollama model in the WP MCP AI settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$payload = $this->build_payload( $messages, $options, $model );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$url = $endpoint_url . '/api/chat';

		$request_args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $this->resolve_timeout( $options ),
		);

		WP_MCP_AI_Logger::log_event( 'ollama_request', 'Sending request to Ollama.', array( 'model' => $model ) );

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Ollama request failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The Ollama API request failed to complete.', 'wp-mcp-ai' ),
				__( 'Ollama', 'wp-mcp-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$decoded  = json_decode( $body, true );
		$json_err = json_last_error();

		if ( JSON_ERROR_NONE !== $json_err ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode Ollama response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The Ollama API returned malformed JSON.', 'wp-mcp-ai' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from Ollama.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'Ollama returned an error response.',
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

		$normalized = $this->normalize_response( $decoded, $model );

		WP_MCP_AI_Logger::log_event( 'ollama_response', 'Ollama request completed.' );

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

		$model = $this->get_model();

		if ( ! empty( $model ) ) {
			return $model;
		}

		return '';
	}

	/**
	 * Build the request payload sent to Ollama.
	 *
	 * @param array  $messages Chat messages.
	 * @param array  $options  Request options.
	 * @param string $model    Model identifier.
	 * @return array|WP_Error
	 */
	protected function build_payload( array $messages, array $options, $model ) {
		if ( empty( $messages ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_messages',
				__( 'No chat messages were provided for the request.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'review_request_payload' => __( 'Provide at least one user or system message before calling the API.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$ollama_messages = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
			$content = isset( $message['content'] ) ? $message['content'] : '';

			// Convert content to string if it's an array of segments.
			if ( is_array( $content ) ) {
				$text_parts = array();
				foreach ( $content as $segment ) {
					if ( is_string( $segment ) ) {
						$text_parts[] = $segment;
					} elseif ( is_array( $segment ) && isset( $segment['text'] ) ) {
						$text_parts[] = $segment['text'];
					}
				}
				$content = implode( "\n", $text_parts );
			}

			$content = wp_kses_post( (string) $content );

			// Skip empty messages.
			if ( '' === trim( $content ) && 'tool' !== $role ) {
				continue;
			}

			// Ollama uses 'user', 'assistant', and 'system' roles.
			if ( 'tool' === $role ) {
				// Convert tool messages to user messages with a prefix.
				$tool_name = isset( $message['name'] ) ? sanitize_text_field( $message['name'] ) : 'tool';
				$content   = sprintf( '[Tool %s]: %s', $tool_name, $content );
				$role      = 'user';
			}

			$ollama_messages[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		$payload = array(
			'model'    => $model,
			'messages' => $ollama_messages,
			'stream'   => false,
		);

		// Add options like temperature.
		$ollama_options = array();

		if ( isset( $options['temperature'] ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
			$ollama_options['temperature'] = (float) $options['temperature'];
		}

		if ( ! empty( $ollama_options ) ) {
			$payload['options'] = $ollama_options;
		}

		// Add system prompt if provided.
		if ( ! empty( $options['system_prompt'] ) ) {
			$payload['system'] = wp_kses_post( $options['system_prompt'] );
		}

		return $payload;
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
	 * Convert an Ollama response to an OpenAI-style structure for downstream compatibility.
	 *
	 * @param array  $response Decoded Ollama response.
	 * @param string $model    Model identifier.
	 * @return array
	 */
	protected function normalize_response( array $response, $model ) {
		$message = array( 'role' => 'assistant' );

		$content = '';
		if ( isset( $response['message']['content'] ) ) {
			$content = (string) $response['message']['content'];
		}

		if ( '' !== $content ) {
			$message['content'] = array(
				array(
					'type' => 'text',
					'text' => $content,
				),
			);
		}

		$choice = array(
			'index'         => 0,
			'message'       => $message,
			'finish_reason' => isset( $response['done'] ) && $response['done'] ? 'stop' : 'length',
		);

		$normalized = array(
			'choices'  => array( $choice ),
			'provider' => 'ollama',
			'model'    => $model,
		);

		// Add usage information if available.
		if ( isset( $response['prompt_eval_count'] ) || isset( $response['eval_count'] ) ) {
			$usage = array(
				'prompt_tokens'     => isset( $response['prompt_eval_count'] ) ? (int) $response['prompt_eval_count'] : 0,
				'completion_tokens' => isset( $response['eval_count'] ) ? (int) $response['eval_count'] : 0,
			);
			$usage['total_tokens'] = $usage['prompt_tokens'] + $usage['completion_tokens'];
			$normalized['usage']   = $usage;
		}

		return $normalized;
	}
}
