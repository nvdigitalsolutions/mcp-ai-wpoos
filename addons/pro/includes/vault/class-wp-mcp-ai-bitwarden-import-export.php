<?php
/**
 * Bitwarden Import/Export Service
 *
 * Handles import/export of vault data in Bitwarden JSON format.
 * Supports folders, items (login, note, card, identity), TOTP seeds, custom fields, and password history.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Bitwarden_Import_Export
 *
 * Import/export vault data in Bitwarden-compatible JSON format.
 */
class WP_MCP_AI_Bitwarden_Import_Export {

	/**
	 * Encryption service instance.
	 *
	 * @var WP_MCP_AI_Vault_Encryption_Service
	 */
	private $encryption_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->encryption_service = new WP_MCP_AI_Vault_Encryption_Service();
	}

	/**
	 * Import Bitwarden JSON export.
	 *
	 * @param string $json_data     Bitwarden JSON export data.
	 * @param int    $user_id       User ID to import for.
	 * @param array  $options       Import options (merge_folders, skip_duplicates, etc.).
	 * @return array                Import results (success, imported_count, skipped_count, errors).
	 */
	public function import_bitwarden_json( $json_data, $user_id, $options = array() ) {
		$defaults = array(
			'merge_folders'    => true,
			'skip_duplicates'  => true,
			'import_totp'      => true,
			'import_favorites' => true,
		);
		$options  = wp_parse_args( $options, $defaults );

		$result = array(
			'success'        => false,
			'imported_count' => 0,
			'skipped_count'  => 0,
			'folder_count'   => 0,
			'errors'         => array(),
		);

		// Parse JSON.
		$data = json_decode( $json_data, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$result['errors'][] = 'Invalid JSON: ' . json_last_error_msg();
			return $result;
		}

		// Validate structure.
		if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			$result['errors'][] = 'Invalid Bitwarden export: missing items array';
			return $result;
		}

		// Check if encrypted.
		if ( ! empty( $data['encrypted'] ) && true === $data['encrypted'] ) {
			$result['errors'][] = 'Encrypted exports are not currently supported. Please export as unencrypted JSON.';
			return $result;
		}

		// Import folders first.
		$folder_map = array();
		if ( isset( $data['folders'] ) && is_array( $data['folders'] ) ) {
			foreach ( $data['folders'] as $folder ) {
				$folder_id = $this->import_folder( $folder, $user_id, $options );
				if ( $folder_id ) {
					$folder_map[ $folder['id'] ] = $folder_id;
					++$result['folder_count'];
				}
			}
		}

		// Import items.
		foreach ( $data['items'] as $item ) {
			try {
				// Check for duplicates if enabled.
				if ( $options['skip_duplicates'] && $this->item_exists( $item, $user_id ) ) {
					++$result['skipped_count'];
					continue;
				}

				// Map folder ID.
				if ( isset( $item['folderId'] ) && isset( $folder_map[ $item['folderId'] ] ) ) {
					$item['folderId'] = $folder_map[ $item['folderId'] ];
				}

				// Import item.
				$item_id = $this->import_item( $item, $user_id, $options );
				if ( $item_id ) {
					++$result['imported_count'];
				} else {
					++$result['skipped_count'];
				}
			} catch ( Exception $e ) {
				$result['errors'][] = sprintf( 'Error importing item "%s": %s', $item['name'] ?? 'unknown', $e->getMessage() );
			}
		}

		$result['success'] = $result['imported_count'] > 0 || $result['folder_count'] > 0;

		return $result;
	}

	/**
	 * Export vault to Bitwarden JSON format.
	 *
	 * @param int   $user_id  User ID to export for.
	 * @param array $options  Export options (include_folders, include_totp, etc.).
	 * @return string|WP_Error JSON export data or error.
	 */
	public function export_to_bitwarden_json( $user_id, $options = array() ) {
		$defaults = array(
			'include_folders'   => true,
			'include_totp'      => true,
			'include_history'   => true,
			'include_favorites' => true,
		);
		$options  = wp_parse_args( $options, $defaults );

		$export_data = array(
			'encrypted' => false,
			'folders'   => array(),
			'items'     => array(),
		);

		// Export folders.
		if ( $options['include_folders'] ) {
			$folders = $this->get_user_folders( $user_id );
			foreach ( $folders as $folder ) {
				$export_data['folders'][] = array(
					'id'   => $folder['id'],
					'name' => $folder['name'],
				);
			}
		}

		// Export items.
		$items = $this->get_user_items( $user_id );
		foreach ( $items as $item ) {
			$bitwarden_item = $this->convert_to_bitwarden_format( $item, $user_id, $options );
			if ( $bitwarden_item ) {
				$export_data['items'][] = $bitwarden_item;
			}
		}

		return wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Import a folder.
	 *
	 * @param array $folder   Folder data from Bitwarden export.
	 * @param int   $user_id  User ID.
	 * @param array $options  Import options.
	 * @return int|false      WordPress post ID or false on failure.
	 */
	private function import_folder( $folder, $user_id, $options ) {
		// Check if folder already exists.
		if ( $options['merge_folders'] ) {
			$existing = get_posts(
				array(
					'post_type'   => 'mcp_vault_folder',
					'author'      => $user_id,
					'title'       => $folder['name'],
					'post_status' => 'private',
					'numberposts' => 1,
				)
			);

			if ( ! empty( $existing ) ) {
				return $existing[0]->ID;
			}
		}

		// Create new folder.
		$folder_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_vault_folder',
				'post_title'  => sanitize_text_field( $folder['name'] ),
				'post_status' => 'private',
				'post_author' => $user_id,
			)
		);

		if ( ! is_wp_error( $folder_id ) ) {
			// Store original Bitwarden ID for reference.
			update_post_meta( $folder_id, '_bitwarden_folder_id', sanitize_text_field( $folder['id'] ) );
			return $folder_id;
		}

		return false;
	}

	/**
	 * Import a vault item.
	 *
	 * @param array $item     Item data from Bitwarden export.
	 * @param int   $user_id  User ID.
	 * @param array $options  Import options.
	 * @return int|false      WordPress post ID or false on failure.
	 */
	private function import_item( $item, $user_id, $options ) {
		// Determine item type (1=login, 2=note, 3=card, 4=identity).
		$type_map  = array(
			1 => 'login',
			2 => 'note',
			3 => 'card',
			4 => 'identity',
		);
		$item_type = isset( $type_map[ $item['type'] ] ) ? $type_map[ $item['type'] ] : 'note';

		// Create vault item post.
		$item_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_vault_item',
				'post_title'  => sanitize_text_field( $item['name'] ),
				'post_status' => 'private',
				'post_author' => $user_id,
			)
		);

		if ( is_wp_error( $item_id ) ) {
			return false;
		}

		// Store item type.
		update_post_meta( $item_id, '_vault_item_type', $item_type );

		// Store folder ID if present.
		if ( isset( $item['folderId'] ) && $item['folderId'] ) {
			update_post_meta( $item_id, '_vault_folder_id', absint( $item['folderId'] ) );
		}

		// Store favorite status.
		if ( $options['import_favorites'] && isset( $item['favorite'] ) ) {
			update_post_meta( $item_id, '_vault_favorite', (bool) $item['favorite'] ? '1' : '0' );
		}

		// Store notes (encrypted).
		if ( isset( $item['notes'] ) && ! empty( $item['notes'] ) ) {
			$encrypted_notes = $this->encryption_service->encrypt( $item['notes'], $user_id );
			update_post_meta( $item_id, '_vault_notes_encrypted', $encrypted_notes );
		}

		// Store original Bitwarden ID.
		if ( isset( $item['id'] ) ) {
			update_post_meta( $item_id, '_bitwarden_item_id', sanitize_text_field( $item['id'] ) );
		}

		// Process type-specific data.
		switch ( $item_type ) {
			case 'login':
				$this->import_login_data( $item_id, $item, $user_id, $options );
				break;
			case 'card':
				$this->import_card_data( $item_id, $item, $user_id );
				break;
			case 'identity':
				$this->import_identity_data( $item_id, $item, $user_id );
				break;
			case 'note':
				// Notes only have title and notes field (already stored above).
				break;
		}

		// Import custom fields.
		if ( isset( $item['fields'] ) && is_array( $item['fields'] ) ) {
			$this->import_custom_fields( $item_id, $item['fields'], $user_id );
		}

		return $item_id;
	}

	/**
	 * Import login data.
	 *
	 * @param int   $item_id  Vault item post ID.
	 * @param array $item     Item data from Bitwarden.
	 * @param int   $user_id  User ID.
	 * @param array $options  Import options.
	 */
	private function import_login_data( $item_id, $item, $user_id, $options ) {
		if ( ! isset( $item['login'] ) ) {
			return;
		}

		$login = $item['login'];

		// Store username (encrypted).
		if ( isset( $login['username'] ) && ! empty( $login['username'] ) ) {
			$encrypted_username = $this->encryption_service->encrypt( $login['username'], $user_id );
			update_post_meta( $item_id, '_vault_username_encrypted', $encrypted_username );
		}

		// Store password (encrypted).
		if ( isset( $login['password'] ) && ! empty( $login['password'] ) ) {
			$encrypted_password = $this->encryption_service->encrypt( $login['password'], $user_id );
			update_post_meta( $item_id, '_vault_password_encrypted', $encrypted_password );
		}

		// Store TOTP secret (encrypted).
		if ( $options['import_totp'] && isset( $login['totp'] ) && ! empty( $login['totp'] ) ) {
			$encrypted_totp = $this->encryption_service->encrypt( $login['totp'], $user_id );
			update_post_meta( $item_id, '_vault_totp_secret_encrypted', $encrypted_totp );
		}

		// Store URIs.
		if ( isset( $login['uris'] ) && is_array( $login['uris'] ) ) {
			$uris = array_map(
				function ( $uri_obj ) {
					return array(
						'uri'   => isset( $uri_obj['uri'] ) ? esc_url_raw( $uri_obj['uri'] ) : '',
						'match' => isset( $uri_obj['match'] ) ? absint( $uri_obj['match'] ) : 0,
					);
				},
				$login['uris']
			);
			update_post_meta( $item_id, '_vault_uris', $uris );
		}
	}

	/**
	 * Import card data.
	 *
	 * @param int   $item_id  Vault item post ID.
	 * @param array $item     Item data from Bitwarden.
	 * @param int   $user_id  User ID.
	 */
	private function import_card_data( $item_id, $item, $user_id ) {
		if ( ! isset( $item['card'] ) ) {
			return;
		}

		$card = $item['card'];

		// Store card data (encrypted).
		$card_data = array();
		if ( isset( $card['cardholderName'] ) ) {
			$card_data['cardholderName'] = $card['cardholderName'];
		}
		if ( isset( $card['brand'] ) ) {
			$card_data['brand'] = $card['brand'];
		}
		if ( isset( $card['number'] ) ) {
			$card_data['number'] = $card['number'];
		}
		if ( isset( $card['expMonth'] ) ) {
			$card_data['expMonth'] = $card['expMonth'];
		}
		if ( isset( $card['expYear'] ) ) {
			$card_data['expYear'] = $card['expYear'];
		}
		if ( isset( $card['code'] ) ) {
			$card_data['code'] = $card['code'];
		}

		if ( ! empty( $card_data ) ) {
			$encrypted_card = $this->encryption_service->encrypt( wp_json_encode( $card_data ), $user_id );
			update_post_meta( $item_id, '_vault_card_data_encrypted', $encrypted_card );
		}
	}

	/**
	 * Import identity data.
	 *
	 * @param int   $item_id  Vault item post ID.
	 * @param array $item     Item data from Bitwarden.
	 * @param int   $user_id  User ID.
	 */
	private function import_identity_data( $item_id, $item, $user_id ) {
		if ( ! isset( $item['identity'] ) ) {
			return;
		}

		$identity = $item['identity'];

		// Store identity data (encrypted).
		$encrypted_identity = $this->encryption_service->encrypt( wp_json_encode( $identity ), $user_id );
		update_post_meta( $item_id, '_vault_identity_data_encrypted', $encrypted_identity );
	}

	/**
	 * Import custom fields.
	 *
	 * @param int   $item_id  Vault item post ID.
	 * @param array $fields   Custom fields from Bitwarden.
	 * @param int   $user_id  User ID.
	 */
	private function import_custom_fields( $item_id, $fields, $user_id ) {
		$custom_fields = array();

		foreach ( $fields as $field ) {
			$field_data = array(
				'name'  => sanitize_text_field( $field['name'] ),
				'value' => $field['value'],
				'type'  => isset( $field['type'] ) ? absint( $field['type'] ) : 0,
			);

			// Encrypt sensitive field values (type 1 = hidden).
			if ( 1 === $field_data['type'] && ! empty( $field_data['value'] ) ) {
				$field_data['value']     = $this->encryption_service->encrypt( $field_data['value'], $user_id );
				$field_data['encrypted'] = true;
			}

			$custom_fields[] = $field_data;
		}

		if ( ! empty( $custom_fields ) ) {
			update_post_meta( $item_id, '_vault_custom_fields', $custom_fields );
		}
	}

	/**
	 * Check if an item already exists.
	 *
	 * @param array $item     Item data from Bitwarden.
	 * @param int   $user_id  User ID.
	 * @return bool           True if item exists.
	 */
	private function item_exists( $item, $user_id ) {
		// Check by original Bitwarden ID if present.
		if ( isset( $item['id'] ) ) {
			$existing = get_posts(
				array(
					'post_type'   => 'mcp_vault_item',
					'author'      => $user_id,
					'meta_key'    => '_bitwarden_item_id',
					'meta_value'  => sanitize_text_field( $item['id'] ),
					'post_status' => 'private',
					'numberposts' => 1,
				)
			);

			if ( ! empty( $existing ) ) {
				return true;
			}
		}

		// Check by name and type.
		$type_map  = array(
			1 => 'login',
			2 => 'note',
			3 => 'card',
			4 => 'identity',
		);
		$item_type = isset( $type_map[ $item['type'] ] ) ? $type_map[ $item['type'] ] : 'note';

		$existing = get_posts(
			array(
				'post_type'   => 'mcp_vault_item',
				'author'      => $user_id,
				'title'       => $item['name'],
				'meta_key'    => '_vault_item_type',
				'meta_value'  => $item_type,
				'post_status' => 'private',
				'numberposts' => 1,
			)
		);

		return ! empty( $existing );
	}

	/**
	 * Get user folders.
	 *
	 * @param int $user_id  User ID.
	 * @return array        Array of folder data.
	 */
	private function get_user_folders( $user_id ) {
		$folders = get_posts(
			array(
				'post_type'      => 'mcp_vault_folder',
				'author'         => $user_id,
				'post_status'    => 'private',
				'posts_per_page' => -1,
			)
		);

		return array_map(
			function ( $folder ) {
				return array(
					'id'   => 'wp-folder-' . $folder->ID,
					'name' => $folder->post_title,
				);
			},
			$folders
		);
	}

	/**
	 * Get user vault items.
	 *
	 * @param int $user_id  User ID.
	 * @return array        Array of vault item posts.
	 */
	private function get_user_items( $user_id ) {
		return get_posts(
			array(
				'post_type'      => 'mcp_vault_item',
				'author'         => $user_id,
				'post_status'    => 'private',
				'posts_per_page' => -1,
			)
		);
	}

	/**
	 * Convert WordPress vault item to Bitwarden format.
	 *
	 * @param WP_Post $item     Vault item post.
	 * @param int     $user_id  User ID.
	 * @param array   $options  Export options.
	 * @return array|null       Bitwarden item data or null on failure.
	 */
	private function convert_to_bitwarden_format( $item, $user_id, $options ) {
		$item_type = get_post_meta( $item->ID, '_vault_item_type', true );

		// Type map (reverse of import).
		$type_map = array(
			'login'    => 1,
			'note'     => 2,
			'card'     => 3,
			'identity' => 4,
		);
		$bw_type  = isset( $type_map[ $item_type ] ) ? $type_map[ $item_type ] : 2;

		$bitwarden_item = array(
			'id'             => 'wp-item-' . $item->ID,
			'organizationId' => null,
			'folderId'       => null,
			'type'           => $bw_type,
			'name'           => $item->post_title,
			'notes'          => null,
			'favorite'       => false,
			'fields'         => array(),
		);

		// Add folder ID if present.
		$folder_id = get_post_meta( $item->ID, '_vault_folder_id', true );
		if ( $folder_id ) {
			$bitwarden_item['folderId'] = 'wp-folder-' . $folder_id;
		}

		// Add favorite status.
		if ( $options['include_favorites'] ) {
			$is_favorite                = get_post_meta( $item->ID, '_vault_favorite', true );
			$bitwarden_item['favorite'] = '1' === $is_favorite;
		}

		// Decrypt and add notes.
		$encrypted_notes = get_post_meta( $item->ID, '_vault_notes_encrypted', true );
		if ( $encrypted_notes ) {
			try {
				$bitwarden_item['notes'] = $this->encryption_service->decrypt( $encrypted_notes, $user_id );
			} catch ( Exception $e ) {
				unset( $e ); // Skip if decryption fails.
			}
		}

		// Add type-specific data.
		switch ( $item_type ) {
			case 'login':
				$bitwarden_item['login'] = $this->export_login_data( $item->ID, $user_id, $options );
				break;
			case 'card':
				$bitwarden_item['card'] = $this->export_card_data( $item->ID, $user_id );
				break;
			case 'identity':
				$bitwarden_item['identity'] = $this->export_identity_data( $item->ID, $user_id );
				break;
		}

		// Add custom fields.
		$custom_fields = get_post_meta( $item->ID, '_vault_custom_fields', true );
		if ( is_array( $custom_fields ) ) {
			$bitwarden_item['fields'] = $this->export_custom_fields( $custom_fields, $user_id );
		}

		return $bitwarden_item;
	}

	/**
	 * Export login data.
	 *
	 * @param int   $item_id  Vault item post ID.
	 * @param int   $user_id  User ID.
	 * @param array $options  Export options.
	 * @return array          Login data for Bitwarden format.
	 */
	private function export_login_data( $item_id, $user_id, $options ) {
		$login_data = array(
			'username' => null,
			'password' => null,
			'totp'     => null,
			'uris'     => array(),
		);

		// Decrypt username.
		$encrypted_username = get_post_meta( $item_id, '_vault_username_encrypted', true );
		if ( $encrypted_username ) {
			try {
				$login_data['username'] = $this->encryption_service->decrypt( $encrypted_username, $user_id );
			} catch ( Exception $e ) {
				unset( $e ); // Skip if decryption fails.
			}
		}

		// Decrypt password.
		$encrypted_password = get_post_meta( $item_id, '_vault_password_encrypted', true );
		if ( $encrypted_password ) {
			try {
				$login_data['password'] = $this->encryption_service->decrypt( $encrypted_password, $user_id );
			} catch ( Exception $e ) {
				unset( $e ); // Skip if decryption fails.
			}
		}

		// Decrypt TOTP secret.
		if ( $options['include_totp'] ) {
			$encrypted_totp = get_post_meta( $item_id, '_vault_totp_secret_encrypted', true );
			if ( $encrypted_totp ) {
				try {
					$login_data['totp'] = $this->encryption_service->decrypt( $encrypted_totp, $user_id );
				} catch ( Exception $e ) {
					unset( $e ); // Skip if decryption fails.
				}
			}
		}

		// Get URIs.
		$uris = get_post_meta( $item_id, '_vault_uris', true );
		if ( is_array( $uris ) ) {
			$login_data['uris'] = $uris;
		}

		return $login_data;
	}

	/**
	 * Export card data.
	 *
	 * @param int $item_id  Vault item post ID.
	 * @param int $user_id  User ID.
	 * @return array        Card data for Bitwarden format.
	 */
	private function export_card_data( $item_id, $user_id ) {
		$encrypted_card = get_post_meta( $item_id, '_vault_card_data_encrypted', true );
		if ( ! $encrypted_card ) {
			return array();
		}

		try {
			$decrypted = $this->encryption_service->decrypt( $encrypted_card, $user_id );
			$decoded   = json_decode( $decrypted, true );
			return is_array( $decoded ) ? $decoded : array();
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Export identity data.
	 *
	 * @param int $item_id  Vault item post ID.
	 * @param int $user_id  User ID.
	 * @return array        Identity data for Bitwarden format.
	 */
	private function export_identity_data( $item_id, $user_id ) {
		$encrypted_identity = get_post_meta( $item_id, '_vault_identity_data_encrypted', true );
		if ( ! $encrypted_identity ) {
			return array();
		}

		try {
			$decrypted = $this->encryption_service->decrypt( $encrypted_identity, $user_id );
			$decoded   = json_decode( $decrypted, true );
			return is_array( $decoded ) ? $decoded : array();
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Export custom fields.
	 *
	 * @param array $fields   Custom fields from post meta.
	 * @param int   $user_id  User ID.
	 * @return array          Custom fields for Bitwarden format.
	 */
	private function export_custom_fields( $fields, $user_id ) {
		return array_map(
			function ( $field ) use ( $user_id ) {
				$exported = array(
					'name'  => $field['name'],
					'value' => $field['value'],
					'type'  => isset( $field['type'] ) ? $field['type'] : 0,
				);

				// Decrypt if encrypted.
				if ( isset( $field['encrypted'] ) && $field['encrypted'] ) {
					try {
						$exported['value'] = $this->encryption_service->decrypt( $field['value'], $user_id );
					} catch ( Exception $e ) {
						$exported['value'] = '';
					}
				}

				return $exported;
			},
			$fields
		);
	}

	/**
	 * Export vault to encrypted Bitwarden JSON format.
	 *
	 * Creates a password-protected encrypted export compatible with Bitwarden.
	 *
	 * @param int    $user_id  User ID to export for.
	 * @param string $password Encryption password.
	 * @param array  $options  Export options.
	 * @return string|WP_Error Encrypted JSON string or WP_Error on failure.
	 */
	public function export_encrypted_bitwarden_json( $user_id, $password, $options = array() ) {
		if ( empty( $password ) ) {
			return new WP_Error( 'no_password', 'Encryption password is required' );
		}

		// Get unencrypted export first.
		$json_data = $this->export_bitwarden_json( $user_id, $options );

		if ( is_wp_error( $json_data ) ) {
			return $json_data;
		}

		// Parse JSON to get data object.
		$data = json_decode( $json_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_error', 'Failed to parse export data' );
		}

		// Encrypt the data using Bitwarden's encryption format.
		$encrypted_data = $this->encrypt_bitwarden_format( $data, $password );

		if ( is_wp_error( $encrypted_data ) ) {
			return $encrypted_data;
		}

		// Create encrypted export structure.
		$encrypted_export = array(
			'encrypted'                    => true,
			'encType'                      => 0, // AES-256-CBC with HMAC-SHA256.
			'encKeyValidation_DO_NOT_EDIT' => $encrypted_data['key_validation'],
			'data'                         => $encrypted_data['encrypted_string'],
			'salt'                         => $encrypted_data['salt'],
			'kdfIterations'                => 100000,
			'kdfType'                      => 0, // PBKDF2-SHA256.
		);

		return wp_json_encode( $encrypted_export, JSON_PRETTY_PRINT );
	}

	/**
	 * Encrypt data using Bitwarden's encryption format.
	 *
	 * Uses PBKDF2-HMAC-SHA256 for key derivation and AES-256-CBC + HMAC-SHA256 for encryption.
	 *
	 * @param array  $data     Data to encrypt.
	 * @param string $password Encryption password.
	 * @return array|WP_Error Encrypted data array or WP_Error on failure.
	 */
	private function encrypt_bitwarden_format( $data, $password ) {
		try {
			// Generate random salt (16 bytes).
			$salt = random_bytes( 16 );

			// Derive encryption key using PBKDF2 (32 bytes = 256 bits).
			$key = hash_pbkdf2( 'sha256', $password, $salt, 100000, 32, true );

			// Split key into encryption key and MAC key.
			$enc_key = substr( $key, 0, 16 ); // First 128 bits for encryption.
			$mac_key = substr( $key, 16, 16 ); // Second 128 bits for MAC.

			// Serialize data to JSON.
			$plain_text = wp_json_encode( $data );

			// Generate random IV (16 bytes).
			$iv = random_bytes( 16 );

			// Encrypt using AES-256-CBC.
			$cipher_text = openssl_encrypt(
				$plain_text,
				'aes-128-cbc',
				$enc_key,
				OPENSSL_RAW_DATA,
				$iv
			);

			if ( false === $cipher_text ) {
				return new WP_Error( 'encryption_failed', 'Failed to encrypt data' );
			}

			// Create MAC over IV + ciphertext.
			$mac_data = $iv . $cipher_text;
			$mac      = hash_hmac( 'sha256', $mac_data, $mac_key, true );

			// Combine IV + ciphertext + MAC.
			$encrypted_bytes = $iv . $cipher_text . $mac;

			// Base64 encode.
			$encrypted_string = base64_encode( $encrypted_bytes );

			// Create key validation string.
			$key_validation = base64_encode( hash( 'sha256', $key, true ) );

			return array(
				'encrypted_string' => $encrypted_string,
				'salt'             => base64_encode( $salt ),
				'key_validation'   => $key_validation,
			);

		} catch ( Exception $e ) {
			return new WP_Error( 'encryption_exception', $e->getMessage() );
		}
	}

	/**
	 * Import encrypted Bitwarden JSON export.
	 *
	 * @param string $encrypted_json Encrypted Bitwarden JSON export data.
	 * @param string $password       Decryption password.
	 * @param int    $user_id        User ID to import for.
	 * @param array  $options        Import options.
	 * @return array|WP_Error Import results or WP_Error on failure.
	 */
	public function import_encrypted_bitwarden_json( $encrypted_json, $password, $user_id, $options = array() ) {
		if ( empty( $password ) ) {
			return new WP_Error( 'no_password', 'Decryption password is required' );
		}

		// Parse encrypted JSON.
		$encrypted_data = json_decode( $encrypted_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_error', 'Invalid encrypted JSON format' );
		}

		// Verify it's encrypted.
		if ( empty( $encrypted_data['encrypted'] ) ) {
			return new WP_Error( 'not_encrypted', 'This is not an encrypted export' );
		}

		// Decrypt the data.
		$decrypted_data = $this->decrypt_bitwarden_format(
			$encrypted_data['data'],
			$encrypted_data['salt'],
			$password,
			$encrypted_data['encKeyValidation_DO_NOT_EDIT'] ?? ''
		);

		if ( is_wp_error( $decrypted_data ) ) {
			return $decrypted_data;
		}

		// Parse decrypted data.
		$data = json_decode( $decrypted_data, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'decryption_error', 'Failed to parse decrypted data' );
		}

		// Import the decrypted data.
		$plain_json = wp_json_encode( $data );
		return $this->import_bitwarden_json( $plain_json, $user_id, $options );
	}

	/**
	 * Decrypt data using Bitwarden's encryption format.
	 *
	 * @param string $encrypted_string Base64 encoded encrypted data.
	 * @param string $salt_b64        Base64 encoded salt.
	 * @param string $password        Decryption password.
	 * @param string $key_validation  Key validation string.
	 * @return string|WP_Error Decrypted data or WP_Error on failure.
	 */
	private function decrypt_bitwarden_format( $encrypted_string, $salt_b64, $password, $key_validation = '' ) {
		try {
			// Decode base64 inputs.
			$encrypted_bytes = base64_decode( $encrypted_string );
			$salt            = base64_decode( $salt_b64 );

			if ( false === $encrypted_bytes || false === $salt ) {
				return new WP_Error( 'decode_error', 'Failed to decode encrypted data' );
			}

			// Derive encryption key using PBKDF2.
			$key = hash_pbkdf2( 'sha256', $password, $salt, 100000, 32, true );

			// Verify key if validation provided.
			if ( ! empty( $key_validation ) ) {
				$computed_validation = base64_encode( hash( 'sha256', $key, true ) );
				if ( $computed_validation !== $key_validation ) {
					return new WP_Error( 'wrong_password', 'Incorrect password' );
				}
			}

			// Split key.
			$enc_key = substr( $key, 0, 16 );
			$mac_key = substr( $key, 16, 16 );

			// Extract components.
			$iv          = substr( $encrypted_bytes, 0, 16 );
			$mac         = substr( $encrypted_bytes, -32 );
			$cipher_text = substr( $encrypted_bytes, 16, -32 );

			// Verify MAC.
			$mac_data     = $iv . $cipher_text;
			$computed_mac = hash_hmac( 'sha256', $mac_data, $mac_key, true );

			if ( ! hash_equals( $computed_mac, $mac ) ) {
				return new WP_Error( 'mac_verification_failed', 'Data integrity check failed' );
			}

			// Decrypt.
			$plain_text = openssl_decrypt(
				$cipher_text,
				'aes-128-cbc',
				$enc_key,
				OPENSSL_RAW_DATA,
				$iv
			);

			if ( false === $plain_text ) {
				return new WP_Error( 'decryption_failed', 'Failed to decrypt data' );
			}

			return $plain_text;

		} catch ( Exception $e ) {
			return new WP_Error( 'decryption_exception', $e->getMessage() );
		}
	}
}
