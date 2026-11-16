<?php
/**
 * Uninstall handler for NV oOS WiFi.
 *
 * ⚠️  DATA IS PRESERVED BY DEFAULT. That is deliberate, not an oversight.
 *
 * Consent records are the legal evidence that a guest authorised access under
 * Jamaica's Data Protection Act 2020 (and equivalent regimes). Deleting the
 * plugin is an operator convenience, not a lawful basis for destroying that
 * evidence. A blanket delete-on-uninstall would also silently break any
 * downstream attribution the venue has published.
 *
 * An operator who genuinely wants the data gone defines:
 *
 *   define( 'NVOOS_WIFI_REMOVE_DATA_ON_UNINSTALL', true );
 *
 * in wp-config.php before deleting the plugin. That is a deliberate,
 * documented act — which is exactly what a data-destroying operation should be.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'NVOOS_WIFI_REMOVE_DATA_ON_UNINSTALL' ) || true !== NVOOS_WIFI_REMOVE_DATA_ON_UNINSTALL ) {
	// Preserve everything and leave a breadcrumb for a future maintainer who
	// wonders why this file does so little.
	return;
}

// ---------------------------------------------------------------------------
// Opt-in removal.
// ---------------------------------------------------------------------------

// Settings.
delete_option( 'nvoos_wifi_settings' );
delete_option( 'nvoos_wifi_venues' );

// KPI samples — one option per venue, named by md5 hash of the venue id.
global $wpdb;
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'nvoos_wifi_kpi_' ) . '%'
	)
);
