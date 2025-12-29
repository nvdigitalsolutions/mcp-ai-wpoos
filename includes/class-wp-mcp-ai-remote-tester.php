<?php
/**
 * Remote MCP API connectivity tester.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide utilities for probing remote MCP REST namespaces.
 */
class WP_MCP_AI_Remote_Tester {

	/**
	 * Default HTTP timeout in seconds.
	 */
	const DEFAULT_TIMEOUT = 15;

	/**
	 * Probe the remote MCP REST namespace using the provided credentials.
	 *
	 * @param string $base_url Base URL to the remote MCP REST namespace.
	 * @param array  $args     Optional arguments controlling the request.
	 * @return array|WP_Error  Structured result array on success, WP_Error on validation failure.
	 */
	public function probe( $base_url, array $args = array() ) {
		$normalized = $this->normalise_base_url( $base_url );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$timeout = isset( $args['timeout'] ) ? absint( $args['timeout'] ) : self::DEFAULT_TIMEOUT;
		if ( $timeout <= 0 ) {
			$timeout = self::DEFAULT_TIMEOUT;
		}

		$verify_ssl = array_key_exists( 'verify_ssl', $args ) ? (bool) $args['verify_ssl'] : true;
		$user_agent = $this->determine_user_agent( $args );
		$headers    = $this->build_headers( $args );

		$assistants_url = trailingslashit( $normalized ) . 'assistants';
		$chat_url       = trailingslashit( $normalized ) . 'chat';

		$query_args = array();
		if ( isset( $args['assistant_id'] ) && '' !== $args['assistant_id'] ) {
			$query_args['assistant_id'] = absint( $args['assistant_id'] );
		}

		if ( isset( $args['query_args'] ) && is_array( $args['query_args'] ) ) {
			$query_args = array_merge( $query_args, $args['query_args'] );
		}

		if ( ! empty( $query_args ) ) {
			$assistants_url = add_query_arg( $query_args, $assistants_url );
		}

		$request_args = array(
			'timeout'     => $timeout,
			'headers'     => $headers,
			'sslverify'   => $verify_ssl,
			'redirection' => isset( $args['redirection'] ) ? (int) $args['redirection'] : 5,
			'user-agent'  => $user_agent,
		);

		$checks  = array();
		$success = true;

		$response = wp_remote_get( $assistants_url, $request_args );

		if ( is_wp_error( $response ) ) {
			$success  = false;
			$checks[] = array(
				'step'      => __( 'GET /assistants', 'wp-mcp-ai' ),
				'url'       => $assistants_url,
				'status'    => 'error',
				'http_code' => null,
				/* translators: %s: error message from the failed request */
				'message'   => sprintf( __( 'Request failed: %s', 'wp-mcp-ai' ), $response->get_error_message() ),
				'details'   => array(
					'error_code' => $response->get_error_code(),
				),
			);

			return array(
				'success'  => false,
				'base_url' => $normalized,
				'checks'   => $checks,
				'error'    => array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				),
			);
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$http_message = wp_remote_retrieve_response_message( $response );
		$body         = wp_remote_retrieve_body( $response );
		$decoded      = $this->decode_json_body( $body );

		$details = array();
		if ( is_array( $decoded ) && isset( $decoded['assistants'] ) && is_array( $decoded['assistants'] ) ) {
			$details['assistant_count'] = count( $decoded['assistants'] );
		}

		if ( is_array( $decoded ) && isset( $decoded['token_scope'] ) && is_array( $decoded['token_scope'] ) ) {
			$details['token_scope'] = $decoded['token_scope'];
		}

		if ( is_array( $decoded ) && isset( $decoded['code'] ) ) {
			$details['rest_error_code'] = $decoded['code'];
		}

		if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
			$details['rest_error_message'] = $decoded['message'];
		}

		if ( is_array( $decoded ) && isset( $decoded['data']['status'] ) ) {
			$details['rest_error_status'] = (int) $decoded['data']['status'];
		}

		if ( $code >= 200 && $code < 300 ) {
			$status  = 'success';
			$message = $this->build_success_message( $code, $decoded );
		} else {
			$status  = 'error';
			$success = false;
			$message = $this->build_error_message( $code, $http_message, $decoded, $body );
		}

