<?php
/**
 * Vault Item Custom Post Type
 *
 * Registers the mcp_vault_item CPT for storing encrypted vault items.
 * Supports login, note, card, and identity types.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Vault_Item_CPT
 *
 * Registers and manages vault item custom post type.
 */
class WP_MCP_AI_Vault_Item_CPT {

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Vault_Item_CPT
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
	 * Register vault item custom post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Vault Items', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => _x( 'Vault Item', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => _x( 'Vault Items', 'Admin Menu text', 'mcp-ai-wpoos-pro' ),
			'name_admin_bar'        => _x( 'Vault Item', 'Add New on Toolbar', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Vault Item', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Vault Item', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Vault Item', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Vault Item', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Vault Items', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Vault Items', 'mcp-ai-wpoos-pro' ),
			'parent_item_colon'     => __( 'Parent Vault Items:', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No vault items found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No vault items found in Trash.', 'mcp-ai-wpoos-pro' ),
			'featured_image'        => _x( 'Vault Item Icon', 'Overrides the "Featured Image" phrase', 'mcp-ai-wpoos-pro' ),
			'set_featured_image'    => _x( 'Set icon', 'Overrides the "Set featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'remove_featured_image' => _x( 'Remove icon', 'Overrides the "Remove featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'use_featured_image'    => _x( 'Use as icon', 'Overrides the "Use as featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'archives'              => _x( 'Vault Item archives', 'The post type archive label used in nav menus', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => _x( 'Insert into vault item', 'Overrides the "Insert into post"/"Insert into page" phrase', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this vault item', 'Overrides the "Uploaded to this post"/"Uploaded to this page" phrase', 'mcp-ai-wpoos-pro' ),
			'filter_items_list'     => _x( 'Filter vault items list', 'Screen reader text for the filter links', 'mcp-ai-wpoos-pro' ),
			'items_list_navigation' => _x( 'Vault items list navigation', 'Screen reader text for the pagination', 'mcp-ai-wpoos-pro' ),
			'items_list'            => _x( 'Vault items list', 'Screen reader text for the items list', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'                => $labels,
			'description'           => __( 'Encrypted vault items (passwords, notes, cards, identities)', 'mcp-ai-wpoos-pro' ),
			'public'                => false,
			'publicly_queryable'    => false,
			'show_ui'               => false, // Hidden from admin UI - managed via custom admin page.
			'show_in_menu'          => false,
			'query_var'             => false,
			'rewrite'               => false,
			'capability_type'       => 'post',
			'capabilities'          => array(
				'edit_post'          => 'edit_own_vault_items',
				'read_post'          => 'read_own_vault_items',
				'delete_post'        => 'delete_own_vault_items',
				'edit_posts'         => 'edit_own_vault_items',
				'edit_others_posts'  => 'edit_others_vault_items',
				'delete_posts'       => 'delete_own_vault_items',
				'publish_posts'      => 'publish_vault_items',
				'read_private_posts' => 'read_private_vault_items',
			),
			'has_archive'           => false,
			'hierarchical'          => false,
			'menu_position'         => null,
			'supports'              => array( 'title', 'author' ),
			'show_in_rest'          => true, // Enable REST API access.
			'rest_base'             => 'vault-items',
			'rest_controller_class' => 'WP_REST_Posts_Controller',
		);

		register_post_type( 'mcp_vault_item', $args );

		// Add custom capabilities to administrator role.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( 'edit_own_vault_items' );
			$admin_role->add_cap( 'read_own_vault_items' );
			$admin_role->add_cap( 'delete_own_vault_items' );
			$admin_role->add_cap( 'edit_others_vault_items' );
			$admin_role->add_cap( 'publish_vault_items' );
			$admin_role->add_cap( 'read_private_vault_items' );
		}
	}
}

// Initialize.
WP_MCP_AI_Vault_Item_CPT::get_instance();
