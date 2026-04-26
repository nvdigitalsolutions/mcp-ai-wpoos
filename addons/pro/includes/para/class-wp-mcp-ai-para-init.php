<?php
/**
 * PARA Initialization.
 *
 * Bootstraps the PARA (Projects/Areas/Resources/Archives) framework when
 * the Project Management toolkit is enabled and the `enable_para_organization`
 * feature flag is on.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

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
