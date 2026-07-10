<?php
/**
 * NV oOS Page Agent — Uninstall
 *
 * Handles cleanup when the plugin is uninstalled.
 * Removes all options, transients, and stored data.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── Delete Options ──────────────────────────────────────────
delete_option( 'nvoos_page_agent_settings' );
delete_option( 'nvoos_page_agent_enabled' );
delete_option( 'nvoos_page_agent_model' );
delete_option( 'nvoos_page_agent_language' );
delete_option( 'nvoos_page_agent_max_steps' );

// ── Delete Transients ───────────────────────────────────────
global $wpdb;

// Delete all DOM snapshot transients.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_nvoos_page_agent_dom_snapshot_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_nvoos_page_agent_dom_snapshot_' ) . '%'
	)
);
// phpcs:enable

// Delete activation transient.
delete_transient( 'nvoos_page_agent_activated' );

// ── Clear Scheduled Hooks (future-proofing) ──────────────────
// No cron hooks are registered in v0.1.0, but this ensures
// cleanup when cron features are added in future versions.
wp_clear_scheduled_hook( 'nvoos_page_agent_daily_cleanup' );
