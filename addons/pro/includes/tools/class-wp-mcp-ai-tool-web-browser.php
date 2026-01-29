<?php
/**
 * Web Browser Automation Tool (Pro).
 *
 * Provides browser automation capabilities using Playwright with support for:
 * - External Playwright service (primary mode)
 * - Local HTTP fallback (when service not configured)
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Web Browser Automation tool with Playwright integration.
 *
 * This tool provides advanced browser automation capabilities including:
 * - JavaScript execution and rendering
 * - Screenshot capture
 * - PDF generation
 * - Form interaction
 * - Dynamic content extraction
 *
 * Architecture follows the Crawl4AI pattern:
 * - Primary: External Playwright service (Node.js + Express)
 * - Fallback: Simple HTTP fetch (when service not configured)
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Web_Browser implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for browser operations in seconds.
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Maximum timeout allowed in seconds.
	 */
	const MAX_TIMEOUT = 60;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'web_browser';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Web Browser Automation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Automate web browsers to navigate JavaScript-heavy sites, take screenshots, generate PDFs, fill forms, and extract dynamic content. Supports both remote Playwright service and local HTTP fallback.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'                => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'The URL to navigate to.', 'mcp-ai-wpoos-pro' ),
				),
				'action'             => array(
					'type'        => 'string',
					'enum'        => array( 'navigate', 'screenshot', 'pdf', 'extract', 'click', 'type', 'submit' ),
					'description' => __( 'The action to perform.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'navigate',
				),
				'selector'           => array(
					'type'        => 'string',
					'description' => __( 'CSS selector for element-specific actions (click, type, extract).', 'mcp-ai-wpoos-pro' ),
				),
				'text'               => array(
					'type'        => 'string',
					'description' => __( 'Text to type into an element (for type action).', 'mcp-ai-wpoos-pro' ),
				),
				'wait_for'           => array(
					'type'        => 'string',
					'enum'        => array( 'load', 'domcontentloaded', 'networkidle' ),
					'description' => __( 'When to consider navigation complete.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'load',
				),
				'timeout'            => array(
					'type'        => 'integer',
					'minimum'     => 5,
					'maximum'     => self::MAX_TIMEOUT,
					'description' => __( 'Operation timeout in seconds.', 'mcp-ai-wpoos-pro' ),
					'default'     => self::DEFAULT_TIMEOUT,
				),
				'screenshot_options' => array(
					'type'        => 'object',
					'description' => __( 'Screenshot configuration (for screenshot action).', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'full_page' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'type'      => array(
							'type'    => 'string',
							'enum'    => array( 'png', 'jpeg' ),
							'default' => 'png',
						),
					),
				),
				'pdf_options'        => array(
					'type'        => 'object',
					'description' => __( 'PDF generation configuration (for pdf action).', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'format'    => array(
							'type'    => 'string',
							'enum'    => array( 'A4', 'Letter', 'Legal' ),
							'default' => 'A4',
						),
						'landscape' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				'extract_content'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to extract page content after navigation.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'url', 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Determine whether the Web Browser tool is available.
	 *
	 * Available when either:
	 * - Remote Playwright service is configured, OR
	 * - Local fallback is enabled (default)
	 *
	 * @return bool
	 */
	public static function is_available() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$base_url = self::resolve_service_url( $settings );

		// If remote service is configured, tool is available.
		if ( '' !== $base_url ) {
			return true;
		}

		/**
		 * Filters whether the local HTTP fallback should be enabled.
		 *
		 * When true (default), the tool uses simple HTTP fetch when no
		 * remote Playwright service is configured. When false, the tool
		 * requires a remote service.
		 *
		 * @since 1.1.0
		 *
		 * @param bool  $enabled  Whether local fallback is enabled. Default true.
		 * @param array $settings Plugin settings array.
		 */
		$local_enabled = apply_filters( 'wp_mcp_ai_web_browser_local_enabled', true, $settings );

		return (bool) $local_enabled;
	}

	/**
	 * Get message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The Web Browser tool is disabled. Configure a Playwright service URL or enable local fallback.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Resolve the Playwright service URL.
	 *
	 * @param array $settings Plugin settings array.
	 * @param array $context  Optional execution context.
	 * @return string Empty string if not configured.
	 */
	protected static function resolve_service_url( array $settings, array $context = array() ) {
		$service_url = '';

		if ( isset( $settings['playwright_service_url'] ) ) {
			$service_url = (string) $settings['playwright_service_url'];
		}

		/**
		 * Filters the Playwright service URL.
		 *
		 * Allows dynamic configuration (e.g., from environment variables).
		 *
		 * @since 1.1.0
		 *
		 * @param string $service_url Service URL from settings.
		 * @param array  $settings    Plugin settings array.
		 * @param array  $context     Execution context.
		 */
		$service_url = apply_filters( 'wp_mcp_ai_playwright_service_url', $service_url, $settings, $context );

		if ( ! is_string( $service_url ) ) {
			return '';
		}

		$sanitized = esc_url_raw( trim( $service_url ) );

		if ( ! $sanitized ) {
			return '';
		}

		return untrailingslashit( $sanitized );
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
			return new WP_Error(
				'wp_mcp_ai_web_browser_unavailable',
				__( 'Web Browser automation is not available on this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check user permissions - requires manage_options for security.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use browser automation.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate URL.
		$url = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : '';
		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_url',
				__( 'A valid URL is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Security: Block access to internal IPs and localhost.
		if ( $this->is_internal_url( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden_url',
				__( 'Access to internal URLs is not allowed for security reasons.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check rate limiting.
		$rate_limit_check = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		// Get action and validate.
		$action        = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'navigate';
		$valid_actions = array( 'navigate', 'screenshot', 'pdf', 'extract', 'click', 'type', 'submit' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			$action = 'navigate';
		}

		// Check if remote service is configured.
		$settings    = WP_MCP_AI_Admin_Settings::get_settings();
		$service_url = self::resolve_service_url( $settings, $context );

		if ( ! empty( $service_url ) ) {
			// Use remote Playwright service.
			return $this->execute_with_playwright_service( $url, $action, $arguments, $service_url );
		} else {
			// Fallback to local HTTP fetch.
			return $this->execute_with_local_fallback( $url, $action, $arguments );
		}
	}

	/**
	 * Execute using remote Playwright service.
	 *
	 * @param string $url         Target URL.
	 * @param string $action      Action to perform.
	 * @param array  $arguments   Full arguments array.
	 * @param string $service_url Playwright service URL.
	 * @return array|WP_Error
	 */
	protected function execute_with_playwright_service( $url, $action, $arguments, $service_url ) {
		// Build request payload for Playwright service.
		$payload = array(
			'url'      => $url,
			'action'   => $action,
			'wait_for' => isset( $arguments['wait_for'] ) ? $arguments['wait_for'] : 'load',
			'timeout'  => isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) * 1000 : self::DEFAULT_TIMEOUT * 1000, // Convert to ms.
		);

		// Add action-specific parameters.
		switch ( $action ) {
			case 'screenshot':
				$payload['screenshot_options'] = isset( $arguments['screenshot_options'] ) ? $arguments['screenshot_options'] : array(
					'full_page' => true,
					'type'      => 'png',
				);
				break;

			case 'pdf':
				$payload['pdf_options'] = isset( $arguments['pdf_options'] ) ? $arguments['pdf_options'] : array(
					'format'    => 'A4',
					'landscape' => false,
				);
				break;

			case 'click':
			case 'extract':
				if ( isset( $arguments['selector'] ) ) {
					$payload['selector'] = sanitize_text_field( $arguments['selector'] );
				}
				break;

			case 'type':
				if ( isset( $arguments['selector'] ) ) {
					$payload['selector'] = sanitize_text_field( $arguments['selector'] );
				}
				if ( isset( $arguments['text'] ) ) {
					$payload['text'] = sanitize_text_field( $arguments['text'] );
				}
				break;
		}

		// Determine endpoint based on action.
		$endpoint = '/api/browser';

		$request_url = trailingslashit( $service_url ) . ltrim( $endpoint, '/' );

		// Make request to Playwright service.
		$response = wp_remote_post(
			$request_url,
			array(
				'timeout' => absint( $payload['timeout'] ) / 1000 + 10, // Add 10s buffer.
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_service_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Playwright service request failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_service_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Playwright service returned error status: %d', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
		}

		$result = json_decode( $body, true );

		if ( null === $result ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from Playwright service.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Add metadata.
		$result['mode']        = 'playwright_service';
		$result['service_url'] = $service_url;

		return $result;
	}

	/**
	 * Execute using local HTTP fallback.
	 *
	 * Provides basic functionality when no Playwright service is configured.
	 * Limited to simple HTTP fetch - no JavaScript execution, screenshots, etc.
	 *
	 * @param string $url       Target URL.
	 * @param string $action    Action to perform.
	 * @param array  $arguments Full arguments array.
	 * @return array|WP_Error
	 */
	protected function execute_with_local_fallback( $url, $action, $arguments ) {
		// Local fallback only supports navigate and extract actions.
		if ( ! in_array( $action, array( 'navigate', 'extract' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_unsupported_action',
				sprintf(
					/* translators: %s: action name */
					__( 'Action "%s" requires a Playwright service. Only navigate and extract are available in local fallback mode.', 'mcp-ai-wpoos-pro' ),
					$action
				)
			);
		}

		$timeout = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : self::DEFAULT_TIMEOUT;

		// Simple HTTP fetch.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => $timeout,
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
				'headers'    => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_fetch_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to fetch URL: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP request failed with status: %d', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
		}

		$html = wp_remote_retrieve_body( $response );

		// Extract text content if requested.
		$text_content = '';
		if ( isset( $arguments['extract_content'] ) && $arguments['extract_content'] ) {
			$text_content = $this->extract_text_from_html( $html );
		}

		return array(
			'success'      => true,
			'url'          => $url,
			'action'       => $action,
			'mode'         => 'local_fallback',
			'status_code'  => $status_code,
			'html'         => $html,
			'text_content' => $text_content,
			'note'         => __( 'Local fallback mode: Limited to simple HTTP fetch. For full browser automation (JavaScript, screenshots, PDFs), configure a Playwright service URL in settings.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Extract plain text from HTML.
	 *
	 * @param string $html HTML content.
	 * @return string Extracted text.
	 */
	protected function extract_text_from_html( $html ) {
		// Remove script and style tags.
		$html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $html );

		// Strip all HTML tags.
		$text = wp_strip_all_tags( $html );

		// Normalize whitespace.
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		// Limit to reasonable length.
		if ( strlen( $text ) > 10000 ) {
			$text = substr( $text, 0, 10000 ) . '...';
		}

		return $text;
	}

	/**
	 * Check if URL is internal (localhost, private IPs, etc.).
	 *
	 * @param string $url URL to check.
	 * @return bool True if internal, false otherwise.
	 */
	protected function is_internal_url( $url ) {
		$parsed = wp_parse_url( $url );

		if ( ! isset( $parsed['host'] ) ) {
			return true; // Invalid URL, block it.
		}

		$host = strtolower( $parsed['host'] );

		// Block localhost variants.
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return true;
		}

		// Block private IP ranges.
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
			return true;
		}

		return false;
	}

	/**
	 * Check rate limiting for browser automation.
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error True if allowed, WP_Error if rate limited.
	 */
	protected function check_rate_limit( $user_id ) {
		$transient_key = 'wp_mcp_ai_web_browser_' . $user_id;
		$current_count = get_transient( $transient_key );
		$max_per_hour  = 20; // Allow up to 20 browser actions per hour.

		/**
		 * Filter the maximum browser actions allowed per hour per user.
		 *
		 * @since 1.1.0
		 *
		 * @param int $max_per_hour Maximum actions per hour (default: 20).
		 * @param int $user_id      User ID.
		 */
		$max_per_hour = apply_filters( 'wp_mcp_ai_web_browser_rate_limit', $max_per_hour, $user_id );

		if ( false === $current_count ) {
			// First action, start counting.
			set_transient( $transient_key, 1, HOUR_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $max_per_hour ) {
			return new WP_Error(
				'wp_mcp_ai_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum actions allowed per hour */
					__( 'Browser automation rate limit exceeded. Maximum %d actions per hour allowed.', 'mcp-ai-wpoos-pro' ),
					$max_per_hour
				)
			);
		}

		// Increment counter.
		set_transient( $transient_key, $current_count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials',  // May need Playwright service credentials.
			'requires-capability',   // Requires manage_options capability.
			'read-only',            // Only reads data, doesn't modify external sites.
			'external-api',         // Makes external HTTP requests.
			'rate-limited',         // Subject to rate limits.
			'may-timeout',          // Operations can be slow (15-60s).
			'async',                // Long-running operations.
			'resource-intensive',   // High resource usage (especially remote service).
			'network-dependent',    // Requires internet connectivity.
			'non-deterministic',    // Results may vary.
			'pro-only',             // Pro addon feature.
		);
	}
}
