<?php
/**
 * NV oOS Docs Hub — Checkout License Store
 *
 * Local license/purchase record storage. Mirrors the Content Graph
 * license store (plugins/nvoos-content-graph/src/Commerce/License.php)
 * with docs-hub naming.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.5.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local license/purchase record.
 *
 * A single, autoload-free option holds the record of the most recent
 * successful purchase for this site: the license key, Stripe identifiers,
 * price paid, and purchaser. No card or secret-key data is ever stored.
 *
 * @since 0.5.2
 */
class NV_oOS_Docs_Hub_Checkout_License {

	/**
	 * Persist a purchase record.
	 *
	 * @since 0.5.2
	 *
	 * @param array<string,mixed> $record Purchase record.
	 * @return bool True on success.
	 */
	public static function save( $record ) {
		return (bool) update_option( NV_oOS_Docs_Hub_Checkout::OPTION_LICENSE, $record, false );
	}

	/**
	 * Retrieve the purchase record.
	 *
	 * @since 0.5.2
	 *
	 * @return array<string,mixed> Empty array when no purchase exists.
	 */
	public static function get() {
		$record = get_option( NV_oOS_Docs_Hub_Checkout::OPTION_LICENSE, array() );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Whether a valid purchase record exists.
	 *
	 * @since 0.5.2
	 *
	 * @return bool
	 */
	public static function is_licensed() {
		return '' !== self::license_key();
	}

	/**
	 * The stored license key (empty when unlicensed).
	 *
	 * @since 0.5.2
	 *
	 * @return string
	 */
	public static function license_key() {
		$record = self::get();
		return (string) ( isset( $record['license_key'] ) ? $record['license_key'] : '' );
	}

	/**
	 * Private constructor — static utility, not instantiable.
	 */
	private function __construct() {}
}
