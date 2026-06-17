<?php
/**
 * Plugin Name: NV oOS SaaS Controller
 * Plugin URI:  https://nvdigitalsolutions.com/wpoos
 * Description: Operator-side toolkit to deploy and manage the NV oOS Cloud control plane (Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, OpenRouter). Provides a One-Click Wizard, Plan/Apply dashboard, drift detector, audit log, and smoke tests inside WP-Admin. Requires NV oOS base plugin.
 * Version:     0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.9
 * Author: NV Digital Solutions
 * Author URI:  https://nvdigitalsolutions.com
 * License: Proprietary — NV Digital Solutions, All Rights Reserved
 * License URI: https://nvdigitalsolutions.com/wpoos/license/saas-controller
 * Text Domain: nvoos-saas-controller
 * Domain Path: /languages
 *
 * @package NV_oOS_SaaS_Controller
 *
 * ⚠️ PROPRIETARY SOFTWARE
 * This is commercial software licensed for authorized users only.
 * Patent Pending (Application #19/410,504)
 * © 2025 NV Digital Solutions - All Rights Reserved
 *
 * Copyright (c) 2026 NV Digital Solutions (https://nvdigitalsolutions.com).
 * All rights reserved.
 *
 * This addon is PROPRIETARY software of NV Digital Solutions. It is NOT
 * licensed under the GPL that covers the rest of the NV oOS repository,
 * and it is NOT distributed via WordPress.org. Use, reproduction, modification,
 * and redistribution are governed by the addon-local `LICENSE` file shipped in
 * this directory. See `LICENSE` for the full terms.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Plugin version. */
define( 'NVOOS_SAAS_CONTROLLER_VERSION', '0.1.0' );

/** Absolute path to this plugin file. */
define( 'NVOOS_SAAS_CONTROLLER_FILE', __FILE__ );

/** Absolute path to this plugin directory (trailing slash). */
define( 'NVOOS_SAAS_CONTROLLER_PATH', plugin_dir_path( __FILE__ ) );

/** URL to this plugin directory (trailing slash). */
define( 'NVOOS_SAAS_CONTROLLER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Check whether the NV oOS base plugin is active.
 *
 * @since 0.1.0
 *
 * @return bool True if the base plugin is detected.
 */
function nvoos_saas_controller_base_is_active() {
	// The base plugin signals readiness via the `wp_mcp_ai_core_loaded()`
	// helper (defined in `includes/bootstrap/helpers.php`) and registers
	// the `WP_MCP_AI_Tool_Registry` class as part of its bootstrap. Either
	// signal is sufficient — mirror the check used by the Pro addon so all
	// addons agree on what "base is active" means.
	if ( function_exists( 'wp_mcp_ai_core_loaded' ) && wp_mcp_ai_core_loaded() ) {
		return true;
	}

	return class_exists( 'WP_MCP_AI_Tool_Registry' );
}

/**
 * Print an admin notice when the base plugin is missing.
 *
 * @since 0.1.0
 *
 * @return void
 */
function nvoos_saas_controller_base_missing_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__(
		'NV oOS SaaS Controller requires the NV oOS base plugin to be installed and activated.',
		'nvoos-saas-controller'
	);
	echo '</p></div>';
}

/**
 * Bootstrap the addon once all plugins are loaded.
 *
 * Phase 2 wires the credential store, the top-level admin menu (Overview +
 * Packages tabs), and the `/wp-json/nvoos-saas/v1/` REST namespace.
 * Subsequent phases (Wizard, Plan/Apply, Drift, Audit Log, Smoke Tests)
 * will mount onto these surfaces.
 *
 * @since 0.1.0
 *
 * @return void
 */
function nvoos_saas_controller_bootstrap() {
	if ( ! nvoos_saas_controller_base_is_active() ) {
		add_action( 'admin_notices', 'nvoos_saas_controller_base_missing_notice' );
		return;
	}

	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/class-nvoos-saas-controller-credential-store.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/class-nvoos-saas-controller-deployment-config.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/class-nvoos-saas-controller-audit-log.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/class-nvoos-saas-controller-webhook-event-store.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-connection-tester.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-cloudflare-client.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-stripe-client.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-stripe-webhook-verifier.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-openrouter-client.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-plan-generator.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-cloudflare-mutating-client.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-apply-engine.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-apply-job.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-smoke-tester.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/services/class-nvoos-saas-controller-drift-detector.php';
	require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/rest/class-nvoos-saas-controller-rest.php';

	// Register apply-job cron-status source for the Tasks Drawer.
	if ( interface_exists( 'Interface_WP_MCP_AI_Cron_Status_Job_Source' ) ) {
		require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/job-sources/class-nvoos-saas-controller-job-source.php';
		add_filter(
			'wp_mcp_ai_cron_status_job_sources',
			static function ( array $sources ) {
				$sources['saas_apply'] = new NVOOS_SaaS_Controller_Job_Source();
				return $sources;
			},
			10,
			1
		);
	}

	NVOOS_SaaS_Controller_REST::init();

	if ( is_admin() ) {
		require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/admin/class-nvoos-saas-controller-admin-page.php';
		require_once NVOOS_SAAS_CONTROLLER_PATH . 'includes/admin/class-nvoos-saas-controller-assets.php';
		NVOOS_SaaS_Controller_Admin_Page::init();
		NVOOS_SaaS_Controller_Assets::init();
	}
}
add_action( 'plugins_loaded', 'nvoos_saas_controller_bootstrap', 20 );
