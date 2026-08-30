<?php
/**
 * Signed ZIP download server.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves addon ZIPs behind signed, expiring download URLs.
 *
 * Endpoint shape (plain query vars — no rewrites needed):
 *   /?nvoos_checkout_download=1&license=…&expires=…&token=…
 *
 * The ZIP is fetched from the configured source (GitHub release or a
 * private mirror) once per addon version and cached under
 * wp-content/uploads/nvoos-checkout/, then streamed to the requesting
 * customer server with the correct headers.
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_Download_Server {

	public const CACHE_DIR = 'nvoos-checkout';

	/**
	 * Register the query var + parse_request handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve' ) );
	}

	/**
	 * Register the download query var.
	 *
	 * @param array<int,string> $vars Existing query vars.
	 * @return array<int,string>
	 */
	public static function register_query_var( array $vars ): array {
		$vars[] = 'nvoos_checkout_download';
		return $vars;
	}

	/**
	 * Serve the ZIP when the download query var is present.
	 *
	 * @param WP $wp WordPress request object.
	 * @return void
	 */
	public static function maybe_serve( $wp ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WordPress parse_request action signature
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- self-authenticating signed URL, not a form action.
		if ( empty( $_GET['nvoos_checkout_download'] ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- signed-token auth, see above.
		$license_key = isset( $_GET['license'] ) ? sanitize_text_field( wp_unslash( $_GET['license'] ) ) : '';
		$expires     = isset( $_GET['expires'] ) ? absint( $_GET['expires'] ) : 0;
		$token       = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $license_key || 0 === $expires || '' === $token ) {
			self::fail( 400, __( 'Missing download parameters.', 'nvoos-checkout-api' ) );
		}

		if ( ! NVOOS_Checkout_API_Token::verify( $license_key, $expires, $token ) ) {
			self::fail( 403, __( 'Invalid or expired download link.', 'nvoos-checkout-api' ) );
		}

		$license = NVOOS_Checkout_API_License_Store::get_by_key( $license_key );
		if ( null === $license ) {
			self::fail( 404, __( 'Unknown license.', 'nvoos-checkout-api' ) );
		}

		if ( NVOOS_Checkout_API_License_Store::STATUS_ACTIVE !== ( $license['status'] ?? '' ) ) {
			self::fail( 402, __( 'This license is no longer active. Please contact support.', 'nvoos-checkout-api' ) );
		}

		$file = self::ensure_cached_zip( (string) $license['addon_version'] );
		if ( is_wp_error( $file ) ) {
			self::fail( 502, $file->get_error_message() );
		}

		self::stream( $file, (string) $license['addon_version'] );
	}

	/**
	 * Download and cache the ZIP for a version.
	 *
	 * @param string $version Addon version.
	 * @return string|WP_Error Absolute path to the cached ZIP.
	 */
	public static function ensure_cached_zip( string $version ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'nvoos_checkout_upload_dir', $uploads['error'] );
		}

		$dir  = trailingslashit( $uploads['basedir'] ) . self::CACHE_DIR;
		$file = $dir . '/nvoos-content-graph-ai-v' . sanitize_file_name( $version ) . '.zip';

		if ( file_exists( $file ) ) {
			return $file;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'nvoos_checkout_cache_dir', __( 'Could not create the package cache directory.', 'nvoos-checkout-api' ) );
		}

		$source = NVOOS_Checkout_API_Settings::zip_source_for( $version );

		// Local filesystem sources are copied directly; URLs are downloaded.
		if ( 0 === strpos( $source, '/' ) ) {
			if ( ! file_exists( $source ) || ! copy( $source, $file ) ) {
				return new WP_Error( 'nvoos_checkout_zip_source', __( 'The configured ZIP source is not readable.', 'nvoos-checkout-api' ) );
			}
			return $file;
		}

		if ( 0 !== strpos( $source, 'https://' ) ) {
			return new WP_Error( 'nvoos_checkout_zip_source', __( 'The configured ZIP source must be an https URL or an absolute path.', 'nvoos-checkout-api' ) );
		}

		$tmp = download_url( $source, 300 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'nvoos_checkout_zip_fetch',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not fetch the addon package: %s', 'nvoos-checkout-api' ),
					$tmp->get_error_message()
				)
			);
		}

		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'nvoos_checkout_zip_cache', __( 'Filesystem access unavailable.', 'nvoos-checkout-api' ) );
		}

		if ( ! $wp_filesystem->move( $tmp, $file, true ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'nvoos_checkout_zip_cache', __( 'Could not cache the addon package.', 'nvoos-checkout-api' ) );
		}

		return $file;
	}

	/**
	 * Stream the ZIP with the correct headers and exit.
	 *
	 * @param string $file    Absolute path to the ZIP.
	 * @param string $version Addon version (used in the filename).
	 * @return never
	 */
	private static function stream( string $file, string $version ) {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			self::fail( 500, __( 'Filesystem access unavailable.', 'nvoos-checkout-api' ) );
		}

		$contents = $wp_filesystem->get_contents( $file );
		if ( false === $contents ) {
			self::fail( 500, __( 'Could not read the addon package.', 'nvoos-checkout-api' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Length: ' . (string) strlen( $contents ) );
		header( 'Content-Disposition: attachment; filename="nvoos-content-graph-ai-v' . sanitize_file_name( $version ) . '.zip"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw ZIP bytes.

		exit;
	}

	/**
	 * Emit an error response and exit.
	 *
	 * @param int    $code    HTTP status code.
	 * @param string $message Error message.
	 * @return never
	 */
	private static function fail( int $code, string $message ) {
		status_header( $code );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $message );
		exit;
	}
}
