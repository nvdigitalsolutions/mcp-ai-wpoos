<?php
/**
 * NV oOS Canvas Addon — Core Class
 *
 * Handles activation checks, admin notices, and the plugin's integration
 * hooks so that the NV oOS Pro OCR service can discover the bundled canvas
 * native binaries.
 *
 * @package NV_oOS_Canvas
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Canvas Addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Canvas {

	/**
	 * Name of the native binary file canvas uses.
	 *
	 * @var string
	 */
	const BINARY_NAME = 'canvas.node';

	/**
	 * Expected relative path to the native binary inside the module directory.
	 *
	 * @var string
	 */
	const BINARY_REL_PATH = 'build/Release/canvas.node';

	/**
	 * WordPress option key used to store the detected platform build label.
	 *
	 * @var string
	 */
	const OPTION_PLATFORM = 'nvoos_canvas_platform';

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'check_pro_plugin' ) );
	}

	/**
	 * Return the absolute path to the bundled canvas module directory.
	 *
	 * Returns an empty string when the native binary is absent (JS-wrapper-only
	 * package — platform build not installed).
	 *
	 * @return string
	 */
	public static function get_canvas_dir() {
		if ( self::is_available() ) {
			return NVOOS_CANVAS_MODULE_PATH;
		}
		return '';
	}

	/**
	 * Check whether the platform-specific canvas native binary is present.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$binary = NVOOS_CANVAS_MODULE_PATH . DIRECTORY_SEPARATOR . self::BINARY_REL_PATH;
		return file_exists( $binary );
	}

	/**
	 * Detect and cache the platform label from the installed binary filename.
	 *
	 * Canvas uses `node-pre-gyp` which names the binary:
	 *   canvas-v{ver}-{node_abi}-{platform}-{libc}-{arch}.tar.gz
	 * After extraction the binary is always `canvas.node`.
	 *
	 * We detect the platform from a small metadata JSON file written by the
	 * build pipeline, falling back to a PHP best-guess.
	 *
	 * @return string Human-readable platform label, e.g. "linux-x64 (Node 20)".
	 */
	public static function get_platform_label() {
		$meta_file = NVOOS_CANVAS_PATH . 'platform.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		// Direct file_get_contents() is intentional here: this is a trusted, static
		// file written by the build pipeline inside the plugin directory. Using
		// WP_Filesystem is not appropriate at this hook (called before admin init)
		// and would add unnecessary complexity for a local, non-user-supplied path.
		if ( file_exists( $meta_file ) && realpath( $meta_file ) && 0 === strpos( realpath( $meta_file ), realpath( NVOOS_CANVAS_PATH ) ) ) {
			$meta = json_decode( file_get_contents( $meta_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! empty( $meta['platform'] ) && ! empty( $meta['arch'] ) ) {
				$node_ver = ! empty( $meta['node_version'] ) ? ' (Node ' . $meta['node_version'] . ')' : '';
				return $meta['platform'] . '-' . $meta['arch'] . $node_ver;
			}
		}

		// Fallback: describe the current PHP host.
		$os   = PHP_OS_FAMILY;
		$arch = php_uname( 'm' );
		return strtolower( $os ) . '-' . $arch . ' (detected)';
	}

	/**
	 * Display admin notices about canvas availability.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		// Only show to administrators.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::is_available() ) {
			// Show a one-time "canvas ready" notice after activation, then dismiss.
			if ( get_transient( 'nvoos_canvas_activated' ) ) {
				delete_transient( 'nvoos_canvas_activated' );
				echo '<div class="notice notice-success is-dismissible"><p>';
				printf(
					/* translators: %s: platform label */
					esc_html__( 'NV oOS Canvas Addon activated — platform build: %s. PDF OCR with Tesseract is now available.', 'nvoos-canvas' ),
					'<strong>' . esc_html( self::get_platform_label() ) . '</strong>'
				);
				echo '</p></div>';
			}
			return;
		}

		// Native binary is missing — this ZIP was installed without platform binaries.
		echo '<div class="notice notice-warning is-dismissible"><p>';
		printf(
			/* translators: 1: plugin name, 2: download page URL */
			wp_kses(
				__( '<strong>NV oOS Canvas Addon</strong> is active but the platform-specific canvas binary is missing. <a href="%s" target="_blank" rel="noopener">Download the correct platform build</a> (linux-x64, linux-arm64) from the NV Digital Solutions website and replace this installation.', 'nvoos-canvas' ),
				array(
					'strong' => array(),
					'a'      => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			),
			esc_url( 'https://nvdigitalsolutions.com/wpoos#canvas-addon' )
		);
		echo '</p></div>';
	}

	/**
	 * Show a notice if NV oOS Pro is not active.
	 *
	 * @return void
	 */
	public static function check_pro_plugin() {
		// Use WP_MCP_AI_PRO_VERSION (defined at the top of the Pro addon entry point)
		// rather than class_exists( 'WP_MCP_AI_OCR_Service' ), because the OCR service
		// class is loaded lazily (only when the OCR tools execute) and therefore is
		// never present at plugins_loaded time — even when Pro is fully active.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) && current_user_can( 'manage_options' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-info is-dismissible"><p>';
					esc_html_e( 'NV oOS Canvas Addon requires the NV oOS Pro addon to be installed and active for PDF OCR functionality.', 'nvoos-canvas' );
					echo '</p></div>';
				}
			);
		}
	}
}

/**
 * Set the "just activated" transient on plugin activation.
 */
register_activation_hook(
	NVOOS_CANVAS_FILE,
	function () {
		set_transient( 'nvoos_canvas_activated', true, 30 );
	}
);
