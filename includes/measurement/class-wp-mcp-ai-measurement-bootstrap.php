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

	// Boot the eval suite registry last — by the time this fires, all
	// verifiers and rewards are registered so suite authors can reference
	// them without ordering headaches.
	if ( class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) ) {
		WP_MCP_AI_Eval_Suite_Registry::get_instance()->boot();
	}

	// Prime the collector singleton so listeners attach early.
	WP_MCP_AI_Metric_Collector::get_instance();
}

/**
 * Register the reference verifiers (rule, schema, LLM-judge).
 *
 * Attached to `wp_mcp_ai_register_verifiers` at priority 20 so third-party
 * verifiers (priority 10) can pre-empt the default instances by slug.
 *
 * Sites may disable any reference verifier via the
 * `wp_mcp_ai_enable_reference_verifiers` filter — returning an array of
 * slugs to keep, or an empty array to disable all of them.
 *
 * @param WP_MCP_AI_Verifier_Registry $registry Registry.
 * @return void
 */
function wp_mcp_ai_register_reference_verifiers( $registry ) {
	if ( ! $registry instanceof WP_MCP_AI_Verifier_Registry ) {
		return;
	}
	/**
	 * Filters which reference verifiers get registered.
	 *
	 * @since 1.3.0
	 *
	 * @param array<int,string> $slugs Slugs to register.
	 */
	$enabled = apply_filters(
		'wp_mcp_ai_enable_reference_verifiers',
		array( 'rule_verifier', 'schema_verifier', 'llm_judge' )
	);
	if ( ! is_array( $enabled ) ) {
		return;
	}

	if ( in_array( 'rule_verifier', $enabled, true ) && null === $registry->get( 'rule_verifier' ) ) {
		$registry->register( new WP_MCP_AI_Rule_Verifier( 'rule_verifier' ) );
	}
	if ( in_array( 'schema_verifier', $enabled, true ) && null === $registry->get( 'schema_verifier' ) ) {
		$registry->register( new WP_MCP_AI_Schema_Verifier( 'schema_verifier' ) );
	}
	if ( in_array( 'llm_judge', $enabled, true ) && null === $registry->get( 'llm_judge' ) ) {
		// No judge callable by default — the verifier abstains until one is
		// supplied via `wp_mcp_ai_llm_judge_callable`.
		$registry->register( new WP_MCP_AI_LLM_Judge_Verifier( 'llm_judge' ) );
	}
}

/**
 * Register the reference reward functions.
 *
 * @param WP_MCP_AI_Reward_Function_Registry $registry Registry.
 * @return void
 */
function wp_mcp_ai_register_reference_rewards( $registry ) {
	if ( ! $registry instanceof WP_MCP_AI_Reward_Function_Registry ) {
		return;
	}
	/**
	 * Filters whether reference reward functions get registered.
	 *
	 * @since 1.3.0
	 *
	 * @param bool $enabled Whether to register defaults. Default true.
	 */
	$enabled = (bool) apply_filters( 'wp_mcp_ai_enable_reference_rewards', true );
	if ( ! $enabled ) {
		return;
	}
	WP_MCP_AI_Reference_Rewards::register( $registry );
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
	add_action( 'wp_mcp_ai_register_verifiers', 'wp_mcp_ai_register_reference_verifiers', 20 );
	add_action( 'wp_mcp_ai_register_reward_functions', 'wp_mcp_ai_register_reference_rewards', 20 );

	// Mount the read-only measurement dashboard admin page.
	if ( is_admin() && class_exists( 'WP_MCP_AI_Admin_Measurement_Dashboard' ) ) {
		add_action(
			'plugins_loaded',
			static function () {
				new WP_MCP_AI_Admin_Measurement_Dashboard();
			},
			55
		);
	}
}