		$checks[]  = array(
			'step'      => __( 'GET /assistants', 'wp-mcp-ai' ),
			'url'       => $assistants_url,
			'status'    => $status,
			'http_code' => $code,
			'message'   => $message,
			'details'   => $details,
		);
		$responses = array(
			'directory' => array(
				'code'    => $code,
				'message' => $http_message,
				'body'    => $decoded,
				'raw'     => $body,
			),
		);

		$assistant_id = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;

		if ( ! $assistant_id && is_array( $decoded ) && isset( $decoded['assistants'] ) && is_array( $decoded['assistants'] ) ) {
			foreach ( $decoded['assistants'] as $assistant_summary ) {
				if ( is_array( $assistant_summary ) && isset( $assistant_summary['id'] ) ) {
					$assistant_id = absint( $assistant_summary['id'] );

					if ( $assistant_id ) {
						break;
					}
				}
			}
		}

		if ( ! $assistant_id ) {
			$success = false;

			$message = __( 'Unable to determine an assistant ID for the chat probe.', 'wp-mcp-ai' );

			$checks[] = array(
				'step'      => __( 'POST /chat', 'wp-mcp-ai' ),
				'url'       => $chat_url,
				'status'    => 'error',
				'http_code' => null,
				'message'   => $message,
				'details'   => array(),
			);

			return array(
				'success'   => false,
				'base_url'  => $normalized,
				'checks'    => $checks,
				'responses' => $responses,
				'response'  => $responses['directory'],
				'error'     => array(
					'code'    => 'wp_mcp_ai_remote_missing_assistant',
					'message' => $message,
				),
			);
		}

		$chat_headers                 = $headers;
		$chat_headers['Content-Type'] = 'application/json';

		$chat_payload = array(
			'assistant_id' => $assistant_id,
			'messages'     => array(
				array(
					'role'    => 'user',
					'content' => __( 'Connectivity probe from NV oOS Remote Tester.', 'wp-mcp-ai' ),
				),
			),
			'options'      => array(
				'probe' => true,
			),
		);

		$chat_args = array(
			'timeout'     => $timeout,
			'headers'     => $chat_headers,
			'sslverify'   => $verify_ssl,
			'redirection' => isset( $args['redirection'] ) ? (int) $args['redirection'] : 5,
			'user-agent'  => $user_agent,
			'body'        => wp_json_encode( $chat_payload ),
		);

		$chat_response = wp_remote_post( $chat_url, $chat_args );

		$error = null;

		if ( is_wp_error( $chat_response ) ) {
			$success = false;

			$checks[] = array(
				'step'      => __( 'POST /chat', 'wp-mcp-ai' ),
				'url'       => $chat_url,
				'status'    => 'error',
				'http_code' => null,
				/* translators: %s: error message from the failed request */
				'message'   => sprintf( __( 'Request failed: %s', 'wp-mcp-ai' ), $chat_response->get_error_message() ),
				'details'   => array(
					'error_code' => $chat_response->get_error_code(),
				),
			);

			return array(
				'success'   => false,
				'base_url'  => $normalized,
				'checks'    => $checks,
				'responses' => $responses,
				'response'  => $responses['directory'],
				'error'     => array(
					'code'    => $chat_response->get_error_code(),
					'message' => $chat_response->get_error_message(),
				),
			);
		}

		$chat_code         = (int) wp_remote_retrieve_response_code( $chat_response );
		$chat_http_message = wp_remote_retrieve_response_message( $chat_response );
		$chat_body         = wp_remote_retrieve_body( $chat_response );
		$chat_decoded      = $this->decode_json_body( $chat_body );

		$chat_details = array(
			'assistant_id' => $assistant_id,
		);

