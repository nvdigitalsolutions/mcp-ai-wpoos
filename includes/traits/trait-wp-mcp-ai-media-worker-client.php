<?php
/**
 * Media Worker HTTP Client Trait
 *
 * Provides a shared HTTP client for communicating with the optional
 * Design Stack Media Worker sidecar. When the sidecar is available,
 * heavy NPM-package-dependent operations are offloaded via HTTP.
 * When unavailable, the existing filter-based and node-services/
 * subprocess mechanisms continue to work unchanged.
 *
 * This trait is designed to be mixed into service classes that currently
 * depend on NPM packages (Prettier, FFmpeg, MJML, OCR, Nodemailer, etc.)
 * to add a zero-config, opt-in sidecar acceleration layer.
 *
 * ## Usage (in a service class):
 *
 * ```php
 * class WP_MCP_AI_Prettier_Service {
 *     use WP_MCP_AI_Media_Worker_Client;
 *
 *     public function format_code( $code, $options = [] ) {
 *         // 1. Try the Media Worker sidecar (preferred when a URL is
 *         //    configured — fails fast when it is not).
 *         $result = $this->sidecar_request( '/api/code/format', $params );
 *         if ( ! is_wp_error( $result ) ) return $result;
 *
 *         // 2. Try existing filters (backward compatibility / custom
 *         //    implementations; the bundled legacy handlers only run
 *         //    local Node.js when a local node binary exists).
 *         $result = apply_filters( 'wp_mcp_ai_prettier_format_code', false, $params );
 *         if ( false !== $result ) return $result;
 *
 *         // 3. Fall back to local Node.js (existing behavior)
 *         if ( $this->is_available() ) return $this->execute_locally( $params );
 *
 *         // 4. Ultimate fallback
 *         return new WP_Error( 501, 'Configure Node.js or the Media Worker sidecar.' );
 *     }
 * }
 * ```
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait WP_MCP_AI_Media_Worker_Client {

	/**
	 * Cached sidecar availability flag.
	 *
	 * @var bool|null
	 */
	private $sidecar_available = null;

	/**
	 * Send a request to the Media Worker sidecar.
	 *
	 * Returns a WP_Error if the sidecar is unavailable, the request fails,
	 * or the response indicates an error. Returns the decoded JSON body
	 * on success.
	 *
	 * @param string $endpoint API path (e.g., '/api/code/format').
	 * @param array  $body     Request payload.
	 * @param array  $options  Optional overrides.
	 *                         - timeout: int (default: 30 for sync, 60 for async)
	 *                         - method: string (default: 'POST').
	 * @return array|WP_Error Decoded response body or error.
	 */
	protected function sidecar_request( $endpoint, array $body = array(), array $options = array() ) {
		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_not_configured',
				__( 'Media Worker sidecar URL is not configured.', 'mcp-ai-wpoos' )
			);
		}

		$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30;
		$method  = isset( $options['method'] ) ? strtoupper( $options['method'] ) : 'POST';

		$request_url = rtrim( $url, '/' ) . '/' . ltrim( $endpoint, '/' );

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-Site-Token' => $this->get_sidecar_token(),
				'X-Site-Url'   => home_url(),
			),
		);

		if ( 'GET' !== $method && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		/**
		 * Filter: modify the sidecar request arguments before sending.
		 *
		 * @param array  $args     HTTP request args (method, timeout, headers, body).
		 * @param string $endpoint The API endpoint path.
		 * @param array  $body     The request payload.
		 */
		$args = apply_filters( 'wp_mcp_ai_sidecar_request_args', $args, $endpoint, $body );

		$response = wp_remote_request( $request_url, $args );

		if ( is_wp_error( $response ) ) {
			$this->sidecar_available = false;
			return $response;
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( 200 !== $status && 202 !== $status ) {
			$error_msg = isset( $decoded['error'] )
				? $decoded['error']
				: sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 200 ) );

			return new WP_Error(
				'wp_mcp_ai_sidecar_error',
				$error_msg,
				array(
					'status'   => $status,
					'response' => $decoded,
				)
			);
		}

		$this->sidecar_available = true;

		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_invalid_json',
				__( 'Media Worker returned invalid JSON.', 'mcp-ai-wpoos' )
			);
		}

		return $decoded;
	}

	/**
	 * Check whether the Media Worker sidecar is reachable.
	 *
	 * Caches the result for the duration of the request to avoid
	 * repeated HTTP calls. Returns false if the URL is not configured.
	 *
	 * @return bool True if the sidecar responded to /api/health.
	 */
	protected function is_sidecar_available() {
		if ( null !== $this->sidecar_available ) {
			return $this->sidecar_available;
		}

		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			$this->sidecar_available = false;
			return false;
		}

		$response = wp_remote_get(
			rtrim( $url, '/' ) . '/api/health',
			array( 'timeout' => 3 )
		);

		if ( is_wp_error( $response ) ) {
			$this->sidecar_available = false;
			return false;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		$this->sidecar_available = ( 200 === $status && isset( $body['status'] ) && 'ok' === $body['status'] );
		return $this->sidecar_available;
	}

	/**
	 * Get the sidecar URL from the WordPress constant or option.
	 *
	 * Priority:
	 *   1. WP_MEDIA_WORKER_URL constant (set in wp-config.php for Docker)
	 *   2. wp_mcp_ai_media_worker_url option (set via admin UI)
	 *   3. Empty string (sidecar disabled)
	 *
	 * @return string Sidecar base URL or empty string.
	 */
	protected function get_sidecar_url() {
		if ( defined( 'WP_MEDIA_WORKER_URL' ) && WP_MEDIA_WORKER_URL ) {
			return rtrim( WP_MEDIA_WORKER_URL, '/' );
		}

		$option = get_option( 'wp_mcp_ai_media_worker_url', '' );
		return $option ? rtrim( $option, '/' ) : '';
	}

	/**
	 * Get a lightweight site token for sidecar authentication.
	 *
	 * Priority:
	 *   1. WP_MEDIA_WORKER_TOKEN constant (set in wp-config.php, must match
	 *      the worker's WORKER_API_TOKEN environment variable).
	 *   2. wp_mcp_ai_media_worker_token_<blog_id> per-blog option
	 *      (multisite only — Phase 3 W1; each blog can map to its own
	 *      worker tenant).
	 *   3. wp_mcp_ai_media_worker_token option (set via admin UI).
	 *   4. wp_hash( home_url() ) fallback, derived from the WordPress auth
	 *      salts (AUTH_KEY / SECURE_AUTH_KEY). If salts are rotated, this
	 *      default changes and must be re-synced with the sidecar, so a
	 *      stable constant or option is preferred for cloud deployments.
	 *
	 * @return string Token string.
	 */
	protected function get_sidecar_token() {
		if ( defined( 'WP_MEDIA_WORKER_TOKEN' ) && WP_MEDIA_WORKER_TOKEN ) {
			return WP_MEDIA_WORKER_TOKEN;
		}

		// Per-blog override (multisite only; never read on single-site).
		if ( is_multisite() ) {
			$blog_token = get_option( 'wp_mcp_ai_media_worker_token_' . get_current_blog_id(), '' );
			if ( ! empty( $blog_token ) ) {
				return $blog_token;
			}
		}

		$token = get_option( 'wp_mcp_ai_media_worker_token', '' );
		if ( ! empty( $token ) ) {
			return $token;
		}

		return wp_hash( home_url() );
	}

	/**
	 * Check whether multipart file uploads to the sidecar are possible.
	 *
	 * Requires a reachable sidecar and the cURL extension (streaming
	 * multipart uploads).
	 *
	 * @return bool True when files can be uploaded to the sidecar.
	 */
	public function is_sidecar_upload_supported() {
		return function_exists( 'curl_file_create' ) && $this->is_sidecar_available();
	}

	/**
	 * Upload a local file to the sidecar as multipart/form-data.
	 *
	 * Uses cURL directly (CURLFile) because the WordPress HTTP API
	 * (Requests) form-encodes array bodies and cannot stream large file
	 * parts; cURL streams the file from disk without loading it into
	 * memory. Gate callers with is_sidecar_upload_supported().
	 *
	 * @param string $endpoint  API path (e.g. '/api/video/process').
	 * @param string $file_path Local file path.
	 * @param array  $fields    Extra form fields sent alongside the file.
	 * @param int    $timeout   Request timeout in seconds.
	 * @return array|WP_Error Decoded JSON body or error.
	 */
	protected function sidecar_upload( $endpoint, $file_path, $fields = array(), $timeout = 330 ) {
		if ( ! function_exists( 'curl_file_create' ) ) {
			return new WP_Error(
				'wp_mcp_ai_curl_required',
				__( 'Multipart uploads require the cURL extension.', 'mcp-ai-wpoos' )
			);
		}
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'File not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}
		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_not_configured',
				__( 'Media Worker sidecar URL is not configured.', 'mcp-ai-wpoos' )
			);
		}

		$filetype = wp_check_filetype( $file_path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';

		$postfields = $fields;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_file_create -- cURL streaming multipart upload; the WordPress HTTP API cannot stream file parts.
		$postfields['file'] = curl_file_create( $file_path, $mime, basename( $file_path ) );

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_errno,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close -- Streaming multipart upload via cURL; see method docblock.
		$ch = curl_init( rtrim( $url, '/' ) . '/' . ltrim( $endpoint, '/' ) );
		if ( false === $ch ) {
			return new WP_Error( 'wp_mcp_ai_curl_init_failed', __( 'Failed to initialise cURL.', 'mcp-ai-wpoos' ) );
		}

		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postfields );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, (int) $timeout );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'X-Site-Token: ' . $this->get_sidecar_token(),
				'X-Site-Url: ' . home_url(),
			)
		);

		/**
		 * Filter: modify the sidecar upload request before sending.
		 *
		 * @param resource $ch       cURL handle.
		 * @param string   $endpoint The API endpoint path.
		 * @param array    $fields   Extra form fields.
		 */
		$ch = apply_filters( 'wp_mcp_ai_sidecar_upload_handle', $ch, $endpoint, $fields );

		$raw = curl_exec( $ch );
		if ( false === $raw ) {
			$errno = curl_errno( $ch );
			$error = curl_error( $ch );
			curl_close( $ch );
			$this->sidecar_available = false;
			return new WP_Error( 'wp_mcp_ai_sidecar_error', sprintf( 'cURL %d: %s', $errno, $error ) );
		}

		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		// phpcs:enable

		$decoded = json_decode( $raw, true );

		if ( 200 !== $status && 202 !== $status ) {
			$error_msg = isset( $decoded['error'] )
				? $decoded['error']
				: sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 200 ) );

			return new WP_Error(
				'wp_mcp_ai_sidecar_error',
				$error_msg,
				array(
					'status'   => $status,
					'response' => $decoded,
				)
			);
		}

		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_invalid_json',
				__( 'Media Worker returned invalid JSON.', 'mcp-ai-wpoos' )
			);
		}

		$this->sidecar_available = true;
		return $decoded;
	}

	/**
	 * Upload MULTIPLE local files to the sidecar as one multipart/form-data
	 * request (e.g. PDF merge sources).
	 *
	 * Files are sent with cURL CURLFile parts named files[0], files[1], … so
	 * they stream from disk without loading into memory; the worker accepts
	 * any field name starting with "files[". Gate callers with
	 * is_sidecar_upload_supported().
	 *
	 * @param string $endpoint   API path (e.g. '/api/pdf/merge').
	 * @param array  $file_paths Local file paths, in merge order.
	 * @param array  $fields     Extra form fields sent alongside the files.
	 * @param int    $timeout    Request timeout in seconds.
	 * @return array|WP_Error Decoded JSON body or error.
	 */
	protected function sidecar_upload_multi( $endpoint, $file_paths, $fields = array(), $timeout = 330 ) {
		if ( ! function_exists( 'curl_file_create' ) ) {
			return new WP_Error(
				'wp_mcp_ai_curl_required',
				__( 'Multipart uploads require the cURL extension.', 'mcp-ai-wpoos' )
			);
		}
		if ( empty( $file_paths ) || ! is_array( $file_paths ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_files',
				__( 'No files provided for upload.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}
		foreach ( $file_paths as $file_path ) {
			if ( ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_file_not_found',
					__( 'File not found.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}
		}
		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_not_configured',
				__( 'Media Worker sidecar URL is not configured.', 'mcp-ai-wpoos' )
			);
		}

		$postfields = $fields;
		foreach ( array_values( $file_paths ) as $index => $file_path ) {
			$filetype = wp_check_filetype( $file_path );
			$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_file_create -- cURL streaming multipart upload; the WordPress HTTP API cannot stream file parts.
			$postfields[ 'files[' . $index . ']' ] = curl_file_create( $file_path, $mime, basename( $file_path ) );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_errno,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close -- Streaming multipart upload via cURL; see method docblock.
		$ch = curl_init( rtrim( $url, '/' ) . '/' . ltrim( $endpoint, '/' ) );
		if ( false === $ch ) {
			return new WP_Error( 'wp_mcp_ai_curl_init_failed', __( 'Failed to initialise cURL.', 'mcp-ai-wpoos' ) );
		}

		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postfields );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, (int) $timeout );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'X-Site-Token: ' . $this->get_sidecar_token(),
				'X-Site-Url: ' . home_url(),
			)
		);

		$raw = curl_exec( $ch );
		if ( false === $raw ) {
			$errno = curl_errno( $ch );
			$error = curl_error( $ch );
			curl_close( $ch );
			$this->sidecar_available = false;
			return new WP_Error( 'wp_mcp_ai_sidecar_error', sprintf( 'cURL %d: %s', $errno, $error ) );
		}

		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		// phpcs:enable

		$decoded = json_decode( $raw, true );

		if ( 200 !== $status && 202 !== $status ) {
			$error_msg = isset( $decoded['error'] )
				? $decoded['error']
				: sprintf( 'HTTP %d: %s', $status, substr( $raw, 0, 200 ) );

			return new WP_Error(
				'wp_mcp_ai_sidecar_error',
				$error_msg,
				array(
					'status'   => $status,
					'response' => $decoded,
				)
			);
		}

		if ( null === $decoded ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_invalid_json',
				__( 'Media Worker returned invalid JSON.', 'mcp-ai-wpoos' )
			);
		}

		$this->sidecar_available = true;
		return $decoded;
	}

	/**
	 * Download a processed output file from the sidecar to a local path.
	 *
	 * Uses cURL with CURLOPT_FILE so large outputs stream to disk instead of
	 * loading into memory (same rationale as sidecar_upload()).
	 *
	 * @param string $name        Relative output name from the worker response.
	 * @param string $destination Absolute local destination path.
	 * @return string|WP_Error Destination path on success.
	 */
	protected function sidecar_download( $name, $destination ) {
		$url = $this->get_sidecar_url();
		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_not_configured',
				__( 'Media Worker sidecar URL is not configured.', 'mcp-ai-wpoos' )
			);
		}

		$dir = dirname( $destination );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Encode each path segment so '/' separators survive proxies that
		// reject %2F in URLs.
		$segments    = array_map( 'rawurlencode', explode( '/', $name ) );
		$request_url = rtrim( $url, '/' ) . '/api/video/download/' . implode( '/', $segments );

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_errno,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close -- Streaming cURL download; see method docblock.
		$ch = curl_init( $request_url );
		if ( false === $ch ) {
			return new WP_Error( 'wp_mcp_ai_curl_init_failed', __( 'Failed to initialise cURL.', 'mcp-ai-wpoos' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Destination is a site temp/uploads path created via wp_mkdir_p() above.
		$fp = fopen( $destination, 'wb' );
		if ( false === $fp ) {
			curl_close( $ch );
			return new WP_Error( 'wp_mcp_ai_download_open_failed', __( 'Could not open destination file for writing.', 'mcp-ai-wpoos' ) );
		}

		curl_setopt( $ch, CURLOPT_FILE, $fp );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'X-Site-Token: ' . $this->get_sidecar_token(),
				'X-Site-Url: ' . home_url(),
			)
		);

		$result = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		if ( false === $result ) {
			$errno = curl_errno( $ch );
			$error = curl_error( $ch );
			curl_close( $ch );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fp );
			if ( file_exists( $destination ) && 0 === filesize( $destination ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $destination );
			}
			$this->sidecar_available = false;
			return new WP_Error( 'wp_mcp_ai_sidecar_error', sprintf( 'cURL %d: %s', $errno, $error ) );
		}
		curl_close( $ch );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fp );
		// phpcs:enable

		if ( 200 !== $status ) {
			if ( file_exists( $destination ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $destination );
			}
			return new WP_Error(
				'wp_mcp_ai_sidecar_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'HTTP %d while downloading worker output.', 'mcp-ai-wpoos' ),
					$status
				),
				array( 'status' => $status )
			);
		}

		if ( ! file_exists( $destination ) || 0 === filesize( $destination ) ) {
			return new WP_Error(
				'wp_mcp_ai_sidecar_download_empty',
				__( 'Worker output file was empty.', 'mcp-ai-wpoos' )
			);
		}

		return $destination;
	}
}
