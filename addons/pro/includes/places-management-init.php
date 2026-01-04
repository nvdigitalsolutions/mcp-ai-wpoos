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
	// Only register if places management is enabled and not base version.
	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
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
				'name'               => __( 'Places', 'wp-mcp-ai' ),
				'singular_name'      => __( 'Place', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New Place', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit Place', 'wp-mcp-ai' ),
				'new_item'           => __( 'New Place', 'wp-mcp-ai' ),
				'view_item'          => __( 'View Place', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search Places', 'wp-mcp-ai' ),
				'not_found'          => __( 'No places found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No places found in trash', 'wp-mcp-ai' ),
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
				'name'          => __( 'Place Types', 'wp-mcp-ai' ),
				'singular_name' => __( 'Place Type', 'wp-mcp-ai' ),
				'search_items'  => __( 'Search Place Types', 'wp-mcp-ai' ),
				'all_items'     => __( 'All Place Types', 'wp-mcp-ai' ),
				'edit_item'     => __( 'Edit Place Type', 'wp-mcp-ai' ),
				'update_item'   => __( 'Update Place Type', 'wp-mcp-ai' ),
				'add_new_item'  => __( 'Add New Place Type', 'wp-mcp-ai' ),
				'new_item_name' => __( 'New Place Type Name', 'wp-mcp-ai' ),
				'menu_name'     => __( 'Place Types', 'wp-mcp-ai' ),
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
				'name'          => __( 'Place Tags', 'wp-mcp-ai' ),
				'singular_name' => __( 'Place Tag', 'wp-mcp-ai' ),
				'search_items'  => __( 'Search Place Tags', 'wp-mcp-ai' ),
				'all_items'     => __( 'All Place Tags', 'wp-mcp-ai' ),
				'edit_item'     => __( 'Edit Place Tag', 'wp-mcp-ai' ),
				'update_item'   => __( 'Update Place Tag', 'wp-mcp-ai' ),
				'add_new_item'  => __( 'Add New Place Tag', 'wp-mcp-ai' ),
				'new_item_name' => __( 'New Place Tag Name', 'wp-mcp-ai' ),
				'menu_name'     => __( 'Place Tags', 'wp-mcp-ai' ),
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
		'restaurant'    => __( 'Restaurant', 'wp-mcp-ai' ),
		'cafe'          => __( 'Cafe', 'wp-mcp-ai' ),
		'hotel'         => __( 'Hotel', 'wp-mcp-ai' ),
		'attraction'    => __( 'Attraction', 'wp-mcp-ai' ),
		'museum'        => __( 'Museum', 'wp-mcp-ai' ),
		'park'          => __( 'Park', 'wp-mcp-ai' ),
		'shopping'      => __( 'Shopping', 'wp-mcp-ai' ),
		'entertainment' => __( 'Entertainment', 'wp-mcp-ai' ),
		'business'      => __( 'Business', 'wp-mcp-ai' ),
		'service'       => __( 'Service', 'wp-mcp-ai' ),
	);

	foreach ( $default_types as $slug => $name ) {
		if ( ! term_exists( $slug, 'mcp_ai_place_type' ) ) {
			wp_insert_term( $name, 'mcp_ai_place_type', array( 'slug' => $slug ) );
		}
	}
}
add_action( 'init', 'wp_mcp_ai_register_places_management_post_type' );
