<?php
/**
 * Printful Print-on-Demand API client wrapper.
 *
 * Printful API uses Bearer token authentication (OAuth or Private Token),
 * returns a { code, result } JSON envelope, and has a 120 req/min rate limit.
 * Base URL: https://api.printful.com
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Printful_Client' ) ) {

	/**
	 * Printful API client.
	 *
	 * Wraps the Printful REST API with Bearer token auth, automatic response
	 * unwrapping ({ code, result } → result), paging passthrough, health
	 * metric recording, and transient-based GET caching.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Printful_Client {

		/**
		 * Printful API base URL.
		 *
		 * @var string
		 */
		const API_BASE = 'https://api.printful.com';

		/**
		 * Default request timeout in seconds.
		 *
		 * @var int
		 */
		const DEFAULT_TIMEOUT = 30;

		/**
		 * Maximum response body size in bytes (5 MB).
		 *
		 * @var int
		 */
		const MAX_RESPONSE_SIZE = 5242880;

		/**
		 * Remote Sites connection ID.
		 *
		 * @var string|null
		 */
		protected $connection_id = null;

		/**
		 * Resolved connection data array (cached per instance).
		 *
		 * @var array|null
		 */
		protected $connection = null;

		/**
		 * Constructor.
		 *
		 * @param string|null $connection_id Optional Remote Sites connection ID.
		 */
		public function __construct( $connection_id = null ) {
			$this->connection_id = $connection_id;
		}

		// ------------------------------------------------------------------ //
		// Credential helpers                                                  //
		// ------------------------------------------------------------------ //

		/**
		 * Load and cache the connection data array.
		 *
		 * @return array|null Connection array or null when not found.
		 */
		protected function get_connection() {
			if ( null !== $this->connection ) {
				return $this->connection;
			}

			if ( ! empty( $this->connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$this->connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $this->connection_id );
			}

			return $this->connection;
		}

		/**
		 * Get the decrypted Printful API token.
		 *
		 * @return string Token or empty string.
		 */
		public function get_token() {
			$connection = $this->get_connection();

			if ( $connection && ! empty( $connection['api_key'] ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
			}

			return '';
		}

		/**
		 * Get the configured store ID (for account-level tokens).
		 *
		 * @return string Store ID or empty string.
		 */
		public function get_store_id() {
			$connection = $this->get_connection();

			if ( $connection && ! empty( $connection['store_id'] ) ) {
				return sanitize_text_field( $connection['store_id'] );
			}

			return '';
		}

		// ------------------------------------------------------------------ //
		// Health monitoring                                                   //
		// ------------------------------------------------------------------ //

		/**
		 * Record a health metric for this connection.
		 *
		 * @param bool  $success  Whether the request succeeded.
		 * @param float $duration Request duration in seconds.
		 */
		protected function record_health( $success, $duration ) {
			if ( ! empty( $this->connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				WP_MCP_AI_Pro_Remote_Site_Manager::record_health_metric(
					$this->connection_id,
					$success,
					$duration
				);
			}
		}

		// ------------------------------------------------------------------ //
		// Request helpers                                                     //
		// ------------------------------------------------------------------ //

		/**
		 * Build request headers including Bearer auth and store context.
		 *
		 * @return array Headers.
		 */
		protected function get_headers() {
			$headers = array(
				'Authorization' => 'Bearer ' . $this->get_token(),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'WP-MCP-AI/' . ( defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '1.0.0' ),
			);

			$store_id = $this->get_store_id();
			if ( ! empty( $store_id ) ) {
				$headers['X-PF-Store-Id'] = $store_id;
			}

			return $headers;
		}

		/**
		 * Generate a transient cache key for a GET request URL.
		 *
		 * @param string $url Full request URL.
		 * @return string Transient key.
		 */
		protected function get_cache_key( $url ) {
			return 'wp_mcp_ai_pf_' . md5( $url );
		}

		/**
		 * Make an authenticated API request to Printful.
		 *
		 * @param string $endpoint  API endpoint path (e.g. '/products').
		 * @param string $method    HTTP method (GET, POST, PUT, DELETE).
		 * @param array  $data      Request body for POST/PUT.
		 * @param array  $options   Additional options (timeout, query params).
		 * @return array|WP_Error   Unwrapped response data or WP_Error.
		 */
		public function make_request( $endpoint, $method = 'GET', $data = array(), $options = array() ) {
			$start_time = microtime( true );
			$method     = strtoupper( $method );
			$token      = $this->get_token();

			if ( empty( $token ) ) {
				$this->record_health( false, microtime( true ) - $start_time );
				return new WP_Error(
					'wp_mcp_ai_printful_missing_token',
					__( 'Printful API token is not configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_token' => __( 'Add a Printful API token in Remote Sites.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$url = self::API_BASE . '/' . ltrim( $endpoint, '/' );

			// Add query parameters if provided.
			if ( isset( $options['query'] ) && is_array( $options['query'] ) ) {
				$url = add_query_arg( array_filter( $options['query'] ), $url );
			}

			// ── Cache lookup for GET requests ────────────────────────────
			$cache_key = '';
			if ( 'GET' === $method && empty( $data ) ) {
				$cache_key = $this->get_cache_key( $url );
				$cached    = get_transient( $cache_key );
				if ( false !== $cached && is_array( $cached ) ) {
					return $cached;
				}
			}

			$request_args = array(
				'timeout' => isset( $options['timeout'] ) ? absint( $options['timeout'] ) : self::DEFAULT_TIMEOUT,
				'method'  => $method,
				'headers' => $this->get_headers(),
			);

			if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && ! empty( $data ) ) {
				$request_args['body'] = wp_json_encode( $data );
			}

			$response = wp_remote_request( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				$this->record_health( false, microtime( true ) - $start_time );
				return new WP_Error(
					'wp_mcp_ai_printful_request_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Printful API request failed: %s', 'mcp-ai-wpoos' ),
						$response->get_error_message()
					)
				);
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
				$this->record_health( false, microtime( true ) - $start_time );
				return new WP_Error(
					'wp_mcp_ai_printful_response_too_large',
					__( 'Printful API response exceeds the maximum allowed size.', 'mcp-ai-wpoos' )
				);
			}

			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$this->record_health( false, microtime( true ) - $start_time );
				return new WP_Error(
					'wp_mcp_ai_printful_invalid_json',
					__( 'Printful API returned an invalid JSON response.', 'mcp-ai-wpoos' )
				);
			}

			// Printful wraps every response in an envelope object containing
			// "code" (HTTP status), "result" (payload), and optionally "error".
			$response_code = isset( $decoded['code'] ) ? absint( $decoded['code'] ) : $code;

			if ( $response_code < 200 || $response_code >= 300 ) {
				$this->record_health( false, microtime( true ) - $start_time );

				$error_message = __( 'Printful API returned an error.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = $decoded['error']['message'];
				} elseif ( isset( $decoded['result'] ) && is_string( $decoded['result'] ) ) {
					$error_message = $decoded['result'];
				}

				$error_data = array( 'status' => $response_code );

				if ( 401 === $response_code || 403 === $response_code ) {
					$error_data['actions'] = array(
						'check_token' => __( 'Verify your Printful API token is valid and not expired.', 'mcp-ai-wpoos' ),
					);
				} elseif ( 429 === $response_code ) {
					$error_data['actions'] = array(
						'rate_limit' => __( 'Printful API rate limit exceeded. Please wait and retry.', 'mcp-ai-wpoos' ),
					);
				} elseif ( $response_code >= 500 ) {
					$error_data['actions'] = array(
						'server_error' => __( 'Printful is experiencing server issues. Please try again later.', 'mcp-ai-wpoos' ),
					);
				}

				return new WP_Error( 'wp_mcp_ai_printful_api_error', $error_message, $error_data );
			}

			$this->record_health( true, microtime( true ) - $start_time );

			// Unwrap the result.
			$result = isset( $decoded['result'] ) ? $decoded['result'] : $decoded;

			// Attach paging info if present.
			if ( isset( $decoded['paging'] ) ) {
				if ( is_array( $result ) ) {
					$result = array(
						'items'  => $result,
						'paging' => $decoded['paging'],
					);
				} elseif ( is_array( $result ) || is_object( $result ) ) {
					$result['_paging'] = $decoded['paging'];
				}
			}

			// ── Cache successful GET responses ────────────────────────────
			if ( '' !== $cache_key ) {
				set_transient( $cache_key, $result, 30 );
			}

			return $result;
		}

		// ------------------------------------------------------------------ //
		// Catalog API                                                         //
		// ------------------------------------------------------------------ //

		/**
		 * Get products from the Printful catalog.
		 *
		 * @param array $options Query options (category_id, category_ids).
		 * @return array|WP_Error
		 */
		public function get_catalog_products( $options = array() ) {
			$query = array();
			if ( ! empty( $options['category_id'] ) ) {
				$query['category_id'] = absint( $options['category_id'] );
			} elseif ( ! empty( $options['category_ids'] ) && is_array( $options['category_ids'] ) ) {
				$query['category_id'] = implode( ',', array_map( 'absint', $options['category_ids'] ) );
			}
			return $this->make_request( 'products', 'GET', array(), array( 'query' => $query ) );
		}

		/**
		 * Get a single catalog product with its variants.
		 *
		 * @param int $product_id Printful product ID.
		 * @return array|WP_Error
		 */
		public function get_catalog_product( $product_id ) {
			return $this->make_request( 'products/' . absint( $product_id ) );
		}

		/**
		 * Get a single catalog variant with its product info.
		 *
		 * @param int $variant_id Printful variant ID.
		 * @return array|WP_Error
		 */
		public function get_catalog_variant( $variant_id ) {
			return $this->make_request( 'products/variant/' . absint( $variant_id ) );
		}

		/**
		 * Get product size guide.
		 *
		 * @param int    $product_id Printful product ID.
		 * @param string $unit       Measurement unit: 'inches' or 'cm'.
		 * @return array|WP_Error
		 */
		public function get_product_size_guide( $product_id, $unit = '' ) {
			$query = array();
			if ( ! empty( $unit ) && in_array( $unit, array( 'inches', 'cm' ), true ) ) {
				$query['unit'] = $unit;
			}
			return $this->make_request( 'products/' . absint( $product_id ) . '/sizes', 'GET', array(), array( 'query' => $query ) );
		}

		/**
		 * Get product categories.
		 *
		 * @return array|WP_Error
		 */
		public function get_categories() {
			return $this->make_request( 'categories' );
		}

		/**
		 * Get a single category by ID.
		 *
		 * @param int $category_id Category ID.
		 * @return array|WP_Error
		 */
		public function get_category( $category_id ) {
			return $this->make_request( 'categories/' . absint( $category_id ) );
		}

		// ------------------------------------------------------------------ //
		// Country / State Code API                                            //
		// ------------------------------------------------------------------ //

		/**
		 * Get list of countries and states accepted by Printful.
		 *
		 * @return array|WP_Error
		 */
		public function get_countries() {
			return $this->make_request( 'countries' );
		}

		// ------------------------------------------------------------------ //
		// Store Products API (Sync Products)                                  //
		// ------------------------------------------------------------------ //

		/**
		 * Get list of sync products from your store.
		 *
		 * @param array $options Query options (status, category_id, offset, limit).
		 * @return array|WP_Error
		 */
		public function get_sync_products( $options = array() ) {
			$query = array();
			if ( isset( $options['status'] ) ) {
				$query['status'] = sanitize_key( $options['status'] );
			}
			if ( isset( $options['category_id'] ) ) {
				$query['category_id'] = sanitize_text_field( $options['category_id'] );
			}
			if ( isset( $options['offset'] ) ) {
				$query['offset'] = absint( $options['offset'] );
			}
			if ( isset( $options['limit'] ) ) {
				$query['limit'] = max( 1, min( 100, absint( $options['limit'] ) ) );
			}
			return $this->make_request( 'store/products', 'GET', array(), array( 'query' => $query ) );
		}

		/**
		 * Create a new sync product with its sync variants.
		 *
		 * @param array $data Product data with sync_product and sync_variants.
		 * @return array|WP_Error
		 */
		public function create_sync_product( $data ) {
			return $this->make_request( 'store/products', 'POST', $data );
		}

		/**
		 * Get a single sync product with its sync variants.
		 *
		 * @param int|string $id Sync product ID or external ID (prefixed with @).
		 * @return array|WP_Error
		 */
		public function get_sync_product( $id ) {
			return $this->make_request( 'store/products/' . rawurlencode( (string) $id ) );
		}

		/**
		 * Update a sync product and optionally its sync variants.
		 *
		 * @param int|string $id   Sync product ID or external ID (prefixed with @).
		 * @param array      $data Fields to update.
		 * @return array|WP_Error
		 */
		public function update_sync_product( $id, $data ) {
			return $this->make_request( 'store/products/' . rawurlencode( (string) $id ), 'PUT', $data );
		}

		/**
		 * Delete a sync product with all its sync variants.
		 *
		 * @param int|string $id Sync product ID or external ID (prefixed with @).
		 * @return array|WP_Error
		 */
		public function delete_sync_product( $id ) {
			return $this->make_request( 'store/products/' . rawurlencode( (string) $id ), 'DELETE' );
		}

		/**
		 * Get a single sync variant.
		 *
		 * @param int|string $id Sync variant ID or external ID (prefixed with @).
		 * @return array|WP_Error
		 */
		public function get_sync_variant( $id ) {
			return $this->make_request( 'store/variants/' . rawurlencode( (string) $id ) );
		}

		/**
		 * Update a sync variant.
		 *
		 * @param int|string $id   Sync variant ID or external ID (prefixed with @).
		 * @param array      $data Fields to update.
		 * @return array|WP_Error
		 */
		public function update_sync_variant( $id, $data ) {
			return $this->make_request( 'store/variants/' . rawurlencode( (string) $id ), 'PUT', $data );
		}

		/**
		 * Delete a sync variant.
		 *
		 * @param int|string $id Sync variant ID or external ID (prefixed with @).
		 * @return array|WP_Error
		 */
		public function delete_sync_variant( $id ) {
			return $this->make_request( 'store/variants/' . rawurlencode( (string) $id ), 'DELETE' );
		}

		/**
		 * Create a new sync variant for an existing sync product.
		 *
		 * @param int|string $product_id Sync product ID or external ID.
		 * @param array      $data       Variant data.
		 * @return array|WP_Error
		 */
		public function create_sync_variant( $product_id, $data ) {
			return $this->make_request( 'store/products/' . rawurlencode( (string) $product_id ) . '/variants', 'POST', $data );
		}

		// ------------------------------------------------------------------ //
		// Orders API                                                          //
		// ------------------------------------------------------------------ //

		/**
		 * Get list of orders.
		 *
		 * @param array $options Query options (status, offset, limit).
		 * @return array|WP_Error
		 */
		public function get_orders( $options = array() ) {
			$query = array();
			if ( isset( $options['status'] ) ) {
				$query['status'] = sanitize_key( $options['status'] );
			}
			if ( isset( $options['offset'] ) ) {
				$query['offset'] = absint( $options['offset'] );
			}
			if ( isset( $options['limit'] ) ) {
				$query['limit'] = max( 1, min( 100, absint( $options['limit'] ) ) );
			}
			return $this->make_request( 'orders', 'GET', array(), array( 'query' => $query ) );
		}

		/**
		 * Create a new order.
		 *
		 * @param array $data    Order data (recipient, items).
		 * @param bool  $confirm Whether to auto-confirm (default: false = draft).
		 * @return array|WP_Error
		 */
		public function create_order( $data, $confirm = false ) {
			$query = array();
			if ( $confirm ) {
				$query['confirm'] = 1;
			}
			return $this->make_request( 'orders', 'POST', $data, array( 'query' => $query ) );
		}

		/**
		 * Get a single order by ID or external ID.
		 *
		 * @param int|string $id Order ID or external ID (prefixed with @).
		 * @return array|WP_Error
		 */
		public function get_order( $id ) {
			return $this->make_request( 'orders/' . rawurlencode( (string) $id ) );
		}

		/**
		 * Update an unsubmitted order.
		 *
		 * @param int|string $id      Order ID or external ID.
		 * @param array      $data    Fields to update.
		 * @param bool       $confirm Whether to auto-confirm after update.
		 * @return array|WP_Error
		 */
		public function update_order( $id, $data, $confirm = false ) {
			$query = array();
			if ( $confirm ) {
				$query['confirm'] = 1;
			}
			return $this->make_request( 'orders/' . rawurlencode( (string) $id ), 'PUT', $data, array( 'query' => $query ) );
		}

		/**
		 * Cancel a pending order or draft.
		 *
		 * @param int|string $id Order ID or external ID.
		 * @return array|WP_Error
		 */
		public function cancel_order( $id ) {
			return $this->make_request( 'orders/' . rawurlencode( (string) $id ), 'DELETE' );
		}

		/**
		 * Confirm a draft order for fulfillment.
		 *
		 * @param int|string $id Order ID or external ID.
		 * @return array|WP_Error
		 */
		public function confirm_order( $id ) {
			return $this->make_request( 'orders/' . rawurlencode( (string) $id ) . '/confirm', 'POST' );
		}

		/**
		 * Estimate order costs before creating.
		 *
		 * @param array $data Order data (recipient, items).
		 * @return array|WP_Error
		 */
		public function estimate_costs( $data ) {
			return $this->make_request( 'orders/estimate-costs', 'POST', $data );
		}

		// ------------------------------------------------------------------ //
		// Shipping Rate API                                                   //
		// ------------------------------------------------------------------ //

		/**
		 * Calculate shipping rates for an order.
		 *
		 * @param array $data Shipping request (recipient, items, currency, locale).
		 * @return array|WP_Error
		 */
		public function get_shipping_rates( $data ) {
			return $this->make_request( 'shipping/rates', 'POST', $data );
		}

		// ------------------------------------------------------------------ //
		// File Library API                                                    //
		// ------------------------------------------------------------------ //

		/**
		 * Add a new file to the Printful file library.
		 *
		 * @param array $data File data (url, type, filename, visible).
		 * @return array|WP_Error
		 */
		public function add_file( $data ) {
			return $this->make_request( 'files', 'POST', $data );
		}

		/**
		 * Get file metadata by ID.
		 *
		 * @param int $file_id File ID.
		 * @return array|WP_Error
		 */
		public function get_file( $file_id ) {
			return $this->make_request( 'files/' . absint( $file_id ) );
		}

		/**
		 * Get suggested thread colors from an image URL.
		 *
		 * @param string $file_url Public image URL.
		 * @return array|WP_Error
		 */
		public function get_thread_colors( $file_url ) {
			return $this->make_request( 'files/thread-colors', 'POST', array( 'file_url' => esc_url_raw( $file_url ) ) );
		}

		// ------------------------------------------------------------------ //
		// Product Templates API                                               //
		// ------------------------------------------------------------------ //

		/**
		 * Get list of product templates.
		 *
		 * @param array $options Query options (offset, limit).
		 * @return array|WP_Error
		 */
		public function get_templates( $options = array() ) {
			$query = array();
			if ( isset( $options['offset'] ) ) {
				$query['offset'] = absint( $options['offset'] );
			}
			if ( isset( $options['limit'] ) ) {
				$query['limit'] = max( 1, min( 100, absint( $options['limit'] ) ) );
			}
			return $this->make_request( 'product-templates', 'GET', array(), array( 'query' => $query ) );
		}

		/**
		 * Get a single product template by ID or external product ID.
		 *
		 * @param int|string $id Template ID or external product ID (prefixed with @).
		 * @return array|WP_Error
		 */
		public function get_template( $id ) {
			return $this->make_request( 'product-templates/' . rawurlencode( (string) $id ) );
		}

		/**
		 * Delete a product template.
		 *
		 * @param int|string $id Template ID or external product ID.
		 * @return array|WP_Error
		 */
		public function delete_template( $id ) {
			return $this->make_request( 'product-templates/' . rawurlencode( (string) $id ), 'DELETE' );
		}

		// ------------------------------------------------------------------ //
		// Webhook API                                                         //
		// ------------------------------------------------------------------ //

		/**
		 * Get current webhook configuration.
		 *
		 * @return array|WP_Error
		 */
		public function get_webhooks() {
			return $this->make_request( 'webhooks' );
		}

		/**
		 * Set up webhook configuration (overwrites existing).
		 *
		 * @param array $data Webhook config (url, types, params).
		 * @return array|WP_Error
		 */
		public function set_webhooks( $data ) {
			return $this->make_request( 'webhooks', 'POST', $data );
		}

		/**
		 * Disable webhook support for the store.
		 *
		 * @return array|WP_Error
		 */
		public function delete_webhooks() {
			return $this->make_request( 'webhooks', 'DELETE' );
		}

		// ------------------------------------------------------------------ //
		// Store Information API                                               //
		// ------------------------------------------------------------------ //

		/**
		 * Get basic information about stores.
		 *
		 * @return array|WP_Error
		 */
		public function get_stores() {
			return $this->make_request( 'stores' );
		}

		/**
		 * Get basic information about a specific store.
		 *
		 * @param int $store_id Store ID.
		 * @return array|WP_Error
		 */
		public function get_store( $store_id ) {
			return $this->make_request( 'stores/' . absint( $store_id ) );
		}

		// ------------------------------------------------------------------ //
		// Reports API                                                         //
		// ------------------------------------------------------------------ //

		/**
		 * Get statistics for specified report types (max 6 months range).
		 *
		 * @param array $options Report options (date_from, date_to, currency, report_types).
		 * @return array|WP_Error
		 */
		public function get_statistics( $options = array() ) {
			$query = array();

			if ( isset( $options['date_from'] ) ) {
				$query['date_from'] = sanitize_text_field( $options['date_from'] );
			}
			if ( isset( $options['date_to'] ) ) {
				$query['date_to'] = sanitize_text_field( $options['date_to'] );
			}
			if ( isset( $options['currency'] ) ) {
				$query['currency'] = sanitize_text_field( $options['currency'] );
			}
			if ( isset( $options['report_types'] ) ) {
				$query['report_types'] = is_array( $options['report_types'] )
					? implode( ',', array_map( 'sanitize_key', $options['report_types'] ) )
					: sanitize_text_field( $options['report_types'] );
			}

			return $this->make_request( 'reports/statistics', 'GET', array(), array( 'query' => $query ) );
		}

		// ------------------------------------------------------------------ //
		// Warehouse Products API                                              //
		// ------------------------------------------------------------------ //

		/**
		 * Get list of warehouse products.
		 *
		 * @param array $options Query options (query, offset, limit).
		 * @return array|WP_Error
		 */
		public function get_warehouse_products( $options = array() ) {
			$query = array();
			if ( isset( $options['query'] ) ) {
				$query['query'] = sanitize_text_field( $options['query'] );
			}
			if ( isset( $options['offset'] ) ) {
				$query['offset'] = absint( $options['offset'] );
			}
			if ( isset( $options['limit'] ) ) {
				$query['limit'] = max( 1, min( 100, absint( $options['limit'] ) ) );
			}
			return $this->make_request( 'warehouse/products', 'GET', array(), array( 'query' => $query ) );
		}

		/**
		 * Get a single warehouse product by ID.
		 *
		 * @param int|string $id Warehouse product ID.
		 * @return array|WP_Error
		 */
		public function get_warehouse_product( $id ) {
			return $this->make_request( 'warehouse/products/' . rawurlencode( (string) $id ) );
		}

		// ------------------------------------------------------------------ //
		// Mockup Generator API                                                //
		// ------------------------------------------------------------------ //

		/**
		 * Create a mockup generation task.
		 *
		 * @param int   $product_id Printful product ID.
		 * @param array $data       Generation request (variant_ids, files, format, etc.).
		 * @return array|WP_Error
		 */
		public function create_mockup_task( $product_id, $data ) {
			return $this->make_request( 'mockup-generator/create-task/' . absint( $product_id ), 'POST', $data );
		}

		/**
		 * Get mockup generation task result.
		 *
		 * @param string $task_key Task key from create_mockup_task.
		 * @return array|WP_Error
		 */
		public function get_mockup_task( $task_key ) {
			return $this->make_request(
				'mockup-generator/task',
				'GET',
				array(),
				array(
					'query' => array( 'task_key' => sanitize_text_field( $task_key ) ),
				)
			);
		}

		/**
		 * Get print files info for a product's variants.
		 *
		 * @param int    $product_id  Printful product ID.
		 * @param string $technique   Optional technique filter (DTG, EMBROIDERY, etc.).
		 * @param string $orientation Optional orientation for wall art.
		 * @return array|WP_Error
		 */
		public function get_printfiles( $product_id, $technique = '', $orientation = '' ) {
			$query = array();
			if ( ! empty( $technique ) ) {
				$query['technique'] = sanitize_key( $technique );
			}
			if ( ! empty( $orientation ) && in_array( $orientation, array( 'horizontal', 'vertical' ), true ) ) {
				$query['orientation'] = $orientation;
			}
			return $this->make_request( 'mockup-generator/printfiles/' . absint( $product_id ), 'GET', array(), array( 'query' => $query ) );
		}

		/**
		 * Get layout templates for a product.
		 *
		 * @param int    $product_id  Printful product ID.
		 * @param string $technique   Optional technique filter.
		 * @param string $orientation Optional orientation for wall art.
		 * @return array|WP_Error
		 */
		public function get_layout_templates( $product_id, $technique = '', $orientation = '' ) {
			$query = array();
			if ( ! empty( $technique ) ) {
				$query['technique'] = sanitize_key( $technique );
			}
			if ( ! empty( $orientation ) && in_array( $orientation, array( 'horizontal', 'vertical' ), true ) ) {
				$query['orientation'] = $orientation;
			}
			return $this->make_request( 'mockup-generator/templates/' . absint( $product_id ), 'GET', array(), array( 'query' => $query ) );
		}
	}
}
