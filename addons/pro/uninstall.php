<?php
/**
 * Uninstall handler for NV oOS Pro Addon.
 *
 * Cleans up plugin-specific options, custom capabilities, and
 * transient data when the plugin is uninstalled.
 *
 * This file is only loaded when WP_UNINSTALL_PLUGIN is defined,
 * i.e. during a proper uninstall (not deactivation).
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// ── 1. Remove plugin-specific options ────────────────────────────────────

$option_keys = array(
	// Pro workflow builder storage.
	'wp_mcp_ai_pro_workflows',

	// Remote sites / chat channels connections.
	'wp_mcp_ai_pro_remote_sites',

	// Audit logs.
	'wp_mcp_ai_imaging_audit_log',
	'wp_mcp_ai_toolkit_mcp_audit_log',
	'wp_mcp_ai_healthcare_audit_log',

	// Migration flags.
	'wp_mcp_ai_channel_contacts_migration_v1',
	'wp_mcp_ai_channel_contacts_migration_v2',
	'wp_mcp_ai_channel_messages_migration_v1',
	'wp_mcp_ai_channel_messages_migration_v2',

	// Google Chat webhook log.
	'wp_mcp_ai_gc_webhook_log',

	// Telegram Mini App template selections.
	'wp_mcp_ai_telegram_mini_app_template',

	// EHR connections.
	'wp_mcp_ai_ehr_connections',
);

foreach ( $option_keys as $key ) {
	delete_option( $key );
}

// ── 2. Clean up wp_mcp_ai_pro_*-prefixed option keys ─────────────────────
// (toolkit settings, schedule settings, etc. follow a consistent prefix)

global $wpdb;

$pro_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		'wp_mcp_ai_pro_%'
	)
);

if ( is_array( $pro_options ) ) {
	foreach ( $pro_options as $option_name ) {
		delete_option( $option_name );
	}
}

// ── 3. Remove custom capabilities from all roles ─────────────────────────

$imaging_caps = array(
	'view_medical_imaging',
	'upload_medical_imaging',
	'delete_medical_imaging',
	'manage_medical_imaging',
);

$vault_folder_caps = array(
	'edit_own_vault_folders',
	'read_own_vault_folders',
	'delete_own_vault_folders',
	'edit_others_vault_folders',
	'publish_vault_folders',
	'read_private_vault_folders',
);

$vault_item_caps = array(
	'edit_own_vault_items',
	'read_own_vault_items',
	'delete_own_vault_items',
	'edit_others_vault_items',
	'publish_vault_items',
	'read_private_vault_items',
);

$qms_caps = array( 'manage_qms' );

$all_custom_caps = array_merge( $imaging_caps, $vault_folder_caps, $vault_item_caps, $qms_caps );

// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$wp_roles = wp_roles();
foreach ( $wp_roles->roles as $role_slug => $role_data ) {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	$role = get_role( $role_slug );
	if ( ! $role ) {
		continue;
	}
	foreach ( $all_custom_caps as $cap ) {
		$role->remove_cap( $cap );
	}
}

// ── 4. Clean up transients ───────────────────────────────────────────────

$transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options}
		WHERE option_name LIKE %s
		OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wp_mcp_ai_pro_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wp_mcp_ai_pro_' ) . '%'
	)
);

if ( is_array( $transients ) ) {
	foreach ( $transients as $transient ) {
		if ( strpos( $transient, '_transient_timeout_' ) === 0 ) {
			delete_option( $transient );
		} else {
			$key = str_replace( '_transient_', '', $transient );
			delete_transient( $key );
		}
	}
}

// ── 5. Clear scheduled cron hooks ────────────────────────────────────────

// These are registered by the Pro schedule manager and workflow dispatcher.
$pro_cron_hooks = array(
	'nvoos_graphify_cron_reindex_embeddings',
);

foreach ( $pro_cron_hooks as $hook ) {
	$timestamps = wp_get_scheduled_event( $hook );
	if ( $timestamps ) {
		wp_clear_scheduled_hook( $hook );
	}
}
