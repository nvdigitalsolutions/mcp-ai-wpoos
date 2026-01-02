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
	const AUTH_TYPES = array( 'application_password', 'basic_auth', 'jwt', 'woocommerce', 'none' );

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
		$connections = self::get_all_connections();
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
			$is_update = isset( $connections[ $connection_id ] );
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
				$connection_data['consumer_key'] = $existing_connection['consumer_key'];
				$connection_data['_consumer_key_encrypted'] = true;
			}

			// Preserve existing consumer_secret if not provided.
			if ( empty( $connection_data['consumer_secret'] ) && ! empty( $existing_connection['consumer_secret'] ) ) {
				$connection_data['consumer_secret'] = $existing_connection['consumer_secret'];
				$connection_data['_consumer_secret_encrypted'] = true;
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
			'auth_type'       => sanitize_key( $connection_data['auth_type'] ),
			'username'        => isset( $connection_data['username'] ) ? sanitize_text_field( $connection_data['username'] ) : '',
			'password'        => isset( $connection_data['password'] ) ? $connection_data['password'] : '',
			'token'           => isset( $connection_data['token'] ) ? $connection_data['token'] : '',
			'consumer_key'    => isset( $connection_data['consumer_key'] ) ? $connection_data['consumer_key'] : '',
			'consumer_secret' => isset( $connection_data['consumer_secret'] ) ? $connection_data['consumer_secret'] : '',
			'has_woocommerce' => ! empty( $connection_data['has_woocommerce'] ),
			'enabled'         => ! empty( $connection_data['enabled'] ),
			'created'         => isset( $connection_data['created'] ) ? $connection_data['created'] : current_time( 'mysql' ),
			'updated'         => current_time( 'mysql' ),
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

		$connections[ $connection_id ] = $connection;

		$updated = update_option( self::OPTION_NAME, $connections );

		if ( false === $updated && ! isset( $connections[ $connection_id ] ) ) {
			// update_option returns false if the value is the same, which shouldn't happen here
			// but also returns false on actual failure. Check if it was actually saved.
			$saved_connections = get_option( self::OPTION_NAME, array() );
			if ( ! isset( $saved_connections[ $connection_id ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_save_failed',
					__( 'Failed to save connection. Please try again.', 'wp-mcp-ai-pro' )
				);
			}
		}

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
		$connections = self::get_all_connections();
		$connection_id = sanitize_key( $connection_id );

		if ( ! isset( $connections[ $connection_id ] ) ) {
			return false;
		}

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
					__( 'Connection not found.', 'wp-mcp-ai-pro' )
				);
			}
		}

		$validation = self::validate_connection_data( $connection );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Test basic WordPress REST API access.
		$response = self::make_request( $connection, 'wp/v2/types' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$results = array(
			'success'         => true,
			'wordpress'       => true,
			'woocommerce'     => false,
			'site_name'       => '',
			'site_url'        => $connection['url'],
			'message'         => __( 'Connection successful.', 'wp-mcp-ai-pro' ),
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
		$url = self::build_api_url( $connection['url'], $endpoint );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		// For WooCommerce authentication, add consumer key/secret to URL.
		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';
		if ( 'woocommerce' === $auth_type ) {
			$consumer_key = isset( $connection['consumer_key'] ) ? self::decrypt_value( $connection['consumer_key'] ) : '';
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

		if ( ! empty( $body ) && in_array( $args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		// Check cache for GET requests only (read-only operations).
		if ( 'GET' === $args['method'] && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			$cache_key     = self::get_request_cache_key( $connection['id'], $endpoint );
			$cached_result = WP_MCP_AI_Cache_Helper::get( $cache_key );

			if ( false !== $cached_result && is_array( $cached_result ) ) {
				return $cached_result;
			}
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Request failed: %s', 'wp-mcp-ai-pro' ),
					$response->get_error_message()
				)
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $status_code >= 400 ) {
			$error_message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP error %d', 'wp-mcp-ai-pro' ),
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
			return new WP_Error(
				'wp_mcp_ai_pro_json_error',
				__( 'Invalid JSON response from remote site.', 'wp-mcp-ai-pro' )
			);
		}

		// Cache successful GET requests.
		if ( 'GET' === $args['method'] && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			$cache_ttl = 5 * MINUTE_IN_SECONDS;

			/**
			 * Filter the cache TTL for remote site requests.
			 *
			 * @param int    $cache_ttl     Cache time-to-live in seconds (default: 300).
			 * @param string $connection_id Connection ID.
			 * @param string $endpoint      API endpoint.
			 */
			$cache_ttl = apply_filters( 'wp_mcp_ai_pro_remote_request_cache_ttl', $cache_ttl, $connection['id'], $endpoint );

			$cache_key = self::get_request_cache_key( $connection['id'], $endpoint );
			WP_MCP_AI_Cache_Helper::set( $cache_key, $decoded, $cache_ttl );
		}

		return $decoded;
	}

	/**
	 * Build full API URL from base URL and endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base_url Base site URL.
	 * @param string $endpoint API endpoint.
	 * @return string|WP_Error Full URL or error.
	 */
	protected static function build_api_url( $base_url, $endpoint ) {
		$base_url = untrailingslashit( $base_url );
		$endpoint = ltrim( $endpoint, '/' );

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
				__( 'Connection name is required.', 'wp-mcp-ai-pro' )
			);
		}

		if ( empty( $connection['url'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_url',
				__( 'Connection URL is required.', 'wp-mcp-ai-pro' )
			);
		}

		if ( ! filter_var( $connection['url'], FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_url',
				__( 'Connection URL is not valid.', 'wp-mcp-ai-pro' )
			);
		}

		$auth_type = isset( $connection['auth_type'] ) ? $connection['auth_type'] : 'none';

		if ( ! in_array( $auth_type, self::AUTH_TYPES, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_auth',
				__( 'Invalid authentication type.', 'wp-mcp-ai-pro' )
			);
		}

		if ( in_array( $auth_type, array( 'application_password', 'basic_auth' ), true ) ) {
			if ( empty( $connection['username'] ) || empty( $connection['password'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_credentials',
					__( 'Username and password are required for this authentication type.', 'wp-mcp-ai-pro' )
				);
			}
		}

		if ( 'jwt' === $auth_type && empty( $connection['token'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_token',
				__( 'JWT token is required for JWT authentication.', 'wp-mcp-ai-pro' )
			);
		}

		if ( 'woocommerce' === $auth_type ) {
			if ( empty( $connection['consumer_key'] ) || empty( $connection['consumer_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_wc_keys',
					__( 'Consumer key and consumer secret are required for WooCommerce authentication.', 'wp-mcp-ai-pro' )
				);
			}
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
		$migrated = array();

		foreach ( $connections as $key => $connection ) {
			$lowercase_key = strtolower( $key );
			
			// Check if key needs migration.
			if ( $key !== $lowercase_key ) {
				$needs_migration = true;
				// Update the id field to match the new lowercase key.
				$connection['id'] = $lowercase_key;
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
	 * Encrypt a sensitive value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Value to encrypt.
	 * @return string Encrypted value.
	 */
	protected static function encrypt_value( $value ) {
		if ( empty( $value ) ) {
			return '';
		}

		// Use WordPress auth salt for encryption key.
		$key = wp_salt( 'auth' );

		// Simple XOR encryption (WordPress doesn't have built-in encryption).
		$encrypted = '';
		$key_length = strlen( $key );
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
	protected static function decrypt_value( $encrypted ) {
		if ( empty( $encrypted ) ) {
			return '';
		}

		$key = wp_salt( 'auth' );
		$encrypted = base64_decode( $encrypted );

		$decrypted = '';
		$key_length = strlen( $key );
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
		return 'remote_request_' . md5( $connection_id . '_' . $endpoint );
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
		return WP_MCP_AI_Cache_Helper::delete_pattern( 'remote_request_' . md5( $connection_id . '_' ) . '%' );
	}
}
