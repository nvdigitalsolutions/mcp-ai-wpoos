<?php
/**
 * ECA Management Custom Post Types Registration
 *
 * Registers custom post types for ECAs (Extra-Curricular Activities) and student bookings.
 * Supports integration with iSAMS and SOCS booking system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register ECA management custom post types.
 */
function wp_mcp_ai_register_eca_management_post_types() {
	// Only register if ECA management is enabled and not base version.
	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
		return;
	}

	// Check if ECA management is enabled in settings.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_eca_management'] ) ) {
		return;
	}

	// Register ECA CPT (Extra-Curricular Activities).
	register_post_type(
		'mcp_ai_eca',
		array(
			'labels'             => array(
				'name'               => __( 'ECAs', 'wp-mcp-ai' ),
				'singular_name'      => __( 'ECA', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New ECA', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit ECA', 'wp-mcp-ai' ),
				'new_item'           => __( 'New ECA', 'wp-mcp-ai' ),
				'view_item'          => __( 'View ECA', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search ECAs', 'wp-mcp-ai' ),
				'not_found'          => __( 'No ECAs found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No ECAs found in trash', 'wp-mcp-ai' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'author' ),
			'menu_icon'          => 'dashicons-calendar',
		)
	);

	// Register ECA Booking CPT.
	register_post_type(
		'mcp_ai_eca_booking',
		array(
			'labels'             => array(
				'name'               => __( 'ECA Bookings', 'wp-mcp-ai' ),
				'singular_name'      => __( 'ECA Booking', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New Booking', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit Booking', 'wp-mcp-ai' ),
				'new_item'           => __( 'New Booking', 'wp-mcp-ai' ),
				'view_item'          => __( 'View Booking', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search Bookings', 'wp-mcp-ai' ),
				'not_found'          => __( 'No bookings found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No bookings found in trash', 'wp-mcp-ai' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'author' ),
			'menu_icon'          => 'dashicons-tickets-alt',
		)
	);
}
add_action( 'init', 'wp_mcp_ai_register_eca_management_post_types' );
