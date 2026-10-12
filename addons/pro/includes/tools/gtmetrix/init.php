<?php
/**
 * GTmetrix × WPCode Performance Optimization Toolkit — Initialization.
 *
 * Loads the GTmetrix toolkit services and registers the snapshot CPT, the
 * Action Scheduler poll hook and the prune cron. Tools register through the
 * central Pro tool registry in mcp-ai-wpoos-pro.php, gated on
 * `enable_gtmetrix_toolkit`.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-settings.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-client.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-fixture-reader.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-score-engine.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-fix-engine.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-poller.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-scheduler.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-snapshot-store.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-wpcode-bridge.php';

/**
 * Register the `enable_gtmetrix_toolkit` toggle in the plugin settings schema.
 *
 * @param array $settings Existing settings defaults.
 * @return array
 */
function wp_mcp_ai_gtmetrix_settings_defaults( $settings ) {
	if ( is_array( $settings ) ) {
		$settings['enable_gtmetrix_toolkit'] = false;
	}

	return $settings;
}
add_filter( 'wp_mcp_ai_settings_defaults', 'wp_mcp_ai_gtmetrix_settings_defaults' );

/**
 * Register the snapshot CPT when the toolkit is active.
 *
 * @return void
 */
function wp_mcp_ai_gtmetrix_register_storage() {
	if ( ! WP_MCP_AI_GTmetrix_Settings::is_enabled() ) {
		return;
	}

	WP_MCP_AI_GTmetrix_Snapshot_Store::register();
}
add_action( 'init', 'wp_mcp_ai_gtmetrix_register_storage' );

/**
 * Wire the Action Scheduler poll hook (only when AS is present).
 *
 * @return void
 */
function wp_mcp_ai_gtmetrix_wire_poller() {
	if ( ! WP_MCP_AI_GTmetrix_Settings::is_enabled() ) {
		return;
	}
	if ( ! function_exists( 'add_action' ) ) {
		return;
	}

	add_action( WP_MCP_AI_GTmetrix_Poller::AS_HOOK, array( 'WP_MCP_AI_GTmetrix_Poller', 'poll' ) );
}
add_action( 'init', 'wp_mcp_ai_gtmetrix_wire_poller', 20 );

/**
 * Prune expired snapshots on the daily cron.
 *
 * @return void
 */
function wp_mcp_ai_gtmetrix_prune_snapshots() {
	if ( ! class_exists( 'WP_MCP_AI_GTmetrix_Snapshot_Store' ) ) {
		return;
	}

	$store = new WP_MCP_AI_GTmetrix_Snapshot_Store();
	$store->prune( WP_MCP_AI_GTmetrix_Settings::get( 'snapshot_retention_days', 90 ) );
}
add_action( 'wp_mcp_ai_gtmetrix_prune', 'wp_mcp_ai_gtmetrix_prune_snapshots' );

/**
 * Weekly sweep hook (metrics-only over the core pages).
 *
 * @return void
 */
function wp_mcp_ai_gtmetrix_scheduled_sweep() {
	WP_MCP_AI_GTmetrix_Scheduler::enqueue_sweep( 'none' );
}
add_action( 'wp_mcp_ai_gtmetrix_scheduled_sweep', 'wp_mcp_ai_gtmetrix_scheduled_sweep' );

/**
 * Monthly review hook (full Lighthouse over the core pages).
 *
 * @return void
 */
function wp_mcp_ai_gtmetrix_monthly_review() {
	WP_MCP_AI_GTmetrix_Scheduler::monthly_review();
}
add_action( 'wp_mcp_ai_gtmetrix_monthly_review', 'wp_mcp_ai_gtmetrix_monthly_review' );
