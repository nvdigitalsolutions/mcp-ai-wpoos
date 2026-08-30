<?php
declare(strict_types=1);

namespace NvoosContentGraph\Commerce;

use Plugin_Upgrader;
use WP_Error;

use function activate_plugin;
use function is_plugin_active;
use function is_wp_error;
use function download_url;
use function wp_clean_plugins_cache;
use function wp_delete_file;

/**
 * Downloads the AI addon ZIP from the release URL and installs it via the
 * WordPress upgrader, then activates it.
 *
 * Runs only after payment verification, inside an admin-authenticated
 * REST request, so filesystem access matches what wp-admin installs use.
 * On hosts that require FTP credentials this fails with a WP_Error
 * carrying the manual download URL.
 *
 * @since 1.0.4
 */
final class Installer {

	public const ADDON_SLUG     = 'nvoos-content-graph-ai';
	public const ADDON_BASENAME = 'nvoos-content-graph-ai/nvoos-content-graph-ai.php';

	/**
	 * Whether the addon is currently active.
	 *
	 * @return bool
	 */
	public static function isActive(): bool {
		return is_plugin_active( self::ADDON_BASENAME );
	}

	/**
	 * Whether the addon files are present on disk.
	 *
	 * @return bool
	 */
	public static function isInstalled(): bool {
		return file_exists( WP_PLUGIN_DIR . '/' . self::ADDON_BASENAME );
	}

	/**
	 * Install and activate the addon.
	 *
	 * Idempotent: an already-active addon returns success immediately.
	 *
	 * @since 1.0.4
	 *
	 * @param string $zipUrl The download URL of the addon ZIP (vendor-issued
	 *                       signed URL, or the filterable fallback URL).
	 * @return array<string,mixed>|WP_Error
	 *   array{installed: bool, activated: bool, message: string} on success.
	 */
	public static function install( string $zipUrl ) {
		if ( self::isActive() ) {
			return array(
				'installed' => true,
				'activated' => true,
				'message'   => __( 'NV oOS Content Graph — AI is already installed and active.', 'nvoos-content-graph' ),
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
					__( 'Could not download the addon package: %s', 'nvoos-content-graph' ),
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
					__( 'Could not install the addon package: %s', 'nvoos-content-graph' ),
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
				__( 'The WordPress upgrader could not install the addon package.', 'nvoos-content-graph' ),
				array(
					'status'  => 500,
					'zip_url' => $zipUrl,
					'manual'  => true,
				)
			);
		}

		if ( ! self::isInstalled() ) {
			return new WP_Error(
				'nvoos_content_graph_install_failed',
				__( 'The addon package was installed but its main plugin file is missing. The package may be incomplete.', 'nvoos-content-graph' ),
				array(
					'status'  => 500,
					'zip_url' => $zipUrl,
					'manual'  => true,
				)
			);
		}

		wp_clean_plugins_cache();

		$activated = activate_plugin( self::ADDON_BASENAME );

		if ( is_wp_error( $activated ) ) {
			return array(
				'installed' => true,
				'activated' => false,
				'message'   => sprintf(
					/* translators: %s: error message. */
					__( 'The addon was installed but could not be activated automatically: %s Activate it on the Plugins screen.', 'nvoos-content-graph' ),
					$activated->get_error_message()
				),
			);
		}

		return array(
			'installed' => true,
			'activated' => true,
			'message'   => __( 'NV oOS Content Graph — AI installed and activated.', 'nvoos-content-graph' ),
		);
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
