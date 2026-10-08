<?php
/**
 * NV oOS Docs Hub — Checkout Installer
 *
 * Downloads the NV oOS Complete bundle ZIP and installs it via the
 * WordPress upgrader after a successful payment. Mirrors the Content
 * Graph installer (plugins/nvoos-content-graph/src/Commerce/Installer.php)
 * with docs-hub naming; the legacy AI-addon paths are dropped (docs-hub
 * never sold that artifact).
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.5.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloads the NV oOS Complete bundle ZIP from the release URL and
 * installs it via the WordPress upgrader, then activates it.
 *
 * The Complete bundle is the full NV oOS plugin (base + Pro) distributed
 * as a separate WordPress plugin — it is NOT the Docs Hub plugin
 * itself, and it is never bundled inside this plugin's own package.
 *
 * Runs only after payment verification, inside an admin-authenticated
 * REST request, so filesystem access matches what wp-admin installs use.
 * On hosts that require FTP credentials this fails with a WP_Error
 * carrying the manual download URL.
 *
 * Installing a second copy of the base plugin would fatally conflict
 * (duplicate constants/classes), so `install()` refuses when another
 * copy of NV oOS already exists on the site.
 *
 * @since 0.5.2
 */
class NV_oOS_Docs_Hub_Checkout_Installer {

	/**
	 * Folder the Complete bundle extracts to.
	 *
	 * @var string
	 */
	const BUNDLE_SLUG = 'nvdigital-open-operator-system-oos-complete';

	/**
	 * Main plugin file of the Complete bundle.
	 *
	 * @var string
	 */
	const BUNDLE_BASENAME = 'nvdigital-open-operator-system-oos-complete/nvdigital-open-operator-system-oos.php';

	/**
	 * Basenames of other NV oOS distributions that must not coexist with
	 * the Complete bundle (duplicate constants/classes → fatal).
	 *
	 * @var string[]
	 */
	const KNOWN_BASE_PLUGINS = array(
		'mcp-ai-wpoos/mcp-ai-wpoos.php',
		'mcp-ai-wpoos/mcp-ai-wpoos-base.php',
		'nvdigital-open-operator-system-oos/nvdigital-open-operator-system-oos.php',
	);

	/**
	 * Whether the Complete bundle is currently active.
	 *
	 * @since 0.5.2
	 *
	 * @return bool
	 */
	public static function is_bundle_active() {
		return is_plugin_active( self::BUNDLE_BASENAME );
	}

	/**
	 * Whether the Complete bundle files are present on disk.
	 *
	 * @since 0.5.2
	 *
	 * @return bool
	 */
	public static function is_bundle_installed() {
		return file_exists( WP_PLUGIN_DIR . '/' . self::BUNDLE_BASENAME );
	}

	/**
	 * Detect another copy of the NV oOS base plugin on this site.
	 *
	 * The Complete bundle is the base plugin under its own folder. A
	 * pre-existing copy (any distribution folder, active or not) would
	 * redeclare the same constants and classes when activated — so the
	 * installer must refuse instead of creating a broken second copy.
	 *
	 * The `nvoos_docs_hub_checkout_skip_base_plugin_detection` filter
	 * returns false by default; it exists so test environments that mount
	 * the monorepo under `wp-content/plugins/mcp-ai-wpoos` can exercise the
	 * download/install paths without tripping the guard.
	 *
	 * @since 0.5.2
	 *
	 * @return string Matching plugin basename, or '' when the site is clean.
	 */
	public static function detect_existing_base_plugin() {
		if ( (bool) apply_filters( 'nvoos_docs_hub_checkout_skip_base_plugin_detection', false ) ) {
			return '';
		}

		// Fast path: a base plugin is already loaded in this request.
		if ( defined( 'WP_MCP_AI_VERSION' ) ) {
			return self::BUNDLE_BASENAME;
		}

		foreach ( self::KNOWN_BASE_PLUGINS as $basename ) {
			if ( file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
				return $basename;
			}
		}

		return '';
	}

	/**
	 * Install and activate the Complete bundle.
	 *
	 * Idempotent: an already-active bundle returns success immediately.
	 * Refuses (without downloading) when another copy of the NV oOS base
	 * plugin already exists on the site.
	 *
	 * @since 0.5.2
	 *
	 * @param string $zip_url The download URL of the Complete bundle ZIP
	 *                        (vendor-issued signed URL, or the filterable
	 *                        fallback URL).
	 * @return array<string,mixed>|WP_Error
	 *   array{installed: bool, activated: bool, message: string} on success.
	 */
	public static function install( $zip_url ) {
		if ( self::is_bundle_active() ) {
			return array(
				'installed' => true,
				'activated' => true,
				'message'   => __( 'NV oOS Complete is already installed and active.', 'nvoos-docs-hub' ),
			);
		}

		$existing = self::detect_existing_base_plugin();
		if ( '' !== $existing ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_base_plugin_exists',
				sprintf(
					/* translators: %s: existing plugin folder. */
					__( 'You already have the NV oOS plugin installed (%s). Installing the Complete bundle alongside it would create a second copy and break both plugins. Update your existing plugin from the Plugins screen instead — your purchase is recorded.', 'nvoos-docs-hub' ),
					$existing
				),
				array(
					'status'  => 409,
					'zip_url' => $zip_url,
					'manual'  => true,
				)
			);
		}

		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$tmp = download_url( $zip_url, 300 );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_download_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not download the NV oOS Complete package: %s', 'nvoos-docs-hub' ),
					$tmp->get_error_message()
				),
				array(
					'status'  => 502,
					'zip_url' => $zip_url,
					'manual'  => true,
				)
			);
		}

		$upgrader = new Plugin_Upgrader();
		$result   = $upgrader->install( $tmp, array( 'clear_destination' => false ) );

		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_install_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not install the NV oOS Complete package: %s', 'nvoos-docs-hub' ),
					$result->get_error_message()
				),
				array(
					'status'  => 500,
					'zip_url' => $zip_url,
					'manual'  => true,
				)
			);
		}

		if ( false === $result ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_install_failed',
				__( 'The WordPress upgrader could not install the NV oOS Complete package.', 'nvoos-docs-hub' ),
				array(
					'status'  => 500,
					'zip_url' => $zip_url,
					'manual'  => true,
				)
			);
		}

		if ( ! self::is_bundle_installed() ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_install_failed',
				__( 'The package was installed but its main plugin file is missing. The package may be incomplete.', 'nvoos-docs-hub' ),
				array(
					'status'  => 500,
					'zip_url' => $zip_url,
					'manual'  => true,
				)
			);
		}

		wp_clean_plugins_cache();

		$activated = activate_plugin( self::BUNDLE_BASENAME );

		if ( is_wp_error( $activated ) ) {
			return array(
				'installed' => true,
				'activated' => false,
				'message'   => sprintf(
					/* translators: %s: error message. */
					__( 'NV oOS Complete was installed but could not be activated automatically: %s Activate it on the Plugins screen.', 'nvoos-docs-hub' ),
					$activated->get_error_message()
				),
			);
		}

		return array(
			'installed' => true,
			'activated' => true,
			'message'   => __( 'NV oOS Complete installed and activated.', 'nvoos-docs-hub' ),
		);
	}

	/**
	 * Private constructor — static utility, not instantiable.
	 */
	private function __construct() {}
}
