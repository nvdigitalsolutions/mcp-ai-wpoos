<?php
/**
 * Tool: Vault Manage (CRUD Operations)
 *
 * Provides create, update, and delete operations for vault items.
 * Allows AI assistants to manage password vault programmatically.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tools
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vault Manage Tool
 */
class WP_MCP_AI_Pro_Tool_Vault_Manage {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'vault_manage';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'vault_manage',
			'description'         => 'Manage password vault items (create, update, delete). Create new login credentials, secure notes, payment cards, or identity information. Update existing vault items or delete them. Use for automating credential management.',
			'category'            => 'password_vault',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'action'        => array(
						'type'        => 'string',
						'enum'        => array( 'create', 'update', 'delete' ),
						'description' => 'Action to perform: create (new item), update (existing item), delete (remove item)',
					),
					'item_id'       => array(
						'type'        => 'integer',
						'description' => 'Vault item ID (required for update/delete)',
					),
					'name'          => array(
						'type'        => 'string',
						'description' => 'Vault item name (required for create, optional for update)',
					),
					'item_type'     => array(
						'type'        => 'string',
						'enum'        => array( 'login', 'note', 'card', 'identity' ),
						'description' => 'Vault item type (required for create)',
					),
					'folder_id'     => array(
						'type'        => 'integer',
						'description' => 'Folder ID for organization (optional)',
					),
					'favorite'      => array(
						'type'        => 'boolean',
						'description' => 'Mark as favorite (optional)',
					),
					'username'      => array(
						'type'        => 'string',
						'description' => 'Username (for login type)',
					),
					'password'      => array(
						'type'        => 'string',
						'description' => 'Password (for login type)',
					),
					'uri'           => array(
						'type'        => 'string',
						'description' => 'Website URI (for login type)',
					),
					'totp'          => array(
						'type'        => 'string',
						'description' => 'TOTP secret (for login type)',
					),
					'notes'         => array(
						'type'        => 'string',
						'description' => 'Secure notes (for any type)',
					),
					'cardholder'    => array(
						'type'        => 'string',
						'description' => 'Cardholder name (for card type)',
					),
					'card_number'   => array(
						'type'        => 'string',
						'description' => 'Card number (for card type)',
					),
					'expiry_month'  => array(
						'type'        => 'string',
						'description' => 'Expiry month MM (for card type)',
					),
					'expiry_year'   => array(
						'type'        => 'string',
						'description' => 'Expiry year YYYY (for card type)',
					),
					'security_code' => array(
						'type'        => 'string',
						'description' => 'CVV/CVC code (for card type)',
					),
					'title'         => array(
						'type'        => 'string',
						'description' => 'Title (for identity type)',
					),
					'first_name'    => array(
						'type'        => 'string',
						'description' => 'First name (for identity type)',
					),
					'last_name'     => array(
						'type'        => 'string',
						'description' => 'Last name (for identity type)',
					),
					'email'         => array(
						'type'        => 'string',
						'description' => 'Email address (for identity type)',
					),
					'phone'         => array(
						'type'        => 'string',
						'description' => 'Phone number (for identity type)',
					),
					'address'       => array(
						'type'        => 'string',
						'description' => 'Physical address (for identity type)',
					),
				),
				'required'   => array( 'action' ),
			),
			'required_capability' => 'edit_posts',
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate action.
		if ( empty( $arguments['action'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: action',
			);
		}

		$action = sanitize_text_field( $arguments['action'] );

		// Route to appropriate method.
		switch ( $action ) {
			case 'create':
				return $this->create_item( $arguments );

			case 'update':
				return $this->update_item( $arguments );

			case 'delete':
				return $this->delete_item( $arguments );

			default:
				return array(
					'success' => false,
					'error'   => 'Invalid action. Use: create, update, or delete',
				);
		}
	}

	/**
	 * Create vault item
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function create_item( $arguments ) {
		// Validate required fields.
		if ( empty( $arguments['name'] ) || empty( $arguments['item_type'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required arguments: name and item_type are required for create action',
			);
		}

		$user_id   = get_current_user_id();
		$name      = sanitize_text_field( $arguments['name'] );
		$item_type = sanitize_text_field( $arguments['item_type'] );
		$folder_id = isset( $arguments['folder_id'] ) ? absint( $arguments['folder_id'] ) : 0;
		$favorite  = isset( $arguments['favorite'] ) ? (bool) $arguments['favorite'] : false;

		// Validate item type.
		if ( ! in_array( $item_type, array( 'login', 'note', 'card', 'identity' ), true ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid item_type. Must be: login, note, card, or identity',
			);
		}

		// Create post.
		$post_id = wp_insert_post(
			array(
				'post_title'  => $name,
				'post_type'   => 'mcp_vault_item',
				'post_status' => 'private',
				'post_author' => $user_id,
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return array(
				'success' => false,
				'error'   => $post_id->get_error_message(),
			);
		}

		// Save metadata.
		update_post_meta( $post_id, '_vault_item_type', $item_type );
		update_post_meta( $post_id, '_vault_folder_id', $folder_id );
		update_post_meta( $post_id, '_vault_favorite', $favorite ? '1' : '0' );

		// Encrypt and save item data.
		$item_data = $this->prepare_item_data( $item_type, $arguments );
		if ( $item_data ) {
			$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();
			$encrypted_data     = $encryption_service->encrypt( wp_json_encode( $item_data ) );
			if ( $encrypted_data ) {
				update_post_meta( $post_id, '_vault_encrypted_data', $encrypted_data );
			}
		}

		return array(
			'success'   => true,
			'action'    => 'create',
			'item_id'   => $post_id,
			'name'      => $name,
			'item_type' => $item_type,
			'message'   => sprintf( 'Successfully created %s vault item: %s', $item_type, $name ),
		);
	}

	/**
	 * Update vault item
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function update_item( $arguments ) {
		// Validate required fields.
		if ( empty( $arguments['item_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: item_id is required for update action',
			);
		}

		$item_id = absint( $arguments['item_id'] );
		$post    = get_post( $item_id );

		if ( ! $post || 'mcp_vault_item' !== $post->post_type ) {
			return array(
				'success' => false,
				'error'   => 'Vault item not found',
			);
		}

		// Check ownership.
		if ( $post->post_author != get_current_user_id() ) {
			return array(
				'success' => false,
				'error'   => 'You do not have permission to update this vault item',
			);
		}

		// Update post title if provided.
		if ( isset( $arguments['name'] ) ) {
			wp_update_post(
				array(
					'ID'         => $item_id,
					'post_title' => sanitize_text_field( $arguments['name'] ),
				)
			);
		}

		// Update metadata if provided.
		if ( isset( $arguments['folder_id'] ) ) {
			update_post_meta( $item_id, '_vault_folder_id', absint( $arguments['folder_id'] ) );
		}

		if ( isset( $arguments['favorite'] ) ) {
			update_post_meta( $item_id, '_vault_favorite', (bool) $arguments['favorite'] ? '1' : '0' );
		}

		// Update encrypted data if any item-specific fields provided.
		$item_type = get_post_meta( $item_id, '_vault_item_type', true );
		$item_data = $this->prepare_item_data( $item_type, $arguments );
		if ( $item_data ) {
			$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();
			$encrypted_data     = $encryption_service->encrypt( wp_json_encode( $item_data ) );
			if ( $encrypted_data ) {
				update_post_meta( $item_id, '_vault_encrypted_data', $encrypted_data );
			}
		}

		return array(
			'success'   => true,
			'action'    => 'update',
			'item_id'   => $item_id,
			'name'      => get_the_title( $item_id ),
			'item_type' => $item_type,
			'message'   => sprintf( 'Successfully updated vault item: %s', get_the_title( $item_id ) ),
		);
	}

	/**
	 * Delete vault item
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function delete_item( $arguments ) {
		// Validate required fields.
		if ( empty( $arguments['item_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: item_id is required for delete action',
			);
		}

		$item_id = absint( $arguments['item_id'] );
		$post    = get_post( $item_id );

		if ( ! $post || 'mcp_vault_item' !== $post->post_type ) {
			return array(
				'success' => false,
				'error'   => 'Vault item not found',
			);
		}

		// Check ownership.
		if ( $post->post_author != get_current_user_id() ) {
			return array(
				'success' => false,
				'error'   => 'You do not have permission to delete this vault item',
			);
		}

		$name = $post->post_title;

		$result = wp_delete_post( $item_id, true );

		if ( ! $result ) {
			return array(
				'success' => false,
				'error'   => 'Failed to delete vault item',
			);
		}

		return array(
			'success' => true,
			'action'  => 'delete',
			'item_id' => $item_id,
			'message' => sprintf( 'Successfully deleted vault item: %s', $name ),
		);
	}

	/**
	 * Prepare item data based on type
	 *
	 * @param string $item_type Item type.
	 * @param array  $arguments Tool arguments.
	 * @return array|null
	 */
	protected function prepare_item_data( $item_type, $arguments ) {
		switch ( $item_type ) {
			case 'login':
				$data = array();
				if ( isset( $arguments['username'] ) ) {
					$data['username'] = sanitize_text_field( $arguments['username'] );
				}
				if ( isset( $arguments['password'] ) ) {
					$data['password'] = $arguments['password']; // Don't sanitize password.
				}
				if ( isset( $arguments['uri'] ) ) {
					$data['uri'] = esc_url_raw( $arguments['uri'] );
				}
				if ( isset( $arguments['totp'] ) ) {
					$data['totp'] = sanitize_text_field( $arguments['totp'] );
				}
				if ( isset( $arguments['notes'] ) ) {
					$data['notes'] = sanitize_textarea_field( $arguments['notes'] );
				}
				return ! empty( $data ) ? $data : null;

			case 'note':
				if ( isset( $arguments['notes'] ) ) {
					return array(
						'notes' => sanitize_textarea_field( $arguments['notes'] ),
					);
				}
				return null;

			case 'card':
				$data = array();
				if ( isset( $arguments['cardholder'] ) ) {
					$data['cardholder'] = sanitize_text_field( $arguments['cardholder'] );
				}
				if ( isset( $arguments['card_number'] ) ) {
					$data['card_number'] = sanitize_text_field( $arguments['card_number'] );
				}
				if ( isset( $arguments['expiry_month'] ) ) {
					$data['expiry_month'] = sanitize_text_field( $arguments['expiry_month'] );
				}
				if ( isset( $arguments['expiry_year'] ) ) {
					$data['expiry_year'] = sanitize_text_field( $arguments['expiry_year'] );
				}
				if ( isset( $arguments['security_code'] ) ) {
					$data['security_code'] = sanitize_text_field( $arguments['security_code'] );
				}
				return ! empty( $data ) ? $data : null;

			case 'identity':
				$data = array();
				if ( isset( $arguments['title'] ) ) {
					$data['title'] = sanitize_text_field( $arguments['title'] );
				}
				if ( isset( $arguments['first_name'] ) ) {
					$data['first_name'] = sanitize_text_field( $arguments['first_name'] );
				}
				if ( isset( $arguments['last_name'] ) ) {
					$data['last_name'] = sanitize_text_field( $arguments['last_name'] );
				}
				if ( isset( $arguments['email'] ) ) {
					$data['email'] = sanitize_email( $arguments['email'] );
				}
				if ( isset( $arguments['phone'] ) ) {
					$data['phone'] = sanitize_text_field( $arguments['phone'] );
				}
				if ( isset( $arguments['address'] ) ) {
					$data['address'] = sanitize_textarea_field( $arguments['address'] );
				}
				return ! empty( $data ) ? $data : null;

			default:
				return null;
		}
	}
}
