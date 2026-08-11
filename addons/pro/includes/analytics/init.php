<?php
/**
 * Shared Analytics Service Bootstrap
 *
 * Loads the analytics subsystem — DTOs, cache, rate limiter, metric normalizer,
 * adapter interface, Meta adapter, and the core service singleton. All subsequent
 * pro-toolkits consume analytics through the WP_MCP_AI_Analytics_Service.
 *
 * Loaded on plugins_loaded priority 20 (after individual toolkit inits at
 * priority 10) so that platform adapters can be registered by toolkits.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license  Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only load if the Pro addon is active.
if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
	return;
}

/**
 * Initialize the analytics subsystem.
 *
 * @since 1.7.0
 * @return void
 */
function wp_mcp_ai_analytics_init() {
	// Phase 1: DTOs (immutable data carriers).
	require_once __DIR__ . '/dto/class-wp-mcp-ai-analytics-account-dto.php';
	require_once __DIR__ . '/dto/class-wp-mcp-ai-analytics-post-dto.php';
	require_once __DIR__ . '/dto/class-wp-mcp-ai-analytics-metric-dto.php';
	require_once __DIR__ . '/dto/class-wp-mcp-ai-analytics-timeseries-dto.php';
	require_once __DIR__ . '/dto/class-wp-mcp-ai-analytics-report-dto.php';

	// Phase 1: Supporting services.
	require_once __DIR__ . '/class-wp-mcp-ai-analytics-cache.php';
	require_once __DIR__ . '/class-wp-mcp-ai-analytics-rate-limiter.php';
	require_once __DIR__ . '/class-wp-mcp-ai-analytics-metric-normalizer.php';
	require_once __DIR__ . '/class-wp-mcp-ai-analytics-site-health.php';

	// Phase 2: Adapter interface + platform adapters.
	require_once __DIR__ . '/adapters/interface-wp-mcp-ai-analytics-adapter.php';
	require_once __DIR__ . '/adapters/class-wp-mcp-ai-analytics-meta-adapter.php';
	require_once __DIR__ . '/adapters/class-wp-mcp-ai-analytics-twitter-adapter.php';
	require_once __DIR__ . '/adapters/class-wp-mcp-ai-analytics-linkedin-adapter.php';
	require_once __DIR__ . '/adapters/class-wp-mcp-ai-analytics-tiktok-adapter.php';
	require_once __DIR__ . '/adapters/class-wp-mcp-ai-analytics-woocommerce-adapter.php';
	require_once __DIR__ . '/adapters/class-wp-mcp-ai-analytics-ga4-adapter.php';
	require_once __DIR__ . '/adapters/class-wp-mcp-ai-analytics-cloudways-adapter.php';

	// Phase 3: Core service singleton.
	require_once __DIR__ . '/class-wp-mcp-ai-analytics-service.php';

	// Register adapters immediately (they have no external dependencies).
	$service = WP_MCP_AI_Analytics_Service::instance();

	if ( class_exists( 'WP_MCP_AI_Analytics_Meta_Adapter' ) ) {
		$meta_adapter = new WP_MCP_AI_Analytics_Meta_Adapter();
		$service->register_adapter( 'meta', $meta_adapter );
		$service->register_adapter( 'facebook', $meta_adapter );
		$service->register_adapter( 'instagram', $meta_adapter );
	}

	if ( class_exists( 'WP_MCP_AI_Analytics_Twitter_Adapter' ) ) {
		$twitter_adapter = new WP_MCP_AI_Analytics_Twitter_Adapter();
		$service->register_adapter( 'twitter', $twitter_adapter );
	}

	if ( class_exists( 'WP_MCP_AI_Analytics_LinkedIn_Adapter' ) ) {
		$linkedin_adapter = new WP_MCP_AI_Analytics_LinkedIn_Adapter();
		$service->register_adapter( 'linkedin', $linkedin_adapter );
	}

	if ( class_exists( 'WP_MCP_AI_Analytics_TikTok_Adapter' ) ) {
		$tiktok_adapter = new WP_MCP_AI_Analytics_TikTok_Adapter();
		$service->register_adapter( 'tiktok', $tiktok_adapter );
	}

	if ( class_exists( 'WP_MCP_AI_Analytics_WooCommerce_Adapter' ) ) {
		$woocommerce_adapter = new WP_MCP_AI_Analytics_WooCommerce_Adapter();
		$service->register_adapter( 'woocommerce', $woocommerce_adapter );
	}

	if ( class_exists( 'WP_MCP_AI_Analytics_GA4_Adapter' ) ) {
		$ga4_adapter = new WP_MCP_AI_Analytics_GA4_Adapter();
		$service->register_adapter( 'google_analytics', $ga4_adapter );
	}

	if ( class_exists( 'WP_MCP_AI_Analytics_Cloudways_Adapter' ) ) {
		$cloudways_adapter = new WP_MCP_AI_Analytics_Cloudways_Adapter();
		$service->register_adapter( 'cloudways', $cloudways_adapter );
	}

	/**
	 * Fires after the analytics subsystem is initialized.
	 *
	 * Pro-toolkits should hook here (or later) to register their platform
	 * adapters via WP_MCP_AI_Analytics_Service::register_adapter().
	 *
	 * @since 1.7.0
	 */
	do_action( 'wp_mcp_ai_analytics_loaded' );

	// Register Site Health integration.
	if ( class_exists( 'WP_MCP_AI_Analytics_Site_Health' ) ) {
		new WP_MCP_AI_Analytics_Site_Health();
	}
}

// Load on plugins_loaded at priority 20 to allow toolkits to init first (priority 10).
add_action( 'plugins_loaded', 'wp_mcp_ai_analytics_init', 20 );
