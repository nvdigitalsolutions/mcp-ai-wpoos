<?php
/**
 * tools/places/init.php (ecosystem port — Wave F6, places data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/places/init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the CPT require resolves from the addon's `src/`
 * copy; the two admin-page requires are file-gated until the places admin slice lands (inside the
 * byte-identical enabled/base-version/pro-active gate); NEW standalone-only wiring (deviation,
 * same as the quiz init): a `wp_mcp_ai_pro_tools` filter plus
 * `wp_mcp_ai_pro_register_places_ecosystem_tools()` — both start empty and fill as the places
 * tool batch lands; full-body `! defined( 'WP_MCP_AI_PATH' )` guard (the global CPT registration
 * helper would collide compile-time with the base copy in the monorepo test matrix).
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

if ( ! defined( 'WP_MCP_AI_PATH' ) ) {

	/**
	 * Register places management custom post type.
	 */
	function wp_mcp_ai_register_places_management_post_type() {
		// Only register if places management is enabled and not base version, unless Pro addon is active.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return;
		}

		// Check if places management is enabled in settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_places_management'] ) ) {
			return;
		}

		// Register Place CPT.
		register_post_type(
			'mcp_ai_place',
			array(
				'labels'             => array(
					'name'               => __( 'Places', 'nvoos-content-graph-pro' ),
					'singular_name'      => __( 'Place', 'nvoos-content-graph-pro' ),
					'add_new'            => __( 'Add New', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Place', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Place', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Place', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Place', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Places', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No places found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No places found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'author' ),
				'menu_icon'          => 'dashicons-location-alt',
				'taxonomies'         => array( 'mcp_ai_place_type', 'mcp_ai_place_tag' ),
			)
		);

		// Register Place Type taxonomy.
		register_taxonomy(
			'mcp_ai_place_type',
			'mcp_ai_place',
			array(
				'labels'            => array(
					'name'          => __( 'Place Types', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Place Type', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Place Types', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Place Types', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Place Type', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Place Type', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Place Type', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Place Type Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Place Types', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register Place Tag taxonomy.
		register_taxonomy(
			'mcp_ai_place_tag',
			'mcp_ai_place',
			array(
				'labels'            => array(
					'name'          => __( 'Place Tags', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Place Tag', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Place Tags', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Place Tags', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Place Tag', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Place Tag', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Place Tag', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Place Tag Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Place Tags', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register default place types if they don't exist.
		$default_types = array(
			'restaurant'    => __( 'Restaurant', 'nvoos-content-graph-pro' ),
			'cafe'          => __( 'Cafe', 'nvoos-content-graph-pro' ),
			'hotel'         => __( 'Hotel', 'nvoos-content-graph-pro' ),
			'attraction'    => __( 'Attraction', 'nvoos-content-graph-pro' ),
			'museum'        => __( 'Museum', 'nvoos-content-graph-pro' ),
			'park'          => __( 'Park', 'nvoos-content-graph-pro' ),
			'shopping'      => __( 'Shopping', 'nvoos-content-graph-pro' ),
			'entertainment' => __( 'Entertainment', 'nvoos-content-graph-pro' ),
			'business'      => __( 'Business', 'nvoos-content-graph-pro' ),
			'service'       => __( 'Service', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_types as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_place_type' ) ) {
				wp_insert_term( $name, 'mcp_ai_place_type', array( 'slug' => $slug ) );
			}
		}
	}
	add_action( 'init', 'wp_mcp_ai_register_places_management_post_type' );

	// Load Place CPT admin enhancements.
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-place-cpt.php';

	// Register Place meta fields with JetEngine for listing/discovery.
	if ( function_exists( 'jet_engine' ) && class_exists( 'WP_MCP_AI_JetEngine_Meta_Helper' ) ) {
		WP_MCP_AI_JetEngine_Meta_Helper::register_cpt_fields( 'mcp_ai_place' );
	}

	// Load Place Research & Add page.
	if ( is_admin() ) {
		// Check if places management is enabled and not in base version (unless Pro addon is active).
		$settings      = get_option( 'wp_mcp_ai_settings', array() );
		$is_enabled    = ! empty( $settings['enable_places_management'] );
		$is_base       = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

		if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
			$nvoos_content_graph_pro_place_research = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-place-research-page.php';
			if ( file_exists( $nvoos_content_graph_pro_place_research ) ) {
				require_once $nvoos_content_graph_pro_place_research;
			}
			$nvoos_content_graph_pro_place_settings = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-place-settings-page.php';
			if ( file_exists( $nvoos_content_graph_pro_place_settings ) ) {
				require_once $nvoos_content_graph_pro_place_settings;
			}
		}
	}

	// ---- Standalone-only tool wiring (deviation). ----
	add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_places_tools', 10 );

	if ( function_exists( 'nvoos_content_graph_get_tool_registry' ) ) {
		wp_mcp_ai_pro_register_places_ecosystem_tools();
	}
} // End monolith guard (deviation).

/**
 * Standalone-only tool filter — mirrors the monolith's inline places map (the
 * `enable_places_management` gate in `mcp-ai-wpoos-pro.php`). The map fills as
 * the places tool batch lands.
 *
 * @param array $tools Existing tool map.
 * @return array Extended tool map.
 */
function wp_mcp_ai_pro_register_places_tools( $tools ) {
	$nvoos_content_graph_pro_places_tools = array();

	return array_merge( $tools, $nvoos_content_graph_pro_places_tools );
}

/**
 * Standalone-only ecosystem registration — registers the ported places tools
 * into the ecosystem graph ToolRegistry and the nvoos/core registry via
 * `WP_MCP_AI_Pro_Tool_Adapter` (same wiring as the quiz inits). The list
 * fills as the places tool batch lands.
 *
 * @return void
 */
function wp_mcp_ai_pro_register_places_ecosystem_tools() {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-pro-tool-adapter.php';

	$nvoos_content_graph_pro_parent_registry = nvoos_content_graph_get_tool_registry();
	if ( ! $nvoos_content_graph_pro_parent_registry instanceof \NvoosContentGraph\ToolRegistry ) {
		return;
	}

	foreach (
		array() as $nvoos_content_graph_pro_tool_class
	) {
		$nvoos_content_graph_pro_adapter = new WP_MCP_AI_Pro_Tool_Adapter( new $nvoos_content_graph_pro_tool_class() );
		try {
			$nvoos_content_graph_pro_parent_registry->register( $nvoos_content_graph_pro_adapter );
		} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
			unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
		}

		// Wrap into the nvoos/core registry so the agentic chat loop can
		// resolve and execute the tool (same path the AI addon uses).
		if ( class_exists( 'NvoosContentGraphAi\CoreBridge' ) ) {
			$nvoos_content_graph_pro_core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
			try {
				$nvoos_content_graph_pro_core_tools->register( new \NvoosContentGraphAi\Adapter\GraphToolAdapter( $nvoos_content_graph_pro_adapter ) );
			} catch ( \RuntimeException $nvoos_content_graph_pro_e ) {
				unset( $nvoos_content_graph_pro_e ); // Duplicate slug — non-fatal.
			}
		}
	}
}
