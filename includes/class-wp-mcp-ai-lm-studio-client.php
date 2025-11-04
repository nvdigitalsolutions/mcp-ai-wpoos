<?php
/**
 * LM Studio API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a wrapper around LM Studio's OpenAI-compatible endpoints.
 * LM Studio implements the OpenAI API format, so this is essentially
 * a lightweight adapter that uses local endpoints instead of api.openai.com.
 */
class WP_MCP_AI_LM_Studio_Client {

	/**
	 * Retrieve the configured LM Studio endpoint URL.
	 *
	 * @return string
	 */
	public function get_endpoint_url() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		return isset( $settings['lm_studio_endpoint_url'] ) ? $settings['lm_studio_endpoint_url'] : '';
	}

	/**
	 * Retrieve the configured model.
	 *
	 * @return string
	 */
	public function get_model() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		return isset( $settings['lm_studio_model'] ) ? $settings['lm_studio_model'] : '';
	}

	/**
	 * Test the connection to the LM Studio instance.
	 *
	 * @return array|WP_Error
	 */
	public function test_connection() {
		$endpoint_url = $this->get_endpoint_url();

		if ( empty( $endpoint_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_lm_studio_endpoint',
				__( 'No LM Studio endpoint URL has been configured.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$url = untrailingslashit( $endpoint_url ) . '/v1/models';

		$request_args = array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/json' ),
		);

		WP_MCP_AI_Logger::log_event( 'lm_studio_test_connection', 'Testing LM Studio connection.', array( 'url' => $url ) );

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'LM Studio connection test failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The LM Studio connection test failed to complete.', 'wp-mcp-ai' ),
				__( 'LM Studio', 'wp-mcp-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			WP_MCP_AI_Logger::log_error(
				'LM Studio returned an error response.',
				array( 'code' => $code )
			);

			return new WP_Error(
				'wp_mcp_ai_api_error',
				__( 'LM Studio returned an unexpected response.', 'wp-mcp-ai' ),
				array( 'status' => $code )
			);
		}

		WP_MCP_AI_Logger::log_event( 'lm_studio_test_connection', 'LM Studio connection successful.' );

		return array(
			'success' => true,
			'message' => __( 'Successfully connected to LM Studio instance.', 'wp-mcp-ai' ),
		);
	}

	/**
	 * List available models from the LM Studio instance.
	 *
	 * @return array|WP_Error
	 */
	public function list_models() {
		$endpoint_url = $this->get_endpoint_url();

		if ( empty( $endpoint_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_lm_studio_endpoint',
				__( 'No LM Studio endpoint URL has been configured.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$url = untrailingslashit( $endpoint_url ) . '/v1/models';

		$request_args = array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/json' ),
		);

		WP_MCP_AI_Logger::log_event( 'lm_studio_list_models', 'Fetching models from LM Studio.', array( 'url' => $url ) );

		$response = wp_remote_get( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'LM Studio model listing failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The LM Studio model listing request failed to complete.', 'wp-mcp-ai' ),
				__( 'LM Studio', 'wp-mcp-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$decoded  = json_decode( $body, true );
		$json_err = json_last_error();

		if ( JSON_ERROR_NONE !== $json_err ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode LM Studio response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The LM Studio API returned malformed JSON.', 'wp-mcp-ai' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error'] ) ? $decoded['error'] : __( 'Unexpected response from LM Studio.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'LM Studio returned an error response.',
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

		// LM Studio uses OpenAI format: { "data": [ { "id": "model-name", ... }, ... ] }.
		if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
			foreach ( $decoded['data'] as $model ) {
				if ( isset( $model['id'] ) ) {
					$models[] = array(
						'id'       => $model['id'],
						'owned_by' => isset( $model['owned_by'] ) ? $model['owned_by'] : '',
						'created'  => isset( $model['created'] ) ? $model['created'] : 0,
					);
				}
			}
		}

		WP_MCP_AI_Logger::log_event( 'lm_studio_list_models', 'LM Studio models retrieved.', array( 'count' => count( $models ) ) );

		return $models;
	}

	/**
	 * Perform a chat completion request against LM Studio.
	 *
	 * @param array $messages Message payload to send to LM Studio.
	 * @param array $options  Additional options (model, temperature, tools, timeout).
	 * @return array|WP_Error
	 */
	public function create_chat_completion( array $messages, array $options = array() ) {
		$endpoint_url = $this->get_endpoint_url();

		if ( empty( $endpoint_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_lm_studio_endpoint',
				__( 'No LM Studio endpoint URL has been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_lm_studio_endpoint' => __( 'Add an LM Studio endpoint URL in the WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$model = $this->resolve_model( $options );

		if ( empty( $model ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_lm_studio_model',
				__( 'No LM Studio model has been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_lm_studio_model' => __( 'Choose an LM Studio model in the WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$payload = $this->build_payload( $messages, $options, $model );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$url = untrailingslashit( $endpoint_url ) . '/v1/chat/completions';

		$request_args = array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $this->resolve_timeout( $options ),
		);

		WP_MCP_AI_Logger::log_event( 'lm_studio_request', 'Sending request to LM Studio.', array( 'model' => $model ) );

		$response = wp_remote_post( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'LM Studio request failed.', array( 'error' => $response->get_error_message() ) );

			return WP_MCP_AI_HTTP::prepare_transport_error(
				$response,
				'wp_mcp_ai_http_error',
				__( 'The LM Studio API request failed to complete.', 'wp-mcp-ai' ),
				__( 'LM Studio', 'wp-mcp-ai' )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$decoded  = json_decode( $body, true );
		$json_err = json_last_error();

		if ( JSON_ERROR_NONE !== $json_err ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode LM Studio response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The LM Studio API returned malformed JSON.', 'wp-mcp-ai' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from LM Studio.', 'wp-mcp-ai' );

			WP_MCP_AI_Logger::log_error(
				'LM Studio returned an error response.',
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

		// LM Studio returns OpenAI-compatible format, so we can use it directly.
		$normalized = $this->normalize_response( $decoded, $model );

		WP_MCP_AI_Logger::log_event( 'lm_studio_response', 'LM Studio request completed.' );

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
	 * Build the request payload sent to LM Studio.
	 * LM Studio uses OpenAI-compatible format.
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

		// LM Studio uses OpenAI format, so we can pass messages mostly as-is.
		$formatted_messages = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : 'user';
			$content = isset( $message['content'] ) ? $message['content'] : '';

			// Convert content array to string if needed.
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

			if ( '' === trim( $content ) && 'tool' !== $role ) {
				continue;
			}

			// Convert tool messages to user messages.
			if ( 'tool' === $role ) {
				$tool_name = isset( $message['name'] ) ? sanitize_text_field( $message['name'] ) : 'tool';
				$content   = sprintf( '[Tool %s]: %s', $tool_name, $content );
				$role      = 'user';
			}

			$formatted_messages[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		$payload = array(
			'model'    => $model,
			'messages' => $formatted_messages,
		);

		// Add temperature if specified.
		if ( isset( $options['temperature'] ) && '' !== $options['temperature'] && null !== $options['temperature'] ) {
			$payload['temperature'] = (float) $options['temperature'];
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
	 * Normalize LM Studio response to match our standard format.
	 * Since LM Studio uses OpenAI format, minimal transformation is needed.
	 *
	 * @param array  $response Decoded LM Studio response.
	 * @param string $model    Model identifier.
	 * @return array
	 */
	protected function normalize_response( array $response, $model ) {
		// LM Studio already returns OpenAI-compatible format.
		// Just ensure we have the provider and model set correctly.
		if ( ! isset( $response['choices'] ) ) {
			$response['choices'] = array();
		}

		// Normalize content to array format if it's a string.
		foreach ( $response['choices'] as $index => $choice ) {
			if ( isset( $choice['message']['content'] ) && is_string( $choice['message']['content'] ) ) {
				$response['choices'][ $index ]['message']['content'] = array(
					array(
						'type' => 'text',
						'text' => $choice['message']['content'],
					),
				);
			}
		}

		$response['provider'] = 'lm_studio';

		if ( ! isset( $response['model'] ) ) {
			$response['model'] = $model;
		}

		return $response;
	}
}
