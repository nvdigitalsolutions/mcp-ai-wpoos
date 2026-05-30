<?php
/**
 * Capture Webpage Screenshot Tool (Pro).
 *
 * Captures a visual screenshot of any public URL. Supports configurable
 * viewports (desktop, tablet, mobile), full-page and viewport-only modes,
 * PNG and JPEG output, and optional save-to-media-library.
 *
 * Architecture:
 * - Primary:  Remote Playwright service (same endpoint as web_browser tool).
 * - Fallback: WordPress.com mshots service (no API key, always available).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.3.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture Webpage Screenshot tool.
 *
 * Provides a dedicated, focused screenshot interface on top of the existing
 * Playwright service used by web_browser.  When the service is unavailable the
 * tool degrades gracefully to the public WordPress.com mshots thumbnail API.
 *
 * Industry-standard design follows MCP screenshot tool patterns:
 * - Single-purpose: screenshot only (no navigation, forms, etc.)
 * - Viewport presets aligned with real-world device dimensions.
 * - Media-library integration for persistent asset storage.
 * - Security: blocks internal / private IPs (SSRF prevention).
 *
 * @since 1.3.5
 */
class WP_MCP_AI_Tool_Capture_Webpage_Screenshot implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default operation timeout in seconds.
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Maximum operation timeout in seconds.
	 */
	const MAX_TIMEOUT = 60;

	/**
	 * Maximum screenshots per hour per user (rate limit).
	 */
	const MAX_PER_HOUR = 30;

	/**
	 * Predefined viewport dimensions (width × height in pixels).
	 */
	const VIEWPORTS = array(
		'desktop'          => array( 1920, 1080 ),
		'laptop'           => array( 1280, 800 ),
		'tablet'           => array( 768, 1024 ),
		'mobile_portrait'  => array( 375, 667 ),
		'mobile_landscape' => array( 667, 375 ),
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'capture_webpage_screenshot';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Capture Webpage Screenshot', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Capture a screenshot of any public web page. Supports desktop, tablet, and mobile viewports, full-page or viewport-only capture, PNG/JPEG output, and optional save to the WordPress media library. Uses a Playwright service when configured, with automatic fallback to the WordPress.com mshots thumbnail API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'           => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'The public URL of the page to screenshot.', 'mcp-ai-wpoos-pro' ),
				),
				'viewport'      => array(
					'type'        => 'string',
					'enum'        => array( 'desktop', 'laptop', 'tablet', 'mobile_portrait', 'mobile_landscape', 'custom' ),
					'default'     => 'desktop',
					'description' => __( 'Viewport preset. Use "custom" together with width/height to set exact dimensions.', 'mcp-ai-wpoos-pro' ),
				),
				'width'         => array(
					'type'        => 'integer',
					'minimum'     => 320,
					'maximum'     => 3840,
					'description' => __( 'Viewport width in pixels. Only used when viewport is "custom".', 'mcp-ai-wpoos-pro' ),
				),
				'height'        => array(
					'type'        => 'integer',
					'minimum'     => 240,
					'maximum'     => 2160,
					'description' => __( 'Viewport height in pixels. Only used when viewport is "custom".', 'mcp-ai-wpoos-pro' ),
				),
				'full_page'     => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Capture the full scrollable page. When false, captures only the visible viewport.', 'mcp-ai-wpoos-pro' ),
				),
				'format'        => array(
					'type'        => 'string',
					'enum'        => array( 'png', 'jpeg' ),
					'default'     => 'png',
					'description' => __( 'Image format for the screenshot.', 'mcp-ai-wpoos-pro' ),
				),
				'quality'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 90,
					'description' => __( 'JPEG quality (1–100). Ignored for PNG.', 'mcp-ai-wpoos-pro' ),
				),
				'save_to_media' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Save the screenshot to the WordPress media library and return the attachment ID and URL.', 'mcp-ai-wpoos-pro' ),
				),
				'timeout'       => array(
					'type'        => 'integer',
					'minimum'     => 5,
					'maximum'     => self::MAX_TIMEOUT,
					'default'     => self::DEFAULT_TIMEOUT,
					'description' => __( 'Operation timeout in seconds (5–60).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'url' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-capability',  // Requires manage_options capability.
			'read-only',            // Does not modify the target site.
			'external-api',         // Makes external HTTP requests.
			'rate-limited',         // Subject to rate limits.
			'may-timeout',          // Operations can be slow.
			'network-dependent',    // Requires internet connectivity.
			'non-deterministic',    // Results may vary.
			'pro-only',             // Pro addon feature.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check whether the tool is available.
	 *
	 * The tool is always available: even without a Playwright service the
	 * mshots fallback provides basic screenshot functionality.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Capability check — same requirement as web_browser for consistency.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to capture webpage screenshots.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate URL.
		$url = isset( $arguments['url'] ) ? esc_url_raw( trim( $arguments['url'] ) ) : '';
		if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_url',
				__( 'A valid public URL is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Block internal / private addresses to prevent SSRF.
		if ( $this->is_internal_url( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden_url',
				__( 'Access to internal or private URLs is not allowed for security reasons.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Rate limiting.
		$rate_limit = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		// Resolve viewport dimensions.
		list( $width, $height ) = $this->resolve_viewport( $arguments );

		$full_page  = isset( $arguments['full_page'] ) ? (bool) $arguments['full_page'] : true;
		$format     = isset( $arguments['format'] ) && 'jpeg' === $arguments['format'] ? 'jpeg' : 'png';
		$quality    = isset( $arguments['quality'] ) ? absint( $arguments['quality'] ) : 90;
		$quality    = max( 1, min( 100, $quality ) );
		$timeout    = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : self::DEFAULT_TIMEOUT;
		$timeout    = max( 5, min( self::MAX_TIMEOUT, $timeout ) );
		$save_media = isset( $arguments['save_to_media'] ) ? (bool) $arguments['save_to_media'] : false;

		// Attempt Playwright service first.
		$settings    = WP_MCP_AI_Admin_Settings::get_settings();
		$service_url = $this->resolve_playwright_url( $settings );

		if ( ! empty( $service_url ) ) {
			$result = $this->capture_via_playwright(
				$url,
				$service_url,
				$width,
				$height,
				$full_page,
				$format,
				$quality,
				$timeout
			);
		} else {
			$result = $this->capture_via_mshots( $url, $width, $height, $timeout );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Optionally save to media library.
		if ( $save_media ) {
			$attachment = $this->save_to_media_library( $result, $url, $format );
			if ( ! is_wp_error( $attachment ) ) {
				$result['attachment_id']  = $attachment['id'];
				$result['attachment_url'] = $attachment['url'];
			}
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Viewport resolution.
	// -------------------------------------------------------------------------

	/**
	 * Resolve viewport dimensions from arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return int[] Array with two elements: [width, height].
	 */
	protected function resolve_viewport( array $arguments ) {
		$preset = isset( $arguments['viewport'] ) ? sanitize_key( $arguments['viewport'] ) : 'desktop';

		if ( 'custom' === $preset ) {
			$w = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : 1920;
			$h = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : 1080;
			$w = max( 320, min( 3840, $w ) );
			$h = max( 240, min( 2160, $h ) );
			return array( $w, $h );
		}

		if ( isset( self::VIEWPORTS[ $preset ] ) ) {
			return self::VIEWPORTS[ $preset ];
		}

		// Default to desktop.
		return self::VIEWPORTS['desktop'];
	}

	// -------------------------------------------------------------------------
	// Playwright service.
	// -------------------------------------------------------------------------

	/**
	 * Resolve the Playwright service URL from settings.
	 *
	 * Reuses the same option that web_browser uses so there is a single place
	 * to configure the service.
	 *
	 * @param array $settings Plugin settings.
	 * @return string Empty string if not configured.
	 */
	protected function resolve_playwright_url( array $settings ) {
		return isset( $settings['playwright_service_url'] )
			? rtrim( (string) $settings['playwright_service_url'], '/' )
			: '';
	}

	/**
	 * Capture screenshot via the configured Playwright service.
	 *
	 * @param string $url         Target URL.
	 * @param string $service_url Playwright service base URL.
	 * @param int    $width       Viewport width.
	 * @param int    $height      Viewport height.
	 * @param bool   $full_page   Capture full scrollable page.
	 * @param string $format      Image format (png|jpeg).
	 * @param int    $quality     JPEG quality.
	 * @param int    $timeout     Timeout in seconds.
	 * @return array|WP_Error
	 */
	protected function capture_via_playwright( $url, $service_url, $width, $height, $full_page, $format, $quality, $timeout ) {
		$payload = array(
			'url'                => $url,
			'action'             => 'screenshot',
			'screenshot_options' => array(
				'full_page' => $full_page,
				'type'      => $format,
				'quality'   => $quality,
			),
			'viewport'           => array(
				'width'  => $width,
				'height' => $height,
			),
			'timeout'            => $timeout * 1000, // Service expects milliseconds.
		);

		$endpoint     = trailingslashit( $service_url ) . 'api/browser';
		$http_timeout = $timeout + 10; // Add buffer for network overhead.

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $http_timeout,
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

		$status = wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			return new WP_Error(
				'wp_mcp_ai_service_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Playwright service returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$status
				)
			);
		}

		$data = json_decode( $body, true );
		if ( null === $data ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid JSON response from Playwright service.', 'mcp-ai-wpoos-pro' )
			);
		}

		$data['mode']         = 'playwright_service';
		$data['service_url']  = $service_url;
		$data['viewport']     = array(
			'width'  => $width,
			'height' => $height,
		);
		$data['full_page']    = $full_page;
		$data['format']       = $format;
		$data['captured_url'] = $url;

		return $data;
	}

	// -------------------------------------------------------------------------
	// mshots fallback.
	// -------------------------------------------------------------------------

	/**
	 * Capture screenshot via the public WordPress.com mshots service.
	 *
	 * The mshots API is free, requires no API key, and is the same service
	 * that WordPress.com uses for site thumbnails.
	 *
	 * Endpoint: https://s0.wp.com/mshots/v1/{encoded_url}?w={width}&h={height}
	 *
	 * @param string $url     Target URL.
	 * @param int    $width   Requested width.
	 * @param int    $height  Requested height.
	 * @param int    $timeout HTTP timeout.
	 * @return array|WP_Error
	 */
	protected function capture_via_mshots( $url, $width, $height, $timeout ) {
		$mshots_url = add_query_arg(
			array(
				'w' => $width,
				'h' => $height,
			),
			'https://s0.wp.com/mshots/v1/' . rawurlencode( $url )
		);

		/**
		 * Filters the mshots screenshot URL before the request is made.
		 *
		 * @since 1.3.5
		 *
		 * @param string $mshots_url  The mshots API URL with query parameters.
		 * @param string $url         The original target URL.
		 * @param int    $width       Requested viewport width.
		 * @param int    $height      Requested viewport height.
		 */
		$mshots_url = apply_filters( 'wp_mcp_ai_capture_screenshot_mshots_url', $mshots_url, $url, $width, $height );

		$response = wp_remote_get(
			$mshots_url,
			array(
				'timeout'     => $timeout,
				'headers'     => array(
					'Accept' => 'image/png,image/jpeg,image/*',
				),
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_screenshot_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Screenshot capture failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status       = wp_remote_retrieve_response_code( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$image_data   = wp_remote_retrieve_body( $response );

		// mshots returns a placeholder on the first request while it queues the.
		// real screenshot. A very small response body (<10 KB) usually means a.
		// placeholder PNG was returned. We still return success; the caller can.
		// retry or use the mshots_url directly.
		$is_placeholder = strlen( $image_data ) < 10240;

		if ( 200 !== $status || empty( $image_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_screenshot_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'mshots service returned HTTP %d. The page may not be publicly accessible.', 'mcp-ai-wpoos-pro' ),
					$status
				)
			);
		}

		return array(
			'success'           => true,
			'mode'              => 'mshots_fallback',
			'captured_url'      => $url,
			'mshots_url'        => $mshots_url,
			'format'            => str_contains( $content_type, 'jpeg' ) ? 'jpeg' : 'png',
			'viewport'          => array(
				'width'  => $width,
				'height' => $height,
			),
			'full_page'         => false, // mshots captures viewport only.
			'image_data_base64' => base64_encode( $image_data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'image_size_bytes'  => strlen( $image_data ),
			'is_placeholder'    => $is_placeholder,
			'note'              => $is_placeholder
				? __( 'mshots returned a placeholder image. The real screenshot may take a few seconds to generate. Re-run the tool or use the mshots_url directly.', 'mcp-ai-wpoos-pro' )
				: null,
		);
	}

	// -------------------------------------------------------------------------
	// Media library integration.
	// -------------------------------------------------------------------------

	/**
	 * Save a screenshot to the WordPress media library.
	 *
	 * Handles both base64-encoded image data (from mshots fallback or Playwright)
	 * and raw image data via a URL returned by the Playwright service.
	 *
	 * @param array  $result Screenshot result array.
	 * @param string $url    Original captured URL.
	 * @param string $format Image format (png|jpeg).
	 * @return array|WP_Error Associative array with 'id' and 'url', or WP_Error.
	 */
	protected function save_to_media_library( array $result, $url, $format ) {
		// Require media-handling functions.
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'wp_check_filetype' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Decode image data.
		$image_data = null;

		if ( ! empty( $result['image_data_base64'] ) ) {
			$image_data = base64_decode( $result['image_data_base64'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		} elseif ( ! empty( $result['screenshot_url'] ) ) {
			// Some Playwright responses return a URL to a temporary file.
			$fetch = wp_remote_get(
				$result['screenshot_url'],
				array(
					'timeout' => 15,
				)
			);
			if ( ! is_wp_error( $fetch ) && 200 === wp_remote_retrieve_response_code( $fetch ) ) {
				$image_data = wp_remote_retrieve_body( $fetch );
			}
		}

		if ( empty( $image_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_image_data',
				__( 'No image data available to save to media library.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build a descriptive filename from the URL hostname.
		$parsed   = wp_parse_url( $url );
		$hostname = isset( $parsed['host'] ) ? sanitize_file_name( $parsed['host'] ) : 'screenshot';
		$ext      = 'jpeg' === $format ? 'jpg' : 'png';
		$filename = sprintf( 'screenshot-%s-%s.%s', $hostname, gmdate( 'YmdHis' ), $ext );

		// Write to a temporary file.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_upload_dir_error',
				$upload_dir['error']
			);
		}

		$temp_file = $upload_dir['path'] . '/' . $filename;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $temp_file, $image_data ) ) {
			return new WP_Error(
				'wp_mcp_ai_write_failed',
				__( 'Failed to write screenshot to upload directory.', 'mcp-ai-wpoos-pro' )
			);
		}

		$mime      = 'jpeg' === $format ? 'image/jpeg' : 'image/png';
		$file_type = wp_check_filetype(
			$filename,
			array(
				'png' => 'image/png',
				'jpg' => 'image/jpeg',
			)
		);

		$attachment = array(
			'post_mime_type' => $file_type['type'] ? $file_type['type'] : $mime,
			'post_title'     => sprintf(
				/* translators: %s: URL hostname */
				__( 'Screenshot of %s', 'mcp-ai-wpoos-pro' ),
				esc_html( $hostname )
			),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $temp_file );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $temp_file );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return array(
			'id'  => $attachment_id,
			'url' => wp_get_attachment_url( $attachment_id ),
		);
	}

	// -------------------------------------------------------------------------
	// Security helpers.
	// -------------------------------------------------------------------------

	/**
	 * Check whether a URL resolves to an internal / private address.
	 *
	 * Prevents SSRF attacks by blocking localhost, loopback, and RFC-1918 ranges.
	 *
	 * Note: gethostbyname() only resolves IPv4. IPv6 link-local (fe80::/10) and
	 * loopback (::1) are blocked via the explicit host-list check below. Full
	 * dual-stack SSRF protection would require dns_get_record() with DNS_AAAA,
	 * which is deferred to a future hardening pass for parity with web_browser.
	 *
	 * @param string $url URL to check.
	 * @return bool True if the URL should be blocked.
	 */
	protected function is_internal_url( $url ) {
		$parsed = wp_parse_url( $url );

		if ( ! isset( $parsed['host'] ) ) {
			return true;
		}

		$host = strtolower( $parsed['host'] );

		// Block localhost and well-known internal addresses (including IPv6 loopback.
		// and link-local prefix detected as a literal bracket-stripped string).
		$blocked_literals = array(
			'localhost',
			'127.0.0.1',
			'::1',
			'[::1]',
			'0.0.0.0',
			'fe80',   // Matches fe80::* link-local IPv6 prefix literal check below.
		);
		if ( in_array( $host, $blocked_literals, true ) ) {
			return true;
		}

		// Block IPv6 link-local addresses (fe80::/10) supplied as literals.
		if ( str_starts_with( $host, 'fe80' ) || str_starts_with( $host, '[fe80' ) ) {
			return true;
		}

		// Resolve to IPv4 (catches DNS-rebinding SSRF for A records).
		$resolved = gethostbyname( $host );

		// If gethostbyname returned the hostname unchanged, DNS resolution failed.
		if ( $resolved === $host && false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		// Block private / reserved IPv4 ranges.
		if ( false === filter_var( $resolved, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Rate limiting.
	// -------------------------------------------------------------------------

	/**
	 * Enforce a per-user hourly rate limit.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return true|WP_Error True when within limits, WP_Error when exceeded.
	 */
	protected function check_rate_limit( $user_id ) {
		$key     = 'wp_mcp_ai_capture_screenshot_' . $user_id;
		$current = get_transient( $key );

		/**
		 * Filters the maximum screenshots allowed per hour per user.
		 *
		 * @since 1.3.5
		 *
		 * @param int $max_per_hour Default maximum (30).
		 * @param int $user_id      User ID.
		 */
		$max = apply_filters( 'wp_mcp_ai_capture_screenshot_rate_limit', self::MAX_PER_HOUR, $user_id );

		if ( false === $current ) {
			set_transient( $key, 1, HOUR_IN_SECONDS );
			return true;
		}

		if ( $current >= $max ) {
			return new WP_Error(
				'wp_mcp_ai_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum screenshots allowed per hour */
					__( 'Screenshot rate limit exceeded. Maximum %d screenshots per hour allowed.', 'mcp-ai-wpoos-pro' ),
					$max
				)
			);
		}

		set_transient( $key, $current + 1, HOUR_IN_SECONDS );
		return true;
	}
}
