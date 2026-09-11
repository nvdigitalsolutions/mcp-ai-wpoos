<?php
/**
 * Vault Folder Custom Post Type (ecosystem port — Wave F1, sub-cluster 4a).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/vault/class-wp-mcp-ai-vault-folder-cpt.php` for the
 * standalone `nvoos-content-graph-pro` addon. The global class name is kept
 * byte-identical; in monolith installs the base Pro addon owns the class
 * (this file is only loaded standalone).
 *
 * Registers the mcp_vault_folder CPT for organizing vault items into folders.
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `mcp-ai-wpoos-pro` → `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since   1.0.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

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
		add_action( 'init', array( $this, 'register_meta' ), 20 );
	}

	/**
	 * Register vault folder custom post type.
	 */
	public function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Vault Folders', 'Post type general name', 'nvoos-content-graph-pro' ),
			'singular_name'         => _x( 'Vault Folder', 'Post type singular name', 'nvoos-content-graph-pro' ),
			'menu_name'             => _x( 'Vault Folders', 'Admin Menu text', 'nvoos-content-graph-pro' ),
			'name_admin_bar'        => _x( 'Vault Folder', 'Add New on Toolbar', 'nvoos-content-graph-pro' ),
			'add_new'               => __( 'Add New', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Vault Folder', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Vault Folder', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Vault Folder', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Vault Folder', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Vault Folders', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Vault Folders', 'nvoos-content-graph-pro' ),
			'parent_item_colon'     => __( 'Parent Vault Folders:', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'No vault folders found.', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'No vault folders found in Trash.', 'nvoos-content-graph-pro' ),
			'archives'              => _x( 'Vault Folder archives', 'The post type archive label used in nav menus', 'nvoos-content-graph-pro' ),
			'filter_items_list'     => _x( 'Filter vault folders list', 'Screen reader text for the filter links', 'nvoos-content-graph-pro' ),
			'items_list_navigation' => _x( 'Vault folders list navigation', 'Screen reader text for the pagination', 'nvoos-content-graph-pro' ),
			'items_list'            => _x( 'Vault folders list', 'Screen reader text for the items list', 'nvoos-content-graph-pro' ),
		);

		$args = array(
			'labels'                => $labels,
			'description'           => __( 'Folders for organizing vault items', 'nvoos-content-graph-pro' ),
			'public'                => false,
			'publicly_queryable'    => false,
			'show_ui'               => true, // Show in admin UI.
			'show_in_menu'          => 'wp-mcp-ai-password-vault', // Show under Password Vault menu.
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

	/**
	 * Register metadata for vault folders.
	 *
	 * Registers all metadata fields used by vault folders with proper
	 * sanitization, authorization, and REST API exposure settings.
	 *
	 * @since 1.3.0
	 */
	public function register_meta() {
		// Register _bitwarden_folder_id metadata for sync.
		register_post_meta(
			'mcp_vault_folder',
			'_bitwarden_folder_id',
			array(
				'type'              => 'string',
				'description'       => __( 'Bitwarden folder ID for synchronization (internal use only).', 'nvoos-content-graph-pro' ),
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( $this, 'check_vault_folder_permission' ),
			)
		);
	}

	/**
	 * Check if current user has permission to access/edit vault folder.
	 *
	 * @since 1.3.0
	 *
	 * @param bool   $allowed  Whether the user can access the meta key.
	 * @param string $meta_key The meta key being accessed.
	 * @param int    $object_id The object ID (post ID).
	 * @param int    $user_id  The user ID.
	 * @return bool Whether the user has permission.
	 */
	public function check_vault_folder_permission( $allowed, $meta_key, $object_id, $user_id ) {
		// If no user is logged in, deny access.
		if ( ! $user_id ) {
			return false;
		}

		// Administrators with edit_others_vault_folders can access all folders.
		if ( current_user_can( 'edit_others_vault_folders' ) ) {
			return true;
		}

		// Check if user owns this vault folder.
		$post = get_post( $object_id );
		if ( ! $post || 'mcp_vault_folder' !== $post->post_type ) {
			return false;
		}

		// User must own the folder or have edit_others_vault_folders capability.
		return ( (int) $post->post_author === $user_id && current_user_can( 'edit_own_vault_folders' ) );
	}
}

// Initialize.
WP_MCP_AI_Vault_Folder_CPT::get_instance();
