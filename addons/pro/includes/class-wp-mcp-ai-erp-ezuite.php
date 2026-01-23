<?php
/**
 * EZuite ERP Connector
 *
 * Adapter for EZuite ERP system integration.
 * Handles authentication, data mapping, and API communication.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EZuite ERP Connector.
 *
 * Adapter for EZuite ERP system integration.
 * Handles authentication, data mapping, and API communication.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_ERP_EZuite implements WP_MCP_AI_ERP_Connector_Interface {

	/**
	 * ERP connection configuration.
	 *
	 * @var array
	 */
	protected $config = array();

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	protected $api_url = '';

	/**
	 * Authentication token.
	 *
	 * @var string
	 */
	protected $auth_token = '';

	/**
	 * Last error message.
	 *
	 * @var string
	 */
	protected $last_error = '';

	/**
	 * Field mapping between WooCommerce and EZuite.
	 *
	 * @var array
	 */
	protected $field_mapping = array(
		'sku'           => 'product_code',
		'name'          => 'product_name',
		'quantity'      => 'available_quantity',
		'warehouse'     => 'location_code',
		'reorder_point' => 'min_quantity',
		'supplier'      => 'vendor_id',
		'cost_price'    => 'unit_cost',
		'last_updated'  => 'last_sync_date',
	);

	/**
	 * Connect to EZuite ERP.
	 *
	 * @param array $config Connection configuration.
	 * @return bool|WP_Error True if connected, WP_Error on failure.
	 */
	public function connect( $config ) {
		$this->config = wp_parse_args(
			$config,
			array(
				'api_url'    => '',
				'api_key'    => '',
				'api_secret' => '',
				'company_id' => '',
				'verify_ssl' => true,
				'timeout'    => 30,
			)
		);

		if ( empty( $this->config['api_url'] ) || empty( $this->config['api_key'] ) ) {
			return new WP_Error(
				'missing_config',
				__( 'EZuite ERP API URL and API key are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$this->api_url = trailingslashit( $this->config['api_url'] );

		// Authenticate and get token.
		$auth_result = $this->authenticate();

		if ( is_wp_error( $auth_result ) ) {
			return $auth_result;
		}

		$this->auth_token = $auth_result;

		return true;
	}

	/**
	 * Authenticate with EZuite ERP API.
	 *
	 * @return string|WP_Error Authentication token or error.
	 */
	protected function authenticate() {
		// NOTE: This is a placeholder implementation.
		// Actual authentication mechanism depends on EZuite's API documentation.
		// Contact hello@ezuite.com for official API docs.

		$response = wp_remote_post(
			$this->api_url . 'auth/login',
			array(
				'headers'   => array(
					'Content-Type' => 'application/json',
				),
				'body'      => wp_json_encode(
					array(
						'api_key'    => $this->config['api_key'],
						'api_secret' => $this->config['api_secret'],
						'company_id' => $this->config['company_id'],
					)
				),
				'timeout'   => absint( $this->config['timeout'] ),
				'sslverify' => (bool) $this->config['verify_ssl'],
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'auth_failed',
				__( 'EZuite ERP authentication failed.', 'mcp-ai-wpoos-pro' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['token'] ) ) {
			return new WP_Error(
				'invalid_response',
				__( 'Invalid authentication response from EZuite ERP.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $body['token'];
	}

	/**
	 * Test connection to EZuite ERP.
	 *
	 * @return bool|WP_Error True if connected, WP_Error on failure.
	 */
	public function test_connection() {
		if ( empty( $this->auth_token ) ) {
			return new WP_Error(
				'not_connected',
				__( 'Not connected to EZuite ERP. Call connect() first.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Make a simple API call to verify connection.
		$response = $this->api_request( 'GET', 'ping' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Make an API request to EZuite ERP.
	 *
	 * @param string $method HTTP method.
	 * @param string $endpoint API endpoint.
	 * @param array  $data Request data.
	 * @return array|WP_Error Response data or error.
	 */
	protected function api_request( $method, $endpoint, $data = array() ) {
		$url = $this->api_url . ltrim( $endpoint, '/' );

		$args = array(
			'method'    => strtoupper( $method ),
			'headers'   => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->auth_token,
			),
			'timeout'   => absint( $this->config['timeout'] ),
			'sslverify' => (bool) $this->config['verify_ssl'],
		);

		if ( in_array( $args['method'], array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->last_error = sprintf(
				/* translators: %d: HTTP status code */
				__( 'EZuite ERP API request failed with status code: %d', 'mcp-ai-wpoos-pro' ),
				$status_code
			);

			return new WP_Error( 'api_request_failed', $this->last_error );
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body, true );
	}

	/**
	 * Get inventory data for a product.
	 *
	 * @param string $sku Product SKU.
	 * @param array  $params Additional parameters.
	 * @return array|WP_Error Inventory data or error.
	 */
	public function get_inventory( $sku, $params = array() ) {
		$product_code = sanitize_text_field( $sku );
		$warehouse    = isset( $params['warehouse'] ) ? sanitize_text_field( $params['warehouse'] ) : '';

		$endpoint = 'inventory/' . $product_code;

		if ( ! empty( $warehouse ) ) {
			$endpoint .= '?warehouse=' . $warehouse;
		}

		$response = $this->api_request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Map EZuite fields to WooCommerce fields.
		return $this->map_fields_from_erp( $response );
	}

	/**
	 * Update inventory in EZuite ERP.
	 *
	 * @param string $sku Product SKU.
	 * @param int    $quantity New quantity.
	 * @param array  $params Additional parameters.
	 * @return bool|WP_Error True if updated, WP_Error on failure.
	 */
	public function update_inventory( $sku, $quantity, $params = array() ) {
		$data = array(
			'product_code' => sanitize_text_field( $sku ),
			'quantity'     => absint( $quantity ),
			'warehouse'    => isset( $params['warehouse'] ) ? sanitize_text_field( $params['warehouse'] ) : '',
			'reason'       => isset( $params['reason'] ) ? sanitize_text_field( $params['reason'] ) : 'stock_adjustment',
			'reference'    => isset( $params['reference'] ) ? sanitize_text_field( $params['reference'] ) : '',
		);

		$response = $this->api_request( 'POST', 'inventory/update', $data );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}

	/**
	 * Sync products from EZuite ERP to WooCommerce.
	 *
	 * @param array $filter Filter criteria.
	 * @return array|WP_Error Sync results or error.
	 */
	public function sync_products( $filter = array() ) {
		$endpoint = 'products';

		if ( ! empty( $filter ) ) {
			$endpoint .= '?' . http_build_query( $filter );
		}

		$response = $this->api_request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$synced_count = 0;
		$errors       = array();

		foreach ( $response['products'] as $erp_product ) {
			$mapped_product = $this->map_fields_from_erp( $erp_product );
			$result         = $this->sync_product_to_woocommerce( $mapped_product );

			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			} else {
				++$synced_count;
			}
		}

		return array(
			'success' => true,
			'synced'  => $synced_count,
			'errors'  => $errors,
		);
	}

	/**
	 * Sync a single product to WooCommerce.
	 *
	 * @param array $product_data Product data.
	 * @return int|WP_Error Product ID or error.
	 */
	protected function sync_product_to_woocommerce( $product_data ) {
		// Find existing product by SKU.
		$product_id = wc_get_product_id_by_sku( $product_data['sku'] );

		if ( $product_id ) {
			$product = wc_get_product( $product_id );
		} else {
			$product = new WC_Product_Simple();
		}

		$product->set_name( $product_data['name'] );
		$product->set_sku( $product_data['sku'] );
		$product->set_stock_quantity( $product_data['quantity'] );
		$product->set_manage_stock( true );

		if ( isset( $product_data['cost_price'] ) ) {
			$product->update_meta_data( '_cost_price', $product_data['cost_price'] );
		}

		$product->save();

		return $product->get_id();
	}

	/**
	 * Get purchase orders from EZuite ERP.
	 *
	 * @param array $params Query parameters.
	 * @return array|WP_Error Purchase orders or error.
	 */
	public function get_purchase_orders( $params = array() ) {
		$endpoint = 'purchase-orders';

		if ( ! empty( $params ) ) {
			$endpoint .= '?' . http_build_query( $params );
		}

		$response = $this->api_request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['purchase_orders'];
	}

	/**
	 * Get product data from EZuite ERP.
	 *
	 * @param string $sku Product SKU.
	 * @return array|WP_Error Product data or error.
	 */
	public function get_product( $sku ) {
		$product_code = sanitize_text_field( $sku );
		$response     = $this->api_request( 'GET', 'products/' . $product_code );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->map_fields_from_erp( $response );
	}

	/**
	 * Get inventory movements/audit trail.
	 *
	 * @param string $sku Product SKU.
	 * @param array  $params Query parameters.
	 * @return array|WP_Error Movement history or error.
	 */
	public function get_inventory_movements( $sku, $params = array() ) {
		$product_code = sanitize_text_field( $sku );
		$endpoint     = 'inventory/movements/' . $product_code;

		if ( ! empty( $params ) ) {
			$endpoint .= '?' . http_build_query( $params );
		}

		$response = $this->api_request( 'GET', $endpoint );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response['movements'];
	}

	/**
	 * Map fields from EZuite ERP to WooCommerce format.
	 *
	 * @param array $erp_data ERP data.
	 * @return array Mapped data.
	 */
	protected function map_fields_from_erp( $erp_data ) {
		$mapped = array();

		foreach ( $this->field_mapping as $woo_field => $erp_field ) {
			if ( isset( $erp_data[ $erp_field ] ) ) {
				$mapped[ $woo_field ] = $erp_data[ $erp_field ];
			}
		}

		return $mapped;
	}

	/**
	 * Map fields from WooCommerce to EZuite ERP format.
	 *
	 * @param array $woo_data WooCommerce data.
	 * @return array Mapped data.
	 */
	protected function map_fields_to_erp( $woo_data ) {
		$mapped = array();

		foreach ( $this->field_mapping as $woo_field => $erp_field ) {
			if ( isset( $woo_data[ $woo_field ] ) ) {
				$mapped[ $erp_field ] = $woo_data[ $woo_field ];
			}
		}

		return $mapped;
	}

	/**
	 * Get last error message.
	 *
	 * @return string Error message.
	 */
	public function get_last_error() {
		return $this->last_error;
	}
}
