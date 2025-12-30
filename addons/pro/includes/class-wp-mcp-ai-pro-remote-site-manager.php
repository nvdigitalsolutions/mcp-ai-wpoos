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
	const AUTH_TYPES = array( 'application_password', 'basic_auth', 'jwt', 'none' );

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
		$validation = self::validate_connection_data( $connection_data );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$connections = self::get_all_connections();

		// Generate or use existing connection ID.
		if ( empty( $connection_data['id'] ) ) {
			$connection_id = self::generate_connection_id();
		} else {
			$connection_id = sanitize_key( $connection_data['id'] );
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
			'has_woocommerce' => ! empty( $connection_data['has_woocommerce'] ),
			'enabled'         => ! empty( $connection_data['enabled'] ),
			'created'         => isset( $connection_data['created'] ) ? $connection_data['created'] : current_time( 'mysql' ),
			'updated'         => current_time( 'mysql' ),
		);

		// Encrypt sensitive data.
		if ( ! empty( $connection['password'] ) ) {
			$connection['password'] = self::encrypt_value( $connection['password'] );
		}

		if ( ! empty( $connection['token'] ) ) {
			$connection['token'] = self::encrypt_value( $connection['token'] );
		}

		$connections[ $connection_id ] = $connection;

		update_option( self::OPTION_NAME, $connections );

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

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => self::get_auth_headers( $connection ),
		);

		if ( ! empty( $body ) && in_array( $args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
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
		return 'conn_' . wp_generate_password( 12, false );
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
}
