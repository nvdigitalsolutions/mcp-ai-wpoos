<?php
/**
 * Tool: Vault Access (Read-Only)
 *
 * Provides read-only access to password vault items for AI assistants.
 * Supports searching and retrieving encrypted credentials securely.
 *
 * Ported from the base Pro addon for the standalone
 * nvoos-content-graph-pro addon (Wave F1, sub-cluster 4b). Global class name
 * kept byte-identical; the base Pro addon owns the class in monolith installs.
 * Deviations: strict types; text domain nvoos-content-graph-pro.
 *
 * @package NvoosContentGraphPro
 * @subpackage Tools
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vault Access Tool
 */
class WP_MCP_AI_Pro_Tool_Vault_Access {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'vault_access';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'vault_access',
			'description'         => 'Read-only access to password vault. Search and retrieve login credentials, secure notes, cards, or identity information securely. Use when you need to access stored passwords or credentials for automation tasks.',
			'category'            => 'password_vault',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'action'    => array(
						'type'        => 'string',
						'enum'        => array( 'list', 'search', 'get' ),
						'description' => 'Action to perform: list (get all items), search (find by query), get (get specific item by ID)',
					),
					'item_type' => array(
						'type'        => 'string',
						'enum'        => array( 'login', 'note', 'card', 'identity' ),
						'description' => 'Filter by item type (optional)',
					),
					'folder_id' => array(
						'type'        => 'integer',
						'description' => 'Filter by folder ID (optional)',
					),
					'query'     => array(
						'type'        => 'string',
						'description' => 'Search query (required for search action)',
					),
					'item_id'   => array(
						'type'        => 'integer',
						'description' => 'Vault item ID (required for get action)',
					),
					'per_page'  => array(
						'type'        => 'integer',
						'default'     => 20,
						'minimum'     => 1,
						'maximum'     => 100,
						'description' => 'Number of items per page (for list/search)',
					),
					'page'      => array(
						'type'        => 'integer',
						'default'     => 1,
						'minimum'     => 1,
						'description' => 'Page number (for list/search)',
					),
				),
				'required'   => array( 'action' ),
			),
			'required_capability' => 'manage_options',
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Byte-identical tool contract signature.
	public function execute( array $arguments = array(), array $context = array() ) {
		// Enforce manage_options capability regardless of tool-framework checks.
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_vault_forbidden',
				__( 'Insufficient permissions. Only administrators may access the password vault.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate action.
		if ( empty( $arguments['action'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_vault_missing_action',
				__( 'Missing required argument: action', 'nvoos-content-graph-pro' )
			);
		}

		$action = sanitize_text_field( $arguments['action'] );

		// Route to appropriate method.
		switch ( $action ) {
			case 'list':
				return $this->list_items( $arguments );

			case 'search':
				return $this->search_items( $arguments );

			case 'get':
				return $this->get_item( $arguments );

			default:
				return new WP_Error(
					'wp_mcp_ai_vault_invalid_action',
					__( 'Invalid action. Use: list, search, or get', 'nvoos-content-graph-pro' )
				);
		}
	}

	/**
	 * List vault items
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_items( $arguments ) {
		$user_id   = get_current_user_id();
		$per_page  = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 20;
		$page      = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$item_type = isset( $arguments['item_type'] ) ? sanitize_text_field( $arguments['item_type'] ) : '';
		$folder_id = isset( $arguments['folder_id'] ) ? absint( $arguments['folder_id'] ) : 0;

		$args = array(
			'post_type'      => 'mcp_vault_item',
			'author'         => $user_id,
			'posts_per_page' => min( $per_page, 100 ),
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $item_type ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_vault_item_type',
					'value' => $item_type,
				),
			);
		}

		if ( $folder_id ) {
			if ( ! isset( $args['meta_query'] ) ) {
				$args['meta_query'] = array();
			}
			$args['meta_query'][] = array(
				'key'   => '_vault_folder_id',
				'value' => $folder_id,
			);
		}

		$query = new WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_item( $post );
		}

		return array(
			'success'     => true,
			'action'      => 'list',
			'items'       => $items,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Search vault items
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function search_items( $arguments ) {
		if ( empty( $arguments['query'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_vault_missing_query',
				__( 'Search query is required for search action', 'nvoos-content-graph-pro' )
			);
		}

		$user_id   = get_current_user_id();
		$query     = sanitize_text_field( $arguments['query'] );
		$item_type = isset( $arguments['item_type'] ) ? sanitize_text_field( $arguments['item_type'] ) : '';
		$folder_id = isset( $arguments['folder_id'] ) ? absint( $arguments['folder_id'] ) : 0;

		$args = array(
			'post_type'      => 'mcp_vault_item',
			'author'         => $user_id,
			'posts_per_page' => 50,
			's'              => $query,
		);

		if ( $item_type ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_vault_item_type',
					'value' => $item_type,
				),
			);
		}

		if ( $folder_id ) {
			if ( ! isset( $args['meta_query'] ) ) {
				$args['meta_query'] = array();
			}
			$args['meta_query'][] = array(
				'key'   => '_vault_folder_id',
				'value' => $folder_id,
			);
		}

		$query_obj = new WP_Query( $args );

		$items = array();
		foreach ( $query_obj->posts as $post ) {
			$items[] = $this->prepare_item( $post );
		}

		return array(
			'success' => true,
			'action'  => 'search',
			'query'   => $query,
			'items'   => $items,
			'total'   => count( $items ),
		);
	}

	/**
	 * Get single vault item
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_item( $arguments ) {
		if ( empty( $arguments['item_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_vault_missing_item_id',
				__( 'Item ID is required for get action', 'nvoos-content-graph-pro' )
			);
		}

		$item_id = absint( $arguments['item_id'] );
		$post    = get_post( $item_id );

		if ( ! $post || 'mcp_vault_item' !== $post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_vault_item_not_found',
				__( 'Vault item not found', 'nvoos-content-graph-pro' )
			);
		}

		// Check ownership.
		if ( (int) get_current_user_id() !== (int) $post->post_author ) {
			return new WP_Error(
				'wp_mcp_ai_vault_not_owner',
				__( 'You do not have permission to access this vault item', 'nvoos-content-graph-pro' )
			);
		}

		return array(
			'success' => true,
			'action'  => 'get',
			'item'    => $this->prepare_item( $post, true ),
		);
	}

	/**
	 * Prepare item for response
	 *
	 * @param WP_Post $post         Post object.
	 * @param bool    $include_data Whether to include decrypted data.
	 * @return array
	 */
	protected function prepare_item( $post, $include_data = true ) {
		$item_type = get_post_meta( $post->ID, '_vault_item_type', true );
		$folder_id = get_post_meta( $post->ID, '_vault_folder_id', true );
		$favorite  = get_post_meta( $post->ID, '_vault_favorite', true ) === '1';

		$item = array(
			'id'         => $post->ID,
			'name'       => $post->post_title,
			'item_type'  => $item_type,
			'folder_id'  => (int) $folder_id,
			'favorite'   => $favorite,
			'created_at' => $post->post_date_gmt,
			'updated_at' => $post->post_modified_gmt,
		);

		if ( $include_data ) {
			$encrypted_data = get_post_meta( $post->ID, '_vault_encrypted_data', true );
			if ( $encrypted_data ) {
				$encryption_service = new WP_MCP_AI_Vault_Encryption_Service();
				$decrypted          = $encryption_service->decrypt( $encrypted_data );
				if ( $decrypted ) {
					$item['data'] = json_decode( $decrypted, true );
				}
			}
		}

		return $item;
	}

	/**
	 * The decrypted `data` payload holds vault secrets (passwords, TOTP seeds,
	 * card numbers) under innocuous keys, so it must never reach a log.
	 *
	 * @since 1.1.64
	 *
	 * @return string[] Dot-notation paths that must never be persisted to a log.
	 */
	public function get_sensitive_result_fields() {
		return array( 'item.data', 'items.*.data' );
	}
}
