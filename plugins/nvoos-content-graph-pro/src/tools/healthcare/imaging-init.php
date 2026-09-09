<?php
/**
 * Healthcare Imaging Sub-toolkit Bootstrap (ecosystem port — Wave F4,
 * healthcare data layer).
 *
 * Slimmed standalone init for the `nvoos-content-graph-pro` addon. Loaded by
 * the addon's slim `src/tools/healthcare/init.php` when the
 * `enable_healthcare_imaging` setting is enabled. The base Pro addon owns the
 * same init monolith.
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the
 * capabilities/audit/metadata/CPT/REST requires resolve from the addon's
 * already-ported `src/` copies; the imaging admin-page require is
 * file-gated until the healthcare admin slice lands; full-body
 * `! defined( 'WP_MCP_AI_PATH' )` guard (consistency with the sibling
 * slim inits).
 *
 * @package NvoosContentGraphPro
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	// Load capability helper.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-imaging-capabilities.php';

	// Load HIPAA-aligned audit log class (legacy, scoped to imaging events).
	// The unified `WP_MCP_AI_Healthcare_Audit` ledger lives alongside it.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-imaging-audit-log.php';

	// Load lightweight DICOM metadata extractor.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-dicom-metadata.php';

	// Load Imaging Study CPT and register it.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-imaging-study-cpt.php';
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
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-imaging-rest-controller.php';
	add_action(
		'rest_api_init',
		static function () {
			$controller = new WP_MCP_AI_Imaging_REST_Controller();
			$controller->register_routes();
		}
	);

	// Load admin page when in WP admin context (file-gated — the page lands
	// with the healthcare admin slice).
	if ( is_admin() ) {
		$nvoos_content_graph_pro_imaging_settings = get_option( 'wp_mcp_ai_settings', array() );
		$nvoos_content_graph_pro_imaging_is_base  = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$nvoos_content_graph_pro_imaging_pro_on   = defined( 'WP_MCP_AI_PRO_VERSION' );

		if ( ! $nvoos_content_graph_pro_imaging_is_base || $nvoos_content_graph_pro_imaging_pro_on ) {
			$nvoos_content_graph_pro_imaging_admin_page = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-imaging-admin-page.php';
			if ( file_exists( $nvoos_content_graph_pro_imaging_admin_page ) ) {
				require_once $nvoos_content_graph_pro_imaging_admin_page;
				WP_MCP_AI_Imaging_Admin_Page::init();
			}
		}

		unset(
			$nvoos_content_graph_pro_imaging_settings,
			$nvoos_content_graph_pro_imaging_is_base,
			$nvoos_content_graph_pro_imaging_pro_on
		);
	}
} // End monolith guard (deviation).
