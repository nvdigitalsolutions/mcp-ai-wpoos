<?php
/**
 * Remote Site Connection Manager.
 *
 * Manages connections to remote WordPress/WooCommerce sites.
 * Stores connection credentials, handles authentication, and provides
 * a centralized interface for managing multiple remote site connections.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages remote WordPress/WooCommerce site connections.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Remote_Site_Manager {

	/**
	 * Option name for storing remote site connections.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_pro_remote_sites';

	/**
	 * Supported authentication types.
	 *
	 * @var array<string>
	 */
	const AUTH_TYPES = array( 'application_password', 'basic_auth', 'jwt', 'woocommerce', 'custom_header', 'none' );

	/**
	 * Get all configured remote site connections.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of site connections.
	 */
	public static function get_all_connections() {
		$connections = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $connections ) ) {
			return array();
		}

		// Migrate connection IDs to lowercase if needed.
		$connections = self::migrate_connection_ids( $connections );

		return $connections;
	}

	/**
	 * Get a specific remote site connection by ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @return array|null Connection data or null if not found.
	 */
	public static function get_connection( $connection_id ) {
		$connections   = self::get_all_connections();
		$connection_id = sanitize_key( $connection_id );

		if ( isset( $connections[ $connection_id ] ) ) {
			return $connections[ $connection_id ];
		}

		return null;
	}

	/**
	 * Add or update a remote site connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection_data Connection data.
	 * @return string|WP_Error Connection ID on success, WP_Error on failure.
	 */
	public static function save_connection( $connection_data ) {
		$connections = self::get_all_connections();

		// Generate or use existing connection ID.
		$is_update = false;
		if ( empty( $connection_data['id'] ) ) {
			$connection_id = self::generate_connection_id();
		} else {
			$connection_id = sanitize_key( $connection_data['id'] );
			$is_update     = isset( $connections[ $connection_id ] );
		}

		// If updating and password/token fields are empty, preserve existing values.
		if ( $is_update ) {
			$existing_connection = $connections[ $connection_id ];

			// Preserve existing password if not provided.
			if ( empty( $connection_data['password'] ) && ! empty( $existing_connection['password'] ) ) {
				$connection_data['password'] = $existing_connection['password'];
				// Mark as already encrypted.
				$connection_data['_password_encrypted'] = true;
			}

			// Preserve existing token if not provided.
			if ( empty( $connection_data['token'] ) && ! empty( $existing_connection['token'] ) ) {
				$connection_data['token'] = $existing_connection['token'];
				// Mark as already encrypted.
				$connection_data['_token_encrypted'] = true;
			}

			// Preserve existing consumer_key if not provided.
			if ( empty( $connection_data['consumer_key'] ) && ! empty( $existing_connection['consumer_key'] ) ) {
				$connection_data['consumer_key']            = $existing_connection['consumer_key'];
				$connection_data['_consumer_key_encrypted'] = true;
			}

			// Preserve existing consumer_secret if not provided.
			if ( empty( $connection_data['consumer_secret'] ) && ! empty( $existing_connection['consumer_secret'] ) ) {
				$connection_data['consumer_secret']            = $existing_connection['consumer_secret'];
				$connection_data['_consumer_secret_encrypted'] = true;
			}

			// Preserve existing api_key if not provided.
			if ( empty( $connection_data['api_key'] ) && ! empty( $existing_connection['api_key'] ) ) {
				$connection_data['api_key']            = $existing_connection['api_key'];
				$connection_data['_api_key_encrypted'] = true;
			}

			// Preserve existing api_secret if not provided.
			if ( empty( $connection_data['api_secret'] ) && ! empty( $existing_connection['api_secret'] ) ) {
				$connection_data['api_secret']            = $existing_connection['api_secret'];
				$connection_data['_api_secret_encrypted'] = true;
			}

			// Preserve existing client_id if not provided.
			if ( empty( $connection_data['client_id'] ) && ! empty( $existing_connection['client_id'] ) ) {
				$connection_data['client_id'] = $existing_connection['client_id'];
			}

			// Preserve existing client_secret if not provided.
			if ( empty( $connection_data['client_secret'] ) && ! empty( $existing_connection['client_secret'] ) ) {
				$connection_data['client_secret']            = $existing_connection['client_secret'];
				$connection_data['_client_secret_encrypted'] = true;
			}

			// Preserve existing app_id if not provided.
			if ( empty( $connection_data['app_id'] ) && ! empty( $existing_connection['app_id'] ) ) {
				$connection_data['app_id'] = $existing_connection['app_id'];
			}

			// Preserve existing app_secret if not provided.
			if ( empty( $connection_data['app_secret'] ) && ! empty( $existing_connection['app_secret'] ) ) {
				$connection_data['app_secret']            = $existing_connection['app_secret'];
				$connection_data['_app_secret_encrypted'] = true;
			}

			// Preserve existing refresh_token (Gmail) if not provided.
			if ( empty( $connection_data['refresh_token'] ) && ! empty( $existing_connection['refresh_token'] ) ) {
				$connection_data['refresh_token']            = $existing_connection['refresh_token'];
				$connection_data['_refresh_token_encrypted'] = true;
			}

			// Preserve existing user_email (Gmail) if not provided.
			if ( empty( $connection_data['user_email'] ) && ! empty( $existing_connection['user_email'] ) ) {
				$connection_data['user_email'] = $existing_connection['user_email'];
			}

			// Preserve existing folder_id (Google Drive) if not provided.
			if ( empty( $connection_data['folder_id'] ) && ! empty( $existing_connection['folder_id'] ) ) {
				$connection_data['folder_id'] = $existing_connection['folder_id'];
			}

			// Preserve existing bot_username (Telegram) if not provided.
			if ( empty( $connection_data['bot_username'] ) && ! empty( $existing_connection['bot_username'] ) ) {
				$connection_data['bot_username'] = $existing_connection['bot_username'];
			}

			// Preserve existing WhatsApp-specific fields if not provided.
			if ( empty( $connection_data['phone_number_id'] ) && ! empty( $existing_connection['phone_number_id'] ) ) {
				$connection_data['phone_number_id'] = $existing_connection['phone_number_id'];
			}

			if ( empty( $connection_data['business_account_id'] ) && ! empty( $existing_connection['business_account_id'] ) ) {
				$connection_data['business_account_id'] = $existing_connection['business_account_id'];
			}

			if ( empty( $connection_data['verify_token'] ) && ! empty( $existing_connection['verify_token'] ) ) {
				$connection_data['verify_token'] = $existing_connection['verify_token'];
			}

			// Preserve existing workspace_id (Slack) if not provided.
			if ( empty( $connection_data['workspace_id'] ) && ! empty( $existing_connection['workspace_id'] ) ) {
				$connection_data['workspace_id'] = $existing_connection['workspace_id'];
			}

			// Preserve existing Discord-specific fields if not provided.
			if ( empty( $connection_data['application_id'] ) && ! empty( $existing_connection['application_id'] ) ) {
				$connection_data['application_id'] = $existing_connection['application_id'];
			}

			if ( empty( $connection_data['guild_id'] ) && ! empty( $existing_connection['guild_id'] ) ) {
				$connection_data['guild_id'] = $existing_connection['guild_id'];
			}

			// Preserve existing tenant_id (Microsoft Teams) if not provided.
			if ( empty( $connection_data['tenant_id'] ) && ! empty( $existing_connection['tenant_id'] ) ) {
				$connection_data['tenant_id'] = $existing_connection['tenant_id'];
			}

			// Preserve existing page_id (Facebook Messenger) if not provided.
			if ( empty( $connection_data['page_id'] ) && ! empty( $existing_connection['page_id'] ) ) {
				$connection_data['page_id'] = $existing_connection['page_id'];
			}

			// Preserve existing p2p_connection_id (WebChat) if not provided.
			if ( empty( $connection_data['p2p_connection_id'] ) && ! empty( $existing_connection['p2p_connection_id'] ) ) {
				$connection_data['p2p_connection_id'] = $existing_connection['p2p_connection_id'];
			}

			// Preserve existing test_endpoint if not provided.
			if ( empty( $connection_data['test_endpoint'] ) && ! empty( $existing_connection['test_endpoint'] ) ) {
				$connection_data['test_endpoint'] = $existing_connection['test_endpoint'];
			}

			// Preserve existing cache_ttl if not provided.
			if ( ! isset( $connection_data['cache_ttl'] ) && isset( $existing_connection['cache_ttl'] ) ) {
				$connection_data['cache_ttl'] = $existing_connection['cache_ttl'];
			}

			// Preserve existing location_id if not provided.
			if ( empty( $connection_data['location_id'] ) && ! empty( $existing_connection['location_id'] ) ) {
				$connection_data['location_id'] = $existing_connection['location_id'];
			}

			// Preserve existing company_id if not provided.
			if ( empty( $connection_data['company_id'] ) && ! empty( $existing_connection['company_id'] ) ) {
				$connection_data['company_id'] = $existing_connection['company_id'];
			}

			// Preserve existing sandbox_mode if not provided.
			if ( ! isset( $connection_data['sandbox_mode'] ) && isset( $existing_connection['sandbox_mode'] ) ) {
				$connection_data['sandbox_mode'] = $existing_connection['sandbox_mode'];
			}

			// Preserve created timestamp.
			if ( ! isset( $connection_data['created'] ) && ! empty( $existing_connection['created'] ) ) {
				$connection_data['created'] = $existing_connection['created'];
			}
		}

		$validation = self::validate_connection_data( $connection_data );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Prepare connection data.
		$connection = array(
			'id'              => $connection_id,
			'name'            => sanitize_text_field( $connection_data['name'] ),
			'url'             => esc_url_raw( trailingslashit( $connection_data['url'] ) ),
			'connection_type' => isset( $connection_data['connection_type'] ) ? sanitize_key( $connection_data['connection_type'] ) : 'wordpress',
			'auth_type'       => sanitize_key( $connection_data['auth_type'] ),
			'username'        => isset( $connection_data['username'] ) ? sanitize_text_field( $connection_data['username'] ) : '',
			'password'        => isset( $connection_data['password'] ) ? $connection_data['password'] : '',
			'token'           => isset( $connection_data['token'] ) ? $connection_data['token'] : '',
			'consumer_key'    => isset( $connection_data['consumer_key'] ) ? $connection_data['consumer_key'] : '',
			'consumer_secret' => isset( $connection_data['consumer_secret'] ) ? $connection_data['consumer_secret'] : '',
			'api_key'         => isset( $connection_data['api_key'] ) ? $connection_data['api_key'] : '',
			'api_secret'      => isset( $connection_data['api_secret'] ) ? $connection_data['api_secret'] : '',
			'client_id'       => isset( $connection_data['client_id'] ) ? sanitize_text_field( $connection_data['client_id'] ) : '',
			'client_secret'   => isset( $connection_data['client_secret'] ) ? $connection_data['client_secret'] : '',
			'app_id'          => isset( $connection_data['app_id'] ) ? sanitize_text_field( $connection_data['app_id'] ) : '',
			'app_secret'      => isset( $connection_data['app_secret'] ) ? $connection_data['app_secret'] : '',
			'location_id'     => isset( $connection_data['location_id'] ) ? sanitize_text_field( $connection_data['location_id'] ) : '',
			'company_id'      => isset( $connection_data['company_id'] ) ? sanitize_text_field( $connection_data['company_id'] ) : '',
			'sandbox_mode'    => ! empty( $connection_data['sandbox_mode'] ),
			'has_woocommerce' => ! empty( $connection_data['has_woocommerce'] ),
			'enabled'         => ! empty( $connection_data['enabled'] ),
			'created'         => isset( $connection_data['created'] ) ? $connection_data['created'] : current_time( 'mysql' ),
			'updated'         => current_time( 'mysql' ),
			// Gmail-specific fields.
			'refresh_token'   => isset( $connection_data['refresh_token'] ) ? $connection_data['refresh_token'] : '',
			'user_email'      => isset( $connection_data['user_email'] ) ? sanitize_email( $connection_data['user_email'] ) : '',
			// Google Drive-specific fields.
			'folder_id'       => isset( $connection_data['folder_id'] ) ? sanitize_text_field( $connection_data['folder_id'] ) : '',
			// Telegram-specific fields.
			'bot_username'    => isset( $connection_data['bot_username'] ) ? sanitize_text_field( $connection_data['bot_username'] ) : '',
			// WhatsApp-specific fields.
			'phone_number_id'     => isset( $connection_data['phone_number_id'] ) ? sanitize_text_field( $connection_data['phone_number_id'] ) : '',
			'business_account_id' => isset( $connection_data['business_account_id'] ) ? sanitize_text_field( $connection_data['business_account_id'] ) : '',
			'verify_token'        => isset( $connection_data['verify_token'] ) ? sanitize_text_field( $connection_data['verify_token'] ) : '',
			// Slack-specific fields.
			'workspace_id'    => isset( $connection_data['workspace_id'] ) ? sanitize_text_field( $connection_data['workspace_id'] ) : '',
			// Discord-specific fields.
			'application_id'  => isset( $connection_data['application_id'] ) ? sanitize_text_field( $connection_data['application_id'] ) : '',
			'guild_id'        => isset( $connection_data['guild_id'] ) ? sanitize_text_field( $connection_data['guild_id'] ) : '',
			// Microsoft Teams-specific fields.
			'tenant_id'       => isset( $connection_data['tenant_id'] ) ? sanitize_text_field( $connection_data['tenant_id'] ) : '',
			// Facebook Messenger-specific fields.
			'page_id'         => isset( $connection_data['page_id'] ) ? sanitize_text_field( $connection_data['page_id'] ) : '',
			// WebChat P2P-specific fields.
			'p2p_connection_id' => isset( $connection_data['p2p_connection_id'] ) ? sanitize_text_field( $connection_data['p2p_connection_id'] ) : '',
			// Generic API test endpoint.
			'test_endpoint'   => isset( $connection_data['test_endpoint'] ) ? sanitize_text_field( $connection_data['test_endpoint'] ) : '',
			// Cache TTL.
			'cache_ttl'       => isset( $connection_data['cache_ttl'] ) ? max( 0, min( 3600, absint( $connection_data['cache_ttl'] ) ) ) : 300,
		);

		// Encrypt sensitive data (only if not already encrypted).
		if ( ! empty( $connection['password'] ) && empty( $connection_data['_password_encrypted'] ) ) {
			$connection['password'] = self::encrypt_value( $connection['password'] );
		}

		if ( ! empty( $connection['token'] ) && empty( $connection_data['_token_encrypted'] ) ) {
			$connection['token'] = self::encrypt_value( $connection['token'] );
		}

		if ( ! empty( $connection['consumer_key'] ) && empty( $connection_data['_consumer_key_encrypted'] ) ) {
			$connection['consumer_key'] = self::encrypt_value( $connection['consumer_key'] );
		}

		if ( ! empty( $connection['consumer_secret'] ) && empty( $connection_data['_consumer_secret_encrypted'] ) ) {
			$connection['consumer_secret'] = self::encrypt_value( $connection['consumer_secret'] );
		}

		if ( ! empty( $connection['api_key'] ) && empty( $connection_data['_api_key_encrypted'] ) ) {
			$connection['api_key'] = self::encrypt_value( $connection['api_key'] );
		}

		if ( ! empty( $connection['api_secret'] ) && empty( $connection_data['_api_secret_encrypted'] ) ) {
			$connection['api_secret'] = self::encrypt_value( $connection['api_secret'] );
		}

		if ( ! empty( $connection['client_secret'] ) && empty( $connection_data['_client_secret_encrypted'] ) ) {
			$connection['client_secret'] = self::encrypt_value( $connection['client_secret'] );
		}

		if ( ! empty( $connection['app_secret'] ) && empty( $connection_data['_app_secret_encrypted'] ) ) {
			$connection['app_secret'] = self::encrypt_value( $connection['app_secret'] );
		}

		if ( ! empty( $connection['refresh_token'] ) && empty( $connection_data['_refresh_token_encrypted'] ) ) {
			$connection['refresh_token'] = self::encrypt_value( $connection['refresh_token'] );
		}

		$connections[ $connection_id ] = $connection;

		$updated = update_option( self::OPTION_NAME, $connections );

		if ( false === $updated && ! isset( $connections[ $connection_id ] ) ) {
			// update_option returns false if the value is the same, which shouldn't happen here
			// but also returns false on actual failure. Check if it was actually saved.
			$saved_connections = get_option( self::OPTION_NAME, array() );
			if ( ! isset( $saved_connections[ $connection_id ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_save_failed',
					__( 'Failed to save connection. Please try again.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		/**
		 * Fires after a remote site connection is saved.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID.
		 * @param array  $connection    Connection data.
		 */
		do_action( 'wp_mcp_ai_pro_remote_site_saved', $connection_id, $connection );

		return $connection_id;
	}

	/**
	 * Delete a remote site connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID to delete.
	 * @return bool True on success, false on failure.
	 */
	public static function delete_connection( $connection_id ) {
		$connections   = self::get_all_connections();
		$connection_id = sanitize_key( $connection_id );

		if ( ! isset( $connections[ $connection_id ] ) ) {
			return false;
		}

		/**
		 * Fires before a remote site connection is deleted.
		 *
		 * @since 1.0.0
		 *
		 * @param string $connection_id Connection ID.
		 */
		do_action( 'wp_mcp_ai_pro_remote_site_deleted', $connection_id );

		unset( $connections[ $connection_id ] );

		return update_option( self::OPTION_NAME, $connections );
	}

	/**
	 * Test a remote site connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array|string $connection Connection data array or connection ID.
	 * @return array|WP_Error Test results on success, WP_Error on failure.
	 */
	public static function test_connection( $connection ) {
		if ( is_string( $connection ) ) {
			$connection = self::get_connection( $connection );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_invalid_connection',
					__( 'Connection not found.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		$validation = self::validate_connection_data( $connection );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : 'WordPress';

		// Handle Mesh Peer connections separately.
		if ( 'mesh_peer' === $connection_type ) {
			return self::test_mesh_peer_connection( $connection );
		}

		// Handle WhatsApp connections separately.
		if ( 'whatsapp' === $connection_type ) {
			return self::test_whatsapp_connection( $connection );
		}

		// Handle Flowhub connections separately.
		if ( 'flowhub' === $connection_type ) {
			return self::test_flowhub_connection( $connection );
		}

		// Handle EZuite ERP connections separately.
		if ( 'ezuite_erp' === $connection_type ) {
			return self::test_ezuite_connection( $connection );
		}

		// Test basic WordPress REST API access.
		$response = self::make_request( $connection, 'wp/v2/types' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = array(
			'success'     => true,
			'wordpress'   => true,
			'woocommerce' => false,
			'site_name'   => '',
			'site_url'    => $connection['url'],
			'message'     => __( 'Connection successful.', 'mcp-ai-wpoos-pro' ),
		);

		// Test WooCommerce API access if enabled.
		if ( ! empty( $connection['has_woocommerce'] ) ) {
			$wc_response = self::make_request( $connection, 'wc/v3/system_status' );

			if ( ! is_wp_error( $wc_response ) ) {
				$results['woocommerce'] = true;
			}
		}

		// Get site info.
		$site_info = self::make_request( $connection, 'wp/v2' );

		if ( ! is_wp_error( $site_info ) && isset( $site_info['name'] ) ) {
			$results['site_name'] = $site_info['name'];
		}

		return $results;
	}

	/**
	 * Test Flowhub API connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_flowhub_connection( $connection ) {
		if ( ! class_exists( 'WP_MCP_AI_Flowhub_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		$connection_id = isset( $connection['id'] ) ? $connection['id'] : null;
		$client        = new WP_MCP_AI_Flowhub_Client( $connection_id );

		// Test with a simple inventory request.
		$response = $client->get_inventory( array( 'limit' => 1 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = array(
			'success' => true,
			'flowhub' => true,
			'message' => __( 'Flowhub connection successful. API credentials verified.', 'mcp-ai-wpoos-pro' ),
		);

		// Add inventory count if available.
		if ( isset( $response['total'] ) ) {
			$results['inventory_count'] = absint( $response['total'] );
			/* translators: %d: number of inventory items */
			$results['message'] = sprintf( __( 'Flowhub connection successful. Found %d inventory items.', 'mcp-ai-wpoos-pro' ), $results['inventory_count'] );
		}

		return $results;
	}

	/**
	 * Test EZuite ERP API connection.
	 *
	 * Makes a simple API call to verify the connection and API key.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_ezuite_connection( $connection ) {
		// Validate required fields.
		if ( empty( $connection['url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_url',
				__( 'EZuite API URL is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $connection['api_key'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_api_key',
				__( 'EZuite API key is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Decrypt the API key.
		$api_key = self::decrypt_value( $connection['api_key'] );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_api_key',
				__( 'Invalid or corrupted API key.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Prepare a simple test request - use LX_ItemPull with a limit to minimize data.
		$url = untrailingslashit( $connection['url'] );

		$request_body = array(
			'API_Key'    => $api_key,
			'API_Action' => 'LX_ItemPull',
			'API_Body'   => array(
				array(
					'Location_Code' => 'ALL',
					'Limit'         => 1, // Only fetch 1 item to test connection.
				),
			),
		);

		$args = array(
			'method'  => 'POST',
			'timeout' => 30,
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $request_body ),
		);

		// Make the request.
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_connection_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to EZuite API: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_pro_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'EZuite API returned error status %d. Please check your API URL and credentials.', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
		}

		// Parse the JSON response.
		$data = json_decode( $body, true );

		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_response',
				__( 'EZuite API returned invalid JSON response.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check the response status.
		$ezuite_status = isset( $data['Status_Code'] ) ? absint( $data['Status_Code'] ) : 0;

		if ( 200 !== $ezuite_status ) {
			$error_message = isset( $data['Message'] ) ? sanitize_text_field( $data['Message'] ) : __( 'Unknown error', 'mcp-ai-wpoos-pro' );
			return new WP_Error(
				'wp_mcp_ai_pro_ezuite_error',
				sprintf(
					/* translators: 1: status code, 2: error message */
					__( 'EZuite API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
					$ezuite_status,
					$error_message
				)
			);
		}

		// Connection successful!
		$results = array(
			'success'    => true,
			'ezuite_erp' => true,
			'api_url'    => $connection['url'],
			'message'    => __( 'EZuite ERP connection successful. API credentials verified.', 'mcp-ai-wpoos-pro' ),
		);

		// Add item count if available in response.
		if ( isset( $data['Response_Body'] ) && is_array( $data['Response_Body'] ) ) {
			$item_count = count( $data['Response_Body'] );
			if ( $item_count > 0 ) {
				/* translators: %d: number of items retrieved */
				$results['message'] = sprintf( __( 'EZuite ERP connection successful. Retrieved %d test item(s).', 'mcp-ai-wpoos-pro' ), $item_count );
			}
		}

		return $results;
	}

	/**
	 * Test WhatsApp Business API connection.
	 *
	 * Tests WhatsApp connection by verifying phone number and attempting to retrieve business profile.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_whatsapp_connection( $connection ) {
		// Validate required fields.
		$access_token    = isset( $connection['api_key'] ) ? self::decrypt_value( $connection['api_key'] ) : '';
		$phone_number_id = isset( $connection['phone_number_id'] ) ? $connection['phone_number_id'] : '';
		$app_secret      = isset( $connection['api_secret'] ) ? self::decrypt_value( $connection['api_secret'] ) : '';

		if ( empty( $access_token ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_whatsapp_missing_token',
				__( 'WhatsApp access token is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $phone_number_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_whatsapp_missing_phone_id',
				__( 'WhatsApp phone number ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $app_secret ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_whatsapp_missing_secret',
				__( 'WhatsApp app secret is required for webhook signature validation.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Test 1: Get phone number info.
		// Explicitly request only fields accessible with whatsapp_business_messaging permission to avoid 403 errors.
		$phone_endpoint = sprintf( 'https://graph.facebook.com/v19.0/%s?fields=display_phone_number,verified_name,quality_rating', rawurlencode( $phone_number_id ) );

		$phone_response = wp_remote_get(
			$phone_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $phone_response ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_whatsapp_http_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to connect to WhatsApp API: %s', 'mcp-ai-wpoos-pro' ),
					$phone_response->get_error_message()
				)
			);
		}

		$phone_code = wp_remote_retrieve_response_code( $phone_response );
		$phone_body = wp_remote_retrieve_body( $phone_response );
		$phone_data = json_decode( $phone_body, true );

		if ( 200 !== $phone_code ) {
			$error_message = __( 'Invalid response from WhatsApp API.', 'mcp-ai-wpoos-pro' );

			if ( isset( $phone_data['error']['message'] ) ) {
				$error_message = $phone_data['error']['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_pro_whatsapp_api_error',
				sprintf(
					/* translators: 1: status code, 2: error message */
					__( 'WhatsApp API error (Status: %1$d): %2$s', 'mcp-ai-wpoos-pro' ),
					$phone_code,
					$error_message
				)
			);
		}

		// Extract phone number details.
		$display_phone = isset( $phone_data['display_phone_number'] ) ? $phone_data['display_phone_number'] : '';
		$verified      = isset( $phone_data['verified_name'] ) ? $phone_data['verified_name'] : '';
		$quality       = isset( $phone_data['quality_rating'] ) ? $phone_data['quality_rating'] : 'unknown';

		// Test 2: Try to get business profile (optional, may not have permissions).
		$profile_endpoint = sprintf( 'https://graph.facebook.com/v19.0/%s/whatsapp_business_profile', rawurlencode( $phone_number_id ) );

		$profile_response = wp_remote_get(
			$profile_endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);

		$business_name = '';
		if ( ! is_wp_error( $profile_response ) && 200 === wp_remote_retrieve_response_code( $profile_response ) ) {
			$profile_body = wp_remote_retrieve_body( $profile_response );
			$profile_data = json_decode( $profile_body, true );

			if ( isset( $profile_data['data'][0]['about'] ) ) {
				$business_name = $profile_data['data'][0]['about'];
			}
		}

		// Build success response.
		$results = array(
			'success'         => true,
			'whatsapp'        => true,
			'phone_number'    => $display_phone,
			'verified_name'   => $verified,
			'quality_rating'  => $quality,
			'business_name'   => $business_name,
			'webhook_url'     => home_url( '/wp-json/mcp-ai/v1/webhooks/whatsapp' ),
			'has_app_secret'  => ! empty( $app_secret ),
			'message'         => __( 'WhatsApp connection successful! Phone number verified and API credentials valid.', 'mcp-ai-wpoos-pro' ),
		);

		// Add quality rating warning if not green.
		if ( 'GREEN' !== strtoupper( $quality ) && 'unknown' !== $quality ) {
			$results['warning'] = sprintf(
				/* translators: %s: quality rating */
				__( 'Note: Phone number quality rating is %s. Monitor your messaging quality to maintain good standing.', 'mcp-ai-wpoos-pro' ),
				strtoupper( $quality )
			);
		}

		return $results;
	}

	/**
	 * Test mesh peer connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Connection test results or error.
	 */
	protected static function test_mesh_peer_connection( $connection ) {
		// Use the base plugin's mesh peer tester if available.
		if ( class_exists( 'WP_MCP_AI_Mesh_Peer_Tester' ) ) {
			$peer = array(
				'name'    => isset( $connection['name'] ) ? $connection['name'] : '',
				'url'     => isset( $connection['url'] ) ? $connection['url'] : '',
				'api_key' => isset( $connection['api_key'] ) ? self::decrypt_value( $connection['api_key'] ) : '',
			);

			return WP_MCP_AI_Mesh_Peer_Tester::test_connection( $peer );
		}

		// Fallback if tester not available (shouldn't happen).
		return new WP_Error(
			'wp_mcp_ai_pro_tester_unavailable',
			__( 'Mesh peer tester not available. Please ensure the base plugin is up to date.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Make an authenticated HTTP request to a remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $connection Connection data.
	 * @param string $endpoint   API endpoint (relative to REST base).
	 * @param string $method     HTTP method (GET, POST, etc.).
	 * @param array  $body       Request body for POST/PUT requests.
	 * @return array|WP_Error Response data or error.
	 */
	public static function make_request( $connection, $endpoint, $method = 'GET', $body = array() ) {
		$connection_id = isset( $connection['id'] ) ? $connection['id'] : '';
		$start_time    = microtime( true );

		$url = self::build_api_url( $connection['url'], $endpoint, $connection );

		if ( is_wp_error( $url ) ) {
			self::record_health_metric( $connection_id, false, 0 );
			return $url;
		}

		// For WooCommerce authentication, add consumer key/secret to URL.
		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';
		if ( 'woocommerce' === $auth_type ) {
			$consumer_key    = isset( $connection['consumer_key'] ) ? self::decrypt_value( $connection['consumer_key'] ) : '';
			$consumer_secret = isset( $connection['consumer_secret'] ) ? self::decrypt_value( $connection['consumer_secret'] ) : '';

			if ( ! empty( $consumer_key ) && ! empty( $consumer_secret ) ) {
				$url = add_query_arg(
					array(
						'consumer_key'    => $consumer_key,
						'consumer_secret' => $consumer_secret,
					),
					$url
				);
			}
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => self::get_auth_headers( $connection ),
		);

		// Add compression support for large responses.
		$args['headers']['Accept-Encoding'] = 'gzip, deflate';

		if ( ! empty( $body ) && in_array( $args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body']                    = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		// Check cache for GET requests only (read-only operations).
		if ( 'GET' === $args['method'] && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			$cache_key     = self::get_request_cache_key( $connection_id, $endpoint );
			$cached_result = WP_MCP_AI_Cache_Helper::get( $cache_key );

			if ( false !== $cached_result && is_array( $cached_result ) ) {
				return $cached_result;
			}
		}

		// Request deduplication - check if this exact request is already in progress.
		$dedup_key   = self::get_dedup_key( $connection_id, $endpoint, $method, $body );
		$in_progress = get_transient( $dedup_key );

		if ( false !== $in_progress ) {
			// Another request is in progress - wait briefly and check cache.
			usleep( 100000 ); // Wait 0.1 seconds.
			if ( 'GET' === $args['method'] && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
				$cache_key     = self::get_request_cache_key( $connection_id, $endpoint );
				$cached_result = WP_MCP_AI_Cache_Helper::get( $cache_key );
				if ( false !== $cached_result && is_array( $cached_result ) ) {
					return $cached_result;
				}
			}
			// If no cached result yet, proceed with request (acceptable race condition).
		}

		// Mark this request as in progress.
		set_transient( $dedup_key, true, 30 );

		// Perform request with retry logic.
		$response = self::make_request_with_retry( $url, $args );

		// Clear deduplication lock.
		delete_transient( $dedup_key );

		if ( is_wp_error( $response ) ) {
			$duration = microtime( true ) - $start_time;
			self::record_health_metric( $connection_id, false, $duration );

			return new WP_Error(
				'wp_mcp_ai_pro_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Request failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code >= 400 ) {
			$duration = microtime( true ) - $start_time;
			self::record_health_metric( $connection_id, false, $duration );

			$error_message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP error %d', 'mcp-ai-wpoos-pro' ),
				$status_code
			);

			$decoded = json_decode( $body, true );

			if ( isset( $decoded['message'] ) ) {
				$error_message .= ': ' . $decoded['message'];
			}

			return new WP_Error( 'wp_mcp_ai_pro_http_error', $error_message );
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$duration = microtime( true ) - $start_time;
			self::record_health_metric( $connection_id, false, $duration );

			return new WP_Error(
				'wp_mcp_ai_pro_json_error',
				__( 'Invalid JSON response from remote site.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Record successful request for health monitoring.
		$duration = microtime( true ) - $start_time;
		self::record_health_metric( $connection_id, true, $duration );

		// Cache successful GET requests.
		if ( 'GET' === $args['method'] && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			// Use per-connection cache TTL if set, otherwise default to 5 minutes.
			$cache_ttl = isset( $connection['cache_ttl'] ) ? absint( $connection['cache_ttl'] ) : 5 * MINUTE_IN_SECONDS;

			// Validate cache_ttl is within acceptable range (0-3600 seconds).
			if ( $cache_ttl > 3600 ) {
				$cache_ttl = 3600; // Cap at 1 hour.
			}

			// Skip caching if TTL is 0 (disabled for this connection).
			if ( $cache_ttl > 0 ) {
				/**
				 * Filter the cache TTL for remote site requests.
				 *
				 * @param int    $cache_ttl     Cache time-to-live in seconds (default: connection setting or 300).
				 * @param string $connection_id Connection ID.
				 * @param string $endpoint      API endpoint.
				 * @param array  $connection    Full connection data.
				 */
				$cache_ttl = apply_filters( 'wp_mcp_ai_pro_remote_request_cache_ttl', $cache_ttl, $connection_id, $endpoint, $connection );

				$cache_key = self::get_request_cache_key( $connection_id, $endpoint );
				WP_MCP_AI_Cache_Helper::set( $cache_key, $decoded, $cache_ttl );
			}
		}

		return $decoded;
	}

	/**
	 * Build full API URL from base URL and endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base_url  Base site URL.
	 * @param string $endpoint  API endpoint.
	 * @param array  $connection Optional connection data for context.
	 * @return string|WP_Error Full URL or error.
	 */
	protected static function build_api_url( $base_url, $endpoint, $connection = array() ) {
		$base_url = untrailingslashit( $base_url );
		$endpoint = ltrim( $endpoint, '/' );

		// For generic REST APIs, just append the endpoint directly.
		if ( ! empty( $connection['connection_type'] ) && 'generic' === $connection['connection_type'] ) {
			$api_url = $base_url . '/' . $endpoint;
			return $api_url;
		}

		// For WordPress/WooCommerce endpoints, use /wp-json/ prefix.
		// Determine if this is a WooCommerce endpoint.
		if ( 0 === strpos( $endpoint, 'wc/' ) ) {
			$api_url = $base_url . '/wp-json/' . $endpoint;
		} else {
			$api_url = $base_url . '/wp-json/' . $endpoint;
		}

		return $api_url;
	}

	/**
	 * Get authentication headers for a connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array Headers array.
	 */
	protected static function get_auth_headers( $connection ) {
		$headers = array(
			'User-Agent' => 'WP-MCP-AI-Pro/' . WP_MCP_AI_PRO_VERSION,
		);

		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';

		switch ( $auth_type ) {
			case 'application_password':
			case 'basic_auth':
				$username = isset( $connection['username'] ) ? $connection['username'] : '';
				$password = isset( $connection['password'] ) ? self::decrypt_value( $connection['password'] ) : '';

				if ( ! empty( $username ) && ! empty( $password ) ) {
					$headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $password );
				}
				break;

			case 'jwt':
				$token = isset( $connection['token'] ) ? self::decrypt_value( $connection['token'] ) : '';

				if ( ! empty( $token ) ) {
					$headers['Authorization'] = 'Bearer ' . $token;
				}
				break;
		}

		return $headers;
	}

	/**
	 * Validate connection data.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return true|WP_Error True if valid, WP_Error if invalid.
	 */
	protected static function validate_connection_data( $connection ) {
		if ( empty( $connection['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_name',
				__( 'Connection name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $connection['url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_url',
				__( 'Connection URL is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! filter_var( $connection['url'], FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_url',
				__( 'Connection URL is not valid.', 'mcp-ai-wpoos-pro' )
			);
		}

		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';

		if ( ! in_array( $auth_type, self::AUTH_TYPES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_auth',
				__( 'Invalid authentication type.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( in_array( $auth_type, array( 'application_password', 'basic_auth' ), true ) ) {
			if ( empty( $connection['username'] ) || empty( $connection['password'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_credentials',
					__( 'Username and password are required for this authentication type.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'jwt' === $auth_type && empty( $connection['token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_token',
				__( 'JWT token is required for JWT authentication.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( 'woocommerce' === $auth_type ) {
			if ( empty( $connection['consumer_key'] ) || empty( $connection['consumer_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_wc_keys',
					__( 'Consumer key and consumer secret are required for WooCommerce authentication.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Validate connection type specific requirements.
		$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : 'WordPress';

		if ( 'ezuite_erp' === $connection_type ) {
			if ( empty( $connection['api_key'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_ezuite_credentials',
					__( 'API key is required for EZuite ERP connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'isams' === $connection_type ) {
			if ( empty( $connection['api_key'] ) || empty( $connection['api_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_isams_credentials',
					__( 'API key and API secret are required for iSAMS connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'flowhub' === $connection_type ) {
			if ( empty( $connection['api_key'] ) || empty( $connection['client_id'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_flowhub_credentials',
					__( 'API key (key header) and client ID (clientId header) are required for Flowhub connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'payhere' === $connection_type ) {
			if ( empty( $connection['app_id'] ) || empty( $connection['app_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_payhere_credentials',
					__( 'App ID and app secret are required for PayHere connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'quickbooks' === $connection_type ) {
			if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_quickbooks_credentials',
					__( 'Client ID and client secret are required for QuickBooks connections.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		if ( 'gmail' === $connection_type ) {
			if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_gmail_credentials',
					__( 'OAuth Client ID and client secret are required for Gmail connections.', 'mcp-ai-wpoos-pro' )
				);
			}
			// Note: refresh_token is optional during initial setup as it's obtained through OAuth flow
		}

		if ( 'google_drive' === $connection_type ) {
			if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_google_drive_credentials',
					__( 'OAuth Client ID and client secret are required for Google Drive connections.', 'mcp-ai-wpoos-pro' )
				);
			}
			// Note: refresh_token is optional during initial setup as it's obtained through OAuth flow
			// Note: folder_id is optional - if not provided, full drive access within granted scopes
		}

		return true;
	}

	/**
	 * Generate a unique connection ID.
	 *
	 * @since 1.0.0
	 *
	 * @return string Connection ID.
	 */
	protected static function generate_connection_id() {
		return 'conn_' . strtolower( wp_generate_password( 12, false ) );
	}

	/**
	 * Migrate connection IDs to lowercase format.
	 *
	 * This method normalizes existing connection IDs that may have mixed case
	 * to lowercase format for consistency with sanitize_key().
	 *
	 * @since 1.0.0
	 *
	 * @param array $connections Array of connections.
	 * @return array Migrated connections array.
	 */
	protected static function migrate_connection_ids( $connections ) {
		$needs_migration = false;
		$migrated        = array();

		foreach ( $connections as $key => $connection ) {
			$lowercase_key = strtolower( $key );

			// Check if key needs migration.
			if ( $key !== $lowercase_key ) {
				$needs_migration = true;
				// Update the id field to match the new lowercase key.
				$connection['id']           = $lowercase_key;
				$migrated[ $lowercase_key ] = $connection;
			} else {
				$migrated[ $key ] = $connection;
			}
		}

		// Save migrated data if changes were made.
		if ( $needs_migration ) {
			update_option( self::OPTION_NAME, $migrated );
		}

		return $migrated;
	}

	/**
	 * Encrypt a sensitive value for storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Value to encrypt.
	 * @return string Encrypted value.
	 */
	public static function encrypt_value( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Use WordPress auth salt for encryption key.
		$key = wp_salt( 'auth' );

		// Simple XOR encryption (WordPress doesn't have built-in encryption).
		$encrypted    = '';
		$key_length   = strlen( $key );
		$value_length = strlen( $value );

		for ( $i = 0; $i < $value_length; $i++ ) {
			$encrypted .= chr( ord( $value[ $i ] ) ^ ord( $key[ $i % $key_length ] ) );
		}

		return base64_encode( $encrypted );
	}

	/**
	 * Decrypt a sensitive value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $encrypted Encrypted value.
	 * @return string Decrypted value.
	 */
	public static function decrypt_value( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}

		$key       = wp_salt( 'auth' );
		$encrypted = base64_decode( $encrypted );

		$decrypted        = '';
		$key_length       = strlen( $key );
		$encrypted_length = strlen( $encrypted );

		for ( $i = 0; $i < $encrypted_length; $i++ ) {
			$decrypted .= chr( ord( $encrypted[ $i ] ) ^ ord( $key[ $i % $key_length ] ) );
		}

		return $decrypted;
	}

	/**
	 * Generate cache key for remote site requests.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $endpoint      API endpoint with query parameters.
	 * @return string Cache key.
	 */
	protected static function get_request_cache_key( $connection_id, $endpoint ) {
		return 'remote_request_' . wp_hash( $connection_id . '_' . $endpoint );
	}

	/**
	 * Invalidate cache for a specific connection.
	 *
	 * Useful when connection settings change or when fresh data is needed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @return int Number of cache entries cleared.
	 */
	public static function invalidate_connection_cache( $connection_id ) {
		// Use wp_hash for consistent hashing with cache key generation.
		$hash_prefix = wp_hash( $connection_id . '_' );
		return WP_MCP_AI_Cache_Helper::delete_pattern( 'remote_request_' . substr( $hash_prefix, 0, 8 ) . '%' );
	}

	/**
	 * Make HTTP request with retry logic and exponential backoff.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments.
	 * @return array|WP_Error HTTP response or error.
	 */
	protected static function make_request_with_retry( $url, $args ) {
		$max_retries = 3;
		$retry_delay = 1; // Start with 1 second.

		/**
		 * Filter the maximum number of retry attempts for remote requests.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_retries Maximum retry attempts (default: 3).
		 */
		$max_retries = apply_filters( 'wp_mcp_ai_pro_remote_request_max_retries', $max_retries );

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			// Success - return response.
			if ( ! is_wp_error( $response ) ) {
				$status_code = wp_remote_retrieve_response_code( $response );
				// Retry on 5xx errors (server errors) but not 4xx (client errors).
				if ( $status_code < 500 ) {
					return $response;
				}
			}

			// If this was the last attempt, return the error.
			if ( $attempt >= $max_retries ) {
				return $response;
			}

			// Wait before retrying (exponential backoff with shorter delays).
			// Use microseconds for non-blocking behavior in web context.
			usleep( $retry_delay * 100000 ); // 0.1s, 0.2s, 0.4s
			$retry_delay *= 2; // Double the delay for next retry.
		}

		return $response;
	}

	/**
	 * Generate deduplication key for requests.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $endpoint      API endpoint.
	 * @param string $method        HTTP method.
	 * @param array  $body          Request body.
	 * @return string Deduplication key.
	 */
	protected static function get_dedup_key( $connection_id, $endpoint, $method, $body ) {
		$key_parts = array( $connection_id, $endpoint, $method );
		if ( ! empty( $body ) ) {
			$key_parts[] = wp_json_encode( $body );
		}
		return 'remote_dedup_' . wp_hash( implode( '|', $key_parts ) );
	}

	/**
	 * Record health metric for connection monitoring.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param bool   $success       Whether request was successful.
	 * @param float  $duration      Request duration in seconds.
	 * @return void
	 */
	protected static function record_health_metric( $connection_id, $success, $duration ) {
		if ( empty( $connection_id ) ) {
			return;
		}

		$health_key  = 'remote_health_' . sanitize_key( $connection_id );
		$health_data = get_transient( $health_key );

		if ( false === $health_data ) {
			$health_data = array(
				'success_count'  => 0,
				'failure_count'  => 0,
				'total_duration' => 0,
				'request_count'  => 0,
				'last_success'   => 0,
				'last_failure'   => 0,
			);
		}

		++$health_data['request_count'];
		$health_data['total_duration'] += $duration;

		if ( $success ) {
			++$health_data['success_count'];
			$health_data['last_success'] = time();
		} else {
			++$health_data['failure_count'];
			$health_data['last_failure'] = time();
		}

		// Store for 1 hour.
		set_transient( $health_key, $health_data, HOUR_IN_SECONDS );
	}

	/**
	 * Get health metrics for a connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @return array Health metrics.
	 */
	public static function get_health_metrics( $connection_id ) {
		$health_key  = 'remote_health_' . sanitize_key( $connection_id );
		$health_data = get_transient( $health_key );

		if ( false === $health_data ) {
			return array(
				'success_count' => 0,
				'failure_count' => 0,
				'success_rate'  => 100,
				'avg_duration'  => 0,
				'request_count' => 0,
				'last_success'  => null,
				'last_failure'  => null,
				'status'        => 'unknown',
			);
		}

		$total_requests = $health_data['request_count'];
		$success_rate   = $total_requests > 0 ? ( $health_data['success_count'] / $total_requests ) * 100 : 100;
		$avg_duration   = $total_requests > 0 ? $health_data['total_duration'] / $total_requests : 0;

		// Determine status.
		$status = 'healthy';
		if ( $success_rate < 50 ) {
			$status = 'unhealthy';
		} elseif ( $success_rate < 80 ) {
			$status = 'degraded';
		}

		return array(
			'success_count' => $health_data['success_count'],
			'failure_count' => $health_data['failure_count'],
			'success_rate'  => round( $success_rate, 2 ),
			'avg_duration'  => round( $avg_duration, 3 ),
			'request_count' => $total_requests,
			'last_success'  => $health_data['last_success'] > 0 ? $health_data['last_success'] : null,
			'last_failure'  => $health_data['last_failure'] > 0 ? $health_data['last_failure'] : null,
			'status'        => $status,
		);
	}
}
