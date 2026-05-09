<?php
/**
 * NV oOS Cornerstone3D Addon — Core Class
 *
 * Handles activation checks, admin notices, and the plugin's integration
 * hooks so that the NV oOS Pro Medical Imaging Viewer can discover the
 * bundled Cornerstone3D ESM modules.
 *
 * @package NV_oOS_Cornerstone3D
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Cornerstone3D Addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Cornerstone3D {

	/**
	 * Required ESM bundle files — all must be present for the addon to be
	 * considered "available".
	 *
	 * @var string[]
	 */
	const REQUIRED_BUNDLES = array(
		'cornerstone-core.esm.js',
		'cornerstone-tools.esm.js',
		'cornerstone-dicom-loader.esm.js',
		'dicom-parser.esm.js',
		'xmlbuilder2.esm.js',
	);

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'check_pro_plugin' ), 20 );

		// Tell the Pro imaging viewer where the vendored bundles live.
		add_filter( 'wp_mcp_ai_cornerstone3d_addon_dir', array( __CLASS__, 'get_assets_dir' ) );
		add_filter( 'wp_mcp_ai_cornerstone3d_addon_url', array( __CLASS__, 'filter_addon_url' ) );
	}

	/**
	 * Return the absolute path to the bundled ESM module directory.
	 *
	 * Returns an empty string when any bundle file is absent.
	 *
	 * @return string
	 */
	public static function get_assets_dir() {
		if ( self::is_available() ) {
			return NVOOS_CORNERSTONE3D_ASSETS_PATH;
		}
		return '';
	}

	/**
	 * Check whether all five ESM bundle files are present on disk.
	 *
	 * @return bool
	 */
	public static function is_available() {
		foreach ( self::REQUIRED_BUNDLES as $file ) {
			if ( ! file_exists( NVOOS_CORNERSTONE3D_ASSETS_PATH . DIRECTORY_SEPARATOR . $file ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Return the URL base for the vendored bundles via filter.
	 *
	 * @param string $url Existing URL (unused).
	 * @return string
	 */
	public static function filter_addon_url( $url = '' ) {
		if ( self::is_available() ) {
			return NVOOS_CORNERSTONE3D_URL . 'assets/cornerstone/';
		}
		return $url;
	}

	/**
	 * Read and return the vendor-meta.json metadata.
	 *
	 * @return array|null Parsed metadata or null on failure.
	 */
	public static function get_vendor_meta() {
		$meta_file = NVOOS_CORNERSTONE3D_ASSETS_PATH . DIRECTORY_SEPARATOR . 'vendor-meta.json';
		if ( ! file_exists( $meta_file ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$json = file_get_contents( $meta_file );
		if ( false === $json ) {
			return null;
		}
		$meta = json_decode( $json, true );
		if ( null === $meta || JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}
		return $meta;
	}

	/**
	 * Get a human-readable summary of the vendored package versions.
	 *
	 * @return string Formatted version string, e.g. "core@1.86.1, tools@1.86.1, …"
	 */
	public static function get_version_summary() {
		$meta = self::get_vendor_meta();
		if ( empty( $meta['packages'] ) ) {
			return __( 'unknown versions', 'nvoos-cornerstone3d' );
		}

		$parts = array();
		foreach ( $meta['packages'] as $pkg => $ver ) {
			// Shorten @cornerstonejs/core → core, etc.
			$short   = str_replace( '@cornerstonejs/', '', $pkg );
			$short   = str_replace( '-', ' ', $short );
			$parts[] = $short . '@' . $ver;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Display admin notices about Cornerstone3D availability.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		// Only show to administrators.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::is_available() ) {
			// Show a one-time "ready" notice after activation, then dismiss.
			if ( get_transient( 'nvoos_cornerstone3d_activated' ) ) {
				delete_transient( 'nvoos_cornerstone3d_activated' );
				echo '<div class="notice notice-success is-dismissible"><p>';
				printf(
					/* translators: %s: version summary */
					esc_html__( 'NV oOS Cornerstone3D Addon activated — packages: %s. The Medical Imaging Viewer will now load libraries locally (no CDN dependency).', 'nvoos-cornerstone3d' ),
					'<strong>' . esc_html( self::get_version_summary() ) . '</strong>'
				);
				echo '</p></div>';
			}
			return;
		}

		// ESM bundles are missing — installation is incomplete.
		echo '<div class="notice notice-warning is-dismissible"><p>';
		printf(
			wp_kses(
				/* translators: %s: download page URL */
				__( '<strong>NV oOS Cornerstone3D Addon</strong> is active but one or more ESM bundle files are missing. <a href="%s" target="_blank" rel="noopener">Download the correct build</a> from the NV Digital Solutions website and replace this installation.', 'nvoos-cornerstone3d' ),
				array(
					'strong' => array(),
					'a'      => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			),
			esc_url( 'https://nvdigitalsolutions.com/wpoos#cornerstone3d-addon' )
		);
		echo '</p></div>';
	}

	/**
	 * Show a notice if NV oOS Pro is not active.
	 *
	 * @return void
	 */
	public static function check_pro_plugin() {
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) && current_user_can( 'manage_options' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-info is-dismissible"><p>';
					esc_html_e( 'NV oOS Cornerstone3D Addon requires the NV oOS Pro addon to be installed and active for Medical Imaging functionality.', 'nvoos-cornerstone3d' );
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
	NVOOS_CORNERSTONE3D_FILE,
	function () {
		set_transient( 'nvoos_cornerstone3d_activated', true, 30 );
	}
);
