<?php
/**
 * Tool that submits Crawl4AI crawl jobs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides an integration with the Crawl4AI REST API.
 */
class WP_MCP_AI_Tool_Run_Crawl4AI_Job implements WP_MCP_AI_Tool_Interface {
	const DEFAULT_WAIT_TIMEOUT  = 120;
	const DEFAULT_POLL_INTERVAL = 3;

	/**
	 * Determine whether the Crawl4AI integration is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$base_url = isset( $settings['crawl4ai_base_url'] ) ? trim( $settings['crawl4ai_base_url'] ) : '';

		return '' !== $base_url;
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Crawl4AI tool is disabled because no API endpoint has been configured.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'run_crawl4ai_job';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Run Crawl4AI Job', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Submits a Crawl4AI crawl request and optionally waits for the results.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'urls'                => array(
					'type'        => 'array',
					'description' => __( 'List of URLs that should be crawled.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'minItems'    => 1,
				),
				'url'                 => array(
					'type'        => 'string',
					'description' => __( 'Convenience field for a single URL when `urls` is not provided.', 'wp-mcp-ai' ),
				),
				'priority'            => array(
					'type'        => 'integer',
					'description' => __( 'Optional job priority forwarded to Crawl4AI.', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'options'             => array(
					'type'        => 'object',
					'description' => __( 'Additional Crawl4AI options (for example, crawler configuration or hook overrides).', 'wp-mcp-ai' ),
					'properties'  => array(),
				),
				'wait_for_completion' => array(
					'type'        => 'boolean',
					'description' => __( 'When true, the tool polls Crawl4AI until the job finishes.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'poll_interval'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of seconds to wait between polling attempts when waiting for completion.', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'maximum'     => 30,
					'default'     => self::DEFAULT_POLL_INTERVAL,
				),
				'timeout'             => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of seconds to wait for the job to finish when polling.', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'maximum'     => 600,
					'default'     => self::DEFAULT_WAIT_TIMEOUT,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_unconfigured', __( 'Crawl4AI is not configured on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to run Crawl4AI jobs.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$urls = $this->extract_urls( $arguments );
		if ( is_wp_error( $urls ) ) {
			return $urls;
		}

		$payload = array(
			'urls' => $urls,
		);

		if ( isset( $arguments['priority'] ) ) {
			$priority            = absint( $arguments['priority'] );
			$payload['priority'] = max( 0, min( 100, $priority ) );
		}

		if ( isset( $arguments['options'] ) ) {
			if ( ! is_array( $arguments['options'] ) ) {
				return new WP_Error( 'wp_mcp_ai_crawl4ai_invalid_options', __( 'Crawl4AI options must be provided as an object.', 'wp-mcp-ai' ) );
			}

			$payload = array_merge( $payload, $this->sanitize_options( $arguments['options'] ) );
		}

		$payload = apply_filters( 'wp_mcp_ai_crawl4ai_payload', $payload, $arguments, $context );

		$encoded_payload = wp_json_encode( $payload );
		if ( false === $encoded_payload ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_encoding_error', __( 'Failed to encode the Crawl4AI request payload.', 'wp-mcp-ai' ) );
		}

		$settings  = WP_MCP_AI_Admin_Settings::get_settings();
		$base_url  = $this->get_base_url( $settings );
		$headers   = $this->build_headers( $settings, $context );
		$timeout   = $this->get_request_timeout( $settings );
		$crawl_url = trailingslashit( $base_url ) . 'crawl';

		$request_args = array(
			'headers' => $headers,
			'timeout' => $timeout,
			'body'    => $encoded_payload,
		);

		WP_MCP_AI_Logger::log_event(
			'crawl4ai_request',
			'Sending Crawl4AI crawl request.',
			array(
				'endpoint' => $crawl_url,
				'payload'  => $this->get_log_safe_payload( $payload ),
			)
		);

		$response = wp_remote_post( $crawl_url, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Crawl4AI request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_crawl4ai_http_error',
				__( 'The Crawl4AI request failed to complete.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$decoded = $this->decode_response( $response );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = $this->build_error_from_response( $decoded );

			WP_MCP_AI_Logger::log_error(
				'Crawl4AI returned an error response.',
				array(
					'status' => $status_code,
					'body'   => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_crawl4ai_api_error',
				$message,
				array(
					'status' => $status_code,
					'body'   => $decoded,
				)
			);
		}

		if ( isset( $decoded['error'] ) && ! empty( $decoded['error'] ) ) {
			$message = $this->build_error_from_response( $decoded );

			WP_MCP_AI_Logger::log_error(
				'Crawl4AI reported an error.',
				array(
					'status' => $status_code,
					'body'   => $decoded,
				)
			);

			return new WP_Error( 'wp_mcp_ai_crawl4ai_error', $message, array( 'body' => $decoded ) );
		}

		$formatted = $this->format_response( $decoded );

		if ( $this->should_wait_for_results( $arguments, $context ) && ! empty( $formatted['task_id'] ) && empty( $formatted['results'] ) ) {
			$wait_timeout  = $this->get_wait_timeout( $arguments, $context );
			$poll_interval = $this->get_poll_interval( $arguments, $context );

			$formatted = $this->poll_for_results( $formatted['task_id'], $base_url, $headers, $wait_timeout, $poll_interval, $timeout );

			if ( is_wp_error( $formatted ) ) {
				return $formatted;
			}
		}

		WP_MCP_AI_Logger::log_event(
			'crawl4ai_response',
			'Crawl4AI request completed.',
			array(
				'status'  => $formatted['status'],
				'task_id' => $formatted['task_id'],
			)
		);

		return apply_filters( 'wp_mcp_ai_crawl4ai_response', $formatted, $decoded, $arguments, $context );
	}

	/**
	 * Extract and sanitise URLs from the provided arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function extract_urls( array $arguments ) {
		$urls = array();

		if ( isset( $arguments['urls'] ) ) {
			if ( ! is_array( $arguments['urls'] ) ) {
				return new WP_Error( 'wp_mcp_ai_crawl4ai_invalid_urls', __( 'The Crawl4AI tool expects the `urls` parameter to be an array.', 'wp-mcp-ai' ) );
			}

			foreach ( $arguments['urls'] as $url ) {
				$sanitised = $this->sanitize_url( $url );
				if ( $sanitised ) {
					$urls[] = $sanitised;
				}
			}
		}

		if ( empty( $urls ) && ! empty( $arguments['url'] ) ) {
			$single = $this->sanitize_url( $arguments['url'] );
			if ( $single ) {
				$urls[] = $single;
			}
		}

		$urls = array_values( array_unique( $urls ) );

		if ( empty( $urls ) ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_missing_urls', __( 'At least one URL must be provided to Crawl4AI.', 'wp-mcp-ai' ) );
		}

		return $urls;
	}

	/**
	 * Sanitise a URL string.
	 *
	 * @param mixed $value Potential URL value.
	 * @return string
	 */
	protected function sanitize_url( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		$sanitised = esc_url_raw( $value );

		return $sanitised ? $sanitised : '';
	}

