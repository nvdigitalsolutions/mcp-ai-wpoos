<?php
/**
 * Shopify Catalog Tool — live product search and lookup across all Shopify catalog modes.
 *
 * Serves three connection modes:
 *  - catalog_api (deprecated REST): cross-merchant search via the legacy
 *    Catalog API authenticated with shpss_ credentials (client_id + client_secret
 *    exchanged for a short-lived JWT bearer token).
 *  - storefront_catalog (UCP MCP, keyless): live search_catalog / lookup_catalog /
 *    get_product calls against the store's own /api/ucp/mcp endpoint.
 *  - global_catalog (UCP MCP, keyless): the same canonical tools against
 *    Shopify's cross-merchant endpoint at https://catalog.shopify.com.
 *
 * UCP catalog modes are live agent-query modes — UCP usage guidelines
 * prohibit caching catalog results or product images, so every call is
 * live and nothing is stored.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides live product discovery across the Shopify catalog modes.
 *
 * Supports natural-language search, batch identifier lookups (UPIDs, VIDs,
 * and GIDs), and full product details with variant selection (get_product).
 *
 * The mode is inherited from the resolved Remote Sites connection:
 * storefront_catalog and global_catalog connections run the keyless UCP
 * MCP tools (no credentials, live only), while catalog_api connections use
 * the deprecated REST Catalog API with a JWT bearer token obtained from
 * shpss_ client credentials. To restrict REST results to a specific store,
 * pass the store's numeric Shopify shop ID (or full GID like
 * gid://shopify/Shop/12345) in the shop_ids parameter — the Catalog API
 * does not accept .myshopify.com domain names directly.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Catalog implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;
	use WP_MCP_AI_Shopify_Smart_Search;
	use WP_MCP_AI_Tool_Product_Card;
	use WP_MCP_AI_Shopify_Product_Normalizers;

	/**
	 * Maximum number of markdown product cards rendered into the chat
	 * message for list/search results. The full product payload stays
	 * available in the structured response.
	 *
	 * @since 1.1.82
	 * @var int
	 */
	const MAX_CARD_COUNT = 10;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_catalog';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Catalog', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Live product search and lookup across Shopify catalog connections. Mode-aware: with a Storefront Catalog MCP connection (keyless UCP) this tool calls the store\'s own search_catalog, lookup_catalog, and get_product tools; with a Global Catalog MCP connection (keyless UCP) it searches products across all Shopify merchants; with the deprecated REST catalog_api connection it uses the legacy Catalog API. UCP usage guidelines prohibit caching catalog results, so every call is live and nothing is stored — do not use this tool\'s output to seed caches. Requires a catalog_api, storefront_catalog, or global_catalog mode connection. Every product result includes image URLs (media) and a chat-rendered product card with the product image.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Discovering products across Shopify catalogs with natural-language search, batch lookups, or variant details.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Managing a specific store\'s products, orders, or inventory; use shopify_products, shopify_orders, or shopify_inventory.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'shopify_products', 'shopify_orders', 'shopify_customers' ),
			'notes'           => __( 'Actions: search, lookup, lookup_by_variant, and get_product. UCP catalog modes are live only; never cache results.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for a Shopify catalog connection (catalog_api, storefront_catalog, or global_catalog mode). If omitted, automatically uses the catalog-capable Shopify connection configured for this assistant.', 'mcp-ai-wpoos-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: search (natural-language product search), lookup (retrieve products by UPID/VID/GID), lookup_by_variant (retrieve variant details by VID), get_product (full product details with optional variant selection — UCP catalog modes only).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'search', 'lookup', 'lookup_by_variant', 'get_product' ),
					'default'     => 'search',
				),
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Natural-language search query for the search action, e.g. "wireless noise-cancelling headphones".', 'mcp-ai-wpoos-pro' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return for the search action. Default: 10. Clamped per mode: 10 for the deprecated REST Catalog API, 250 for Storefront Catalog MCP, 50 for Global Catalog MCP.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 250,
				),
				'upid'          => array(
					'type'        => 'string',
					'description' => __( 'Universal Product ID (UPID) returned by a previous search action. Used by the lookup action — in UCP catalog modes this may be any product or variant GID (e.g. gid://shopify/Product/123).', 'mcp-ai-wpoos-pro' ),
				),
				'ids'           => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Batch of product or variant identifiers for the lookup action in UCP catalog modes (up to 10 for Storefront Catalog, 50 for Global Catalog). Identifiers may be GIDs or Shopify product URLs.', 'mcp-ai-wpoos-pro' ),
				),
				'vid'           => array(
					'type'        => 'string',
					'description' => __( 'Variant ID (VID) returned by a previous search or lookup action. Required for the lookup_by_variant action.', 'mcp-ai-wpoos-pro' ),
				),
				'product_id'    => array(
					'type'        => 'string',
					'description' => __( 'Product or variant identifier (GID) for the get_product action in UCP catalog modes.', 'mcp-ai-wpoos-pro' ),
				),
				'selected'      => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => __( 'Option selections for the get_product action, e.g. [{"name":"Color","label":"Blue"}]. Narrows the returned product to matching variants with availability signals.', 'mcp-ai-wpoos-pro' ),
				),
				'context'       => array(
					'type'                 => 'object',
					'description'          => __( 'Buyer context for UCP catalog queries (Storefront/Global Catalog modes). Optional keys: address_country (2-letter ISO country), language (BCP-47 code), currency (ISO 4217 code), intent (free-text buyer intent). Passed through to Shopify so results are localized and ranked to the buyer.', 'mcp-ai-wpoos-pro' ),
					'properties'           => array(
						'address_country' => array( 'type' => 'string' ),
						'language'        => array( 'type' => 'string' ),
						'currency'        => array( 'type' => 'string' ),
						'intent'          => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'cursor'        => array(
					'type'        => 'string',
					'description' => __( 'Opaque pagination cursor from a previous search response (UCP catalog modes). Pass it back to page forward through live results.', 'mcp-ai-wpoos-pro' ),
				),
				'min_price'     => array(
					'type'        => 'number',
					'description' => __( 'Minimum price filter for the search action (inclusive). REST Catalog API mode only.', 'mcp-ai-wpoos-pro' ),
				),
				'max_price'     => array(
					'type'        => 'number',
					'description' => __( 'Maximum price filter for the search action (inclusive). REST Catalog API mode only.', 'mcp-ai-wpoos-pro' ),
				),
				'categories'    => array(
					'type'        => 'string',
					'description' => __( 'Comma-separated category filter for the search action, e.g. "Electronics,Audio". REST Catalog API mode only.', 'mcp-ai-wpoos-pro' ),
				),
				'country_code'  => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 alpha-2 country code to filter search results by merchant shipping destination, e.g. "US", "CA", "GB". REST Catalog API mode only — in UCP catalog modes pass context.address_country instead.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 2,
					'maxLength'   => 2,
				),
				'shop_ids'      => array(
					'type'        => 'string',
					'description' => __( 'Limit search results to specific Shopify stores. Accepts a numeric shop ID (e.g. "12345"), a Shop GID (e.g. "gid://shopify/Shop/12345"), or a comma-separated list for multiple stores. .myshopify.com domain names are not accepted — use the numeric ID found in the Shopify admin URL. REST Catalog API mode only.', 'mcp-ai-wpoos-pro' ),
				),
				'ships_from'    => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 alpha-2 country code to filter search results by merchant location (where the item ships from), e.g. "US", "GB", "DE". REST Catalog API mode only.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 2,
					'maxLength'   => 2,
				),
				'smart_search'  => array(
					'type'        => 'boolean',
					'description' => __( 'Enable smart search (default: true). When the full query returns zero results, automatically decomposes it into smaller keyword groups and merges results. Set to false to disable progressive relaxation.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls to Shopify.
			'requires-credentials', // The deprecated REST catalog_api mode requires shpss_ credentials (UCP modes are keyless).
			'requires-capability',  // Requires WordPress user capabilities.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id  = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		$required_capability = apply_filters( 'wp_mcp_ai_shopify_catalog_required_capability', 'read', $context );

		// Allow guest users when the assistant is configured for public access.
		if ( ! $is_guest && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_forbidden', __( 'You do not have permission to use the Shopify Catalog tool.', 'mcp-ai-wpoos-pro' ) );
		}

		// Resolve the Shopify connection — auto-resolves from assistant context
		// when not provided. The catalog tool serves all three catalog-capable
		// modes (legacy REST plus the two keyless UCP modes).
		$catalog_modes = array( 'catalog_api', 'storefront_catalog', 'global_catalog' );
		$connection_id = $this->resolve_shopify_connection_id( $arguments, $context, $catalog_modes );
		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_no_manager', __( 'Remote Sites Manager is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			$available = $this->get_available_shopify_connections( $context, $catalog_modes );
			$conn_list = $this->format_available_connections_message( $available );
			return new WP_Error( 'wp_mcp_ai_shopify_connection_not_found', __( 'The specified connection was not found.', 'mcp-ai-wpoos-pro' ) . $conn_list );
		}
		if ( empty( $connection['connection_type'] ) || 'shopify' !== $connection['connection_type'] ) {
			return new WP_Error( 'wp_mcp_ai_shopify_wrong_type', __( 'The specified connection is not a Shopify connection.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_disabled', __( 'This Shopify connection is disabled.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! $this->is_shopify_connection_enabled_for_assistant( $connection_id, $context ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_not_enabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Shopify connection "%s" is not enabled for this assistant. Enable it in the assistant editor under Remote Site Connections.', 'mcp-ai-wpoos-pro' ),
					isset( $connection['name'] ) ? $connection['name'] : $connection_id
				)
			);
		}

		$api_mode = isset( $connection['shopify_api_mode'] ) ? $connection['shopify_api_mode'] : 'admin_api';
		if ( ! in_array( $api_mode, $catalog_modes, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_catalog_wrong_mode',
				__( 'This tool requires a Shopify connection configured in catalog_api (deprecated REST), storefront_catalog (UCP), or global_catalog (UCP) mode. The specified connection uses admin_api mode — use the shopify_products tool instead.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-client.php';
		}

		$client = new WP_MCP_AI_Shopify_Client( $connection_id );
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'search';

		switch ( $action ) {
			case 'search':
				if ( 'catalog_api' === $api_mode ) {
					return $this->handle_search( $client, $arguments );
				}
				return $this->handle_ucp_search( $client, $arguments, $api_mode );

			case 'lookup':
				if ( 'catalog_api' === $api_mode ) {
					return $this->handle_lookup( $client, $arguments );
				}
				return $this->handle_ucp_lookup( $client, $arguments, $api_mode );

			case 'lookup_by_variant':
				if ( 'catalog_api' === $api_mode ) {
					return $this->handle_lookup_by_variant( $client, $arguments );
				}
				return $this->handle_ucp_lookup_by_variant( $client, $arguments, $api_mode );

			case 'get_product':
				if ( 'catalog_api' === $api_mode ) {
					return new WP_Error(
						'wp_mcp_ai_shopify_catalog_get_product_not_supported',
						__( 'The get_product action is available in the UCP catalog modes (storefront_catalog, global_catalog). Use lookup with a UPID in catalog_api mode.', 'mcp-ai-wpoos-pro' )
					);
				}
				return $this->handle_ucp_get_product( $client, $arguments, $api_mode );

			default:
				return new WP_Error( 'wp_mcp_ai_shopify_invalid_action', __( 'Invalid action specified. Use: search, lookup, lookup_by_variant, get_product.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle the search action.
	 *
	 * Calls GET /global/v2/search with the provided query and optional filters.
	 * When the initial query returns zero results and the query has enough tokens,
	 * automatically decomposes the query into smaller keyword groups and merges
	 * the results (progressive query relaxation).
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client instance.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_search( $client, array $arguments ) {
		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		if ( empty( $query ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_catalog_missing_query', __( 'query is required for the search action.', 'mcp-ai-wpoos-pro' ) );
		}

		$limit = isset( $arguments['limit'] ) ? max( 1, min( 10, absint( $arguments['limit'] ) ) ) : 10;

		$filters = $this->build_catalog_filters( $arguments );
		if ( is_wp_error( $filters ) ) {
			return $filters;
		}

		// --- Primary search: try the full original query first. ---
		$response = $client->catalog_search( $query, $limit, $filters );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$products = isset( $response['products'] ) ? $response['products'] : array();

		// --- Progressive relaxation: decompose when no results. ---
		$smart_search = ! isset( $arguments['smart_search'] ) || ! empty( $arguments['smart_search'] );
		$decomposed   = false;

		if ( empty( $products ) && $smart_search && $this->should_decompose_query( $query ) ) {
			$tokens      = $this->extract_search_tokens( $query );
			$sub_queries = $this->generate_sub_queries( $tokens, $query );

			if ( ! empty( $sub_queries ) ) {
				$result_sets = array();

				foreach ( $sub_queries as $sub_query ) {
					$sub_response = $client->catalog_search( $sub_query, $limit, $filters );

					if ( is_wp_error( $sub_response ) ) {
						continue; // Skip failed sub-queries but keep trying.
					}

					$sub_products = isset( $sub_response['products'] ) ? $sub_response['products'] : array();
					if ( ! empty( $sub_products ) ) {
						$result_sets[] = $sub_products;
					}
				}

				if ( ! empty( $result_sets ) ) {
					$products   = $this->merge_and_rank_products(
						$result_sets,
						function ( $product ) {
							// Catalog API products use 'upid' as the unique identifier.
							if ( isset( $product['upid'] ) ) {
								return $product['upid'];
							}
							if ( isset( $product['id'] ) ) {
								return $product['id'];
							}
							return isset( $product['title'] ) ? $product['title'] : '';
						},
						$limit
					);
					$decomposed = true;
				}
			}
		}

		$count      = count( $products );
		$normalized = array_map( array( $this, 'normalize_catalog_product' ), $products );
		$message    = $this->format_catalog_cards_message( $normalized );
		if ( empty( $message ) ) {
			$message = sprintf(
				/* translators: %d: number of results */
				__( 'Found %d product(s) in the Shopify Catalog.', 'mcp-ai-wpoos-pro' ),
				$count
			);
		}

		$result = array(
			'success'  => true,
			'action'   => 'search',
			'message'  => $message,
			'query'    => $query,
			'count'    => $count,
			'products' => $products,
			'raw'      => $response,
		);

		if ( $decomposed ) {
			$result['smart_search'] = true;
			$result['note']         = sprintf(
				/* translators: %1$d: number of results, %2$s: original query */
				__( 'The original query "%2$s" returned 0 results. Smart search decomposed the query into smaller keywords and found %1$d product(s).', 'mcp-ai-wpoos-pro' ),
				$count,
				$query
			);
		}

		return $result;
	}

	/**
	 * Build catalog search filters from tool arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Filters array or error on validation failure.
	 */
	protected function build_catalog_filters( array $arguments ) {
		$filters = array();

		if ( isset( $arguments['min_price'] ) && is_numeric( $arguments['min_price'] ) ) {
			$filters['min_price'] = (float) $arguments['min_price'];
		}
		if ( isset( $arguments['max_price'] ) && is_numeric( $arguments['max_price'] ) ) {
			$filters['max_price'] = (float) $arguments['max_price'];
		}
		if ( ! empty( $arguments['categories'] ) ) {
			$filters['categories'] = sanitize_text_field( $arguments['categories'] );
		}
		if ( ! empty( $arguments['country_code'] ) ) {
			$country_code = strtoupper( sanitize_text_field( $arguments['country_code'] ) );
			if ( ! preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
				return new WP_Error( 'wp_mcp_ai_shopify_catalog_invalid_country_code', __( 'country_code must be a 2-letter ISO 3166-1 alpha-2 code, e.g. "US", "CA", "GB".', 'mcp-ai-wpoos-pro' ) );
			}
			$filters['country_code'] = $country_code;
		}
		if ( ! empty( $arguments['shop_ids'] ) ) {
			$filters['shop_ids'] = sanitize_text_field( $arguments['shop_ids'] );
		}
		if ( ! empty( $arguments['ships_from'] ) ) {
			$ships_from = strtoupper( sanitize_text_field( $arguments['ships_from'] ) );
			if ( ! preg_match( '/^[A-Z]{2}$/', $ships_from ) ) {
				return new WP_Error( 'wp_mcp_ai_shopify_catalog_invalid_ships_from', __( 'ships_from must be a 2-letter ISO 3166-1 alpha-2 code, e.g. "US", "GB".', 'mcp-ai-wpoos-pro' ) );
			}
			$filters['ships_from'] = $ships_from;
		}

		return $filters;
	}

	/**
	 * Handle the lookup action.
	 *
	 * Calls GET /global/v2/lookup with the provided UPID to retrieve full product details.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client instance.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_lookup( $client, array $arguments ) {
		$upid = isset( $arguments['upid'] ) ? sanitize_text_field( $arguments['upid'] ) : '';
		if ( empty( $upid ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_catalog_missing_upid', __( 'upid (Universal Product ID) is required for the lookup action.', 'mcp-ai-wpoos-pro' ) );
		}

		$response = $client->catalog_lookup( $upid );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$product = $this->normalize_catalog_product( is_array( $response ) ? $response : array() );
		$message = __( 'Product retrieved successfully.', 'mcp-ai-wpoos-pro' );
		if ( ! empty( $product['title'] ) || ! empty( $product['images'] ) ) {
			$message = $this->format_single_product_card( $product, 'shopify', array( 'max_description' => 200 ) );
		}

		return array(
			'success' => true,
			'action'  => 'lookup',
			'upid'    => $upid,
			'product' => $response,
			'message' => $message,
		);
	}

	/**
	 * Handle the lookup_by_variant action.
	 *
	 * Calls GET /global/v2/lookup/by-variant with the provided VID to retrieve
	 * full variant details including its parent product.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client instance.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_lookup_by_variant( $client, array $arguments ) {
		$vid = isset( $arguments['vid'] ) ? sanitize_text_field( $arguments['vid'] ) : '';
		if ( empty( $vid ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_catalog_missing_vid', __( 'vid (Variant ID) is required for the lookup_by_variant action.', 'mcp-ai-wpoos-pro' ) );
		}

		$response = $client->catalog_lookup_by_variant( $vid );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$normalized = $this->normalize_catalog_product( is_array( $response ) ? $response : array() );

		$result = array(
			'success' => true,
			'action'  => 'lookup_by_variant',
			'vid'     => $vid,
			'variant' => $response,
		);

		if ( ! empty( $normalized['title'] ) || ! empty( $normalized['images'] ) ) {
			$result['message'] = $this->format_single_product_card( $normalized, 'shopify', array( 'max_description' => 200 ) );
		}

		return $result;
	}

	// ------------------------------------------------------------------ //
	// UCP catalog handlers (keyless Storefront/Global Catalog MCP)        //
	//
	// UCP usage guidelines prohibit caching catalog results or product    //
	// images, so every call below is live and nothing is stored.          //
	// ------------------------------------------------------------------ //

	/**
	 * Handle the search action for UCP catalog modes.
	 *
	 * Calls the canonical UCP search_catalog tool on the storefront's own
	 * endpoint (storefront_catalog) or Shopify's cross-merchant endpoint
	 * (global_catalog). When the initial query returns zero results and the
	 * query has enough tokens, automatically decomposes the query into
	 * smaller keyword groups and merges the results (progressive query
	 * relaxation) — all live.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client instance.
	 * @param array                    $arguments Tool arguments.
	 * @param string                   $api_mode  'storefront_catalog' or 'global_catalog'.
	 * @return array|WP_Error
	 */
	protected function handle_ucp_search( $client, array $arguments, $api_mode ) {
		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		if ( empty( $query ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_catalog_missing_query', __( 'query is required for the search action.', 'mcp-ai-wpoos-pro' ) );
		}

		$is_global = 'global_catalog' === $api_mode;
		$max_limit = $is_global ? 50 : 250;
		$limit     = isset( $arguments['limit'] ) ? max( 1, min( $max_limit, absint( $arguments['limit'] ) ) ) : 10;
		$context   = $this->build_ucp_context( $arguments );
		$cursor    = isset( $arguments['cursor'] ) ? sanitize_text_field( $arguments['cursor'] ) : '';

		// --- Primary search: try the full original query first. ---
		$response = $this->ucp_search_call( $client, $is_global, $query, $limit, $context, $cursor );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$products = $this->extract_ucp_products( $response );

		// --- Progressive relaxation: decompose when no results. ---
		$smart_search = ! isset( $arguments['smart_search'] ) || ! empty( $arguments['smart_search'] );
		$decomposed   = false;

		if ( empty( $products ) && $smart_search && $this->should_decompose_query( $query ) ) {
			$tokens      = $this->extract_search_tokens( $query );
			$sub_queries = $this->generate_sub_queries( $tokens, $query );

			if ( ! empty( $sub_queries ) ) {
				$result_sets = array();

				foreach ( $sub_queries as $sub_query ) {
					$sub_response = $this->ucp_search_call( $client, $is_global, $sub_query, $limit, $context, '' );

					if ( is_wp_error( $sub_response ) ) {
						continue; // Skip failed sub-queries but keep trying.
					}

					$sub_products = $this->extract_ucp_products( $sub_response );
					if ( ! empty( $sub_products ) ) {
						$result_sets[] = $sub_products;
					}
				}

				if ( ! empty( $result_sets ) ) {
					$products   = $this->merge_and_rank_products(
						$result_sets,
						function ( $product ) {
							// UCP products use a GID (gid://shopify/Product/…) as the unique identifier.
							if ( isset( $product['id'] ) ) {
								return $product['id'];
							}
							return isset( $product['title'] ) ? $product['title'] : '';
						},
						$limit
					);
					$decomposed = true;
				}
			}
		}

		$count      = count( $products );
		$normalized = array_map( array( $this, 'normalize_ucp_product' ), $products );
		$message    = $this->format_catalog_cards_message( $normalized );
		if ( empty( $message ) ) {
			$message = sprintf(
				/* translators: %d: number of results */
				__( 'Found %d product(s) in the Shopify catalog.', 'mcp-ai-wpoos-pro' ),
				$count
			);
		}

		$result = array(
			'success'    => true,
			'action'     => 'search',
			'mode'       => $api_mode,
			'live'       => true, // UCP usage guidelines: live query, nothing cached.
			'message'    => $message,
			'query'      => $query,
			'count'      => $count,
			'products'   => $products,
			'pagination' => $this->extract_ucp_pagination( $response ),
			'raw'        => $response,
		);

		if ( $decomposed ) {
			$result['smart_search'] = true;
			$result['note']         = sprintf(
				/* translators: %1$d: number of results, %2$s: original query */
				__( 'The original query "%2$s" returned 0 results. Smart search decomposed the query into smaller keywords and found %1$d product(s).', 'mcp-ai-wpoos-pro' ),
				$count,
				$query
			);
		}

		return $result;
	}

	/**
	 * Handle the lookup action for UCP catalog modes.
	 *
	 * Calls the canonical UCP lookup_catalog tool with a batch of product or
	 * variant identifiers. Unresolved identifiers surface in not_found per
	 * the UCP spec.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client instance.
	 * @param array                    $arguments Tool arguments.
	 * @param string                   $api_mode  'storefront_catalog' or 'global_catalog'.
	 * @return array|WP_Error
	 */
	protected function handle_ucp_lookup( $client, array $arguments, $api_mode ) {
		$ids = array();

		if ( isset( $arguments['ids'] ) && is_array( $arguments['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'sanitize_text_field', $arguments['ids'] ) ) );
		} elseif ( ! empty( $arguments['upid'] ) ) {
			$ids = array( sanitize_text_field( $arguments['upid'] ) );
		}

		if ( empty( $ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_catalog_missing_ids',
				__( 'ids (array) or upid (single identifier) is required for the lookup action in UCP catalog modes.', 'mcp-ai-wpoos-pro' )
			);
		}

		$context  = $this->build_ucp_context( $arguments );
		$response = 'global_catalog' === $api_mode
			? $client->global_catalog_lookup( $ids, $context )
			: $client->storefront_catalog_lookup( $ids, $context );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$products   = $this->extract_ucp_products( $response );
		$count      = count( $products );
		$normalized = array_map( array( $this, 'normalize_ucp_product' ), $products );
		$message    = $this->format_catalog_cards_message( $normalized );
		if ( empty( $message ) ) {
			$message = sprintf(
				/* translators: %d: number of results */
				__( 'Resolved %d identifier(s) in the Shopify catalog.', 'mcp-ai-wpoos-pro' ),
				$count
			);
		}

		$result = array(
			'success'  => true,
			'action'   => 'lookup',
			'mode'     => $api_mode,
			'live'     => true, // UCP usage guidelines: live query, nothing cached.
			'message'  => $message,
			'ids'      => $ids,
			'count'    => $count,
			'products' => $products,
			'raw'      => $response,
		);

		$not_found = $this->extract_ucp_not_found( $response );
		if ( ! empty( $not_found ) ) {
			$result['not_found'] = $not_found;
		}

		return $result;
	}

	/**
	 * Handle the lookup_by_variant action for UCP catalog modes.
	 *
	 * The UCP spec has no separate by-variant endpoint: variants are first
	 * class identifiers in lookup_catalog, so the VID is resolved through
	 * the same canonical tool and the matching product is returned.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client instance.
	 * @param array                    $arguments Tool arguments.
	 * @param string                   $api_mode  'storefront_catalog' or 'global_catalog'.
	 * @return array|WP_Error
	 */
	protected function handle_ucp_lookup_by_variant( $client, array $arguments, $api_mode ) {
		$vid = isset( $arguments['vid'] ) ? sanitize_text_field( $arguments['vid'] ) : '';
		if ( empty( $vid ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_catalog_missing_vid', __( 'vid (Variant ID) is required for the lookup_by_variant action.', 'mcp-ai-wpoos-pro' ) );
		}

		$response = 'global_catalog' === $api_mode
			? $client->global_catalog_lookup( array( $vid ), $this->build_ucp_context( $arguments ) )
			: $client->storefront_catalog_lookup( array( $vid ), $this->build_ucp_context( $arguments ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$products = $this->extract_ucp_products( $response );
		$variant  = array();
		$parent   = array();

		// The matching variant lives inside the resolved product's variants array.
		foreach ( $products as $product ) {
			if ( empty( $product['variants'] ) || ! is_array( $product['variants'] ) ) {
				continue;
			}
			foreach ( $product['variants'] as $candidate ) {
				if ( isset( $candidate['id'] ) && $candidate['id'] === $vid ) {
					$variant = array_merge( $candidate, array( 'product_id' => isset( $product['id'] ) ? $product['id'] : '' ) );
					$parent  = $product;
					break 2;
				}
			}
		}

		if ( empty( $variant ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_variant_not_found',
				__( 'The requested variant was not found in the Shopify catalog.', 'mcp-ai-wpoos-pro' )
			);
		}

		$result = array(
			'success' => true,
			'action'  => 'lookup_by_variant',
			'mode'    => $api_mode,
			'live'    => true, // UCP usage guidelines: live query, nothing cached.
			'vid'     => $vid,
			'variant' => $variant,
			'raw'     => $response,
		);

		if ( ! empty( $parent ) ) {
			$normalized = $this->normalize_ucp_product( $parent );
			if ( ! empty( $normalized['title'] ) || ! empty( $normalized['images'] ) ) {
				$result['message'] = $this->format_single_product_card( $normalized, 'shopify', array( 'max_description' => 200 ) );
			}
		}

		return $result;
	}

	/**
	 * Handle the get_product action for UCP catalog modes.
	 *
	 * Calls the canonical UCP get_product tool. Optional `selected` option
	 * selections narrow the variants and add availability/exists signals.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client instance.
	 * @param array                    $arguments Tool arguments.
	 * @param string                   $api_mode  'storefront_catalog' or 'global_catalog'.
	 * @return array|WP_Error
	 */
	protected function handle_ucp_get_product( $client, array $arguments, $api_mode ) {
		$id = isset( $arguments['product_id'] ) ? sanitize_text_field( $arguments['product_id'] ) : '';
		if ( empty( $id ) && ! empty( $arguments['upid'] ) ) {
			$id = sanitize_text_field( $arguments['upid'] );
		}

		if ( empty( $id ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_catalog_missing_product_id',
				__( 'product_id (product or variant GID) is required for the get_product action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$selected = isset( $arguments['selected'] ) && is_array( $arguments['selected'] )
			? array_slice( $arguments['selected'], 0, 25 )
			: array();

		$response = 'global_catalog' === $api_mode
			? $client->global_catalog_get_product( $id, $selected, $this->build_ucp_context( $arguments ) )
			: $client->storefront_catalog_get_product( $id, $selected, $this->build_ucp_context( $arguments ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$product = $this->extract_ucp_product( $response );

		if ( empty( $product ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_product_not_found',
				__( 'The requested product was not found in the Shopify catalog.', 'mcp-ai-wpoos-pro' )
			);
		}

		$normalized = $this->normalize_ucp_product( $product );
		$message    = __( 'Product retrieved successfully.', 'mcp-ai-wpoos-pro' );
		if ( ! empty( $normalized['title'] ) || ! empty( $normalized['images'] ) ) {
			$message = $this->format_single_product_card( $normalized, 'shopify', array( 'max_description' => 200 ) );
		}

		return array(
			'success' => true,
			'action'  => 'get_product',
			'mode'    => $api_mode,
			'live'    => true, // UCP usage guidelines: live query, nothing cached.
			'message' => $message,
			'product' => $product,
			'raw'     => $response,
		);
	}

	/**
	 * Extract the product list from a UCP MCP tool response.
	 *
	 * UCP results wrap products inside result.structuredContent.products.
	 *
	 * @param array $response Decoded UCP MCP result.
	 * @return array Product items.
	 */
	protected function extract_ucp_products( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}
		$content  = isset( $response['structuredContent'] ) ? $response['structuredContent'] : $response;
		$products = isset( $content['products'] ) ? $content['products'] : array();
		return is_array( $products ) ? $products : array();
	}

	/**
	 * Extract the single product object from a UCP get_product response.
	 *
	 * The get_product tool returns result.structuredContent.product; this
	 * helper falls back to the first product entry for lookup-style shapes.
	 *
	 * @param array $response Decoded UCP MCP result.
	 * @return array Product item or empty array.
	 */
	protected function extract_ucp_product( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}
		$content = isset( $response['structuredContent'] ) ? $response['structuredContent'] : $response;
		if ( isset( $content['product'] ) && is_array( $content['product'] ) ) {
			return $content['product'];
		}
		$products = isset( $content['products'] ) ? $content['products'] : array();
		return is_array( $products ) && ! empty( $products ) ? $products[0] : array();
	}

	/**
	 * Extract the UCP pagination envelope from a search response.
	 *
	 * @param array $response Decoded UCP MCP result.
	 * @return array Pagination object or empty array.
	 */
	protected function extract_ucp_pagination( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}
		$content    = isset( $response['structuredContent'] ) ? $response['structuredContent'] : $response;
		$pagination = isset( $content['pagination'] ) ? $content['pagination'] : array();
		return is_array( $pagination ) ? $pagination : array();
	}

	/**
	 * Extract unresolved identifiers from a UCP lookup response.
	 *
	 * @param array $response Decoded UCP MCP result.
	 * @return array not_found entries or empty array.
	 */
	protected function extract_ucp_not_found( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}
		$content   = isset( $response['structuredContent'] ) ? $response['structuredContent'] : $response;
		$not_found = isset( $content['not_found'] ) ? $content['not_found'] : array();
		return is_array( $not_found ) ? $not_found : array();
	}

	/**
	 * Build the UCP buyer-context object from tool arguments.
	 *
	 * Only the four UCP context signals are forwarded (address_country,
	 * language, currency, intent) — everything else is ignored so agent
	 * input cannot inject arbitrary keys into the upstream request.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array UCP context object.
	 */
	protected function build_ucp_context( array $arguments ) {
		$context = array();

		if ( isset( $arguments['context'] ) && is_array( $arguments['context'] ) ) {
			$allowed = array( 'address_country', 'language', 'currency', 'intent' );
			foreach ( $allowed as $key ) {
				if ( ! empty( $arguments['context'][ $key ] ) ) {
					$context[ $key ] = sanitize_text_field( $arguments['context'][ $key ] );
				}
			}
		}

		return $context;
	}

	/**
	 * Dispatch a live UCP search call to the matching client method.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client   Shopify client instance.
	 * @param bool                     $is_global True for Global Catalog, false for Storefront Catalog.
	 * @param string                   $query    Free-text query.
	 * @param int                      $limit    Result limit.
	 * @param array                    $context  UCP buyer context.
	 * @param string                   $cursor   Optional pagination cursor.
	 * @return array|WP_Error Decoded UCP MCP result or WP_Error.
	 */
	protected function ucp_search_call( $client, $is_global, $query, $limit, array $context, $cursor ) {
		if ( $is_global ) {
			return $client->global_catalog_search( $query, $limit, $context, array(), $cursor );
		}
		return $client->storefront_catalog_search( $query, $limit, $context, $cursor );
	}

	/**
	 * Format capped markdown product cards for catalog list/search results.
	 *
	 * Keeps the chat message bounded for large result sets — at most
	 * MAX_CARD_COUNT cards are rendered — while the full product payload
	 * stays available in the structured response. Returns '' when there
	 * is nothing to render.
	 *
	 * @param array $products Normalized Shopify product arrays.
	 * @return string Markdown cards message.
	 */
	protected function format_catalog_cards_message( array $products ) {
		if ( empty( $products ) ) {
			return '';
		}

		$capped    = array_slice( $products, 0, self::MAX_CARD_COUNT );
		$message   = $this->format_product_cards( $capped, 'shopify' );
		$remaining = count( $products ) - count( $capped );

		if ( $remaining > 0 ) {
			$message .= "\n\n" . sprintf(
				/* translators: 1: number of cards shown, 2: number of products omitted from the cards */
				__( '*Showing the first %1$d product card(s) — %2$d more product(s) in the structured results.*', 'mcp-ai-wpoos-pro' ),
				count( $capped ),
				$remaining
			);
		}

		return $message;
	}
}
