<?php
/**
 * NV oOS Skote — JetEngine Bridge
 *
 * Phase-1 stub. Future phases will expose JetEngine Custom Content Type
 * (CCT) records to the SPA. CCT tables use the prefix `jet_cct_` (with
 * UNDERSCORES, not hyphens) — verified via JetEngine's
 * `Jet_Engine\Modules\Custom_Content_Types\DB::table_prefix()`. Hyphens are
 * only ever used in admin slugs and the REST namespace
 * `/wp-json/jet-cct/<slug>`.
 *
 * Any CCT registration done by this bridge MUST attach to `init` at priority
 * 11+ to avoid racing JetEngine's own CCT cache hydration which runs at
 * priorities 1–10.
 *
 * @package NV_oOS_Skote
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetEngine integration bridge.
 *
 * @since 0.1.0
 */
class NVOOS_Skote_JetEngine_Bridge {

	/**
	 * Initialise hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		// Stub — no hooks required in Phase 1.
		// Reserved: when we wire CCT bridges in Phase 4, register them on
		// `init` priority 11+ via a separate method here.
	}

	/**
	 * Resolve the wpdb table name for a CCT slug.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug CCT slug (without prefix).
	 *
	 * @return string Fully-qualified table name.
	 */
	public static function get_cct_table_name( $slug ) {
		global $wpdb;
		$slug = sanitize_key( (string) $slug );
		return $wpdb->prefix . 'jet_cct_' . $slug;
	}
}
