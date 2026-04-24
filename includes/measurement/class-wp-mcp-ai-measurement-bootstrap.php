<?php
/**
 * Measurement Bootstrap
 *
 * Instantiates the measurement singletons and wires them into the plugin's
 * standard lifecycle. Loaded from `includes/bootstrap/loader.php`.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize the measurement subsystem.
 *
 * Safe to call multiple times — each registry's boot() is idempotent.
 *
 * @return void
 */
function wp_mcp_ai_measurement_bootstrap() {
	$registry = WP_MCP_AI_Measurement_Registry::get_instance();
	$registry->boot();

	$verifiers = WP_MCP_AI_Verifier_Registry::get_instance();
	$verifiers->boot();

	$rewards = WP_MCP_AI_Reward_Function_Registry::get_instance();
	$rewards->boot();

	// Prime the collector singleton so listeners attach early.
	WP_MCP_AI_Metric_Collector::get_instance();
}

/**
 * Lightweight capability bootstrap — grants the `manage_wp_mcp_ai_measurements`
 * and `view_wp_mcp_ai_measurements` capabilities to administrators unless the
 * site has explicitly filtered the default role map. Called on first admin
 * request; does nothing if the capabilities are already present.
 *
 * @return void
 */
function wp_mcp_ai_measurement_ensure_capabilities() {
	if ( ! function_exists( 'get_role' ) ) {
		return;
	}
	$role = get_role( 'administrator' );
	if ( null === $role ) {
		return;
	}

	/**
	 * Filters the measurement capabilities added to administrators.
	 *
	 * Site owners can remove capabilities to split measurement roles among
	 * different users. Removing a capability here does not grant it back
	 * automatically — use `user_has_cap` or a membership plugin to delegate.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int,string> $caps Capability slugs.
	 */
	$caps = apply_filters(
		'wp_mcp_ai_measurement_admin_capabilities',
		array( 'manage_wp_mcp_ai_measurements', 'view_wp_mcp_ai_measurements' )
	);

	if ( ! is_array( $caps ) ) {
		return;
	}
	foreach ( $caps as $cap ) {
		if ( is_string( $cap ) && '' !== $cap && ! $role->has_cap( $cap ) ) {
			$role->add_cap( $cap );
		}
	}
}

// Wire bootstrap into WordPress lifecycle. `plugins_loaded` at a late priority
// ensures that other plugins (and the Pro addon) have had a chance to register
// their own measurement hooks before the registries freeze.
if ( function_exists( 'add_action' ) ) {
	add_action( 'plugins_loaded', 'wp_mcp_ai_measurement_bootstrap', 50 );
	add_action( 'admin_init', 'wp_mcp_ai_measurement_ensure_capabilities', 5 );
}
