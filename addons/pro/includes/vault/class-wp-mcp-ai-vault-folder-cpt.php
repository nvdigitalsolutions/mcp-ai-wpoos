<?php
/**
 * Vault Folder Custom Post Type
 *
 * Registers the mcp_vault_folder CPT for organizing vault items into folders.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Vault_Folder_CPT
 *
 * Registers and manages vault folder custom post type.
 */
class WP_MCP_AI_Vault_Folder_CPT {

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Vault_Folder_CPT
	 */
	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Call register_post_type() directly instead of hooking to 'init'.
		// This is necessary because this class is instantiated during the 'init' hook,
		// and adding another 'init' action at that point won't fire until the next request.
		$this->register_post_type();
	}

	/**
	 * Register vault folder custom post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Vault Folders', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => _x( 'Vault Folder', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => _x( 'Vault Folders', 'Admin Menu text', 'mcp-ai-wpoos-pro' ),
			'name_admin_bar'        => _x( 'Vault Folder', 'Add New on Toolbar', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Vault Folder', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Vault Folder', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Vault Folder', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Vault Folder', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Vault Folders', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Vault Folders', 'mcp-ai-wpoos-pro' ),
			'parent_item_colon'     => __( 'Parent Vault Folders:', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No vault folders found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No vault folders found in Trash.', 'mcp-ai-wpoos-pro' ),
			'archives'              => _x( 'Vault Folder archives', 'The post type archive label used in nav menus', 'mcp-ai-wpoos-pro' ),
			'filter_items_list'     => _x( 'Filter vault folders list', 'Screen reader text for the filter links', 'mcp-ai-wpoos-pro' ),
			'items_list_navigation' => _x( 'Vault folders list navigation', 'Screen reader text for the pagination', 'mcp-ai-wpoos-pro' ),
			'items_list'            => _x( 'Vault folders list', 'Screen reader text for the items list', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'                => $labels,
			'description'           => __( 'Folders for organizing vault items', 'mcp-ai-wpoos-pro' ),
			'public'                => false,
			'publicly_queryable'    => false,
			'show_ui'               => false, // Hidden from admin UI - managed via custom admin page.
			'show_in_menu'          => false,
			'query_var'             => false,
			'rewrite'               => false,
			'capability_type'       => 'post',
			'capabilities'          => array(
				'edit_post'          => 'edit_own_vault_folders',
				'read_post'          => 'read_own_vault_folders',
				'delete_post'        => 'delete_own_vault_folders',
				'edit_posts'         => 'edit_own_vault_folders',
				'edit_others_posts'  => 'edit_others_vault_folders',
				'delete_posts'       => 'delete_own_vault_folders',
				'publish_posts'      => 'publish_vault_folders',
				'read_private_posts' => 'read_private_vault_folders',
			),
			'has_archive'           => false,
			'hierarchical'          => true, // Support parent/child folders.
			'menu_position'         => null,
			'supports'              => array( 'title', 'author', 'page-attributes' ),
			'show_in_rest'          => true, // Enable REST API access.
			'rest_base'             => 'vault-folders',
			'rest_controller_class' => 'WP_REST_Posts_Controller',
		);

		register_post_type( 'mcp_vault_folder', $args );

		// Add custom capabilities to administrator role.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( 'edit_own_vault_folders' );
			$admin_role->add_cap( 'read_own_vault_folders' );
			$admin_role->add_cap( 'delete_own_vault_folders' );
			$admin_role->add_cap( 'edit_others_vault_folders' );
			$admin_role->add_cap( 'publish_vault_folders' );
			$admin_role->add_cap( 'read_private_vault_folders' );
		}
	}
}

// Initialize.
WP_MCP_AI_Vault_Folder_CPT::get_instance();