		if ( is_array( $chat_decoded ) ) {
			if ( isset( $chat_decoded['probe']['status'] ) ) {
				$chat_details['probe_status'] = (string) $chat_decoded['probe']['status'];
			}

			if ( isset( $chat_decoded['probe']['checked_at'] ) ) {
				$chat_details['probe_checked_at'] = (string) $chat_decoded['probe']['checked_at'];
			}

			if ( isset( $chat_decoded['code'] ) ) {
				$chat_details['rest_error_code'] = $chat_decoded['code'];
			}

			if ( isset( $chat_decoded['message'] ) ) {
				$chat_details['rest_error_message'] = $chat_decoded['message'];
			}

			if ( isset( $chat_decoded['data']['status'] ) ) {
				$chat_details['rest_error_status'] = (int) $chat_decoded['data']['status'];
			}
		}

		if ( $chat_code >= 200 && $chat_code < 300 ) {
			$chat_status = 'success';

			$message_parts = array( __( 'Chat endpoint reachable.', 'wp-mcp-ai' ) );

			if ( isset( $chat_details['probe_status'] ) ) {
				/* translators: %s: probe status */
				$message_parts[] = sprintf( __( 'Status: %s.', 'wp-mcp-ai' ), $chat_details['probe_status'] );
			}

			if ( isset( $chat_details['probe_checked_at'] ) ) {
				/* translators: %s: timestamp when the check was performed */
				$message_parts[] = sprintf( __( 'Checked at %s.', 'wp-mcp-ai' ), $chat_details['probe_checked_at'] );
			}

			$chat_message = implode( ' ', $message_parts );
		} else {
			$chat_status  = 'error';
			$success      = false;
			$chat_message = $this->build_error_message( $chat_code, $chat_http_message, $chat_decoded, $chat_body );

			$error = array(
				'code'    => isset( $chat_details['rest_error_code'] ) ? $chat_details['rest_error_code'] : 'wp_mcp_ai_remote_chat_failed',
				'message' => $chat_message,
			);
		}

		$checks[] = array(
			'step'      => __( 'POST /chat', 'wp-mcp-ai' ),
			'url'       => $chat_url,
			'status'    => $chat_status,
			'http_code' => $chat_code,
			'message'   => $chat_message,
			'details'   => $chat_details,
		);

		$responses['chat'] = array(
			'code'    => $chat_code,
			'message' => $chat_http_message,
			'body'    => $chat_decoded,
			'raw'     => $chat_body,
		);

		$result = array(
			'success'   => $success,
			'base_url'  => $normalized,
			'checks'    => $checks,
			'responses' => $responses,
			'response'  => $responses['directory'],
		);

		if ( null !== $error ) {
			$result['error'] = $error;
		}

