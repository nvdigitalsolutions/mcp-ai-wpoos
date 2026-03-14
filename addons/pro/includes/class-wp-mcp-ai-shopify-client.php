<?php
/**
 * Shopify Admin GraphQL API client.
 *
 * Provides a wrapper around the Shopify Admin GraphQL API (https://shopify.dev/docs/api/admin-graphql).
 * Authentication uses the X-Shopify-Access-Token header populated from the Remote Sites
 * connection credential (api_key field, encrypted at rest).
 *
 * @package WP_MCP_AI_Pro
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

		// ------------------------------------------------------------------ //
		//  Static helpers                                                       //
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
		//  Credential helpers                                                  //
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

		// ------------------------------------------------------------------ //
		//  GraphQL helpers                                                     //
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
					'Content-Type'            => 'application/json',
					'X-Shopify-Access-Token'  => $access_token,
					'Accept'                  => 'application/json',
					'User-Agent'              => 'WP-MCP-AI-Pro/' . WP_MCP_AI_PRO_VERSION,
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

			return $decoded;
		}

		// ------------------------------------------------------------------ //
		//  Convenience methods – products                                      //
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
		//  Convenience methods – orders                                        //
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
		//  Convenience methods – customers                                     //
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
		//  Convenience methods – inventory                                     //
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
		//  Convenience methods – shop info                                     //
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
	}
}
