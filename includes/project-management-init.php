<?php
/**
 * Project Management Custom Post Types Registration
 *
 * Registers custom post types for projects, tasks, and events.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register project management custom post types.
 */
function wp_mcp_ai_register_project_management_post_types() {
	// Only register if project management is enabled and not base version.
	if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
		return;
	}

	if ( ! get_option( 'wp_mcp_ai_enable_project_management', false ) ) {
		return;
	}

	// Register Project CPT.
	register_post_type(
		'mcp_ai_project',
		array(
			'labels'             => array(
				'name'               => __( 'Projects', 'wp-mcp-ai' ),
				'singular_name'      => __( 'Project', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New Project', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit Project', 'wp-mcp-ai' ),
				'new_item'           => __( 'New Project', 'wp-mcp-ai' ),
				'view_item'          => __( 'View Project', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search Projects', 'wp-mcp-ai' ),
				'not_found'          => __( 'No projects found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No projects found in trash', 'wp-mcp-ai' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'author' ),
			'menu_icon'          => 'dashicons-portfolio',
		)
	);

	// Register Task CPT.
	register_post_type(
		'mcp_ai_task',
		array(
			'labels'             => array(
				'name'               => __( 'Tasks', 'wp-mcp-ai' ),
				'singular_name'      => __( 'Task', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New Task', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit Task', 'wp-mcp-ai' ),
				'new_item'           => __( 'New Task', 'wp-mcp-ai' ),
				'view_item'          => __( 'View Task', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search Tasks', 'wp-mcp-ai' ),
				'not_found'          => __( 'No tasks found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No tasks found in trash', 'wp-mcp-ai' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'author' ),
			'menu_icon'          => 'dashicons-list-view',
		)
	);

	// Register Event CPT.
	register_post_type(
		'mcp_ai_event',
		array(
			'labels'             => array(
				'name'               => __( 'Events', 'wp-mcp-ai' ),
				'singular_name'      => __( 'Event', 'wp-mcp-ai' ),
				'add_new'            => __( 'Add New', 'wp-mcp-ai' ),
				'add_new_item'       => __( 'Add New Event', 'wp-mcp-ai' ),
				'edit_item'          => __( 'Edit Event', 'wp-mcp-ai' ),
				'new_item'           => __( 'New Event', 'wp-mcp-ai' ),
				'view_item'          => __( 'View Event', 'wp-mcp-ai' ),
				'search_items'       => __( 'Search Events', 'wp-mcp-ai' ),
				'not_found'          => __( 'No events found', 'wp-mcp-ai' ),
				'not_found_in_trash' => __( 'No events found in trash', 'wp-mcp-ai' ),
			),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'show_in_rest'       => true,
			'has_archive'        => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'supports'           => array( 'title', 'editor', 'author' ),
			'menu_icon'          => 'dashicons-calendar-alt',
		)
	);
}
add_action( 'init', 'wp_mcp_ai_register_project_management_post_types' );
