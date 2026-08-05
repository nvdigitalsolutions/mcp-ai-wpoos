<?php
/**
 * Shopify Admin GraphQL API client.
 *
 * Provides a wrapper around the Shopify Admin GraphQL API (https://shopify.dev/docs/api/admin-graphql).
 * Authentication uses the X-Shopify-Access-Token header populated from the Remote Sites
 * connection credential (api_key field, encrypted at rest).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {

	/**
	 * Shopify Admin GraphQL API client.
	 *
	 * Handles API communication with Shopify stores via the Admin GraphQL API.
	 * WordPress integration and capability checks are handled by tool classes.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Shopify_Client {

		/**
		 * Default Shopify Admin API version (updated quarterly).
		 *
		 * @var string
		 */
		const DEFAULT_API_VERSION = '2025-01';

		/**
		 * Latest known stable Shopify Admin API version for deprecation warnings.
		 *
		 * Shopify deprecates versions after 12 months.  When the configured
		 * version is older than LATEST_KNOWN_VERSION the admin UI shows a
		 * notice encouraging an upgrade.
		 *
		 * Update this constant each quarter when Shopify releases a new version.
		 *
		 * @var string
		 */
		const LATEST_KNOWN_VERSION = '2025-04';

		/**
		 * Maximum response body size in bytes (5 MB).
		 *
		 * @var int
		 */
		const MAX_RESPONSE_SIZE = 5242880;

		/**
		 * Default request timeout in seconds.
		 *
		 * @var int
		 */
		const DEFAULT_TIMEOUT = 30;

		/**
		 * Shopify Catalog API base URL (global agentic commerce search).
		 *
		 * Endpoints: /global/v2/search, /global/v2/lookup, /global/v2/lookup/by-variant
		 *
		 * @var string
		 */
		const CATALOG_BASE_URL = 'https://discover.shopifyapps.com';

		/**
		 * Shopify token endpoint for obtaining a Catalog API JWT bearer token.
		 *
		 * POST client_id + client_secret (shpss_…) to receive an access_token (JWT, ~60 min TTL).
		 *
		 * @var string
		 */
		const CATALOG_AUTH_URL = 'https://api.shopify.com/auth/access_token';

		/**
		 * Remote Sites connection ID.
		 *
		 * @var string|null
		 */
		protected $connection_id = null;

		/**
		 * Resolved connection data array (cached per request).
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

		/**
		 * Determine whether sandbox / development mode is active.
		 *
		 * Development stores created from the Shopify Partners dashboard are
		 * used for testing.  When sandbox_mode is on, certain safety checks
		 * (e.g. writing to a production store) may be relaxed or logged
		 * differently.
		 *
		 * @since 1.0.0
		 *
		 * @return bool
		 */
		public function is_sandbox() {
			$connection = $this->get_connection();
			return $connection && ! empty( $connection['sandbox_mode'] );
		}

		// ------------------------------------------------------------------ //
		// Static helpers                                                       //
		// ------------------------------------------------------------------ //

		/**
		 * Validate a Shopify API version string.
		 *
		 * Valid format: YYYY-MM (e.g. 2025-01).
		 *
		 * @since 1.0.0
		 *
		 * @param string $version Version string to validate.
		 * @return bool True if valid, false otherwise.
		 */
		public static function is_valid_api_version( $version ) {
			return is_string( $version ) && 1 === preg_match( '/^\d{4}-\d{2}$/', $version );
		}

		/**
		 * Return a valid Shopify API version, falling back to the default.
		 *
		 * @since 1.0.0
		 *
		 * @param string $version User-supplied version string.
		 * @return string Validated version or DEFAULT_API_VERSION.
		 */
		public static function sanitize_api_version( $version ) {
			return self::is_valid_api_version( $version ) ? $version : self::DEFAULT_API_VERSION;
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
		 * Get the decrypted Admin API access token.
		 *
		 * @return string Access token or empty string.
		 */
		public function get_access_token() {
			$connection = $this->get_connection();

			if ( $connection && ! empty( $connection['api_key'] ) ) {
				return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
			}

			return '';
		}

		/**
		 * Get the decrypted Storefront API access token (optional).
		 *
		 * @return string Storefront token or empty string.
		 */
		public function get_storefront_token() {
			$connection = $this->get_connection();

			if ( $connection && ! empty( $connection['api_secret'] ) ) {
				return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] );
			}

			return '';
		}

		/**
		 * Get the configured Shopify store base URL (e.g. https://mystore.myshopify.com).
		 *
		 * @return string Store URL or empty string.
		 */
		public function get_store_url() {
			$connection = $this->get_connection();

			if ( $connection && ! empty( $connection['url'] ) ) {
				return untrailingslashit( $connection['url'] );
			}

			return '';
		}

		/**
		 * Get the configured API version (e.g. 2025-01).
		 *
		 * @return string API version string.
		 */
		public function get_api_version() {
			$connection  = $this->get_connection();
			$api_version = isset( $connection['shopify_api_version'] ) ? $connection['shopify_api_version'] : '';
			return self::sanitize_api_version( $api_version );
		}

		/**
		 * Get the configured API mode: 'admin_api' (default) or 'catalog_api'.
		 *
		 * @return string 'admin_api' or 'catalog_api'.
		 */
		public function get_api_mode() {
			$connection = $this->get_connection();
			$mode       = isset( $connection['shopify_api_mode'] ) ? $connection['shopify_api_mode'] : 'admin_api';
			return in_array( $mode, array( 'admin_api', 'catalog_api' ), true ) ? $mode : 'admin_api';
		}

		/**
		 * Get the Catalog API Shop ID configured on the connection.
		 *
		 * Used to scope Catalog API search results to a single store via the
		 * `shop_ids` query parameter. Accepts a bare numeric ID or a full GID
		 * (gid://shopify/Shop/12345).
		 *
		 * @since 1.1.6
		 *
		 * @return string Shop ID string or empty string when not configured.
		 */
		public function get_catalog_shop_id() {
			$connection = $this->get_connection();
			return $connection && ! empty( $connection['shopify_catalog_shop_id'] )
				? sanitize_text_field( $connection['shopify_catalog_shop_id'] )
				: '';
		}

		/**
		 * Get the Catalog API client ID (Dev Dashboard credential).
		 *
		 * In catalog_api mode the connection stores client_id in api_key (plain-text).
		 *
		 * @return string Client ID or empty string.
		 */
		public function get_catalog_client_id() {
			$connection = $this->get_connection();
			return $connection && ! empty( $connection['api_key'] )
				? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] )
				: '';
		}

		/**
		 * Get the Catalog API client secret (shpss_… Dev Dashboard credential).
		 *
		 * In catalog_api mode the connection stores client_secret in api_secret (encrypted).
		 *
		 * @return string Client secret or empty string.
		 */
		public function get_catalog_client_secret() {
			$connection = $this->get_connection();
			return $connection && ! empty( $connection['api_secret'] )
				? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] )
				: '';
		}

		/**
		 * Fetch (or return a cached) Catalog API JWT bearer token.
		 *
		 * Exchanges client_id + client_secret for a short-lived JWT using
		 * POST https://api.shopify.com/auth/access_token with grant_type=client_credentials.
		 * The token is cached in a WordPress transient for its TTL minus a 60-second buffer.
		 *
		 * @return string|WP_Error JWT access token string or WP_Error on failure.
		 */
		public function get_catalog_token() {
			$client_id     = $this->get_catalog_client_id();
			$client_secret = $this->get_catalog_client_secret();

			if ( empty( $client_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_missing_client_id',
					__( 'Shopify Catalog API client ID is not configured for this connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( empty( $client_secret ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_missing_client_secret',
					__( 'Shopify Catalog API client secret (shpss_…) is not configured for this connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Cache key unique to this connection's credentials.
			$transient_key = 'wp_mcp_ai_shopify_cat_tok_' . md5( $client_id );

			$cached = get_transient( $transient_key );
			if ( ! empty( $cached ) ) {
				return $cached;
			}

			$body = array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'client_credentials',
			);

			$raw_response = wp_safe_remote_post(
				self::CATALOG_AUTH_URL,
				array(
					'timeout' => self::DEFAULT_TIMEOUT,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
						'User-Agent'   => 'WP-MCP-AI-Pro/' . WP_MCP_AI_PRO_VERSION,
					),
					'body'    => wp_json_encode( $body ),
				)
			);

			if ( is_wp_error( $raw_response ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_auth_failed',
					/* translators: %s: error message */
					sprintf( __( 'Shopify Catalog API token request failed: %s', 'mcp-ai-wpoos-pro' ), $raw_response->get_error_message() )
				);
			}

			$response_code = wp_remote_retrieve_response_code( $raw_response );
			$response_body = wp_remote_retrieve_body( $raw_response );

			if ( 401 === $response_code || 403 === $response_code ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_unauthorized',
					__( 'Shopify Catalog API credentials rejected. Please verify the client ID and client secret (shpss_…).', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( $response_code < 200 || $response_code >= 300 ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_auth_http_error',
					/* translators: %d: HTTP status code */
					sprintf( __( 'Shopify Catalog API token endpoint returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response_code )
				);
			}

			$decoded = json_decode( $response_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE || empty( $decoded['access_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_invalid_token_response',
					__( 'Shopify Catalog API returned an unexpected token response.', 'mcp-ai-wpoos-pro' )
				);
			}

			$token      = $decoded['access_token'];
			$expires_in = isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600;
			// Cache with a 60-second safety buffer to avoid using an expired token.
			$cache_ttl = max( 60, $expires_in - 60 );

			set_transient( $transient_key, $token, $cache_ttl );

			return $token;
		}                                                     //
		// ------------------------------------------------------------------ //

		/**
		 * Build the Admin GraphQL endpoint URL for the configured store and version.
		 *
		 * @return string Full endpoint URL.
		 */
		public function get_graphql_endpoint() {
			return $this->get_store_url() . '/admin/api/' . $this->get_api_version() . '/graphql.json';
		}

		/**
		 * Execute a Shopify Admin GraphQL query or mutation.
		 *
		 * @since 1.0.0
		 *
		 * @param string $query     GraphQL query or mutation string.
		 * @param array  $variables Optional variables map.
		 * @return array|WP_Error Decoded response array or WP_Error on failure.
		 */
		public function graphql( $query, array $variables = array() ) {
			$store_url    = $this->get_store_url();
			$access_token = $this->get_access_token();

			if ( empty( $store_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_missing_url',
					__( 'Shopify store URL is not configured for this connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( empty( $access_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_missing_token',
					__( 'Shopify Admin API access token is not configured for this connection.', 'mcp-ai-wpoos-pro' )
				);
			}

			$endpoint = $this->get_graphql_endpoint();

			$body = array( 'query' => $query );
			if ( ! empty( $variables ) ) {
				$body['variables'] = $variables;
			}

			$args = array(
				'method'  => 'POST',
				'timeout' => self::DEFAULT_TIMEOUT,
				'headers' => array(
					'Content-Type'           => 'application/json',
					'X-Shopify-Access-Token' => $access_token,
					'Accept'                 => 'application/json',
					'User-Agent'             => 'WP-MCP-AI-Pro/' . WP_MCP_AI_PRO_VERSION,
				),
				'body'    => wp_json_encode( $body ),
			);

			$raw_response = wp_safe_remote_post( $endpoint, $args );

			if ( is_wp_error( $raw_response ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_request_failed',
					/* translators: %s: error message */
					sprintf( __( 'Shopify API request failed: %s', 'mcp-ai-wpoos-pro' ), $raw_response->get_error_message() )
				);
			}

			$response_code = wp_remote_retrieve_response_code( $raw_response );
			$response_body = wp_remote_retrieve_body( $raw_response );

			// Guard against unexpectedly large responses.
			if ( strlen( $response_body ) > self::MAX_RESPONSE_SIZE ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_response_too_large',
					__( 'Shopify API response exceeds the maximum allowed size.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 401 === $response_code ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_unauthorized',
					__( 'Shopify API access denied. Please verify the Admin API access token and its scopes.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 402 === $response_code ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_payment_required',
					__( 'Shopify payment required. The store may need to upgrade its plan.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 404 === $response_code ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_not_found',
					__( 'Shopify store not found. Please verify the shop domain.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 429 === $response_code ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_rate_limited',
					__( 'Shopify API rate limit reached. Please retry after a moment.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( $response_code < 200 || $response_code >= 300 ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_http_error',
					/* translators: %d: HTTP status code */
					sprintf( __( 'Shopify API returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $response_code )
				);
			}

			$decoded = json_decode( $response_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_invalid_json',
					__( 'Shopify API returned an invalid JSON response.', 'mcp-ai-wpoos-pro' )
				);
			}

			// ── GraphQL cost telemetry ───────────────────────────────────
			// Shopify's Admin GraphQL API returns cost metadata in
			// extensions.cost so callers can track query expense and
			// throttle budget.  Log it on every response; when the
			// remaining budget dips below 10% of the maximum, include a
			// warning in the data so upstream tool handlers can back off.
			if ( isset( $decoded['extensions']['cost'] ) ) {
				$cost = $decoded['extensions']['cost'];

				if ( function_exists( 'WP_MCP_AI_Logger' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'shopify_graphql_cost',
						'Shopify GraphQL cost telemetry.',
						array(
							'requestedQueryCost' => isset( $cost['requestedQueryCost'] ) ? absint( $cost['requestedQueryCost'] ) : 0,
							'actualQueryCost'    => isset( $cost['actualQueryCost'] ) ? absint( $cost['actualQueryCost'] ) : null,
							'throttleStatus'     => isset( $cost['throttleStatus'] ) ? $cost['throttleStatus'] : null,
							'connection_id'      => $this->connection_id,
						)
					);
				}

				// Warn when remaining budget is critically low.
				if (
					isset( $cost['throttleStatus']['maximumAvailable'], $cost['throttleStatus']['currentlyAvailable'] )
					&& $cost['throttleStatus']['maximumAvailable'] > 0
				) {
					$pct_remaining = ( $cost['throttleStatus']['currentlyAvailable'] / $cost['throttleStatus']['maximumAvailable'] ) * 100;
					if ( $pct_remaining < 10 ) {
						$decoded['_cost_warning'] = sprintf(
							/* translators: 1: available points, 2: max points */
							__( 'Shopify API cost budget nearly exhausted (%1$d of %2$d points remaining). Consider batching requests or reducing query complexity.', 'mcp-ai-wpoos-pro' ),
							(int) $cost['throttleStatus']['currentlyAvailable'],
							(int) $cost['throttleStatus']['maximumAvailable']
						);
					}
				}
			}

			return $decoded;
		}

		// ------------------------------------------------------------------ //
		// Convenience methods – products                                      //
		// ------------------------------------------------------------------ //

		/**
		 * List products from the Shopify store.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $first  Number of products to fetch (1–250). Default 10.
		 * @param string $after  Cursor for pagination (optional).
		 * @param string $query  GraphQL filter query string (optional), e.g. 'status:active'.
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function get_products( $first = 10, $after = '', $query = '' ) {
			$first = max( 1, min( 250, absint( $first ) ) );

			$gql_query = '
query GetProducts($first: Int!, $after: String, $query: String) {
  products(first: $first, after: $after, query: $query) {
    pageInfo { hasNextPage hasPreviousPage startCursor endCursor }
    edges {
      cursor
      node {
        id title handle status vendor productType tags createdAt updatedAt
        priceRangeV2 {
          minVariantPrice { amount currencyCode }
          maxVariantPrice { amount currencyCode }
        }
        totalInventory
        variants(first: 10) {
          edges {
            node {
              id title sku price compareAtPrice inventoryQuantity
              selectedOptions { name value }
            }
          }
        }
        images(first: 5) {
          edges { node { id url altText } }
        }
      }
    }
  }
}';

			$variables = array( 'first' => $first );
			if ( ! empty( $after ) ) {
				$variables['after'] = $after;
			}
			if ( ! empty( $query ) ) {
				$variables['query'] = $query;
			}

			return $this->graphql( $gql_query, $variables );
		}

		/**
		 * Get a single product by its Shopify GID.
		 *
		 * @since 1.0.0
		 *
		 * @param string $product_id Shopify product GID (e.g. gid://shopify/Product/123456789).
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function get_product( $product_id ) {
			$gql_query = '
query GetProduct($id: ID!) {
  product(id: $id) {
    id title handle descriptionHtml status vendor productType tags createdAt updatedAt
    priceRangeV2 {
      minVariantPrice { amount currencyCode }
      maxVariantPrice { amount currencyCode }
    }
    totalInventory
    variants(first: 100) {
      edges {
        node {
          id title sku price compareAtPrice inventoryQuantity barcode weight weightUnit
          selectedOptions { name value }
        }
      }
    }
    images(first: 20) {
      edges { node { id url altText } }
    }
    collections(first: 10) {
      edges { node { id title handle } }
    }
    seo { title description }
  }
}';

			return $this->graphql( $gql_query, array( 'id' => $product_id ) );
		}

		/**
		 * Create a product in the Shopify store.
		 *
		 * @since 1.0.0
		 *
		 * @param array $input Product input fields matching the Shopify ProductInput type.
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function create_product( array $input ) {
			$gql_query = '
mutation CreateProduct($input: ProductInput!) {
  productCreate(input: $input) {
    product {
      id title handle status
      priceRangeV2 { minVariantPrice { amount currencyCode } }
    }
    userErrors { field message }
  }
}';

			return $this->graphql( $gql_query, array( 'input' => $input ) );
		}

		/**
		 * Update a product in the Shopify store.
		 *
		 * @since 1.0.0
		 *
		 * @param string $product_id Shopify product GID.
		 * @param array  $input      Fields to update (ProductInput type).
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function update_product( $product_id, array $input ) {
			$input['id'] = $product_id;

			$gql_query = '
mutation UpdateProduct($input: ProductInput!) {
  productUpdate(input: $input) {
    product {
      id title handle status updatedAt
      priceRangeV2 { minVariantPrice { amount currencyCode } }
    }
    userErrors { field message }
  }
}';

			return $this->graphql( $gql_query, array( 'input' => $input ) );
		}

		// ------------------------------------------------------------------ //
		// Convenience methods – orders                                        //
		// ------------------------------------------------------------------ //

		/**
		 * List orders from the Shopify store.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $first Number of orders to fetch (1–250). Default 10.
		 * @param string $after Cursor for pagination (optional).
		 * @param string $query GraphQL filter query string (optional), e.g. 'financial_status:paid'.
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function get_orders( $first = 10, $after = '', $query = '' ) {
			$first = max( 1, min( 250, absint( $first ) ) );

			$gql_query = '
query GetOrders($first: Int!, $after: String, $query: String) {
  orders(first: $first, after: $after, query: $query) {
    pageInfo { hasNextPage endCursor }
    edges {
      cursor
      node {
        id name createdAt updatedAt processedAt
        displayFinancialStatus displayFulfillmentStatus
        totalPriceSet { shopMoney { amount currencyCode } }
        subtotalPriceSet { shopMoney { amount currencyCode } }
        totalShippingPriceSet { shopMoney { amount currencyCode } }
        totalTaxSet { shopMoney { amount currencyCode } }
        customer { id firstName lastName email phone }
        shippingAddress { address1 address2 city province zip country }
        lineItems(first: 20) {
          edges {
            node {
              id title quantity sku
              originalUnitPriceSet { shopMoney { amount currencyCode } }
            }
          }
        }
        fulfillments(first: 5) {
          id status trackingInfo { company number url }
        }
        tags note
      }
    }
  }
}';

			$variables = array( 'first' => $first );
			if ( ! empty( $after ) ) {
				$variables['after'] = $after;
			}
			if ( ! empty( $query ) ) {
				$variables['query'] = $query;
			}

			return $this->graphql( $gql_query, $variables );
		}

		/**
		 * Get a single order by its Shopify GID.
		 *
		 * @since 1.0.0
		 *
		 * @param string $order_id Shopify order GID (e.g. gid://shopify/Order/123456789).
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function get_order( $order_id ) {
			$gql_query = '
query GetOrder($id: ID!) {
  order(id: $id) {
    id name createdAt updatedAt processedAt cancelledAt cancelReason
    displayFinancialStatus displayFulfillmentStatus email phone
    totalPriceSet { shopMoney { amount currencyCode } }
    subtotalPriceSet { shopMoney { amount currencyCode } }
    totalShippingPriceSet { shopMoney { amount currencyCode } }
    totalTaxSet { shopMoney { amount currencyCode } }
    totalRefundedSet { shopMoney { amount currencyCode } }
    customer { id firstName lastName email phone }
    billingAddress { address1 address2 city province zip country firstName lastName }
    shippingAddress { address1 address2 city province zip country firstName lastName }
    lineItems(first: 50) {
      edges {
        node {
          id title quantity sku fulfillableQuantity fulfillmentStatus
          originalUnitPriceSet { shopMoney { amount currencyCode } }
          discountedUnitPriceSet { shopMoney { amount currencyCode } }
          variant { id title sku barcode }
        }
      }
    }
    fulfillments(first: 10) {
      id status createdAt updatedAt
      trackingInfo { company number url }
      fulfillmentLineItems(first: 20) {
        edges { node { id quantity lineItem { id title } } }
      }
    }
    transactions(first: 10) {
      id kind status amountSet { shopMoney { amount currencyCode } }
      gateway createdAt
    }
    refunds(first: 10) {
      id createdAt
      totalRefundedSet { shopMoney { amount currencyCode } }
    }
    tags note
  }
}';

			return $this->graphql( $gql_query, array( 'id' => $order_id ) );
		}

		// ------------------------------------------------------------------ //
		// Convenience methods – customers                                     //
		// ------------------------------------------------------------------ //

		/**
		 * List customers from the Shopify store.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $first Number of customers to fetch (1–250). Default 10.
		 * @param string $after Cursor for pagination (optional).
		 * @param string $query GraphQL filter query string (optional), e.g. 'email:john@example.com'.
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function get_customers( $first = 10, $after = '', $query = '' ) {
			$first = max( 1, min( 250, absint( $first ) ) );

			$gql_query = '
query GetCustomers($first: Int!, $after: String, $query: String) {
  customers(first: $first, after: $after, query: $query) {
    pageInfo { hasNextPage endCursor }
    edges {
      cursor
      node {
        id firstName lastName email phone createdAt updatedAt
        numberOfOrders amountSpent { amount currencyCode }
        verifiedEmail tags note
        defaultAddress { address1 address2 city province zip country }
      }
    }
  }
}';

			$variables = array( 'first' => $first );
			if ( ! empty( $after ) ) {
				$variables['after'] = $after;
			}
			if ( ! empty( $query ) ) {
				$variables['query'] = $query;
			}

			return $this->graphql( $gql_query, $variables );
		}

		/**
		 * Get a single customer by Shopify GID.
		 *
		 * @since 1.0.0
		 *
		 * @param string $customer_id Shopify customer GID.
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function get_customer( $customer_id ) {
			$gql_query = '
query GetCustomer($id: ID!) {
  customer(id: $id) {
    id firstName lastName email phone createdAt updatedAt
    numberOfOrders amountSpent { amount currencyCode }
    verifiedEmail emailMarketingConsent { marketingState consentUpdatedAt }
    smsMarketingConsent { marketingState consentUpdatedAt }
    tags note
    addresses {
      address1 address2 city province zip country firstName lastName company phone default
    }
    orders(first: 10) {
      edges {
        node {
          id name createdAt displayFinancialStatus displayFulfillmentStatus
          totalPriceSet { shopMoney { amount currencyCode } }
        }
      }
    }
  }
}';

			return $this->graphql( $gql_query, array( 'id' => $customer_id ) );
		}

		// ------------------------------------------------------------------ //
		// Convenience methods – inventory                                     //
		// ------------------------------------------------------------------ //

		/**
		 * List inventory levels for a location.
		 *
		 * @since 1.0.0
		 *
		 * @param string $location_id Shopify location GID (optional). Fetches first location if empty.
		 * @param int    $first       Number of inventory items to fetch (1–250). Default 50.
		 * @param string $after       Cursor for pagination (optional).
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function get_inventory_levels( $location_id = '', $first = 50, $after = '' ) {
			$first = max( 1, min( 250, absint( $first ) ) );

			if ( ! empty( $location_id ) ) {
				$gql_query = '
query GetInventoryLevels($locationId: ID!, $first: Int!, $after: String) {
  location(id: $locationId) {
    id name
    inventoryLevels(first: $first, after: $after) {
      pageInfo { hasNextPage endCursor }
      edges {
        cursor
        node {
          id quantities(names: ["available","incoming","on_hand","reserved"]) { name quantity }
          item { id sku variant { id title product { id title } } }
        }
      }
    }
  }
}';
				$variables = array(
					'locationId' => $location_id,
					'first'      => $first,
				);
			} else {
				$gql_query = '
query GetLocationsInventory($first: Int!, $after: String) {
  locations(first: 1) {
    edges {
      node {
        id name
        inventoryLevels(first: $first, after: $after) {
          pageInfo { hasNextPage endCursor }
          edges {
            cursor
            node {
              id quantities(names: ["available","incoming","on_hand","reserved"]) { name quantity }
              item { id sku variant { id title product { id title } } }
            }
          }
        }
      }
    }
  }
}';
				$variables = array( 'first' => $first );
			}

			if ( ! empty( $after ) ) {
				$variables['after'] = $after;
			}

			return $this->graphql( $gql_query, $variables );
		}

		/**
		 * Adjust available inventory quantity for an inventory item at a location.
		 *
		 * @since 1.0.0
		 *
		 * @param string $inventory_item_id Shopify InventoryItem GID.
		 * @param string $location_id       Shopify Location GID.
		 * @param int    $delta             Quantity change (positive to add, negative to remove).
		 * @param string $reason            Reason for adjustment (optional). Defaults to 'correction'.
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function adjust_inventory( $inventory_item_id, $location_id, $delta, $reason = 'correction' ) {
			$gql_query = '
mutation AdjustInventory($input: InventoryAdjustQuantitiesInput!) {
  inventoryAdjustQuantities(input: $input) {
    inventoryAdjustmentGroup {
      createdAt reason
      changes { name delta quantityAfterChange item { id sku } location { id name } }
    }
    userErrors { field message }
  }
}';

			$variables = array(
				'input' => array(
					'reason'  => $reason,
					'name'    => 'available',
					'changes' => array(
						array(
							'inventoryItemId' => $inventory_item_id,
							'locationId'      => $location_id,
							'delta'           => (int) $delta,
						),
					),
				),
			);

			return $this->graphql( $gql_query, $variables );
		}

		// ------------------------------------------------------------------ //
		// Convenience methods – shop info                                     //
		// ------------------------------------------------------------------ //

		/**
		 * Retrieve basic shop information.
		 *
		 * @since 1.0.0
		 *
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function get_shop_info() {
			$gql_query = '
query GetShopInfo {
  shop {
    id name email myshopifyDomain primaryDomain { host sslEnabled }
    currencyCode timezoneAbbreviation ianaTimezone
    plan { displayName }
    billingAddress { address1 city province zip country }
    shipsToCountries
    taxesIncluded
  }
}';
			return $this->graphql( $gql_query );
		}

		// ------------------------------------------------------------------ //
		// Bulk Operations — efficient large data exports                      //
		// ------------------------------------------------------------------ //

		/**
		 * Polling interval (seconds) between bulk operation status checks.
		 *
		 * @var int
		 */
		const BULK_POLL_INTERVAL = 2;

		/**
		 * Maximum time (seconds) to wait for a bulk operation to complete.
		 *
		 * @var int
		 */
		const BULK_MAX_WAIT = 300;

		/**
		 * Initiate a Shopify GraphQL Bulk Operation and wait for results.
		 *
		 * Bulk operations are the recommended way to export large data-sets
		 * (all products, all orders, etc.).  They use a flat per-operation
		 * cost instead of per-query-point costs, making them dramatically
		 * cheaper for full-store exports.  Results are returned as JSONL.
		 *
		 * @since 1.0.0
		 *
		 * @param string $query     GraphQL query to run as a bulk operation
		 *                          (use the `bulkOperationRunQuery` mutation).
		 * @param bool   $wait      Whether to poll until completion (default: true).
		 * @return array|WP_Error   Parsed result or WP_Error on failure.
		 */
		public function bulk_query( $query, $wait = true ) {
			$store_url    = $this->get_store_url();
			$access_token = $this->get_access_token();

			if ( empty( $store_url ) || empty( $access_token ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_bulk_missing_config',
					__( 'Shopify store URL and access token are required for bulk operations.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Step 1 — create the bulk operation.
			$mutation = 'mutation { bulkOperationRunQuery( query: """' . $query . '""" ) { bulkOperation { id status } userErrors { field message } } }';
			$result   = $this->graphql( $mutation );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! empty( $result['data']['bulkOperationRunQuery']['userErrors'] ) ) {
				$first_error = $result['data']['bulkOperationRunQuery']['userErrors'][0];
				return new WP_Error(
					'wp_mcp_ai_shopify_bulk_error',
					sprintf(
						/* translators: 1: field, 2: message */
						__( 'Bulk operation error on %1$s: %2$s', 'mcp-ai-wpoos-pro' ),
						$first_error['field'],
						$first_error['message']
					)
				);
			}

			$bulk_op_id = isset( $result['data']['bulkOperationRunQuery']['bulkOperation']['id'] )
				? $result['data']['bulkOperationRunQuery']['bulkOperation']['id']
				: '';

			if ( empty( $bulk_op_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_bulk_no_id',
					__( 'Bulk operation was created but no ID was returned.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( ! $wait ) {
				return array(
					'bulk_operation_id' => $bulk_op_id,
					'status'            => 'CREATED',
					'message'           => __( 'Bulk operation created. Poll for status using the bulk_operation_id.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Step 2 — poll until completed.
			$poll_query = 'query PollBulk($id: ID!) { node(id: $id) { ... on BulkOperation { id status errorCode objectCount url partialDataUrl } } }';
			$elapsed    = 0;
			$result_url = '';

			while ( $elapsed < self::BULK_MAX_WAIT ) {
				sleep( self::BULK_POLL_INTERVAL );
				$elapsed += self::BULK_POLL_INTERVAL;

				$poll_result = $this->graphql( $poll_query, array( 'id' => $bulk_op_id ) );

				if ( is_wp_error( $poll_result ) ) {
					return $poll_result;
				}

				$status = isset( $poll_result['data']['node']['status'] )
					? $poll_result['data']['node']['status']
					: 'RUNNING';

				if ( 'COMPLETED' === $status ) {
					$result_url = isset( $poll_result['data']['node']['url'] )
						? $poll_result['data']['node']['url']
						: '';
					break;
				}

				if ( 'FAILED' === $status ) {
					$error_code = isset( $poll_result['data']['node']['errorCode'] )
						? $poll_result['data']['node']['errorCode']
						: 'UNKNOWN';
					return new WP_Error(
						'wp_mcp_ai_shopify_bulk_failed',
						sprintf(
							/* translators: %s: error code */
							__( 'Bulk operation failed with error code: %s', 'mcp-ai-wpoos-pro' ),
							$error_code
						)
					);
				}

				// CANCELLED, EXPIRED, etc. — terminal but not success.
				if ( ! in_array( $status, array( 'CREATED', 'RUNNING' ), true ) ) {
					return new WP_Error(
						'wp_mcp_ai_shopify_bulk_terminal',
						sprintf(
							/* translators: %s: status */
							__( 'Bulk operation ended with status: %s', 'mcp-ai-wpoos-pro' ),
							$status
						)
					);
				}
			}

			if ( empty( $result_url ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_bulk_timeout',
					sprintf(
						/* translators: %d: seconds */
						__( 'Bulk operation did not complete within %d seconds.', 'mcp-ai-wpoos-pro' ),
						self::BULK_MAX_WAIT
					)
				);
			}

			// Step 3 — download and parse the JSONL result.
			$raw_response = wp_safe_remote_get( $result_url, array( 'timeout' => 60 ) );

			if ( is_wp_error( $raw_response ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_bulk_download_failed',
					/* translators: %s: error message */
					sprintf( __( 'Failed to download bulk operation result: %s', 'mcp-ai-wpoos-pro' ), $raw_response->get_error_message() )
				);
			}

			$body = wp_remote_retrieve_body( $raw_response );

			if ( strlen( $body ) > self::MAX_RESPONSE_SIZE ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_bulk_too_large',
					sprintf(
						/* translators: %d: size in MB */
						__( 'Bulk operation result exceeds the maximum allowed size of %d MB.', 'mcp-ai-wpoos-pro' ),
						(int) ( self::MAX_RESPONSE_SIZE / 1048576 )
					)
				);
			}

			// Parse JSONL: each line is a complete JSON object.
			$lines  = explode( "\n", trim( $body ) );
			$parsed = array();
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$item = json_decode( $line, true );
				if ( null !== $item ) {
					$parsed[] = $item;
				}
			}

			return array(
				'bulk_operation_id' => $bulk_op_id,
				'count'             => count( $parsed ),
				'items'             => $parsed,
			);
		}

		/**
		 * List locations configured for the store.
		 *
		 * @since 1.0.0
		 *
		 * @param int $first Number of locations to fetch. Default 10.
		 * @return array|WP_Error GraphQL response or error.
		 */
		public function get_locations( $first = 10 ) {
			$first     = max( 1, min( 50, absint( $first ) ) );
			$gql_query = '
query GetLocations($first: Int!) {
  locations(first: $first) {
    edges {
      node {
        id name isActive isPrimary address { address1 address2 city province zip country }
      }
    }
  }
}';
			return $this->graphql( $gql_query, array( 'first' => $first ) );
		}

		// ------------------------------------------------------------------ //
		// Catalog API helpers (global agentic commerce, JWT bearer auth)      //
		// ------------------------------------------------------------------ //

		/**
		 * Send a GET request to the Shopify Catalog API using a JWT bearer token.
		 *
		 * Automatically fetches (and caches) a token via get_catalog_token().
		 *
		 * @since 1.0.0
		 *
		 * @param string $path        Catalog API path, e.g. '/global/v2/search'.
		 * @param array  $query_args  URL query parameters (key => value).
		 * @return array|WP_Error Decoded response array or WP_Error on failure.
		 */
		public function catalog_request( $path, array $query_args = array() ) {
			$token = $this->get_catalog_token();

			if ( is_wp_error( $token ) ) {
				return $token;
			}

			$url = self::CATALOG_BASE_URL . '/' . ltrim( $path, '/' );
			if ( ! empty( $query_args ) ) {
				$url = add_query_arg( $query_args, $url );
			}

			$raw_response = wp_safe_remote_get(
				$url,
				array(
					'timeout' => self::DEFAULT_TIMEOUT,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
						'User-Agent'    => 'WP-MCP-AI-Pro/' . WP_MCP_AI_PRO_VERSION,
					),
				)
			);

			if ( is_wp_error( $raw_response ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_request_failed',
					/* translators: %s: error message */
					sprintf( __( 'Shopify Catalog API request failed: %s', 'mcp-ai-wpoos-pro' ), $raw_response->get_error_message() )
				);
			}

			$response_code = wp_remote_retrieve_response_code( $raw_response );
			$response_body = wp_remote_retrieve_body( $raw_response );

			if ( strlen( $response_body ) > self::MAX_RESPONSE_SIZE ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_response_too_large',
					__( 'Shopify Catalog API response exceeds the maximum allowed size.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 401 === $response_code ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_unauthorized',
					__( 'Shopify Catalog API access denied. The bearer token may have expired; please retry.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 404 === $response_code ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_not_found',
					__( 'Shopify Catalog API: the requested product was not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 429 === $response_code ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_rate_limited',
					__( 'Shopify Catalog API rate limit reached. Please retry after a moment.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( $response_code < 200 || $response_code >= 300 ) {
				$detail = '';
				if ( ! empty( $response_body ) ) {
					$detail = ' ' . esc_html( mb_substr( wp_strip_all_tags( $response_body ), 0, 200 ) );
				}
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_http_error',
					/* translators: 1: HTTP status code, 2: optional response detail */
					sprintf( __( 'Shopify Catalog API returned HTTP %1$d.%2$s', 'mcp-ai-wpoos-pro' ), $response_code, $detail )
				);
			}

			$decoded = json_decode( $response_body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_invalid_json',
					__( 'Shopify Catalog API returned an invalid JSON response.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $decoded;
		}

		/**
		 * Search for products across the global Shopify Catalog.
		 *
		 * @since 1.0.0
		 *
		 * @param string $query   Search query string, e.g. 'wireless headphones'.
		 * @param int    $limit   Maximum number of results to return (1–10). Default 10.
		 * @param array  $filters Optional additional query parameters. Supported keys include:
		 *                        - shop_ids: Shopify Shop GID (gid://shopify/Shop/12345) or bare numeric ID,
		 *                          comma-separated for multiple stores. Filters results to specific merchants.
		 *                        - min_price, max_price: price range filters.
		 *                        - categories: comma-separated taxonomy category IDs.
		 *                        - country_code: ISO 3166-1 alpha-2 shipping destination, e.g. 'US'.
		 *                        - ships_from: ISO 3166-1 alpha-2 merchant location (ship-from country), e.g. 'US'.
		 * @return array|WP_Error Decoded response array or WP_Error on failure.
		 */
		public function catalog_search( $query, $limit = 10, array $filters = array() ) {
			$query_args = array_merge(
				array(
					'query' => $query,
					'limit' => max( 1, min( 10, absint( $limit ) ) ),
				),
				$filters
			);
			return $this->catalog_request( 'global/v2/search', $query_args );
		}

		/**
		 * Look up detailed product information using a Universal Product ID (UPID).
		 *
		 * @since 1.0.0
		 *
		 * @param string $upid Universal Product ID issued by the Shopify Catalog API.
		 * @return array|WP_Error Decoded response array or WP_Error on failure.
		 */
		public function catalog_lookup( $upid ) {
			return $this->catalog_request( 'global/v2/lookup', array( 'upid' => $upid ) );
		}

		/**
		 * Look up detailed product information using a Variant ID (VID).
		 *
		 * @since 1.0.0
		 *
		 * @param string $vid Variant ID issued by the Shopify Catalog API.
		 * @return array|WP_Error Decoded response array or WP_Error on failure.
		 */
		public function catalog_lookup_by_variant( $vid ) {
			return $this->catalog_request( 'global/v2/lookup/by-variant', array( 'vid' => $vid ) );
		}
	}
}
