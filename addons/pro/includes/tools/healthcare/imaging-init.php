<?php
/**
 * Healthcare Imaging Sub-toolkit Bootstrap
 *
 * Loaded by `includes/healthcare-toolkit-init.php` when the
 * `enable_healthcare_imaging` setting is enabled.  Carries the boot
 * sequence that previously lived in
 * `includes/healthcare-imaging-toolkit-init.php` (now a backwards-
 * compatibility shim that forwards to the unified bootstrap).
 *
 * Loads in order:
 *  1. Capabilities helper (static – no hooks needed at this point)
 *  2. Audit log class (static)
 *  3. DICOM metadata extractor (static)
 *  4. Imaging Study CPT class + registers CPT
 *  5. REST controller (hooked to rest_api_init)
 *  6. Admin page (hooked to admin_menu via its own init())
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load capability helper.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-imaging-capabilities.php';

// Load HIPAA-aligned audit log class (legacy, scoped to imaging events).
// The unified `WP_MCP_AI_Healthcare_Audit` ledger lives alongside it.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-imaging-audit-log.php';

// Load lightweight DICOM metadata extractor.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-dicom-metadata.php';

// Load Imaging Study CPT and register it.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-imaging-study-cpt.php';
WP_MCP_AI_Imaging_Study_CPT::init();

// Add custom capabilities to administrator on first load.
add_action(
	'init',
	static function () {
		WP_MCP_AI_Imaging_Capabilities::add_caps();
	},
	1
);

// Load and register REST controller.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-imaging-rest-controller.php';
add_action(
	'rest_api_init',
	static function () {
		$controller = new WP_MCP_AI_Imaging_REST_Controller();
		$controller->register_routes();
	}
);

// Load admin page when in WP admin context.
if ( is_admin() ) {
	$wp_mcp_ai_imaging_settings = get_option( 'wp_mcp_ai_settings', array() );
	$wp_mcp_ai_imaging_is_base  = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$wp_mcp_ai_imaging_pro_on   = defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( ! $wp_mcp_ai_imaging_is_base || $wp_mcp_ai_imaging_pro_on ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-imaging-admin-page.php';
		WP_MCP_AI_Imaging_Admin_Page::init();
	}

	unset( $wp_mcp_ai_imaging_settings, $wp_mcp_ai_imaging_is_base, $wp_mcp_ai_imaging_pro_on );
}
