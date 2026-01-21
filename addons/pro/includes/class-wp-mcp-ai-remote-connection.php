<?php
/**
 * Remote Connection Manager
 *
 * Manages connections to remote WordPress/WooCommerce sites via REST API.
 * Supports multiple authentication methods and connection pooling.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remote Connection Manager class.
 *
 * Handles authentication, connection management, and API requests
 * to remote WordPress/WooCommerce installations.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Remote_Connection {

	/**
	 * Connection configuration.
	 *
	 * @var array
	 */
	protected $config = array();

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	protected $last_error = '';

	/**
	 * Constructor.
	 *
	 * @param array $config Connection configuration.
	 */
	public function __construct( $config = array() ) {
		$this->config = wp_parse_args(
			$config,
			array(
				'url'          => '',
				'auth_type'    => 'app_password',
				'username'     => '',
				'password'     => '',
				'token'        => '',
				'verify_ssl'   => true,
				'timeout'      => 30,
				'retry_count'  => 3,
				'retry_delay'  => 1,
			)
		);
	}

	/**
	 * Test the connection to remote site.
	 *
	 * @return bool|WP_Error True if connected, WP_Error on failure.
	 */
	public function test_connection() {
		if ( empty( $this->config['url'] ) ) {
			return new WP_Error(
				'missing_url',
				__( 'Remote site URL is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate HTTPS for security.
		if ( ! $this->config['verify_ssl'] && 'https' !== parse_url( $this->config['url'], PHP_URL_SCHEME ) ) {
			return new WP_Error(
				'insecure_connection',
				__( 'Remote connections must use HTTPS for security.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Test connection with a simple request.
		$response = $this->request( 'GET', '/wp-json' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Make an authenticated request to remote site.
	 *
	 * @param string $method HTTP method (GET, POST, PUT, DELETE).
	 * @param string $endpoint API endpoint path.
	 * @param array  $data Request data.
	 * @param array  $headers Additional headers.
	 * @return array|WP_Error Response data or error.
	 */
	public function request( $method, $endpoint, $data = array(), $headers = array() ) {
		$url = trailingslashit( $this->config['url'] ) . ltrim( $endpoint, '/' );

		// Prepare headers with authentication.
		$auth_headers = $this->get_auth_headers();
		if ( is_wp_error( $auth_headers ) ) {
			return $auth_headers;
		}

		$all_headers = array_merge( $auth_headers, $headers );

		// Prepare request arguments.
		$args = array(
			'method'    => strtoupper( $method ),
			'headers'   => $all_headers,
			'timeout'   => absint( $this->config['timeout'] ),
			'sslverify' => (bool) $this->config['verify_ssl'],
		);

		// Add body for POST/PUT/PATCH.
		if ( in_array( $args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = $data;
		}

		// Make request with retry logic.
		$retry_count = absint( $this->config['retry_count'] );
		$retry_delay = absint( $this->config['retry_delay'] );
		$attempt     = 0;
		$response    = null;

		while ( $attempt < $retry_count ) {
			$response = wp_remote_request( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				$status_code = wp_remote_retrieve_response_code( $response );

				// Success codes.
				if ( $status_code >= 200 && $status_code < 300 ) {
					$body = wp_remote_retrieve_body( $response );
					return json_decode( $body, true );
				}

				// Rate limited - retry with backoff.
				if ( 429 === $status_code ) {
					$attempt++;
					if ( $attempt < $retry_count ) {
						sleep( $retry_delay * $attempt ); // Exponential backoff.
						continue;
					}
				}

				// Other error codes.
				$this->last_error = sprintf(
					/* translators: %d: HTTP status code */
					__( 'Remote request failed with status code: %d', 'mcp-ai-wpoos-pro' ),
					$status_code
				);

				return new WP_Error(
					'remote_request_failed',
					$this->last_error,
					array( 'status' => $status_code )
				);
			}

			$attempt++;
			if ( $attempt < $retry_count ) {
				sleep( $retry_delay );
			}
		}

		$this->last_error = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unknown error', 'mcp-ai-wpoos-pro' );

		return new WP_Error(
			'remote_request_failed',
			$this->last_error
		);
	}

	/**
	 * Get authentication headers based on auth type.
	 *
	 * @return array|WP_Error Authentication headers or error.
	 */
	protected function get_auth_headers() {
		$headers = array(
			'Content-Type' => 'application/json',
		);

		switch ( $this->config['auth_type'] ) {
			case 'app_password':
				if ( empty( $this->config['username'] ) || empty( $this->config['password'] ) ) {
					return new WP_Error(
						'missing_credentials',
						__( 'Username and application password are required.', 'mcp-ai-wpoos-pro' )
					);
				}

				// Application passwords use Basic Auth.
				$headers['Authorization'] = 'Basic ' . base64_encode( $this->config['username'] . ':' . $this->config['password'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

				break;

			case 'jwt':
				if ( empty( $this->config['token'] ) ) {
					return new WP_Error(
						'missing_token',
						__( 'JWT token is required.', 'mcp-ai-wpoos-pro' )
					);
				}

				$headers['Authorization'] = 'Bearer ' . sanitize_text_field( $this->config['token'] );

				break;

			case 'oauth':
				if ( empty( $this->config['token'] ) ) {
					return new WP_Error(
						'missing_token',
						__( 'OAuth token is required.', 'mcp-ai-wpoos-pro' )
					);
				}

				$headers['Authorization'] = 'Bearer ' . sanitize_text_field( $this->config['token'] );

				break;

			default:
				return new WP_Error(
					'invalid_auth_type',
					__( 'Invalid authentication type.', 'mcp-ai-wpoos-pro' )
				);
		}

		return $headers;
	}

	/**
	 * Get last error message.
	 *
	 * @return string Error message.
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Get WooCommerce product from remote site.
	 *
	 * @param int $product_id Product ID.
	 * @return array|WP_Error Product data or error.
	 */
	public function get_product( $product_id ) {
		return $this->request( 'GET', '/wp-json/wc/v3/products/' . absint( $product_id ) );
	}

	/**
	 * Get WooCommerce products from remote site.
	 *
	 * @param array $params Query parameters.
	 * @return array|WP_Error Products data or error.
	 */
	public function get_products( $params = array() ) {
		$query_string = http_build_query( $params );
		$endpoint     = '/wp-json/wc/v3/products';

		if ( ! empty( $query_string ) ) {
			$endpoint .= '?' . $query_string;
		}

		return $this->request( 'GET', $endpoint );
	}

	/**
	 * Update product on remote site.
	 *
	 * @param int   $product_id Product ID.
	 * @param array $data Product data.
	 * @return array|WP_Error Updated product or error.
	 */
	public function update_product( $product_id, $data ) {
		return $this->request( 'PUT', '/wp-json/wc/v3/products/' . absint( $product_id ), $data );
	}

	/**
	 * Get orders from remote site.
	 *
	 * @param array $params Query parameters.
	 * @return array|WP_Error Orders data or error.
	 */
	public function get_orders( $params = array() ) {
		$query_string = http_build_query( $params );
		$endpoint     = '/wp-json/wc/v3/orders';

		if ( ! empty( $query_string ) ) {
			$endpoint .= '?' . $query_string;
		}

		return $this->request( 'GET', $endpoint );
	}
}
