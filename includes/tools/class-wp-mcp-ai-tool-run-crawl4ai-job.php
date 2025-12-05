<?php
/**
 * Tool that submits Crawl4AI crawl jobs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';

/**
 * Provides an integration with the Crawl4AI REST API.
 */
class WP_MCP_AI_Tool_Run_Crawl4AI_Job implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
	const DEFAULT_WAIT_TIMEOUT  = 120;
	const DEFAULT_POLL_INTERVAL = 3;

	/**
	 * Determine whether the Crawl4AI integration is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$base_url = self::resolve_base_url( $settings );

		if ( '' !== $base_url ) {
			return true;
		}

		/**
		 * Filters whether the built-in crawler should be exposed.
		 *
		 * Returning false here disables the fallback entirely which effectively
		 * mirrors the previous behaviour where an external Crawl4AI endpoint
		 * was mandatory.
		 *
		 * @param bool  $enabled  Whether the local crawler is available.
		 * @param array $settings Plugin settings array.
		 */
		$local_enabled = apply_filters( 'wp_mcp_ai_crawl4ai_local_enabled', true, $settings );

		return (bool) $local_enabled;
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Crawl4AI tool is disabled on this site.', 'wp-mcp-ai' );
	}

	/**
	 * Resolve the configured Crawl4AI base URL.
	 *
	 * @param array $settings Plugin settings array.
	 * @param array $context  Optional execution context passed to the tool.
	 * @return string
	 */
	protected static function resolve_base_url( array $settings, array $context = array() ) {
		$base_url = '';

		if ( isset( $settings['crawl4ai_base_url'] ) ) {
			$base_url = (string) $settings['crawl4ai_base_url'];
		}

		/**
		 * Filters the Crawl4AI base URL used by the tool.
		 *
		 * This allows environments to provide a base URL dynamically (for example,
		 * from environment variables) when the admin setting is left blank.
		 *
		 * @param string $base_url Base URL configured in the settings.
		 * @param array  $settings Entire WP oOS settings array.
		 * @param array  $context  Execution context provided to the tool.
		 */
		$base_url = apply_filters( 'wp_mcp_ai_crawl4ai_base_url', $base_url, $settings, $context );

		if ( ! is_string( $base_url ) ) {
			return '';
		}

		$sanitised = esc_url_raw( trim( $base_url ) );

		if ( ! $sanitised ) {
			return '';
		}

		$sanitised = self::normalise_loopback_url( $sanitised );

		return untrailingslashit( $sanitised );
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
					'type'                 => 'object',
					'description'          => __( 'Additional Crawl4AI options (for example, crawler configuration or hook overrides).', 'wp-mcp-ai' ),
					'additionalProperties' => true,
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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_unavailable', __( 'Crawl4AI is not available on this site.', 'wp-mcp-ai' ) );
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

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$base_url = $this->get_base_url( $settings, $context );

		if ( '' !== $base_url ) {
			return $this->execute_remote_crawl( $payload, $arguments, $context, $settings, $base_url );
		}

		return $this->execute_local_crawl( $payload, $arguments, $context, $settings );
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

				if ( is_wp_error( $sanitised ) ) {
					return $sanitised;
				}

				if ( $sanitised ) {
					$urls[] = $sanitised;
				}
			}
		}

		if ( empty( $urls ) && ! empty( $arguments['url'] ) ) {
			$single = $this->sanitize_url( $arguments['url'] );
			if ( is_wp_error( $single ) ) {
				return $single;
			}

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
	 * @return string|WP_Error
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

		if ( ! $sanitised ) {
			return '';
		}

		$parts = wp_parse_url( $sanitised );

		if ( false === $parts || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( $this->is_url_trusted_host( $sanitised, $parts ) ) {
			return $sanitised;
		}

		if ( ! $this->is_url_network_safe( $sanitised, $parts ) ) {
			return new WP_Error(
				'wp_mcp_ai_crawl4ai_unsafe_url',
				__( 'Crawl4AI cannot access loopback, link-local, or private network URLs.', 'wp-mcp-ai' ),
				array( 'url' => $sanitised )
			);
		}

		return $sanitised;
	}

	/**
	 * Determine whether the provided URL points to a trusted host.
	 *
	 * @param string $url   Sanitised URL string.
	 * @param array  $parts Parsed URL parts.
	 * @return bool
	 */
	protected function is_url_trusted_host( $url, array $parts ) {
		$host = strtolower( $parts['host'] );

		/**
		 * Filters the list of trusted hosts that the crawler may access.
		 *
		 * Returning one or more hostnames here restricts crawling to the
		 * provided values. Hostnames may include a leading wildcard (e.g.
		 * `*.example.com`).
		 *
		 * @param string[] $trusted_hosts Array of trusted host patterns.
		 * @param string   $url           Sanitised URL string.
		 * @param array    $parts         Parsed URL parts from wp_parse_url().
		 */
		$trusted_hosts = apply_filters( 'wp_mcp_ai_crawl4ai_trusted_hosts', array(), $url, $parts );

		if ( empty( $trusted_hosts ) || ! is_array( $trusted_hosts ) ) {
			return false;
		}

		foreach ( $trusted_hosts as $trusted ) {
			if ( ! is_string( $trusted ) ) {
				continue;
			}

			$trusted = strtolower( trim( $trusted ) );

			if ( '' === $trusted ) {
				continue;
			}

			if ( $trusted === $host ) {
				return true;
			}

			if ( 0 === strpos( $trusted, '*.' ) ) {
				$suffix = substr( $trusted, 1 );

				if ( '' !== $suffix && substr( $host, -strlen( $suffix ) ) === $suffix ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Determine whether the URL resolves to a public network destination.
	 *
	 * @param string $url   Sanitised URL string.
	 * @param array  $parts Parsed URL parts.
	 * @return bool
	 */
	protected function is_url_network_safe( $url, array $parts ) {
		$host = $parts['host'];

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return $this->is_ip_public( $host );
		}

		$ips = $this->resolve_host_ips( $host );

		if ( empty( $ips ) ) {
			return true;
		}

		foreach ( $ips as $ip ) {
			if ( ! $this->is_ip_public( $ip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve a hostname to a set of IP addresses.
	 *
	 * @param string $host Hostname to resolve.
	 * @return string[]
	 */
	protected function resolve_host_ips( $host ) {
		$ips = array();

		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_A' ) ) {
			$type = DNS_A;

			if ( defined( 'DNS_AAAA' ) ) {
				$type |= DNS_AAAA;
			}

			$records = @dns_get_record( $host, $type );

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( isset( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					} elseif ( isset( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		if ( function_exists( 'gethostbynamel' ) ) {
			$ipv4 = @gethostbynamel( $host );

			if ( is_array( $ipv4 ) ) {
				$ips = array_merge( $ips, $ipv4 );
			}
		}

		return array_values( array_unique( $ips ) );
	}

	/**
	 * Normalise HTTPS loopback URLs so they use HTTP instead.
	 *
	 * Local development environments frequently expose Crawl4AI on
	 * 127.0.0.1 with a self-signed certificate, which results in TLS
	 * errors when WordPress performs HTTPS requests. Switching to HTTP
	 * avoids these certificate mismatches without affecting production
	 * deployments that rely on publicly routable hosts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Raw Crawl4AI base URL.
	 * @return string
	 */
	protected static function normalise_loopback_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $url;
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return $url;
		}

		if ( ! self::is_loopback_host( $parts['host'] ) ) {
			return $url;
		}

		return preg_replace( '#^https://#i', 'http://', $url, 1 );
	}

	/**
	 * Determine whether a hostname refers to the local machine.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Hostname component from the base URL.
	 * @return bool
	 */
	protected static function is_loopback_host( $host ) {
		$host = trim( strtolower( $host ) );

		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		if ( 0 === strpos( $host, '127.' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Determine whether an IP address is routable on the public internet.
	 *
	 * @param string $ip IP address (IPv4 or IPv6).
	 * @return bool
	 */
	protected function is_ip_public( $ip ) {
		$validated = filter_var( $ip, FILTER_VALIDATE_IP );

		if ( false === $validated ) {
			return false;
		}

		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, $flags ) ) {
			return false;
		}

		if ( false === strpos( $ip, ':' ) ) {
			// IPv4 specific exclusions.
			if ( 0 === strpos( $ip, '127.' ) ) {
				return false;
			}

			if ( 0 === strpos( $ip, '169.254.' ) ) {
				return false;
			}

			return true;
		}

		$lower = strtolower( $ip );

		if ( '::1' === $lower ) {
			return false;
		}

		if ( 0 === strpos( $lower, 'fe80:' ) ) {
			return false;
		}

		return true;
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
	 * @param array $context  Execution context including user_id.
	 * @return string
	 */
	protected function get_base_url( array $settings, array $context = array() ) {
		return self::resolve_base_url( $settings, $context );
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
	 * Build request arguments for local crawls.
	 *
	 * @param array $settings Plugin settings array.
	 * @param array $context  Execution context.
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function build_local_request_args( array $settings, array $context, array $arguments ) {
		$language = get_bloginfo( 'language' );
		$headers  = array(
			'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'Accept-Language' => $language ? str_replace( '_', '-', $language ) : 'en-US,en;q=0.9',
			'User-Agent'      => $this->get_local_user_agent( $settings, $context ),
		);

		$args = array(
			'headers'            => $headers,
			'redirection'        => 5,
			'sslverify'          => true,
			'decompress'         => true,
			'reject_unsafe_urls' => true,
		);

		/**
		 * Filters the HTTP request arguments used by the local crawler.
		 */
		return apply_filters( 'wp_mcp_ai_crawl4ai_local_request_args', $args, $settings, $context, $arguments );
	}

	/**
	 * Determine the default User-Agent string for local crawl requests.
	 *
	 * @param array $settings Plugin settings array.
	 * @param array $context  Execution context.
	 * @return string
	 */
	protected function get_local_user_agent( array $settings, array $context ) {
		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url( '/' );

		$user_agent = sprintf( 'WP-MCP-AI-Crawler/1.0 (+%s)', $site_url );

		if ( $site_name ) {
			$user_agent = sprintf( 'WP-MCP-AI-Crawler/1.0 (%s; +%s)', sanitize_text_field( $site_name ), $site_url );
		}

		/**
		 * Filters the User-Agent string sent by the local crawler.
		 */
		return apply_filters( 'wp_mcp_ai_crawl4ai_local_user_agent', $user_agent, $settings, $context );
	}

	/**
	 * Build a structured result for a locally crawled URL.
	 *
	 * @param string $url      Requested URL.
	 * @param array  $response HTTP response array.
	 * @param array  $payload  Prepared payload data.
	 * @param array  $settings Plugin settings array.
	 * @param array  $context  Execution context.
	 * @return array
	 */
	protected function build_local_result( $url, $response, array $payload, array $settings, array $context ) {
		$status_code  = wp_remote_retrieve_response_code( $response );
		$body         = wp_remote_retrieve_body( $response );
		$headers      = wp_remote_retrieve_headers( $response );
		$header_array = $this->normalise_headers( $headers );
		$content_type = isset( $header_array['content-type'] ) ? $header_array['content-type'] : '';
		$charset      = isset( $header_array['content-type'] ) ? $this->detect_charset_from_content_type( $header_array['content-type'] ) : '';

		if ( $charset && function_exists( 'mb_convert_encoding' ) ) {
			$body = mb_convert_encoding( $body, 'UTF-8', $charset );
		}

		$result = array(
			'url'            => $url,
			'status_code'    => $status_code,
			'content_type'   => $content_type,
			'content_length' => strlen( (string) $body ),
			'retrieved_at'   => current_time( 'mysql', true ),
			'html'           => '',
			'markdown'       => '',
			'text'           => '',
			'metadata'       => array(
				'headers' => $header_array,
			),
		);

		if ( $this->should_treat_as_html( $content_type ) ) {
			// Sanitize HTML content for valid UTF-8 to prevent JSON encoding failures.
			$result['html']     = $this->sanitize_utf8( $body );
			$result['markdown'] = $this->sanitize_utf8( $this->convert_html_to_markdown( $body ) );
			$result['text']     = $this->sanitize_utf8( $this->convert_html_to_text( $body ) );
		} elseif ( $this->should_treat_as_json( $content_type, $body ) ) {
			$decoded = json_decode( $body, true );
			if ( null !== $decoded ) {
				$result['markdown'] = "```json\n" . wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n```";
				$result['text']     = wp_json_encode( $decoded );
			} else {
				$result['text'] = $this->sanitize_utf8( trim( (string) $body ) );
			}
		} elseif ( $this->should_treat_as_text( $content_type ) ) {
			$result['text']     = $this->sanitize_utf8( trim( (string) $body ) );
			$result['markdown'] = $result['text'];
		}

		// Validate that the result can be JSON-encoded before returning.
		// This prevents SSE stream corruption from invalid UTF-8 in scraped content.
		$test_encode = wp_json_encode( $result );
		if ( false === $test_encode ) {
			// Log the encoding failure for debugging.
			WP_MCP_AI_Logger::log_error(
				'crawl4ai_result_json_encode_failed',
				'Failed to JSON encode Crawl4AI result',
				array(
					'url'        => $url,
					'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'Unknown',
				)
			);

			// Return a safe minimal result instead of corrupting the stream.
			return array(
				'url'            => $url,
				'status_code'    => $status_code,
				'content_type'   => $content_type,
				'content_length' => 0,
				'retrieved_at'   => current_time( 'mysql', true ),
				'html'           => '',
				'markdown'       => '',
				'text'           => __( 'Content could not be properly encoded for transmission. This may indicate invalid characters in the scraped data.', 'wp-mcp-ai' ),
				'metadata'       => array(
					'error' => 'json_encode_failed',
				),
			);
		}

		return $result;
	}

	/**
	 * Normalise HTTP headers from the WordPress HTTP API into an array of lowercase keys.
	 *
	 * @param array|Requests_Utility_CaseInsensitiveDictionary $headers HTTP headers.
	 * @return array
	 */
	protected function normalise_headers( $headers ) {
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		$normalised = array();

		if ( is_array( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				$normalised[ strtolower( (string) $key ) ] = is_array( $value ) ? array_map( 'trim', $value ) : trim( (string) $value );
			}
		}

		return $normalised;
	}

	/**
	 * Detect the charset from a content type header string.
	 *
	 * @param string $content_type Content type header.
	 * @return string
	 */
	protected function detect_charset_from_content_type( $content_type ) {
		if ( preg_match( '/charset=([^;]+)/i', $content_type, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Determine if the response should be parsed as HTML.
	 *
	 * @param string $content_type Content type header.
	 * @return bool
	 */
	protected function should_treat_as_html( $content_type ) {
		if ( empty( $content_type ) ) {
			return true;
		}

		return false !== strpos( strtolower( $content_type ), 'text/html' ) || false !== strpos( strtolower( $content_type ), 'application/xhtml+xml' );
	}

	/**
	 * Determine if the response should be parsed as JSON.
	 *
	 * @param string $content_type Content type header.
	 * @param string $body         Response body.
	 * @return bool
	 */
	protected function should_treat_as_json( $content_type, $body ) {
		if ( false !== strpos( strtolower( $content_type ), 'application/json' ) ) {
			return true;
		}

		$trimmed = trim( (string) $body );
		return ( '' !== $trimmed ) && ( '{' === $trimmed[0] || '[' === $trimmed[0] );
	}

	/**
	 * Determine if the response should be treated as plain text.
	 *
	 * @param string $content_type Content type header.
	 * @return bool
	 */
	protected function should_treat_as_text( $content_type ) {
		return false !== strpos( strtolower( $content_type ), 'text/plain' );
	}

	/**
	 * Convert HTML to Markdown.
	 *
	 * @param string $html HTML content.
	 * @return string
	 */
	protected function convert_html_to_markdown( $html ) {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return $this->convert_html_to_text( $html );
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return $this->convert_html_to_text( $html );
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return $this->convert_html_to_text( $html );
		}

		$markdown = '';

		foreach ( $body->childNodes as $child ) {
			$markdown .= $this->render_dom_node_to_markdown( $child, 0 );
		}

		$markdown = preg_replace( "/\n{3,}/", "\n\n", $markdown );
		$markdown = preg_replace( "/[ \t]+\n/", "\n", $markdown );

		return trim( $markdown );
	}

	/**
	 * Render a DOM node to Markdown.
	 *
	 * @param DOMNode $node       DOM node.
	 * @param int     $list_depth Current list depth.
	 * @return string
	 */
	protected function render_dom_node_to_markdown( $node, $list_depth = 0 ) {
		if ( $node instanceof DOMText ) {
			$text = preg_replace( '/\s+/u', ' ', $node->wholeText );
			return $text;
		}

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		$tag      = strtolower( $node->tagName );
		$contents = $this->render_dom_children_to_markdown( $node, $list_depth );

		switch ( $tag ) {
			case 'h1':
				return "\n\n# " . trim( $contents ) . "\n\n";
			case 'h2':
				return "\n\n## " . trim( $contents ) . "\n\n";
			case 'h3':
				return "\n\n### " . trim( $contents ) . "\n\n";
			case 'h4':
				return "\n\n#### " . trim( $contents ) . "\n\n";
			case 'h5':
				return "\n\n##### " . trim( $contents ) . "\n\n";
			case 'h6':
				return "\n\n###### " . trim( $contents ) . "\n\n";
			case 'p':
				return "\n\n" . trim( $contents ) . "\n\n";
			case 'br':
				return "  \n";
			case 'strong':
			case 'b':
				return '**' . trim( $contents ) . '**';
			case 'em':
			case 'i':
				return '_' . trim( $contents ) . '_';
			case 'code':
				if ( strtolower( $node->parentNode->nodeName ) === 'pre' ) {
					return $contents;
				}
				return '`' . trim( $contents ) . '`';
			case 'pre':
				$text = trim( $contents );
				return "\n\n```\n" . $text . "\n```\n\n";
			case 'a':
				$href  = $node->getAttribute( 'href' );
				$href  = esc_url_raw( $href );
				$label = trim( $contents );
				if ( '' === $label ) {
					$label = $href;
				}
				if ( '' === $href ) {
					return $label;
				}
				return '[' . $label . '](' . $href . ')';
			case 'ul':
			case 'ol':
				$output = "\n";
				foreach ( $node->childNodes as $child ) {
					$output .= $this->render_dom_node_to_markdown( $child, $list_depth + 1 );
				}
				return $output . "\n";
			case 'li':
				$content = trim( $contents );
				if ( '' === $content ) {
					return '';
				}
				$indent  = str_repeat( '    ', max( 0, $list_depth - 1 ) );
				$ordered = $node->parentNode && 'ol' === strtolower( $node->parentNode->nodeName );
				$marker  = $ordered ? '1.' : '-';
				$content = preg_replace( '/\n+/', "\n" . $indent . '    ', $content );
				return $indent . $marker . ' ' . $content . "\n";
			case 'img':
				$alt = trim( $node->getAttribute( 'alt' ) );
				$src = esc_url_raw( $node->getAttribute( 'src' ) );
				if ( ! $src ) {
					return '';
				}
				return '![' . $alt . '](' . $src . ')';
			default:
				return $contents;
		}
	}

	/**
	 * Render the child nodes of a DOM element to Markdown.
	 *
	 * @param DOMNode $node       DOM element.
	 * @param int     $list_depth Current list depth.
	 * @return string
	 */
	protected function render_dom_children_to_markdown( $node, $list_depth ) {
		$buffer = '';

		foreach ( $node->childNodes as $child ) {
			$buffer .= $this->render_dom_node_to_markdown( $child, $list_depth );
		}

		return $buffer;
	}

	/**
	 * Convert HTML content to plain text.
	 *
	 * @param string $html HTML markup.
	 * @return string
	 */
	protected function convert_html_to_text( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
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
				/* translators: %s: error message from Crawl4AI */
				return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['error'] );
			}

			if ( is_array( $decoded['error'] ) ) {
				if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
					/* translators: %s: error message from Crawl4AI */
					return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['error']['message'] );
				}

				if ( isset( $decoded['error']['detail'] ) && is_string( $decoded['error']['detail'] ) ) {
					/* translators: %s: error message from Crawl4AI */
					return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['error']['detail'] );
				}
			}
		}

		if ( isset( $decoded['detail'] ) && is_string( $decoded['detail'] ) ) {
			/* translators: %s: error message from Crawl4AI */
			return sprintf( __( 'Crawl4AI reported an error: %s', 'wp-mcp-ai' ), $decoded['detail'] );
		}

		if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
			/* translators: %s: error message from Crawl4AI */
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
	 * Request the latest status for a remote Crawl4AI task.
	 *
	 * @param string $task_id  Task identifier returned by Crawl4AI.
	 * @param string $base_url Crawl4AI base URL.
	 * @param array  $settings Plugin settings array.
	 * @param array  $arguments Tool arguments provided to the job.
	 * @param array  $context  Execution context.
	 * @return array|WP_Error  Array with 'formatted' and 'decoded' keys on success.
	 */
	public function check_remote_task( $task_id, $base_url, array $settings, array $arguments, array $context ) {
		$headers  = $this->build_headers( $settings, $context );
		$timeout  = $this->get_request_timeout( $settings );
		$endpoint = trailingslashit( $base_url ) . 'task/' . rawurlencode( $task_id );

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
				'timeout' => max( 5, $timeout ),
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
		$formatted            = $this->enforce_result_size_limits( $formatted );

		WP_MCP_AI_Logger::log_event(
			'crawl4ai_poll_response',
			'Received Crawl4AI task status.',
			array(
				'status'  => isset( $formatted['status'] ) ? $formatted['status'] : '',
				'task_id' => $task_id,
			)
		);

		return array(
			'formatted' => $formatted,
			'decoded'   => $decoded,
		);
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

	/**
	 * Ensure the response payload respects approximate token limits.
	 *
	 * @param array $response Response array that will be returned to the assistant.
	 * @return array
	 */
	protected function enforce_result_size_limits( array $response ) {
		if ( empty( $response['results'] ) || ! is_array( $response['results'] ) ) {
			return $response;
		}

		// Increase default token limit to 100000 for crawl4ai to handle larger web scraping jobs.
		// This tool specifically needs high token capacity due to the nature of web content.
		// When results exceed model TPM limits, WP_MCP_AI_Model_Selector::check_tpm_and_suggest_fallback()
		// will automatically switch to the configured high-capacity fallback model (default: gemini-2.5-flash)
		// if 'enable_high_token_model_switch' is enabled in settings.
		$limit_tokens = (int) apply_filters( 'wp_mcp_ai_crawl4ai_result_token_limit', 100000, $response );

		if ( $limit_tokens <= 0 ) {
			return $response;
		}

		$chars_per_token = (int) apply_filters( 'wp_mcp_ai_crawl4ai_chars_per_token', 4, $response );
		if ( $chars_per_token <= 0 ) {
			$chars_per_token = 4;
		}

		$max_chars   = $limit_tokens * $chars_per_token;
		$total_chars = 0;
		$truncated   = false;

		foreach ( $response['results'] as $index => &$result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			foreach ( array( 'markdown', 'text', 'html' ) as $field ) {
				if ( empty( $result[ $field ] ) || ! is_string( $result[ $field ] ) ) {
					continue;
				}

				$length = $this->get_string_length( $result[ $field ] );

				if ( ( $total_chars + $length ) <= $max_chars ) {
					$total_chars += $length;
					continue;
				}

				$remaining = $max_chars - $total_chars;

				if ( $remaining <= 0 ) {
					$result[ $field ] = '';
				} else {
					$result[ $field ] = $this->truncate_string( $result[ $field ], $remaining );
					$total_chars      = $max_chars;
				}

				$truncated = true;

				if ( ! isset( $result['metadata'] ) || ! is_array( $result['metadata'] ) ) {
					$result['metadata'] = array();
				}

				if ( ! isset( $result['metadata']['truncated_fields'] ) || ! is_array( $result['metadata']['truncated_fields'] ) ) {
					$result['metadata']['truncated_fields'] = array();
				}

				if ( ! in_array( $field, $result['metadata']['truncated_fields'], true ) ) {
					$result['metadata']['truncated_fields'][] = $field;
				}
			}
		}

		unset( $result );

		if ( ! $truncated ) {
			return $response;
		}

		// Log truncation for debugging high-token scenarios.
		WP_MCP_AI_Logger::log_event(
			'crawl4ai_result_truncated',
			'Crawl4AI results truncated to fit token limits.',
			array(
				'token_limit'         => $limit_tokens,
				'original_char_count' => $total_chars,
				'max_chars'           => $max_chars,
			)
		);

		if ( ! isset( $response['metadata'] ) || ! is_array( $response['metadata'] ) ) {
			$response['metadata'] = array();
		}

		$response['metadata']['truncated']               = true;
		$response['metadata']['truncated_reason']        = __( 'Results trimmed to satisfy model token limits.', 'wp-mcp-ai' );
		$response['metadata']['approximate_token_limit'] = $limit_tokens;

		if ( ! isset( $response['raw'] ) || ! is_array( $response['raw'] ) ) {
			$response['raw'] = array();
		}

		$response['raw']['results'] = $response['results'];

		if ( isset( $response['metadata'] ) ) {
			$response['raw']['metadata'] = $response['metadata'];
		}

		return $response;
	}

	/**
	 * Determine the length of a string, accounting for multibyte support when available.
	 *
	 * @param string $value String value.
	 * @return int
	 */
	protected function get_string_length( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}

		return strlen( $value );
	}

	/**
	 * Truncate a string to the requested length, preferring multibyte functions when available.
	 *
	 * @param string $value     Original string value.
	 * @param int    $max_chars Maximum character count to keep.
	 * @return string
	 */
	protected function truncate_string( $value, $max_chars ) {
		if ( $max_chars <= 0 ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_chars, 'UTF-8' );
		}

		return substr( $value, 0, $max_chars );
	}

	/**
	 * Execute a crawl through the remote Crawl4AI service.
	 *
	 * @param array  $payload   Prepared payload array.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @param array  $settings  Plugin settings array.
	 * @param string $base_url  Crawl4AI base URL.
	 * @return array|WP_Error
	 */
	protected function execute_remote_crawl( array $payload, array $arguments, array $context, array $settings, $base_url ) {
		$encoded_payload = wp_json_encode( $payload );
		if ( false === $encoded_payload ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_encoding_error', __( 'Failed to encode the Crawl4AI request payload.', 'wp-mcp-ai' ) );
		}

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
		$filtered  = apply_filters( 'wp_mcp_ai_crawl4ai_response', $formatted, $decoded, $arguments, $context );

		$filtered = $this->enforce_result_size_limits( $filtered );

		if ( empty( $filtered['task_id'] ) && ! empty( $formatted['task_id'] ) ) {
			$filtered['task_id'] = $formatted['task_id'];
		}

		$has_results = ! empty( $filtered['results'] );

		if ( empty( $filtered['task_id'] ) && $has_results ) {
			$filtered['task_id'] = '';
		}

		if ( empty( $filtered['task_id'] ) && ! $has_results ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_missing_task', __( 'Crawl4AI did not return a task identifier.', 'wp-mcp-ai' ) );
		}

		if ( empty( $filtered['status'] ) ) {
			$filtered['status'] = 'pending';
		}

		if ( $has_results || 'completed' === $filtered['status'] ) {
			// Register the completed job with the manager for tracking
			WP_MCP_AI_Crawler::register_completed_job(
				$filtered['task_id'],
				array(
					'base_url'     => $base_url,
					'arguments'    => $arguments,
					'context'      => $context,
					'status'       => $filtered['status'],
					'result'       => $filtered,
					'raw_response' => $decoded,
				)
			);

			WP_MCP_AI_Logger::log_event(
				'crawl4ai_response',
				'Crawl4AI request completed synchronously.',
				array(
					'status'  => $filtered['status'],
					'task_id' => $filtered['task_id'],
				)
			);

			return $filtered;
		}

		$wait_timeout  = $this->get_wait_timeout( $arguments, $context );
		$poll_interval = $this->get_poll_interval( $arguments, $context );

		if ( $wait_timeout <= 0 ) {
			$wait_timeout = self::DEFAULT_WAIT_TIMEOUT;
		}

		if ( $poll_interval <= 0 ) {
			$poll_interval = self::DEFAULT_POLL_INTERVAL;
		}

		$metadata                  = isset( $filtered['metadata'] ) && is_array( $filtered['metadata'] ) ? $filtered['metadata'] : array();
		$metadata['poll_interval'] = $poll_interval;
		$metadata['wait_timeout']  = $wait_timeout;
		$metadata['queued_at']     = current_time( 'mysql', true );

		$pending_result = array(
			'async'    => true,
			'status'   => $filtered['status'],
			'task_id'  => $filtered['task_id'],
			'job_id'   => $filtered['task_id'], // Alias for consistency with other async tools
			'message'  => __( 'Crawl job queued for background processing. Results will appear when ready.', 'wp-mcp-ai' ),
			'results'  => array(),
			'metadata' => $metadata,
			'raw'      => isset( $filtered['raw'] ) ? $filtered['raw'] : $formatted['raw'],
		);

		$queued = WP_MCP_AI_Crawler::register_remote_job(
			$filtered['task_id'],
			array(
				'base_url'       => $base_url,
				'arguments'      => $arguments,
				'context'        => $context,
				'poll_interval'  => $poll_interval,
				'wait_timeout'   => $wait_timeout,
				'status'         => $filtered['status'],
				'initial_result' => $pending_result,
				'raw_response'   => $decoded,
			)
		);

		if ( ! $queued ) {
			return new WP_Error( 'wp_mcp_ai_crawl4ai_queue_failed', __( 'Failed to queue the Crawl4AI job for background processing.', 'wp-mcp-ai' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'crawl4ai_response',
			'Crawl4AI request queued for background polling.',
			array(
				'status'  => $filtered['status'],
				'task_id' => $filtered['task_id'],
			)
		);

		return $pending_result;
	}

	/**
	 * Execute a crawl using the built-in WordPress HTTP client.
	 *
	 * @param array $payload   Prepared payload array.
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param array $settings  Plugin settings array.
	 * @return array|WP_Error
	 */
	protected function execute_local_crawl( array $payload, array $arguments, array $context, array $settings ) {
		$timeout      = $this->get_request_timeout( $settings );
		$results      = array();
		$errors       = array();
		$urls         = isset( $payload['urls'] ) ? (array) $payload['urls'] : array();
		$request_args = $this->build_local_request_args( $settings, $context, $arguments );

		foreach ( $urls as $url ) {
			// Wrap in try-catch to handle exceptions from wp_remote_get, build_local_result, or filters.
			try {
				$response = wp_remote_get(
					$url,
					array_merge(
						$request_args,
						array(
							'timeout' => $timeout,
						)
					)
				);

				if ( is_wp_error( $response ) ) {
					$errors[ $url ] = $response->get_error_message();
					WP_MCP_AI_Logger::log_error(
						'Crawl4AI local crawl failed.',
						array(
							'url'   => $url,
							'error' => $response->get_error_message(),
						)
					);
					continue;
				}

				$result    = $this->build_local_result( $url, $response, $payload, $settings, $context );
				$results[] = apply_filters( 'wp_mcp_ai_crawl4ai_local_result', $result, $response, $url, $settings, $context, $arguments );
			} catch ( Exception $e ) {
				// Handle any unexpected errors gracefully.
				$errors[ $url ] = $e->getMessage();
				WP_MCP_AI_Logger::log_error(
					'Crawl4AI local crawl exception.',
					array(
						'url'       => $url,
						'exception' => $e->getMessage(),
						'trace'     => $e->getTraceAsString(),
					)
				);
				continue;
			}
		}

		if ( empty( $results ) ) {
			$message = $this->build_local_failure_message( $errors );

			return new WP_Error(
				'wp_mcp_ai_crawl4ai_local_failed',
				$message,
				array( 'errors' => $errors )
			);
		}

		$metadata = array(
			'mode'       => 'local',
			'errors'     => $errors,
			'fetched_at' => current_time( 'mysql', true ),
		);

		if ( isset( $request_args['headers']['User-Agent'] ) ) {
			$metadata['user_agent'] = $request_args['headers']['User-Agent'];
		}

		if ( isset( $payload['priority'] ) ) {
			$metadata['priority'] = $payload['priority'];
		}

		$metadata = apply_filters( 'wp_mcp_ai_crawl4ai_local_metadata', $metadata, $payload, $context, $settings );

		// Generate a task ID for local jobs to enable tracking
		$task_id = $this->generate_task_id();

		$response = array(
			'status'   => 'completed',
			'task_id'  => $task_id,
			'results'  => $results,
			'metadata' => $metadata,
			'raw'      => array(
				'results'  => $results,
				'metadata' => $metadata,
			),
		);

		// Register the completed local job with the manager for tracking
		WP_MCP_AI_Crawler::register_completed_job(
			$task_id,
			array(
				'base_url'  => '', // Empty for local jobs
				'arguments' => $arguments,
				'context'   => $context,
				'status'    => 'completed',
				'result'    => $response,
			)
		);

		WP_MCP_AI_Logger::log_event(
			'crawl4ai_local_response',
			'Local Crawl4AI request completed.',
			array(
				'url_count' => count( $results ),
				'errors'    => $errors,
			)
		);

		$response = apply_filters( 'wp_mcp_ai_crawl4ai_local_response', $response, $payload, $arguments, $context, $settings );

		return $this->enforce_result_size_limits( $response );
	}

	/**
	 * Build a detailed error message when all local crawl attempts fail.
	 *
	 * @param array $errors Map of URL => error message pairs.
	 * @return string
	 */
	protected function build_local_failure_message( array $errors ) {
		$message = __( 'Unable to crawl the requested URLs.', 'wp-mcp-ai' );

		if ( empty( $errors ) ) {
			return $message;
		}

		if ( function_exists( 'array_key_first' ) ) {
			$first_url = array_key_first( $errors );
		} else {
			$keys      = array_keys( $errors );
			$first_url = reset( $keys );
		}

		$first_error = '';

		if ( null !== $first_url && isset( $errors[ $first_url ] ) ) {
			$first_error = $errors[ $first_url ];
		}

		$first_url   = is_scalar( $first_url ) ? trim( (string) $first_url ) : '';
		$first_error = is_scalar( $first_error ) ? trim( (string) $first_error ) : '';

		if ( $first_url && $first_error ) {
			return sprintf(
			/* translators: 1: URL that failed, 2: error message */
				__( 'Unable to crawl the requested URLs. Example: %1$s (%2$s).', 'wp-mcp-ai' ),
				$first_url,
				$first_error
			);
		}

		if ( $first_url ) {
			return sprintf(
			/* translators: %s: example URL that couldn't be crawled */
				__( 'Unable to crawl the requested URLs. Example URL: %s.', 'wp-mcp-ai' ),
				$first_url
			);
		}

		if ( $first_error ) {
			return sprintf(
			/* translators: %s: example error message */
				__( 'Unable to crawl the requested URLs. Example error: %s.', 'wp-mcp-ai' ),
				$first_error
			);
		}

		return $message;
	}

	/**
	 * Generate a unique identifier for local Crawl4AI tasks.
	 *
	 * @return string
	 */
	protected function generate_task_id() {
		// Generate a unique ID with timestamp and random component for better uniqueness
		$timestamp = time();
		$random    = wp_generate_password( 8, false, false );

		return 'local-' . $timestamp . '-' . strtolower( $random );
	}

	/**
	 * Sanitize crawl4ai results for LLM consumption.
	 *
	 * The crawl4ai tool already truncates content fields (markdown, text, html)
	 * within token limits. However, it includes a 'raw' field that duplicates
	 * the full untruncated results, which wastes context.
	 *
	 * This method strips:
	 * - 'raw' field (duplicate of truncated results)
	 * - 'html' field (redundant with markdown, much larger)
	 * - Verbose metadata (headers, user_agent, timestamps)
	 *
	 * Keeps:
	 * - 'markdown' and 'text' (already truncated, most efficient for LLM)
	 * - 'url', 'status_code', 'status' (essential metadata)
	 * - 'task_id' and 'job_id' (for status tracking)
	 * - 'async' (for UI async detection - CRITICAL)
	 * - 'message' (user-friendly status message)
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		$sanitized = $result;

		// Remove duplicate raw data.
		unset( $sanitized['raw'] );

		// Clean metadata.
		if ( isset( $sanitized['metadata'] ) && is_array( $sanitized['metadata'] ) ) {
			unset( $sanitized['metadata']['headers'] );
			unset( $sanitized['metadata']['user_agent'] );
			unset( $sanitized['metadata']['retrieved_at'] );
			unset( $sanitized['metadata']['fetched_at'] );
			unset( $sanitized['metadata']['queued_at'] );

			if ( empty( $sanitized['metadata'] ) ) {
				unset( $sanitized['metadata'] );
			}
		}

		// Recursively clean results array.
		if ( isset( $sanitized['results'] ) && is_array( $sanitized['results'] ) ) {
			$sanitized['results'] = array_map(
				function ( $item ) {
					if ( ! is_array( $item ) ) {
						return $item;
					}

					// Remove HTML field - it's redundant and much larger than markdown.
					unset( $item['html'] );

					// Clean individual result metadata.
					if ( isset( $item['metadata'] ) && is_array( $item['metadata'] ) ) {
						unset( $item['metadata']['headers'] );
						unset( $item['metadata']['user_agent'] );
						unset( $item['metadata']['retrieved_at'] );
						unset( $item['metadata']['fetched_at'] );
						unset( $item['metadata']['queued_at'] );

						if ( empty( $item['metadata'] ) ) {
							unset( $item['metadata'] );
						}
					}

					return $item;
				},
				$sanitized['results']
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize a string to ensure it contains only valid UTF-8 characters.
	 *
	 * Scraped content from external websites may contain invalid UTF-8 sequences
	 * that can cause wp_json_encode() to fail, corrupting SSE streams and
	 * causing HTTP2 protocol errors. This method removes or replaces invalid
	 * sequences to ensure the string is safe for JSON encoding.
	 *
	 * @param string $string String to sanitize.
	 * @return string Sanitized string with only valid UTF-8 characters.
	 */
	protected function sanitize_utf8( $string ) {
		// Return early for non-strings.
		if ( ! is_string( $string ) ) {
			return $string;
		}

		// Empty strings are always valid.
		if ( '' === $string ) {
			return '';
		}

		// Remove invalid UTF-8 sequences from the source string.
		// The iconv IGNORE flag skips any bytes that are not valid in the source encoding (UTF-8),
		// effectively removing malformed UTF-8 sequences while preserving valid characters.
		$sanitized = iconv( 'UTF-8', 'UTF-8//IGNORE', $string );

		// If iconv failed (returned false), fall back to mb_convert_encoding.
		if ( false === $sanitized && function_exists( 'mb_convert_encoding' ) ) {
			$sanitized = mb_convert_encoding( $string, 'UTF-8', 'UTF-8' );
		}

		// If both methods failed, use preg_replace to remove common problematic control characters.
		// This targets specific control characters (null bytes, form feed, etc.) that often cause issues.
		if ( false === $sanitized || '' === $sanitized ) {
			$sanitized = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $string );
		}

		// Final fallback: if still invalid, return empty string.
		if ( false === $sanitized ) {
			return '';
		}

		return $sanitized;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external HTTP requests to Crawl4AI service.
			'requires-capability',  // Requires user capabilities.
			'network-dependent',    // Requires connectivity to Crawl4AI service.
			'long-running',         // Web crawling can take minutes.
			'may-timeout',          // Crawling multiple pages can exceed PHP timeout.
			'async-capable',        // Can execute asynchronously via new cron system.
			'background-preferred', // Should default to background execution for large jobs.
		);
	}
}
