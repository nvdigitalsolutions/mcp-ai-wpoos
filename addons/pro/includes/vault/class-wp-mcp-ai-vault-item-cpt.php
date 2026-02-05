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
		add_action( 'init', array( $this, 'register_meta' ), 20 );
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
			'show_ui'               => true, // Show in admin UI.
			'show_in_menu'          => 'wp-mcp-ai-password-vault', // Show under Password Vault menu.
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

	/**
	 * Register metadata for vault items.
	 *
	 * Registers all metadata fields used by vault items with proper
	 * sanitization, authorization, and REST API exposure settings.
	 *
	 * @since 1.3.0
	 */
	public function register_meta() {
		// Register _vault_item_type metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_item_type',
			array(
				'type'              => 'string',
				'description'       => __( 'Type of vault item (login, note, card, identity).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'default'           => 'login',
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_item_type' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_folder_id metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_folder_id',
			array(
				'type'              => 'integer',
				'description'       => __( 'ID of the parent folder for organizing vault items.', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_favorite metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_favorite',
			array(
				'type'              => 'string',
				'description'       => __( 'Whether this vault item is marked as a favorite (1 or 0).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'default'           => '0',
				'show_in_rest'      => true,
				'sanitize_callback' => array( $this, 'sanitize_favorite' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_encrypted_data metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_encrypted_data',
			array(
				'type'              => 'string',
				'description'       => __( 'Encrypted vault item data (internal use only, not exposed via REST).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'show_in_rest'      => false, // Don't expose encrypted data in REST.
				'sanitize_callback' => array( $this, 'sanitize_encrypted_field' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_username_encrypted metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_username_encrypted',
			array(
				'type'              => 'string',
				'description'       => __( 'Encrypted username for login items (internal use only).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'show_in_rest'      => false, // Don't expose encrypted data in REST.
				'sanitize_callback' => array( $this, 'sanitize_encrypted_field' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_password_encrypted metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_password_encrypted',
			array(
				'type'              => 'string',
				'description'       => __( 'Encrypted password for login items (internal use only).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'show_in_rest'      => false, // Don't expose encrypted data in REST.
				'sanitize_callback' => array( $this, 'sanitize_encrypted_field' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_totp_secret_encrypted metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_totp_secret_encrypted',
			array(
				'type'              => 'string',
				'description'       => __( 'Encrypted TOTP secret for 2FA (internal use only).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'show_in_rest'      => false, // Don't expose encrypted data in REST.
				'sanitize_callback' => array( $this, 'sanitize_encrypted_field' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_uris metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_uris',
			array(
				'type'              => 'array',
				'description'       => __( 'Array of URIs/URLs associated with this vault item.', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type' => 'string',
						),
					),
				),
				'sanitize_callback' => array( $this, 'sanitize_uris' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_notes_encrypted metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_notes_encrypted',
			array(
				'type'              => 'string',
				'description'       => __( 'Encrypted notes content (internal use only).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'show_in_rest'      => false, // Don't expose encrypted data in REST.
				'sanitize_callback' => array( $this, 'sanitize_encrypted_field' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_card_data_encrypted metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_card_data_encrypted',
			array(
				'type'              => 'string',
				'description'       => __( 'Encrypted credit card data (internal use only).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'show_in_rest'      => false, // Don't expose encrypted data in REST.
				'sanitize_callback' => array( $this, 'sanitize_encrypted_field' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_identity_data_encrypted metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_identity_data_encrypted',
			array(
				'type'              => 'string',
				'description'       => __( 'Encrypted identity data (internal use only).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'show_in_rest'      => false, // Don't expose encrypted data in REST.
				'sanitize_callback' => array( $this, 'sanitize_encrypted_field' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_custom_fields metadata.
		register_post_meta(
			'mcp_vault_item',
			'_vault_custom_fields',
			array(
				'type'              => 'array',
				'description'       => __( 'Array of custom fields with name, value, and type properties.', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'default'           => array(),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'  => array(
									'type' => 'string',
								),
								'value' => array(
									'type' => 'string',
								),
								'type'  => array(
									'type' => 'string',
								),
							),
						),
					),
				),
				'sanitize_callback' => array( $this, 'sanitize_custom_fields' ),
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _bitwarden_item_id metadata for sync.
		register_post_meta(
			'mcp_vault_item',
			'_bitwarden_item_id',
			array(
				'type'              => 'string',
				'description'       => __( 'Bitwarden item ID for synchronization (internal use only).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_last_used metadata for audit trail.
		register_post_meta(
			'mcp_vault_item',
			'_vault_last_used',
			array(
				'type'              => 'integer',
				'description'       => __( 'Unix timestamp of last access (for audit purposes).', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);

		// Register _vault_access_count metadata for monitoring.
		register_post_meta(
			'mcp_vault_item',
			'_vault_access_count',
			array(
				'type'              => 'integer',
				'description'       => __( 'Number of times this vault item has been accessed.', 'mcp-ai-wpoos-pro' ),
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( $this, 'check_vault_item_permission' ),
			)
		);
	}

	/**
	 * Sanitize item type to ensure it's one of the valid types.
	 *
	 * @since 1.3.0
	 *
	 * @param string $value The item type value.
	 * @return string Sanitized item type.
	 */
	public function sanitize_item_type( $value ) {
		$valid_types = array( 'login', 'note', 'card', 'identity' );
		$sanitized   = sanitize_text_field( $value );

		if ( ! in_array( $sanitized, $valid_types, true ) ) {
			return 'login'; // Default to login if invalid.
		}

		return $sanitized;
	}

	/**
	 * Sanitize favorite field to ensure it's 1 or 0.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value The favorite value.
	 * @return string '1' or '0'.
	 */
	public function sanitize_favorite( $value ) {
		return $value ? '1' : '0';
	}

	/**
	 * Sanitize encrypted field.
	 *
	 * Encrypted data should not be modified by sanitization as it would
	 * break the encryption. We only validate that it's a string.
	 *
	 * @since 1.3.0
	 *
	 * @param string $value The encrypted value.
	 * @return string The validated encrypted value.
	 */
	public function sanitize_encrypted_field( $value ) {
		// Encrypted data must be kept as-is. Only validate type.
		if ( ! is_string( $value ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Sanitize URIs array.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value The URIs value.
	 * @return array Sanitized array of URIs.
	 */
	public function sanitize_uris( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_map( 'esc_url_raw', array_filter( $value ) );
	}

	/**
	 * Sanitize custom fields array.
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $value The custom fields value.
	 * @return array Sanitized array of custom fields.
	 */
	public function sanitize_custom_fields( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_map(
			function( $field ) {
				if ( ! is_array( $field ) ) {
					return null;
				}
				return array(
					'name'  => sanitize_text_field( $field['name'] ?? '' ),
					'value' => sanitize_text_field( $field['value'] ?? '' ),
					'type'  => sanitize_text_field( $field['type'] ?? 'text' ),
				);
			},
			$value
		);
	}

	/**
	 * Check if current user has permission to access/edit vault item.
	 *
	 * @since 1.3.0
	 *
	 * @param bool   $allowed  Whether the user can access the meta key.
	 * @param string $meta_key The meta key being accessed.
	 * @param int    $object_id The object ID (post ID).
	 * @param int    $user_id  The user ID.
	 * @return bool Whether the user has permission.
	 */
	public function check_vault_item_permission( $allowed, $meta_key, $object_id, $user_id ) {
		// If no user is logged in, deny access.
		if ( ! $user_id ) {
			return false;
		}

		// Administrators with edit_others_vault_items can access all items.
		if ( current_user_can( 'edit_others_vault_items' ) ) {
			return true;
		}

		// Check if user owns this vault item.
		$post = get_post( $object_id );
		if ( ! $post || 'mcp_vault_item' !== $post->post_type ) {
			return false;
		}

		// User must own the item or have edit_others_vault_items capability.
		return ( (int) $post->post_author === $user_id && current_user_can( 'edit_own_vault_items' ) );
	}
}

// Initialize.
WP_MCP_AI_Vault_Item_CPT::get_instance();
