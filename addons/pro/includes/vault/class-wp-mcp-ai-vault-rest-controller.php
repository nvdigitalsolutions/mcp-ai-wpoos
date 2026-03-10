<?php
/**
 * Password Vault REST API Controller
 *
 * Provides REST API endpoints for programmatic vault access and AI assistant integration.
 * Implements rate limiting, authentication, and encryption for all operations.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Vault_REST_Controller
 *
 * REST API controller for password vault operations.
 */
class WP_MCP_AI_Vault_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai/v1';

	/**
	 * REST base
	 *
	 * @var string
	 */
	protected $rest_base = 'vault';

	/**
	 * Encryption service instance
	 *
	 * @var WP_MCP_AI_Vault_Encryption_Service
	 */
	protected $encryption_service;

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * Maximum requests per rate limit window.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX = 60;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->encryption_service = new WP_MCP_AI_Vault_Encryption_Service();
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// List vault items.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/items',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_list_items_params(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_create_item_params(),
				),
			)
		);

		// Get/update/delete specific vault item.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/items/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_update_item_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// List folders.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/folders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_folders' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_folder' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_create_folder_params(),
				),
			)
		);

		// Generate password.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/generate-password',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_password' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->get_generate_password_params(),
			)
		);

		// Generate TOTP secret.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/generate-totp-secret',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generate_totp_secret' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Search vault items.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search_items' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'query'     => array(
						'required'    => true,
						'type'        => 'string',
						'description' => 'Search query',
					),
					'item_type' => array(
						'type'        => 'string',
						'enum'        => array( 'login', 'note', 'card', 'identity' ),
						'description' => 'Filter by item type',
					),
					'folder_id' => array(
						'type'        => 'integer',
						'description' => 'Filter by folder ID',
					),
				),
			)
		);
	}

	/**
	 * Check if current user has permission
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_permission( $request ) {
		// Rate limiting check.
		if ( ! $this->check_rate_limit( $request ) ) {
			return new WP_Error(
				'rest_rate_limit_exceeded',
				__( 'Rate limit exceeded. Please try again later.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 429 )
			);
		}

		// Require authentication.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Authentication required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 401 )
			);
		}

		// Check capability (edit_posts minimum).
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access the vault.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check rate limit for user using persistent transient storage.
	 *
	 * Stores the request count in a transient so limits are enforced
	 * across all concurrent requests and page reloads.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the request is allowed, false if rate limit exceeded.
	 */
	protected function check_rate_limit( $request ) {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$cache_key = 'vault_rl_u_' . $user_id;
		} else {
			// Validate the IP before using it as a cache key.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated with filter_var immediately below.
			$raw_ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
			$ip        = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : 'unknown';
			$cache_key = 'vault_rl_g_' . md5( $ip );
		}

		$count = (int) get_transient( $cache_key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}

		// Increment counter; initialise the transient window on first request.
		set_transient( $cache_key, $count + 1, self::RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * Get list of vault items
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$user_id   = get_current_user_id();
		$per_page  = $request->get_param( 'per_page' ) ? absint( $request->get_param( 'per_page' ) ) : 20;
		$page      = $request->get_param( 'page' ) ? absint( $request->get_param( 'page' ) ) : 1;
		$item_type = $request->get_param( 'item_type' );
		$folder_id = $request->get_param( 'folder_id' );

		$args = array(
			'post_type'      => 'mcp_vault_item',
			'author'         => $user_id,
			'posts_per_page' => min( $per_page, 100 ), // Max 100 items.
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( $item_type ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_vault_item_type',
					'value' => sanitize_text_field( $item_type ),
				),
			);
		}

		if ( $folder_id ) {
			$args['meta_query'][] = array(
				'key'   => '_vault_folder_id',
				'value' => absint( $folder_id ),
			);
		}

		$query = new WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->prepare_item_for_response( $post );
		}

		$response = rest_ensure_response( $items );

		// Add pagination headers.
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	/**
	 * Get single vault item
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || 'mcp_vault_item' !== $post->post_type ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Vault item not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Check ownership.
		if ( (int) $post->post_author !== get_current_user_id() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this vault item.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		return rest_ensure_response( $this->prepare_item_for_response( $post ) );
	}

	/**
	 * Verify that a folder belongs to the current user.
	 *
	 * Returns a WP_Error when the folder does not exist or is owned by another
	 * user, so callers can return it directly from their endpoint handler.
	 *
	 * @param int $folder_id Folder post ID (0 means "no folder" — always valid).
	 * @return true|WP_Error True when ownership is confirmed, WP_Error otherwise.
	 */
	protected function verify_folder_ownership( $folder_id ) {
		if ( 0 === $folder_id ) {
			return true;
		}

		$folder = get_post( $folder_id );

		if ( ! $folder || 'mcp_vault_folder' !== $folder->post_type ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Vault folder not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		if ( (int) $folder->post_author !== get_current_user_id() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to use this vault folder.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Create vault item
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$user_id   = get_current_user_id();
		$name      = sanitize_text_field( $request->get_param( 'name' ) );
		$item_type = sanitize_text_field( $request->get_param( 'item_type' ) );
		$folder_id = $request->get_param( 'folder_id' ) ? absint( $request->get_param( 'folder_id' ) ) : 0;
		$favorite  = (bool) $request->get_param( 'favorite' );

		// Validate item type.
		if ( ! in_array( $item_type, array( 'login', 'note', 'card', 'identity' ), true ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Invalid item type.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Verify the target folder (if any) belongs to the current user.
		$folder_check = $this->verify_folder_ownership( $folder_id );
		if ( is_wp_error( $folder_check ) ) {
			return $folder_check;
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
			return $post_id;
		}

		// Save metadata.
		update_post_meta( $post_id, '_vault_item_type', $item_type );
		update_post_meta( $post_id, '_vault_folder_id', $folder_id );
		update_post_meta( $post_id, '_vault_favorite', $favorite ? '1' : '0' );

		// Encrypt and save item data based on type.
		$item_data = $this->prepare_item_data_for_storage( $item_type, $request );
		if ( $item_data ) {
			$encrypted_data = $this->encryption_service->encrypt( wp_json_encode( $item_data ) );
			if ( $encrypted_data ) {
				update_post_meta( $post_id, '_vault_encrypted_data', $encrypted_data );
			}
		}

		$post = get_post( $post_id );
		return rest_ensure_response( $this->prepare_item_for_response( $post ) );
	}

	/**
	 * Update vault item
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || 'mcp_vault_item' !== $post->post_type ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Vault item not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Check ownership.
		if ( (int) $post->post_author !== get_current_user_id() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to update this vault item.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Update post title if provided.
		if ( $request->has_param( 'name' ) ) {
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => sanitize_text_field( $request->get_param( 'name' ) ),
				)
			);
		}

		// Update metadata if provided.
		if ( $request->has_param( 'folder_id' ) ) {
			$new_folder_id = absint( $request->get_param( 'folder_id' ) );
			// Verify the target folder (if any) belongs to the current user.
			$folder_check = $this->verify_folder_ownership( $new_folder_id );
			if ( is_wp_error( $folder_check ) ) {
				return $folder_check;
			}
			update_post_meta( $id, '_vault_folder_id', $new_folder_id );
		}

		if ( $request->has_param( 'favorite' ) ) {
			update_post_meta( $id, '_vault_favorite', (bool) $request->get_param( 'favorite' ) ? '1' : '0' );
		}

		// Update encrypted data if provided.
		$item_type = get_post_meta( $id, '_vault_item_type', true );
		$item_data = $this->prepare_item_data_for_storage( $item_type, $request );
		if ( $item_data ) {
			$encrypted_data = $this->encryption_service->encrypt( wp_json_encode( $item_data ) );
			if ( $encrypted_data ) {
				update_post_meta( $id, '_vault_encrypted_data', $encrypted_data );
			}
		}

		$post = get_post( $id );
		return rest_ensure_response( $this->prepare_item_for_response( $post ) );
	}

	/**
	 * Delete vault item
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || 'mcp_vault_item' !== $post->post_type ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Vault item not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Check ownership.
		if ( (int) $post->post_author !== get_current_user_id() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to delete this vault item.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$result = wp_delete_post( $id, true );

		if ( ! $result ) {
			return new WP_Error(
				'rest_cannot_delete',
				__( 'Failed to delete vault item.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Get folders
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_folders( $request ) {
		$user_id = get_current_user_id();

		$args = array(
			'post_type'      => 'mcp_vault_folder',
			'author'         => $user_id,
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$query = new WP_Query( $args );

		$folders = array();
		foreach ( $query->posts as $post ) {
			$folders[] = array(
				'id'   => $post->ID,
				'name' => $post->post_title,
			);
		}

		return rest_ensure_response( $folders );
	}

	/**
	 * Create folder
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_folder( $request ) {
		$user_id = get_current_user_id();
		$name    = sanitize_text_field( $request->get_param( 'name' ) );

		$post_id = wp_insert_post(
			array(
				'post_title'  => $name,
				'post_type'   => 'mcp_vault_folder',
				'post_status' => 'private',
				'post_author' => $user_id,
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		return rest_ensure_response(
			array(
				'id'   => $post_id,
				'name' => $name,
			)
		);
	}

	/**
	 * Generate password
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_password( $request ) {
		$length          = $request->get_param( 'length' ) ? absint( $request->get_param( 'length' ) ) : 16;
		$uppercase       = (bool) $request->get_param( 'uppercase' );
		$lowercase       = (bool) $request->get_param( 'lowercase' );
		$numbers         = (bool) $request->get_param( 'numbers' );
		$symbols         = (bool) $request->get_param( 'symbols' );
		$avoid_ambiguous = (bool) $request->get_param( 'avoid_ambiguous' );

		$password = $this->encryption_service->generate_password(
			$length,
			$uppercase,
			$lowercase,
			$numbers,
			$symbols,
			$avoid_ambiguous
		);

		if ( ! $password ) {
			return new WP_Error(
				'rest_password_generation_failed',
				__( 'Failed to generate password.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		$strength = $this->encryption_service->calculate_password_strength( $password );

		return rest_ensure_response(
			array(
				'password' => $password,
				'strength' => $strength,
			)
		);
	}

	/**
	 * Generate TOTP secret
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_totp_secret( $request ) {
		$secret = $this->encryption_service->generate_totp_secret();

		if ( ! $secret ) {
			return new WP_Error(
				'rest_totp_generation_failed',
				__( 'Failed to generate TOTP secret.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'secret' => $secret,
			)
		);
	}

	/**
	 * Search vault items
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_items( $request ) {
		$user_id   = get_current_user_id();
		$query     = sanitize_text_field( $request->get_param( 'query' ) );
		$item_type = $request->get_param( 'item_type' );
		$folder_id = $request->get_param( 'folder_id' );

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
					'value' => sanitize_text_field( $item_type ),
				),
			);
		}

		if ( $folder_id ) {
			$args['meta_query'][] = array(
				'key'   => '_vault_folder_id',
				'value' => absint( $folder_id ),
			);
		}

		$query_obj = new WP_Query( $args );

		$items = array();
		foreach ( $query_obj->posts as $post ) {
			$items[] = $this->prepare_item_for_response( $post );
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Prepare item for REST response
	 *
	 * @param WP_Post         $item Post object.
	 * @param WP_REST_Request $request Request object.
	 * @return array
	 */
	public function prepare_item_for_response( $item, $request ) {
		$post           = $item; // For backward compatibility with existing code
		$item_type      = get_post_meta( $post->ID, '_vault_item_type', true );
		$folder_id      = get_post_meta( $post->ID, '_vault_folder_id', true );
		$favorite       = get_post_meta( $post->ID, '_vault_favorite', true ) === '1';
		$encrypted_data = get_post_meta( $post->ID, '_vault_encrypted_data', true );

		$data = array();
		if ( $encrypted_data ) {
			$decrypted = $this->encryption_service->decrypt( $encrypted_data );
			if ( $decrypted ) {
				$data = json_decode( $decrypted, true );
			}
		}

		return array(
			'id'         => $post->ID,
			'name'       => $post->post_title,
			'item_type'  => $item_type,
			'folder_id'  => (int) $folder_id,
			'favorite'   => $favorite,
			'data'       => $data,
			'created_at' => $post->post_date_gmt,
			'updated_at' => $post->post_modified_gmt,
		);
	}

	/**
	 * Prepare item data for storage
	 *
	 * @param string          $item_type Item type.
	 * @param WP_REST_Request $request   Request object.
	 * @return array|null
	 */
	protected function prepare_item_data_for_storage( $item_type, $request ) {
		switch ( $item_type ) {
			case 'login':
				return array(
					'username' => sanitize_text_field( $request->get_param( 'username' ) ),
					'password' => $request->get_param( 'password' ), // Don't sanitize password.
					'uri'      => esc_url_raw( $request->get_param( 'uri' ) ),
					'totp'     => sanitize_text_field( $request->get_param( 'totp' ) ),
					'notes'    => sanitize_textarea_field( $request->get_param( 'notes' ) ),
				);

			case 'note':
				return array(
					'notes' => sanitize_textarea_field( $request->get_param( 'notes' ) ),
				);

			case 'card':
				return array(
					'cardholder'    => sanitize_text_field( $request->get_param( 'cardholder' ) ),
					'card_number'   => sanitize_text_field( $request->get_param( 'card_number' ) ),
					'expiry_month'  => sanitize_text_field( $request->get_param( 'expiry_month' ) ),
					'expiry_year'   => sanitize_text_field( $request->get_param( 'expiry_year' ) ),
					'security_code' => sanitize_text_field( $request->get_param( 'security_code' ) ),
				);

			case 'identity':
				return array(
					'title'      => sanitize_text_field( $request->get_param( 'title' ) ),
					'first_name' => sanitize_text_field( $request->get_param( 'first_name' ) ),
					'last_name'  => sanitize_text_field( $request->get_param( 'last_name' ) ),
					'email'      => sanitize_email( $request->get_param( 'email' ) ),
					'phone'      => sanitize_text_field( $request->get_param( 'phone' ) ),
					'address'    => sanitize_textarea_field( $request->get_param( 'address' ) ),
				);

			default:
				return null;
		}
	}

	/**
	 * Get list items params
	 *
	 * @return array
	 */
	protected function get_list_items_params() {
		return array(
			'per_page'  => array(
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Number of items per page.', 'mcp-ai-wpoos-pro' ),
			),
			'page'      => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page number.', 'mcp-ai-wpoos-pro' ),
			),
			'item_type' => array(
				'type'        => 'string',
				'enum'        => array( 'login', 'note', 'card', 'identity' ),
				'description' => __( 'Filter by item type.', 'mcp-ai-wpoos-pro' ),
			),
			'folder_id' => array(
				'type'        => 'integer',
				'description' => __( 'Filter by folder ID.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get create item params
	 *
	 * @return array
	 */
	protected function get_create_item_params() {
		return array(
			'name'      => array(
				'required'    => true,
				'type'        => 'string',
				'description' => __( 'Vault item name.', 'mcp-ai-wpoos-pro' ),
			),
			'item_type' => array(
				'required'    => true,
				'type'        => 'string',
				'enum'        => array( 'login', 'note', 'card', 'identity' ),
				'description' => __( 'Vault item type.', 'mcp-ai-wpoos-pro' ),
			),
			'folder_id' => array(
				'type'        => 'integer',
				'description' => __( 'Folder ID (optional).', 'mcp-ai-wpoos-pro' ),
			),
			'favorite'  => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Mark as favorite.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get update item params
	 *
	 * @return array
	 */
	protected function get_update_item_params() {
		return array(
			'name'      => array(
				'type'        => 'string',
				'description' => __( 'Vault item name.', 'mcp-ai-wpoos-pro' ),
			),
			'folder_id' => array(
				'type'        => 'integer',
				'description' => __( 'Folder ID.', 'mcp-ai-wpoos-pro' ),
			),
			'favorite'  => array(
				'type'        => 'boolean',
				'description' => __( 'Mark as favorite.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get create folder params
	 *
	 * @return array
	 */
	protected function get_create_folder_params() {
		return array(
			'name' => array(
				'required'    => true,
				'type'        => 'string',
				'description' => __( 'Folder name.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get generate password params
	 *
	 * @return array
	 */
	protected function get_generate_password_params() {
		return array(
			'length'          => array(
				'type'        => 'integer',
				'default'     => 16,
				'minimum'     => 12,
				'maximum'     => 128,
				'description' => __( 'Password length.', 'mcp-ai-wpoos-pro' ),
			),
			'uppercase'       => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Include uppercase letters.', 'mcp-ai-wpoos-pro' ),
			),
			'lowercase'       => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Include lowercase letters.', 'mcp-ai-wpoos-pro' ),
			),
			'numbers'         => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Include numbers.', 'mcp-ai-wpoos-pro' ),
			),
			'symbols'         => array(
				'type'        => 'boolean',
				'default'     => true,
				'description' => __( 'Include symbols.', 'mcp-ai-wpoos-pro' ),
			),
			'avoid_ambiguous' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Avoid ambiguous characters.', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
