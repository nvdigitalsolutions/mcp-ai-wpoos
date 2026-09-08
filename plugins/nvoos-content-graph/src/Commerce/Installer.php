<?php
declare(strict_types=1);

namespace NvoosContentGraph\Commerce;

use Plugin_Upgrader;
use WP_Error;

use function activate_plugin;
use function apply_filters;
use function defined;
use function file_exists;
use function is_plugin_active;
use function is_wp_error;
use function download_url;
use function wp_clean_plugins_cache;
use function wp_delete_file;

/**
 * Downloads the NV oOS Complete bundle ZIP from the release URL and
 * installs it via the WordPress upgrader, then activates it.
 *
 * The Complete bundle is the full NV oOS plugin (base + Pro) distributed
 * as a separate WordPress plugin — it is NOT the Content Graph plugin
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
 * @since 1.0.4
 */
final class Installer {

	/** @var string Legacy AI-addon slug (purchases made before the Complete bundle). */
	public const ADDON_SLUG = 'nvoos-content-graph-ai';

	/** @var string Legacy AI-addon basename. */
	public const ADDON_BASENAME = 'nvoos-content-graph-ai/nvoos-content-graph-ai.php';

	/** @var string Folder the Complete bundle extracts to. */
	public const BUNDLE_SLUG = 'nvdigital-open-operator-system-oos-complete';

	/** @var string Main plugin file of the Complete bundle. */
	public const BUNDLE_BASENAME = 'nvdigital-open-operator-system-oos-complete/nvdigital-open-operator-system-oos.php';

	/**
	 * Basenames of other NV oOS distributions that must not coexist with
	 * the Complete bundle (duplicate constants/classes → fatal).
	 *
	 * @var string[]
	 */
	public const KNOWN_BASE_PLUGINS = array(
		'mcp-ai-wpoos/mcp-ai-wpoos.php',
		'mcp-ai-wpoos/mcp-ai-wpoos-base.php',
		'nvdigital-open-operator-system-oos/nvdigital-open-operator-system-oos.php',
	);

	/**
	 * Whether the Complete bundle is currently active.
	 *
	 * @return bool
	 */
	public static function isBundleActive(): bool {
		return is_plugin_active( self::BUNDLE_BASENAME );
	}

	/**
	 * Whether the Complete bundle files are present on disk.
	 *
	 * @return bool
	 */
	public static function isBundleInstalled(): bool {
		return file_exists( WP_PLUGIN_DIR . '/' . self::BUNDLE_BASENAME );
	}

	/**
	 * Whether the legacy AI addon is currently active.
	 *
	 * @return bool
	 */
	public static function isAddonActive(): bool {
		return is_plugin_active( self::ADDON_BASENAME );
	}

	/**
	 * Whether any purchased artifact is currently active.
	 *
	 * The Complete bundle is the current product; the legacy AI addon
	 * covers purchases made before 1.0.6.
	 *
	 * @return bool
	 */
	public static function isActive(): bool {
		return self::isBundleActive() || self::isAddonActive();
	}

	/**
	 * Whether any purchased artifact files are present on disk.
	 *
	 * @return bool
	 */
	public static function isInstalled(): bool {
		return self::isBundleInstalled()
			|| file_exists( WP_PLUGIN_DIR . '/' . self::ADDON_BASENAME );
	}

	/**
	 * Detect another copy of the NV oOS base plugin on this site.
	 *
	 * The Complete bundle is the base plugin under its own folder. A
	 * pre-existing copy (any distribution folder, active or not) would
	 * redeclare the same constants and classes when activated — so the
	 * installer must refuse instead of creating a broken second copy.
	 *
	 * The `nvoos_content_graph/commerce/skip_base_plugin_detection` filter
	 * returns false by default; it exists so test environments that mount
	 * the monorepo under `wp-content/plugins/mcp-ai-wpoos` can exercise the
	 * download/install paths without tripping the guard.
	 *
	 * @since 1.0.6
	 *
	 * @return string Matching plugin basename, or '' when the site is clean.
	 */
	public static function detectExistingBasePlugin(): string {
		if ( (bool) apply_filters( 'nvoos_content_graph/commerce/skip_base_plugin_detection', false ) ) {
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
	 * Idempotent: an already-active artifact returns success immediately.
	 * Refuses (without downloading) when another copy of the NV oOS base
	 * plugin already exists on the site.
	 *
	 * @since 1.0.4
	 *
	 * @param string $zipUrl The download URL of the Complete bundle ZIP
	 *                       (vendor-issued signed URL, or the filterable
	 *                       fallback URL).
	 * @return array<string,mixed>|WP_Error
	 *   array{installed: bool, activated: bool, message: string} on success.
	 */
	public static function install( string $zipUrl ) {
		if ( self::isBundleActive() ) {
			return array(
				'installed' => true,
				'activated' => true,
				'message'   => __( 'NV oOS Complete is already installed and active.', 'nvoos-content-graph' ),
			);
		}

		// Legacy purchases: the AI addon may still be the active artifact.
		if ( self::isAddonActive() ) {
			return array(
				'installed' => true,
				'activated' => true,
				'message'   => __( 'The NV oOS Content Graph — AI addon is already installed and active.', 'nvoos-content-graph' ),
			);
		}

		$existing = self::detectExistingBasePlugin();
		if ( '' !== $existing ) {
			return new WP_Error(
				'nvoos_content_graph_base_plugin_exists',
				sprintf(
					/* translators: %s: existing plugin folder. */
					__( 'You already have the NV oOS plugin installed (%s). Installing the Complete bundle alongside it would create a second copy and break both plugins. Update your existing plugin from the Plugins screen instead — your purchase is recorded.', 'nvoos-content-graph' ),
					$existing
				),
				array(
					'status'  => 409,
					'zip_url' => $zipUrl,
					'manual'  => true,
				)
			);
		}

		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}

		$tmp = download_url( $zipUrl, 300 );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'nvoos_content_graph_download_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not download the NV oOS Complete package: %s', 'nvoos-content-graph' ),
					$tmp->get_error_message()
				),
				array(
					'status'  => 502,
					'zip_url' => $zipUrl,
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
				'nvoos_content_graph_install_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'Could not install the NV oOS Complete package: %s', 'nvoos-content-graph' ),
					$result->get_error_message()
				),
				array(
					'status'  => 500,
					'zip_url' => $zipUrl,
					'manual'  => true,
				)
			);
		}

		if ( false === $result ) {
			return new WP_Error(
				'nvoos_content_graph_install_failed',
				__( 'The WordPress upgrader could not install the NV oOS Complete package.', 'nvoos-content-graph' ),
				array(
					'status'  => 500,
					'zip_url' => $zipUrl,
					'manual'  => true,
				)
			);
		}

		if ( ! self::isBundleInstalled() ) {
			return new WP_Error(
				'nvoos_content_graph_install_failed',
				__( 'The package was installed but its main plugin file is missing. The package may be incomplete.', 'nvoos-content-graph' ),
				array(
					'status'  => 500,
					'zip_url' => $zipUrl,
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
					__( 'NV oOS Complete was installed but could not be activated automatically: %s Activate it on the Plugins screen.', 'nvoos-content-graph' ),
					$activated->get_error_message()
				),
			);
		}

		return array(
			'installed' => true,
			'activated' => true,
			'message'   => __( 'NV oOS Complete installed and activated.', 'nvoos-content-graph' ),
		);
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
