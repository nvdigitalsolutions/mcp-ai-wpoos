<?php
/**
 * Tool that stores and updates credentials in Bitwarden vault.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-bitwarden-client.php';

/**
 * Provides an assistant tool for creating and updating Bitwarden vault items.
 */
class WP_MCP_AI_Pro_Tool_Bitwarden_Store_Credential implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'bitwarden_store_credential';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Bitwarden Store Credential', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create or update credentials in Bitwarden vault. Supports all item types: Login, Secure Note, Card, and Identity.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'          => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: create (new item) or update (existing item).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'create', 'update' ),
					'default'     => 'create',
				),
				'item_id'         => array(
					'type'        => 'string',
					'description' => __( 'Vault item ID (required for update action).', 'mcp-ai-wpoos-pro' ),
				),
				'type'            => array(
					'type'        => 'integer',
					'description' => __( 'Item type: 1=Login, 2=Secure Note, 3=Card, 4=Identity.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 1, 2, 3, 4 ),
					'default'     => 1,
				),
				'name'            => array(
					'type'        => 'string',
					'description' => __( 'Item name (required).', 'mcp-ai-wpoos-pro' ),
				),
				'username'        => array(
					'type'        => 'string',
					'description' => __( 'Username (for Login type).', 'mcp-ai-wpoos-pro' ),
				),
				'password'        => array(
					'type'        => 'string',
					'description' => __( 'Password (for Login type).', 'mcp-ai-wpoos-pro' ),
				),
				'uris'            => array(
					'type'        => 'array',
					'description' => __( 'URIs associated with this item (for Login type).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Secure notes or additional information.', 'mcp-ai-wpoos-pro' ),
				),
				'favorite'        => array(
					'type'        => 'boolean',
					'description' => __( 'Mark as favorite.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'folder_id'       => array(
					'type'        => 'string',
					'description' => __( 'Folder ID to organize the item.', 'mcp-ai-wpoos-pro' ),
				),
				'organization_id' => array(
					'type'        => 'string',
					'description' => __( 'Organization ID for shared items.', 'mcp-ai-wpoos-pro' ),
				),
				'collection_ids'  => array(
					'type'        => 'array',
					'description' => __( 'Collection IDs for organization items.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'action', 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check capability - allow filtering.
		$required_capability = apply_filters( 'wp_mcp_ai_bitwarden_write_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_bitwarden_forbidden', __( 'You do not have permission to modify Bitwarden vault.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if Bitwarden is connected.
		if ( ! class_exists( 'WP_MCP_AI_Bitwarden_OAuth_Handler' ) ) {
			return new WP_Error( 'wp_mcp_ai_bitwarden_not_loaded', __( 'Bitwarden integration is not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! WP_MCP_AI_Bitwarden_OAuth_Handler::is_connected() ) {
			return new WP_Error( 'wp_mcp_ai_bitwarden_not_connected', __( 'Bitwarden account is not connected. Please connect in Settings → NV oOS → Tools → External Tools.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = ! empty( $arguments['action'] ) ? $arguments['action'] : 'create';
		$client = new WP_MCP_AI_Bitwarden_Client();

		// Log the operation.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log(
				sprintf(
					'Bitwarden vault %s: %s by user %d',
					$action,
					! empty( $arguments['name'] ) ? $arguments['name'] : 'item',
					$user_id
				),
				'info',
				array(
					'action'  => $action,
					'user_id' => $user_id,
					'name'    => ! empty( $arguments['name'] ) ? $arguments['name'] : '',
					'type'    => ! empty( $arguments['type'] ) ? $arguments['type'] : 1,
				)
			);
		}

		// Build item data.
		$item_data = $this->build_item_data( $arguments );

		if ( is_wp_error( $item_data ) ) {
			return $item_data;
		}

		// Perform action.
		if ( 'update' === $action ) {
			if ( empty( $arguments['item_id'] ) ) {
				return new WP_Error( 'missing_item_id', __( 'item_id is required for update action.', 'mcp-ai-wpoos-pro' ) );
			}

			$item_id = sanitize_text_field( $arguments['item_id'] );
			$result  = $client->update_vault_item( $item_id, $item_data );
		} else {
			$result = $client->create_vault_item( $item_data );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'action'  => $action,
			'item'    => array(
				'id'         => $result['id'],
				'name'       => $result['name'],
				'type'       => WP_MCP_AI_Bitwarden_Client::get_item_type_name( $result['type'] ),
				'created_at' => $result['creationDate'],
				'updated_at' => $result['revisionDate'],
			),
			'message' => 'create' === $action
				? __( 'Credential created successfully.', 'mcp-ai-wpoos-pro' )
				: __( 'Credential updated successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Build item data from arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Item data or error.
	 */
	private function build_item_data( $arguments ) {
		$type = ! empty( $arguments['type'] ) ? (int) $arguments['type'] : 1;
		$name = ! empty( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';

		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', __( 'name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$item_data = array(
			'type'     => $type,
			'name'     => $name,
			'favorite' => ! empty( $arguments['favorite'] ),
		);

		// Add notes if provided.
		if ( ! empty( $arguments['notes'] ) ) {
			$item_data['notes'] = sanitize_textarea_field( $arguments['notes'] );
		}

		// Add folder if provided.
		if ( ! empty( $arguments['folder_id'] ) ) {
			$item_data['folderId'] = sanitize_text_field( $arguments['folder_id'] );
		}

		// Add organization and collections if provided.
		if ( ! empty( $arguments['organization_id'] ) ) {
			$item_data['organizationId'] = sanitize_text_field( $arguments['organization_id'] );

			if ( ! empty( $arguments['collection_ids'] ) && is_array( $arguments['collection_ids'] ) ) {
				$item_data['collectionIds'] = array_map( 'sanitize_text_field', $arguments['collection_ids'] );
			}
		}

		// Add type-specific data.
		switch ( $type ) {
			case 1: // Login.
				$item_data['login'] = $this->build_login_data( $arguments );
				break;

			case 2: // Secure Note.
				$item_data['secureNote'] = array(
					'type' => 0, // Generic note.
				);
				break;

			case 3: // Card.
				return new WP_Error( 'unsupported_type', __( 'Card type is not yet supported. Use type 1 (Login) or 2 (Secure Note).', 'mcp-ai-wpoos-pro' ) );

			case 4: // Identity.
				return new WP_Error( 'unsupported_type', __( 'Identity type is not yet supported. Use type 1 (Login) or 2 (Secure Note).', 'mcp-ai-wpoos-pro' ) );

			default:
				return new WP_Error( 'invalid_type', __( 'Invalid item type. Use 1 (Login) or 2 (Secure Note).', 'mcp-ai-wpoos-pro' ) );
		}

		return $item_data;
	}

	/**
	 * Build login data from arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Login data.
	 */
	private function build_login_data( $arguments ) {
		$login_data = array();

		// Add username if provided.
		if ( ! empty( $arguments['username'] ) ) {
			$login_data['username'] = sanitize_text_field( $arguments['username'] );
		}

		// Add password if provided.
		if ( ! empty( $arguments['password'] ) ) {
			$login_data['password'] = $arguments['password']; // Don't sanitize passwords.
		}

		// Add URIs if provided.
		if ( ! empty( $arguments['uris'] ) && is_array( $arguments['uris'] ) ) {
			$login_data['uris'] = array();
			foreach ( $arguments['uris'] as $uri ) {
				$login_data['uris'][] = array(
					'uri'   => esc_url_raw( $uri ),
					'match' => null, // Default match type.
				);
			}
		}

		return $login_data;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Tool is part of the Pro tier.
			'write',                // Modifies vault data.
			'requires-credentials', // Requires Bitwarden OAuth authentication.
			'external-api',         // Makes external API calls to Bitwarden.
			'network-dependent',    // Requires internet connectivity.
			'sensitive-data',       // Handles sensitive credential data.
		);
	}
}
