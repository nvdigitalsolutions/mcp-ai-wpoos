<?php
/**
 * ECA Management Custom Post Types Registration
 *
 * Registers custom post types for Extra-Curricular Activities (ECAs) and Students.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize ECA management admin interface.
 */
function wp_mcp_ai_init_eca_management_admin() {
	// Only load in admin context.
	if ( ! is_admin() ) {
		return;
	}

	// Check if ECA management is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_eca_management'] ) ) {
		return;
	}

	// Load metabox classes when available.
	$eca_metabox = __DIR__ . '/admin/class-wp-mcp-ai-eca-metabox.php';
	$student_metabox = __DIR__ . '/admin/class-wp-mcp-ai-student-metabox.php';
	
	if ( file_exists( $eca_metabox ) ) {
		require_once $eca_metabox;
		WP_MCP_AI_ECA_Metabox::init();
	}
	
	if ( file_exists( $student_metabox ) ) {
		require_once $student_metabox;
		WP_MCP_AI_Student_Metabox::init();
	}
}
add_action( 'admin_init', 'wp_mcp_ai_init_eca_management_admin' );

/**
 * Enqueue ECA management admin styles.
 *
 * @param string $hook Current admin page hook.
 */
function wp_mcp_ai_enqueue_eca_management_admin_styles( $hook ) {
	// Only load on ECA management edit screens.
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'mcp_ai_eca', 'mcp_ai_student' ), true ) ) {
		return;
	}

	// Check if ECA management is enabled.
	$settings = get_option( 'wp_mcp_ai_settings', array() );
	if ( empty( $settings['enable_eca_management'] ) ) {
		return;
	}

	// Enqueue admin styles.
	$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-eca-management.css';
	if ( file_exists( $css_file ) ) {
		wp_enqueue_style(
			'wp-mcp-ai-eca-management-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/admin-eca-management.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);
	}
}
add_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_eca_management_admin_styles' );

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

	// Register ECA (Extra-Curricular Activity) post type.
	register_post_type(
		'mcp_ai_eca',
		array(
			'labels'              => array(
				'name'                  => __( 'ECAs', 'mcp-ai-wpoos-pro' ),
				'singular_name'         => __( 'ECA', 'mcp-ai-wpoos-pro' ),
				'add_new'               => __( 'Add New', 'mcp-ai-wpoos-pro' ),
				'add_new_item'          => __( 'Add New ECA', 'mcp-ai-wpoos-pro' ),
				'edit_item'             => __( 'Edit ECA', 'mcp-ai-wpoos-pro' ),
				'new_item'              => __( 'New ECA', 'mcp-ai-wpoos-pro' ),
				'view_item'             => __( 'View ECA', 'mcp-ai-wpoos-pro' ),
				'search_items'          => __( 'Search ECAs', 'mcp-ai-wpoos-pro' ),
				'not_found'             => __( 'No ECAs found', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash'    => __( 'No ECAs found in Trash', 'mcp-ai-wpoos-pro' ),
				'all_items'             => __( 'All ECAs', 'mcp-ai-wpoos-pro' ),
				'menu_name'             => __( 'ECAs', 'mcp-ai-wpoos-pro' ),
			),
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => 'wp-mcp-ai-pro',
			'query_var'           => true,
			'rewrite'             => array( 'slug' => 'eca' ),
			'capability_type'     => 'post',
			'has_archive'         => true,
			'hierarchical'        => false,
			'menu_position'       => null,
			'menu_icon'           => 'dashicons-calendar-alt',
			'supports'            => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'show_in_rest'        => true,
		)
	);

	// Register Student post type.
	register_post_type(
		'mcp_ai_student',
		array(
			'labels'              => array(
				'name'                  => __( 'Students', 'mcp-ai-wpoos-pro' ),
				'singular_name'         => __( 'Student', 'mcp-ai-wpoos-pro' ),
				'add_new'               => __( 'Add New', 'mcp-ai-wpoos-pro' ),
				'add_new_item'          => __( 'Add New Student', 'mcp-ai-wpoos-pro' ),
				'edit_item'             => __( 'Edit Student', 'mcp-ai-wpoos-pro' ),
				'new_item'              => __( 'New Student', 'mcp-ai-wpoos-pro' ),
				'view_item'             => __( 'View Student', 'mcp-ai-wpoos-pro' ),
				'search_items'          => __( 'Search Students', 'mcp-ai-wpoos-pro' ),
				'not_found'             => __( 'No Students found', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash'    => __( 'No Students found in Trash', 'mcp-ai-wpoos-pro' ),
				'all_items'             => __( 'All Students', 'mcp-ai-wpoos-pro' ),
				'menu_name'             => __( 'Students', 'mcp-ai-wpoos-pro' ),
			),
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => 'wp-mcp-ai-pro',
			'query_var'           => true,
			'rewrite'             => array( 'slug' => 'student' ),
			'capability_type'     => 'post',
			'has_archive'         => true,
			'hierarchical'        => false,
			'menu_position'       => null,
			'menu_icon'           => 'dashicons-groups',
			'supports'            => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
			'show_in_rest'        => true,
		)
	);
}
add_action( 'init', 'wp_mcp_ai_register_eca_management_post_types' );
