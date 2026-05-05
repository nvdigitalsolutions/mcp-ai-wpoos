<?php
/**
 * NV oOS Skote — WooCommerce Bridge
 *
 * Phase-1 stub. Future phases will expose WooCommerce read paths
 * (`/wc/v3/products`, `/orders`, `/customers`) via the bridge controller.
 *
 * The bridge will NOT mint API keys. It re-uses the current user's
 * capabilities and the `WC_REST_*` permission helpers, which means the SPA
 * inherits whatever WC has configured for the logged-in operator.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce integration bridge.
 *
 * @since 0.1.0
 */
class NVOOS_Skote_WooCommerce_Bridge {

	/**
	 * Initialise hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		// Stub — no hooks required in Phase 1.
	}
}