		return $result;
	}

	/**
	 * Normalise and validate the provided base URL.
	 *
	 * @param string $base_url Raw base URL.
	 * @return string|WP_Error Sanitised base URL or WP_Error when invalid.
	 */
	protected function normalise_base_url( $base_url ) {
		if ( ! is_string( $base_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_invalid_base_url',
				__( 'Please provide a valid MCP REST base URL.', 'wp-mcp-ai' )
			);
		}

		$base_url = trim( $base_url );

		if ( '' === $base_url ) {
			return new WP_Error(
				'wp_mcp_ai_remote_invalid_base_url',
				__( 'Please provide a valid MCP REST base URL.', 'wp-mcp-ai' )
			);
		}

		if ( ! preg_match( '#^https?://#i', $base_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_invalid_base_url',
				__( 'The base URL must include the http or https scheme.', 'wp-mcp-ai' )
			);
		}

		$parsed = wp_parse_url( $base_url );

		if ( false === $parsed || empty( $parsed['host'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_invalid_base_url',
				__( 'The provided base URL is not a valid HTTP or HTTPS URL.', 'wp-mcp-ai' )
			);
		}

		$scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_remote_invalid_base_url',
				__( 'The base URL must include the http or https scheme.', 'wp-mcp-ai' )
			);
		}

		$sanitised = esc_url_raw( $base_url );

		if ( '' === $sanitised ) {
			return new WP_Error(
				'wp_mcp_ai_remote_invalid_base_url',
				__( 'The provided base URL is not a valid HTTP or HTTPS URL.', 'wp-mcp-ai' )
			);
		}

		return untrailingslashit( $sanitised );
	}

	/**
	 * Build the HTTP headers for the remote request.
	 *
	 * @param array $args Probe arguments.
	 * @return array
	 */
	protected function build_headers( array $args ) {
		$headers = array(
			'Accept' => 'application/json',
		);

		if ( isset( $args['token'] ) && is_string( $args['token'] ) && '' !== trim( $args['token'] ) ) {
			$headers['Authorization'] = 'Bearer ' . trim( $args['token'] );
		}

		if ( isset( $args['guest_token'] ) && is_string( $args['guest_token'] ) && '' !== trim( $args['guest_token'] ) ) {
			$headers['X-WP-MCP-AI-Guest'] = trim( $args['guest_token'] );
		}

		if ( isset( $args['nonce'] ) && is_string( $args['nonce'] ) && '' !== trim( $args['nonce'] ) ) {
			$headers['X-WP-Nonce'] = trim( $args['nonce'] );
		}

		if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
			$headers = array_merge( $headers, $args['headers'] );
		}

		return $headers;
	}

	/**
	 * Determine the user agent string to send with the request.
	 *
	 * @param array $args Probe arguments.
	 * @return string
	 */
	protected function determine_user_agent( array $args ) {
		if ( isset( $args['user_agent'] ) && is_string( $args['user_agent'] ) ) {
			$user_agent = trim( $args['user_agent'] );
			if ( '' !== $user_agent ) {
				return $user_agent;
			}
		}

		$version = defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev';

		return 'WP-MCP-AI-Remote-Tester/' . $version;
	}

	/**
	 * Decode a JSON response body.
	 *
	 * @param string $body Raw response body.
	 * @return array|null
	 */
	protected function decode_json_body( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return null;
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Build a success message summarising the response.
	 *
	 * @param int   $code    HTTP status code.
	 * @param array $decoded Decoded JSON body.
	 * @return string
	 */
	protected function build_success_message( $code, $decoded ) {
		$parts = array();

		if ( is_array( $decoded ) && isset( $decoded['assistants'] ) && is_array( $decoded['assistants'] ) ) {
			$count   = count( $decoded['assistants'] );
			$parts[] = sprintf(
				/* translators: %d: number of assistants received */
				_n( 'Received %d assistant.', 'Received %d assistants.', $count, 'wp-mcp-ai' ),
				$count
			);
		}

		if ( is_array( $decoded ) && isset( $decoded['token_scope']['type'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: OAuth token scope type */
				__( 'Token scope: %s.', 'wp-mcp-ai' ),
				$decoded['token_scope']['type']
			);
		}

		if ( empty( $parts ) ) {
			/* translators: %d: HTTP status code */
			$parts[] = sprintf( __( 'Received HTTP %d.', 'wp-mcp-ai' ), $code );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Build an error message summarising the failure.
	 *
	 * @param int         $code         HTTP status code.
	 * @param string|null $http_message Response message string.
	 * @param array|null  $decoded      Decoded JSON body.
	 * @param string      $raw_body     Raw response body.
	 * @return string
	 */
	protected function build_error_message( $code, $http_message, $decoded, $raw_body ) {
		$parts = array();

		if ( $code > 0 ) {
			if ( $http_message ) {
				/* translators: 1: HTTP status code, 2: HTTP status message */
				$parts[] = sprintf( __( 'HTTP %1$d %2$s.', 'wp-mcp-ai' ), $code, $http_message );
			} else {
				/* translators: %d: HTTP status code */
				$parts[] = sprintf( __( 'HTTP %d.', 'wp-mcp-ai' ), $code );
			}
		}

		if ( is_array( $decoded ) && isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			$parts[] = $decoded['message'];
		} elseif ( is_array( $decoded ) && isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			$parts[] = $decoded['error'];
		}

		if ( empty( $parts ) ) {
			if ( is_string( $raw_body ) && '' !== $raw_body ) {
				$excerpt = function_exists( 'mb_substr' ) ? mb_substr( $raw_body, 0, 200 ) : substr( $raw_body, 0, 200 );
				$parts[] = $excerpt;
			} else {
				$parts[] = __( 'Unexpected response body.', 'wp-mcp-ai' );
			}
		}

		return implode( ' ', $parts );
	}
}
