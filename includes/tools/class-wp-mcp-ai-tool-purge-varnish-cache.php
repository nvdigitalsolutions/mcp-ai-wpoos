<?php
/**
 * Tool that purges the Varnish cache for the local server.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-interface.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
}

/**
 * Provides a tool for purging Varnish cache entries.
 */
class WP_MCP_AI_Tool_Purge_Varnish_Cache implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const DEFAULT_TIMEOUT = 30;

	const MAX_TIMEOUT = 120;

	const MIN_TIMEOUT = 5;

	const DEFAULT_VARNISH_HOST = '127.0.0.1';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'purge_varnish_cache';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Purge Varnish Cache', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Purges the local Varnish cache. Supports full-cache purges (bans) and specific URL purges.', 'wp-mcp-ai' );
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
					'description' => __( 'Whether to purge the entire Varnish cache using a ban.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'urls'             => array(
					'type'        => 'array',
					'description' => __( 'Specific URLs to purge from Varnish. Provide absolute URLs.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'   => 'string',
						'format' => 'uri',
					),
				),
				'timeout'          => array(
					'type'        => 'integer',
					'description' => __( 'Optional timeout in seconds for the Varnish PURGE request.', 'wp-mcp-ai' ),
					'minimum'     => self::MIN_TIMEOUT,
					'maximum'     => self::MAX_TIMEOUT,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute a cache purge against the Varnish server.
	 *
	 * @param array $arguments Parsed tool arguments.
	 * @param array $context   Request context including acting user details.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to purge the Varnish cache.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_settings', __( 'The admin settings component is not available.', 'wp-mcp-ai' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$enabled = ! empty( $settings['enable_varnish_purge'] );

		if ( ! $enabled ) {
			return new WP_Error(
				'wp_mcp_ai_varnish_disabled',
				__( 'Varnish purge is not enabled in the plugin settings.', 'wp-mcp-ai' ),
				array(
					'status'  => 400,
					'actions' => array(
						'enable_varnish_purge' => __( 'Enable Varnish purge in the WP oOS settings.', 'wp-mcp-ai' ),
					),
				)
			);
		}

		$purge_everything = ! empty( $arguments['purge_everything'] );
		$urls             = isset( $arguments['urls'] ) && is_array( $arguments['urls'] ) ? $arguments['urls'] : array();

		if ( ! $purge_everything && empty( $urls ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_purge', __( 'Provide purge_everything or at least one URL to purge.', 'wp-mcp-ai' ) );
		}

		$timeout = $this->resolve_timeout( $arguments, $context, $settings );
		$results = array();

		if ( $purge_everything ) {
			$ban_result = $this->purge_all_varnish( $timeout );
			if ( is_wp_error( $ban_result ) ) {
				return $ban_result;
			}
			$results[] = $ban_result;
		}

		if ( ! empty( $urls ) ) {
			foreach ( $urls as $url ) {
				if ( ! is_string( $url ) ) {
					continue;
				}

				$clean = esc_url_raw( $url );

				if ( '' === $clean ) {
					continue;
				}

				$purge_result = $this->purge_varnish_url( $clean, $timeout );

				if ( is_wp_error( $purge_result ) ) {
					return $purge_result;
				}

				$results[] = $purge_result;
			}
		}

		$summary = array(
			'message'          => __( 'Varnish cache purge completed successfully.', 'wp-mcp-ai' ),
			'purge_everything' => $purge_everything,
			'urls_purged'      => ! empty( $urls ) ? count( array_filter( $urls, 'is_string' ) ) : 0,
			'results'          => $results,
		);

		return $summary;
	}

	/**
	 * Purge all Varnish cache using a ban command.
	 *
	 * @param int $timeout Request timeout in seconds.
	 * @return array|WP_Error
	 */
	protected function purge_all_varnish( $timeout ) {
		$site_url = home_url( '/' );

		WP_MCP_AI_Logger::log_event(
			'varnish_purge_all',
			'Sending Varnish ban request to purge all cache.',
			array(
				'site_url' => $site_url,
			)
		);

		$response = $this->send_varnish_purge_request( $site_url, $timeout, true );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Varnish ban request failed.', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		return array(
			'type'    => 'ban',
			'message' => __( 'Varnish accepted the ban (purge all) request.', 'wp-mcp-ai' ),
			'status'  => $response['status'],
		);
	}

	/**
	 * Purge a specific URL from Varnish cache.
	 *
	 * @param string $url     URL to purge.
	 * @param int    $timeout Request timeout in seconds.
	 * @return array|WP_Error
	 */
	protected function purge_varnish_url( $url, $timeout ) {
		WP_MCP_AI_Logger::log_event(
			'varnish_purge_url',
			'Sending Varnish PURGE request for specific URL.',
			array(
				'url' => $this->mask_url( $url ),
			)
		);

		$response = $this->send_varnish_purge_request( $url, $timeout, false );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'Varnish URL purge request failed.', array( 'error' => $response->get_error_message() ) );
			return $response;
		}

		return array(
			'type'    => 'url',
			'url'     => $this->mask_url( $url ),
			'message' => __( 'Varnish accepted the URL purge request.', 'wp-mcp-ai' ),
			'status'  => $response['status'],
		);
	}

	/**
	 * Send a PURGE or BAN request to Varnish.
	 *
	 * @param string $url     URL to purge.
	 * @param int    $timeout Request timeout in seconds.
	 * @param bool   $is_ban  Whether this is a ban (purge all) request.
	 * @return array|WP_Error
	 */
	protected function send_varnish_purge_request( $url, $timeout, $is_ban = false ) {
		$varnish_host = $this->get_varnish_host();

		$parsed_url = wp_parse_url( $url );

		if ( empty( $parsed_url['path'] ) ) {
			$parsed_url['path'] = '/';
		}

		$purge_url = 'http://' . $varnish_host . $parsed_url['path'];

		if ( ! empty( $parsed_url['query'] ) ) {
			$purge_url .= '?' . $parsed_url['query'];
		}

		$headers = array(
			'Host' => isset( $parsed_url['host'] ) ? $parsed_url['host'] : wp_parse_url( home_url(), PHP_URL_HOST ),
		);

		if ( $is_ban ) {
			$headers['X-Ban-Regex'] = '.*';
		}

		$request_args = array(
			'method'  => 'PURGE',
			'headers' => $headers,
			'timeout' => $timeout,
		);

		// Disable SSL verification only for localhost/loopback addresses to prevent certificate mismatch.
		// Note: WP_MCP_AI_HTTP_Helper also handles this globally, but we set it explicitly here for clarity.
		if ( $this->is_loopback_address( $varnish_host ) ) {
			$request_args['sslverify'] = false;
		}

		$response = wp_remote_request( $purge_url, $request_args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_varnish_http_error',
				__( 'The Varnish PURGE request failed to complete.', 'wp-mcp-ai' ),
				array( 'error' => $response )
			);
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$body = wp_remote_retrieve_body( $response );

			WP_MCP_AI_Logger::log_error(
				'Varnish purge request returned an error status.',
				array(
					'status_code' => $code,
					'response'    => $body,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_varnish_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Varnish returned an error status: %d', 'wp-mcp-ai' ),
					$code
				),
				array(
					'status'   => $code,
					'response' => $body,
				)
			);
		}

		return array(
			'status' => $code,
		);
	}

	/**
	 * Get the Varnish host address.
	 *
	 * @return string
	 */
	protected function get_varnish_host() {
		/**
		 * Filter the Varnish host address for PURGE requests.
		 *
		 * @param string $host Default Varnish host (127.0.0.1).
		 */
		$host = apply_filters( 'wp_mcp_ai_varnish_host', self::DEFAULT_VARNISH_HOST );

		return sanitize_text_field( $host );
	}

	/**
	 * Determine the request timeout for the Varnish PURGE call.
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

		if ( isset( $context['assistant_config']['varnish_timeout'] ) ) {
			return $this->normalise_timeout( $context['assistant_config']['varnish_timeout'] );
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
	 * Check if a host is a loopback/localhost address.
	 *
	 * @param string $host Host address to check.
	 * @return bool True if the host is a loopback address, false otherwise.
	 */
	protected function is_loopback_address( $host ) {
		if ( empty( $host ) ) {
			return false;
		}

		// Normalize the host.
		$host = strtolower( trim( $host ) );

		// Check for common localhost names.
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return true;
		}

		// Check for IPv4 loopback (127.0.0.0/8).
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $host );
			if ( isset( $parts[0] ) && '127' === $parts[0] ) {
				return true;
			}
		}

		// Check for IPv6 loopback (::1).
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			if ( '::1' === $host || '0:0:0:0:0:0:0:1' === $host ) {
				return true;
			}
		}

		return false;
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
