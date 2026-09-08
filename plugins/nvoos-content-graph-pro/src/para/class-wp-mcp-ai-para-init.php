<?php
/**
 * PARA Init (self-booting) (ecosystem port — Wave F2, PM PARA batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/para/class-wp-mcp-ai-para-init.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs — the addon's autoloader skips its copy when
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the init's `__DIR__` requires resolve from
 * the addon's `src/para/` copies (same batch).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-para-taxonomy.php';
require_once __DIR__ . '/class-wp-mcp-ai-para-area-cpt.php';
require_once __DIR__ . '/class-wp-mcp-ai-para-lifecycle.php';
require_once __DIR__ . '/class-wp-mcp-ai-para-admin-columns.php';

WP_MCP_AI_PARA_Taxonomy::init();
WP_MCP_AI_PARA_Area_CPT::init();
WP_MCP_AI_PARA_Lifecycle::init();
WP_MCP_AI_PARA_Admin_Columns::init();

// Hook the metabox save handler for any post that supports the taxonomy.
add_action(
	'save_post',
	function ( $post_id ) {
		if ( ! class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		if ( ! in_array( $post->post_type, WP_MCP_AI_PARA_Taxonomy::get_object_types(), true ) ) {
			return;
		}
		WP_MCP_AI_PARA_Taxonomy::save_post( $post_id );
	},
	10,
	1
);
