<?php
/**
 * Tool that purges the Cloudflare cache for the configured zone.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-admin-settings.php';
}

/**
 * Provides a tool for purging Cloudflare cache entries.
 */
class WP_MCP_AI_Tool_Purge_Cloudflare_Cache implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const DEFAULT_TIMEOUT = 30;

	const MAX_TIMEOUT = 120;

	const MIN_TIMEOUT = 5;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'purge_cloudflare_cache';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Purge Cloudflare Cache', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Requests a cache purge for the configured Cloudflare zone.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'purge_everything' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to purge the entire Cloudflare cache for the zone.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'urls'             => array(
					'type'        => 'array',
					'description' => __( 'Specific asset URLs to purge. Provide absolute URLs that map to the configured zone.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'uri',
					),
				),
				'hosts'            => array(
					'type'        => 'array',
					'description' => __( 'Hostnames to purge from cache.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'tags'             => array(
					'type'        => 'array',
					'description' => __( 'Cache tags to purge.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'timeout'          => array(
					'type'        => 'integer',
					'description' => __( 'Optional timeout in seconds for the Cloudflare API request.', 'wp-mcp-ai' ),
					'minimum'     => self::MIN_TIMEOUT,
					'maximum'     => self::MAX_TIMEOUT,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute a cache purge against the Cloudflare API.
	 *
	 * @param array $arguments Parsed tool arguments.
	 * @param array $context   Request context including acting user details.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to purge the Cloudflare cache.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_settings', __( 'The admin settings component is not available.', 'wp-mcp-ai' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$api_token = isset( $settings['cloudflare_api_token'] ) ? trim( (string) $settings['cloudflare_api_token'] ) : '';
		$zone_id   = isset( $settings['cloudflare_zone_id'] ) ? trim( (string) $settings['cloudflare_zone_id'] ) : '';

		if ( '' === $api_token ) {
			return new WP_Error(
				'wp_mcp_ai_missing_cloudflare_token',
				__( 'No Cloudflare API token has been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_cloudflare_api_token' => __( 'Add a Cloudflare API token in the WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		if ( '' === $zone_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_cloudflare_zone',
				__( 'No Cloudflare zone ID has been configured.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'configure_cloudflare_zone_id' => __( 'Add the Cloudflare zone ID in the WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$payload = $this->build_payload( $arguments );

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$timeout = $this->resolve_timeout( $arguments, $context, $settings );

		$endpoint = $this->build_endpoint( $zone_id, $context, $settings );

		$request_args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => $timeout,
			'body'    => wp_json_encode( $payload ),
		);

		if ( false === $request_args['body'] ) {
			return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the Cloudflare purge request.', 'wp-mcp-ai' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'cloudflare_purge_request',
			'Sending Cloudflare cache purge request.',
			array(
				'endpoint' => $endpoint,
				'payload'  => $this->get_log_safe_payload( $payload ),
			)
		);

		$response = wp_remote_post( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Cloudflare purge request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_cloudflare_http_error',
				__( 'The Cloudflare API request failed to complete.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code || empty( $decoded['success'] ) ) {
			$message = $this->extract_error_message( $decoded );

			WP_MCP_AI_Logger::log_error(
				'Cloudflare purge request returned an error.',
				array(
					'status_code' => $code,
					'response'    => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_cloudflare_error',
				$message,
				array(
					'status'   => $code,
					'response' => $decoded,
				)
			);
		}

		$summary = array(
			'purge_everything' => ! empty( $payload['purge_everything'] ),
			'urls'             => isset( $payload['files'] ) ? $payload['files'] : array(),
			'hosts'            => isset( $payload['hosts'] ) ? $payload['hosts'] : array(),
			'tags'             => isset( $payload['tags'] ) ? $payload['tags'] : array(),
		);

		$result = array(
			'message' => __( 'Cloudflare accepted the purge request.', 'wp-mcp-ai' ),
			'request' => $summary,
		);

		if ( isset( $decoded['result'] ) ) {
			$result['result'] = $decoded['result'];
		}

		if ( ! empty( $decoded['messages'] ) ) {
			$result['messages'] = $decoded['messages'];
		}

		return $result;
	}

	/**
	 * Build the purge payload from the supplied arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function build_payload( array $arguments ) {
		$payload = array();

		$purge_everything = ! empty( $arguments['purge_everything'] );
		if ( $purge_everything ) {
			$payload['purge_everything'] = true;
		}

		if ( isset( $arguments['urls'] ) ) {
			if ( ! is_array( $arguments['urls'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_urls', __( 'The URLs parameter must be an array of absolute URLs.', 'wp-mcp-ai' ) );
			}

			$files = array();
			foreach ( $arguments['urls'] as $url ) {
				if ( ! is_string( $url ) ) {
					continue;
				}

				$clean = esc_url_raw( $url );

				if ( '' !== $clean ) {
					$files[] = $clean;
				}
			}

			if ( empty( $files ) && ! empty( $arguments['urls'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_urls', __( 'None of the supplied URLs were valid.', 'wp-mcp-ai' ) );
			}

			if ( ! empty( $files ) ) {
				$payload['files'] = array_values( array_unique( $files ) );
			}
		}

		if ( isset( $arguments['hosts'] ) ) {
			if ( ! is_array( $arguments['hosts'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_hosts', __( 'Hosts must be provided as an array.', 'wp-mcp-ai' ) );
			}

			$hosts = array();
			foreach ( $arguments['hosts'] as $host ) {
				if ( ! is_string( $host ) ) {
					continue;
				}

				$host = trim( sanitize_text_field( $host ) );

				if ( '' !== $host ) {
					$hosts[] = $host;
				}
			}

			if ( empty( $hosts ) && ! empty( $arguments['hosts'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_hosts', __( 'None of the supplied hosts were valid.', 'wp-mcp-ai' ) );
			}

			if ( ! empty( $hosts ) ) {
				$payload['hosts'] = array_values( array_unique( $hosts ) );
			}
		}

		if ( isset( $arguments['tags'] ) ) {
			if ( ! is_array( $arguments['tags'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_tags', __( 'Tags must be provided as an array.', 'wp-mcp-ai' ) );
			}

			$tags = array();
			foreach ( $arguments['tags'] as $tag ) {
				if ( ! is_string( $tag ) ) {
					continue;
				}

				$tag = trim( sanitize_text_field( $tag ) );

				if ( '' !== $tag ) {
					$tags[] = $tag;
				}
			}

			if ( empty( $tags ) && ! empty( $arguments['tags'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_tags', __( 'None of the supplied tags were valid.', 'wp-mcp-ai' ) );
			}

			if ( ! empty( $tags ) ) {
				$payload['tags'] = array_values( array_unique( $tags ) );
			}
		}

		if ( empty( $payload ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_payload', __( 'Provide purge_everything or at least one URL, host, or tag to purge.', 'wp-mcp-ai' ) );
		}

		return $payload;
	}

	/**
	 * Determine the request timeout for the Cloudflare API call.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param array $settings  Plugin settings.
	 * @return int
	 */
	protected function resolve_timeout( array $arguments, array $context, array $settings ) {
		if ( isset( $arguments['timeout'] ) ) {
			return $this->normalise_timeout( $arguments['timeout'] );
		}

		if ( isset( $context['assistant_config']['cloudflare_timeout'] ) ) {
			return $this->normalise_timeout( $context['assistant_config']['cloudflare_timeout'] );
		}

		if ( isset( $settings['request_timeout'] ) ) {
			return $this->normalise_timeout( $settings['request_timeout'] );
		}

		return self::DEFAULT_TIMEOUT;
	}

	/**
	 * Normalise a timeout value ensuring it falls within the allowed range.
	 *
	 * @param mixed $timeout Timeout value to normalise.
	 * @return int
	 */
	protected function normalise_timeout( $timeout ) {
		$timeout = (int) $timeout;

		if ( $timeout < self::MIN_TIMEOUT ) {
			return self::MIN_TIMEOUT;
		}

		if ( $timeout > self::MAX_TIMEOUT ) {
			return self::MAX_TIMEOUT;
		}

		return $timeout;
	}

	/**
	 * Build the Cloudflare purge endpoint.
	 *
	 * @param string $zone_id  Zone identifier.
	 * @param array  $context  Execution context.
	 * @param array  $settings Plugin settings.
	 * @return string
	 */
	protected function build_endpoint( $zone_id, array $context, array $settings ) {
		$base = 'https://api.cloudflare.com/client/v4';

		/**
		 * Filters the Cloudflare API base URL used for cache purges.
		 *
		 * @param string $base_url Default base URL.
		 * @param array  $context  Tool execution context.
		 * @param array  $settings Plugin settings.
		 */
		$base = apply_filters( 'wp_mcp_ai_cloudflare_api_base_url', $base, $context, $settings );

		$base = rtrim( $base, '/' );

		return $base . '/zones/' . rawurlencode( $zone_id ) . '/purge_cache';
	}

	/**
	 * Prepare a log-safe payload that strips sensitive information.
	 *
	 * @param array $payload Request payload.
	 * @return array
	 */
	protected function get_log_safe_payload( array $payload ) {
		$safe = $payload;

		if ( isset( $safe['files'] ) ) {
			$safe['files'] = array_map( array( $this, 'mask_url' ), $safe['files'] );
		}

		return $safe;
	}

	/**
	 * Mask a URL so logs do not capture query strings or credentials.
	 *
	 * @param string $url URL to mask.
	 * @return string
	 */
	protected function mask_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';

		return $scheme . $parts['host'] . $path;
	}

	/**
	 * Extract an error message from the Cloudflare response.
	 *
	 * @param array $response Decoded response.
	 * @return string
	 */
	protected function extract_error_message( array $response ) {
		if ( ! empty( $response['errors'] ) && is_array( $response['errors'] ) ) {
			$first = reset( $response['errors'] );

			if ( is_array( $first ) && ! empty( $first['message'] ) ) {
				return sanitize_text_field( $first['message'] );
			}

			if ( is_string( $first ) ) {
				return sanitize_text_field( $first );
			}
		}

		if ( ! empty( $response['messages'] ) && is_array( $response['messages'] ) ) {
			$first = reset( $response['messages'] );

			if ( is_string( $first ) ) {
				return sanitize_text_field( $first );
			}
		}

		return __( 'Cloudflare rejected the purge request.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
