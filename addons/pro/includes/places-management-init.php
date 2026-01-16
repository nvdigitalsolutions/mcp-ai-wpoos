<?php
/**
 * Places Management Custom Post Type Registration
 *
 * Registers custom post type for managing places (attractions, businesses, locations).
 * Integrates with Google Maps/Places API for enhanced geospatial capabilities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
				'name'               => __( 'Places', 'mcp-ai-wpoos-pro' ),
				'singular_name'      => __( 'Place', 'mcp-ai-wpoos-pro' ),
				'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
				'add_new_item'       => __( 'Add New Place', 'mcp-ai-wpoos-pro' ),
				'edit_item'          => __( 'Edit Place', 'mcp-ai-wpoos-pro' ),
				'new_item'           => __( 'New Place', 'mcp-ai-wpoos-pro' ),
				'view_item'          => __( 'View Place', 'mcp-ai-wpoos-pro' ),
				'search_items'       => __( 'Search Places', 'mcp-ai-wpoos-pro' ),
				'not_found'          => __( 'No places found', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash' => __( 'No places found in trash', 'mcp-ai-wpoos-pro' ),
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
				'name'          => __( 'Place Types', 'mcp-ai-wpoos-pro' ),
				'singular_name' => __( 'Place Type', 'mcp-ai-wpoos-pro' ),
				'search_items'  => __( 'Search Place Types', 'mcp-ai-wpoos-pro' ),
				'all_items'     => __( 'All Place Types', 'mcp-ai-wpoos-pro' ),
				'edit_item'     => __( 'Edit Place Type', 'mcp-ai-wpoos-pro' ),
				'update_item'   => __( 'Update Place Type', 'mcp-ai-wpoos-pro' ),
				'add_new_item'  => __( 'Add New Place Type', 'mcp-ai-wpoos-pro' ),
				'new_item_name' => __( 'New Place Type Name', 'mcp-ai-wpoos-pro' ),
				'menu_name'     => __( 'Place Types', 'mcp-ai-wpoos-pro' ),
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
				'name'          => __( 'Place Tags', 'mcp-ai-wpoos-pro' ),
				'singular_name' => __( 'Place Tag', 'mcp-ai-wpoos-pro' ),
				'search_items'  => __( 'Search Place Tags', 'mcp-ai-wpoos-pro' ),
				'all_items'     => __( 'All Place Tags', 'mcp-ai-wpoos-pro' ),
				'edit_item'     => __( 'Edit Place Tag', 'mcp-ai-wpoos-pro' ),
				'update_item'   => __( 'Update Place Tag', 'mcp-ai-wpoos-pro' ),
				'add_new_item'  => __( 'Add New Place Tag', 'mcp-ai-wpoos-pro' ),
				'new_item_name' => __( 'New Place Tag Name', 'mcp-ai-wpoos-pro' ),
				'menu_name'     => __( 'Place Tags', 'mcp-ai-wpoos-pro' ),
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
		'restaurant'    => __( 'Restaurant', 'mcp-ai-wpoos-pro' ),
		'cafe'          => __( 'Cafe', 'mcp-ai-wpoos-pro' ),
		'hotel'         => __( 'Hotel', 'mcp-ai-wpoos-pro' ),
		'attraction'    => __( 'Attraction', 'mcp-ai-wpoos-pro' ),
		'museum'        => __( 'Museum', 'mcp-ai-wpoos-pro' ),
		'park'          => __( 'Park', 'mcp-ai-wpoos-pro' ),
		'shopping'      => __( 'Shopping', 'mcp-ai-wpoos-pro' ),
		'entertainment' => __( 'Entertainment', 'mcp-ai-wpoos-pro' ),
		'business'      => __( 'Business', 'mcp-ai-wpoos-pro' ),
		'service'       => __( 'Service', 'mcp-ai-wpoos-pro' ),
	);

	foreach ( $default_types as $slug => $name ) {
		if ( ! term_exists( $slug, 'mcp_ai_place_type' ) ) {
			wp_insert_term( $name, 'mcp_ai_place_type', array( 'slug' => $slug ) );
		}
	}
}
add_action( 'init', 'wp_mcp_ai_register_places_management_post_type' );

// Load Place CPT admin enhancements.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-place-cpt.php';

// Load Place Research & Add page.
if ( is_admin() ) {
	// Check if places management is enabled and not in base version (unless Pro addon is active).
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	$is_enabled = ! empty( $settings['enable_places_management'] );
	$is_base = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
	$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );

	if ( $is_enabled && ( ! $is_base || $is_pro_active ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-place-research-page.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-place-settings-page.php';
	}
}