	/**
	 * Sanitise arbitrary Crawl4AI options provided by the caller.
	 *
	 * @param array $options Options array supplied by the assistant.
	 * @return array
	 */
	protected function sanitize_options( array $options ) {
		$sanitised = array();

		foreach ( $options as $key => $value ) {
			$clean_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;

			if ( '' === $clean_key && ! is_int( $key ) ) {
				continue;
			}

			$sanitised[ $clean_key ] = $this->sanitize_option_value( $value );
		}

		return $sanitised;
	}

	/**
	 * Sanitise a single option value.
	 *
	 * @param mixed $value Value to sanitise.
	 * @return mixed
	 */
	protected function sanitize_option_value( $value ) {
		if ( is_array( $value ) ) {
			$sanitised = array();

			foreach ( $value as $key => $nested_value ) {
				$clean_key = is_string( $key ) ? sanitize_text_field( $key ) : $key;

				if ( '' === $clean_key && ! is_int( $key ) ) {
					continue;
				}

				$sanitised[ $clean_key ] = $this->sanitize_option_value( $nested_value );
			}

			return $sanitised;
		}

		if ( is_string( $value ) ) {
			return sanitize_textarea_field( $value );
		}

		if ( is_bool( $value ) ) {
			return (bool) $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 + $value;
		}

		if ( null === $value ) {
			return null;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Retrieve the configured Crawl4AI base URL.
	 *
	 * @param array $settings Plugin settings array.
	 * @return string
	 */
	protected function get_base_url( array $settings ) {
		if ( empty( $settings['crawl4ai_base_url'] ) ) {
			return '';
		}

		return untrailingslashit( $settings['crawl4ai_base_url'] );
	}

	/**
	 * Build the HTTP headers for Crawl4AI requests.
	 *
	 * @param array $settings Plugin settings array.
	 * @param array $context  Execution context.
	 * @return array
	 */
	protected function build_headers( array $settings, array $context ) {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);

		if ( ! empty( $settings['crawl4ai_api_key'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $settings['crawl4ai_api_key'];
		}

		/**
		 * Allow plugins to filter the headers sent to Crawl4AI.
		 */
		return apply_filters( 'wp_mcp_ai_crawl4ai_headers', $headers, $settings, $context );
	}

	/**
	 * Determine the HTTP timeout for Crawl4AI requests.
	 *
	 * @param array $settings Plugin settings array.
	 * @return int
	 */
	protected function get_request_timeout( array $settings ) {
		$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;

		return max( 5, $timeout );
	}

	/**
	 * Determine whether the tool should wait for job completion.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return bool
	 */
	protected function should_wait_for_results( array $arguments, array $context ) {
		if ( isset( $arguments['wait_for_completion'] ) ) {
			return (bool) $arguments['wait_for_completion'];
		}

		if ( isset( $context['assistant_config']['crawl4ai_wait_for_completion'] ) ) {
			return (bool) $context['assistant_config']['crawl4ai_wait_for_completion'];
		}

		return false;
	}

	/**
	 * Retrieve the polling timeout in seconds.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return int
	 */
	protected function get_wait_timeout( array $arguments, array $context ) {
		if ( isset( $arguments['timeout'] ) ) {
			return max( 0, min( 600, absint( $arguments['timeout'] ) ) );
		}

		if ( isset( $context['assistant_config']['crawl4ai_timeout'] ) ) {
			return max( 0, min( 600, absint( $context['assistant_config']['crawl4ai_timeout'] ) ) );
		}

		return self::DEFAULT_WAIT_TIMEOUT;
	}

	/**
	 * Retrieve the polling interval in seconds.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return int
	 */
	protected function get_poll_interval( array $arguments, array $context ) {
		if ( isset( $arguments['poll_interval'] ) ) {
			return max( 0, min( 30, absint( $arguments['poll_interval'] ) ) );
		}

		if ( isset( $context['assistant_config']['crawl4ai_poll_interval'] ) ) {
			return max( 0, min( 30, absint( $context['assistant_config']['crawl4ai_poll_interval'] ) ) );
		}

		return self::DEFAULT_POLL_INTERVAL;
	}

	/**
	 * Decode the Crawl4AI HTTP response body.
	 *
	 * @param array $response Response array from wp_remote_*.
	 * @return array|WP_Error
	 */
	protected function decode_response( $response ) {
		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_empty_response', __( 'Crawl4AI returned an empty response.', 'wp-mcp-ai' ) );
		}

		$decoded = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			WP_MCP_AI_Logger::log_error( 'Failed to decode Crawl4AI response.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_crawl4ai_invalid_response', __( 'Crawl4AI returned malformed JSON.', 'wp-mcp-ai' ) );
		}

		return $decoded;
	}

	/**
	 * Create a human readable error message from a Crawl4AI response.
	 *
	 * @param array $decoded Decoded response body.
	 * @return string
	 */
	protected function build_error_from_response( array $decoded ) {
		if ( isset( $decoded['error'] ) ) {
			if ( is_string( $decoded['error'] ) ) {
				return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['error'] );
			}

			if ( is_array( $decoded['error'] ) ) {
				if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
					return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['error']['message'] );
				}

				if ( isset( $decoded['error']['detail'] ) && is_string( $decoded['error']['detail'] ) ) {
					return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['error']['detail'] );
				}
			}
		}

		if ( isset( $decoded['detail'] ) && is_string( $decoded['detail'] ) ) {
			return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['detail'] );
		}

		if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['message'] );
		}

		return __( 'Crawl4AI returned an unexpected response.', 'wp-mcp-ai' );
	}

	/**
	 * Normalise a Crawl4AI response into a consistent structure for the assistant.
	 *
	 * @param array $decoded Decoded response body.
	 * @return array
	 */
	protected function format_response( array $decoded ) {
		$status = '';

		if ( isset( $decoded['status'] ) && is_string( $decoded['status'] ) ) {
			$status = sanitize_key( $decoded['status'] );
		} elseif ( isset( $decoded['state'] ) && is_string( $decoded['state'] ) ) {
			$status = sanitize_key( $decoded['state'] );
		} elseif ( ! empty( $decoded['results'] ) ) {
			$status = 'completed';
		} elseif ( isset( $decoded['task_id'] ) ) {
			$status = 'pending';
		}

		$task_id = '';
		if ( isset( $decoded['task_id'] ) && is_scalar( $decoded['task_id'] ) ) {
			$task_id = sanitize_text_field( (string) $decoded['task_id'] );
		}

		$results = array();
		if ( isset( $decoded['results'] ) && is_array( $decoded['results'] ) ) {
			$results = $decoded['results'];
		}

		$metadata = array();
		if ( isset( $decoded['metadata'] ) && is_array( $decoded['metadata'] ) ) {
			$metadata = $decoded['metadata'];
		}

		return array(
			'status'   => $status,
			'task_id'  => $task_id,
			'results'  => $results,
			'metadata' => $metadata,
			'raw'      => $decoded,
		);
	}

	/**
	 * Poll Crawl4AI for job completion.
	 *
	 * @param string $task_id       Task identifier returned by Crawl4AI.
	 * @param string $base_url      Crawl4AI base URL.
	 * @param array  $headers       Request headers.
	 * @param int    $timeout       Maximum seconds to wait.
	 * @param int    $poll_interval Seconds between polls.
	 * @param int    $request_timeout HTTP timeout for individual poll requests.
	 * @return array|WP_Error
	 */
	protected function poll_for_results( $task_id, $base_url, array $headers, $timeout, $poll_interval, $request_timeout ) {
		$endpoint = trailingslashit( $base_url ) . 'task/' . rawurlencode( $task_id );
		$deadline = time() + max( 0, (int) $timeout );

		do {
			WP_MCP_AI_Logger::log_event(
				'crawl4ai_poll_request',
				'Polling Crawl4AI for task status.',
				array(
					'task_id' => $task_id,
				)
			);

			$response = wp_remote_get(
				$endpoint,
				array(
					'headers' => $headers,
					'timeout' => max( 5, $request_timeout ),
				)
			);

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'Crawl4AI polling request failed.', array( 'error' => $response->get_error_message() ) );

				return new WP_Error(
					'wp_mcp_ai_crawl4ai_poll_error',
					__( 'The Crawl4AI status check failed.', 'wp-mcp-ai' ),
					array( 'error' => $response )
				);
			}

			$decoded = $this->decode_response( $response );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			if ( $status_code < 200 || $status_code >= 300 ) {
				$message = $this->build_error_from_response( $decoded );

				return new WP_Error(
					'wp_mcp_ai_crawl4ai_poll_http_error',
					$message,
					array(
						'status' => $status_code,
						'body'   => $decoded,
					)
				);
			}

			if ( isset( $decoded['status'] ) && is_string( $decoded['status'] ) ) {
				$status = strtolower( $decoded['status'] );
				if ( in_array( $status, array( 'failed', 'error' ), true ) ) {
					$message = $this->build_error_from_response( $decoded );

					return new WP_Error( 'wp_mcp_ai_crawl4ai_failed', $message, array( 'body' => $decoded ) );
				}
			}

			if ( isset( $decoded['error'] ) && ! empty( $decoded['error'] ) ) {
				$message = $this->build_error_from_response( $decoded );

				return new WP_Error( 'wp_mcp_ai_crawl4ai_failed', $message, array( 'body' => $decoded ) );
			}

			$formatted            = $this->format_response( $decoded );
			$formatted['task_id'] = $task_id;

			if ( ! empty( $formatted['results'] ) ) {
				return $formatted;
			}

			if ( time() >= $deadline ) {
				break;
			}

			if ( $poll_interval > 0 ) {
				$this->sleep( $poll_interval );
			}
		} while ( time() <= $deadline );

		return new WP_Error( 'wp_mcp_ai_crawl4ai_timeout', __( 'Timed out while waiting for Crawl4AI to finish the job.', 'wp-mcp-ai' ) );
	}

	/**
	 * Sleep for a number of seconds.
	 *
	 * @param int $seconds Seconds to sleep.
	 */
	protected function sleep( $seconds ) {
		if ( function_exists( 'wp_sleep' ) ) {
			wp_sleep( $seconds );
		} else {
			sleep( $seconds );
		}
	}

	/**
	 * Reduce payload noise before logging.
	 *
	 * @param array $payload Payload that will be logged.
	 * @return array
	 */
	protected function get_log_safe_payload( array $payload ) {
		$log_payload = $payload;

		if ( isset( $log_payload['urls'] ) && is_array( $log_payload['urls'] ) ) {
			$log_payload['urls'] = array_slice( $log_payload['urls'], 0, 3 );
			if ( count( $payload['urls'] ) > 3 ) {
				$log_payload['urls'][] = '…';
			}
		}

		return $log_payload;
	}
}
