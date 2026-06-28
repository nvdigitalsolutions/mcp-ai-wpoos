<?php
/**
 * Shopify Catalog Tool — search and look up products via the Shopify Catalog API.
 *
 * Uses the global agentic commerce Catalog API (https://shopify.dev/docs/agents/catalog)
 * which requires a catalog_api mode connection authenticated with shpss_ credentials
 * (client_id + client_secret exchanged for a short-lived JWT bearer token).
 *
 * Unlike the Admin GraphQL tools this tool does NOT require a store URL and works
 * across all Shopify merchants simultaneously.
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
 * Provides product discovery operations via the Shopify Catalog API.
 *
 * Supports natural-language search across the global Shopify catalog as well as
 * detailed product/variant lookups using Universal Product IDs (UPIDs) and
 * Variant IDs (VIDs) issued by the Catalog API.
 *
 * To restrict results to a specific store, pass the store's numeric Shopify shop ID
 * (or full GID like gid://shopify/Shop/12345) in the shop_ids parameter. The Catalog
 * API does not accept .myshopify.com domain names directly — find the numeric ID in
 * the Shopify admin URL or via the Admin GraphQL API (shop { id }).
 *
 * Authentication uses a JWT bearer token obtained by exchanging a shpss_ client
 * secret (stored in a catalog_api mode Remote Sites connection) via the Shopify
 * token endpoint.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Catalog implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;
	use WP_MCP_AI_Shopify_Smart_Search;

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
		return __( 'Search and look up products across the global Shopify Catalog API. Uses shpss_ credentials (catalog_api mode connection) for cross-merchant product discovery without requiring a store URL. Supports natural-language search, UPID lookup, and variant lookup.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Remote Sites connection ID for a Shopify catalog_api mode connection. If omitted, automatically uses the catalog_api Shopify connection configured for this assistant.', 'mcp-ai-wpoos-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: search (natural-language product search), lookup (retrieve product details by UPID), lookup_by_variant (retrieve variant details by VID).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'search', 'lookup', 'lookup_by_variant' ),
					'default'     => 'search',
				),
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Natural-language search query for the search action, e.g. "wireless noise-cancelling headphones".', 'mcp-ai-wpoos-pro' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return for the search action (1–10). Default: 10.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 10,
				),
				'upid'          => array(
					'type'        => 'string',
					'description' => __( 'Universal Product ID (UPID) returned by a previous search action. Required for the lookup action.', 'mcp-ai-wpoos-pro' ),
				),
				'vid'           => array(
					'type'        => 'string',
					'description' => __( 'Variant ID (VID) returned by a previous search or lookup action. Required for the lookup_by_variant action.', 'mcp-ai-wpoos-pro' ),
				),
				'min_price'     => array(
					'type'        => 'number',
					'description' => __( 'Minimum price filter for the search action (inclusive).', 'mcp-ai-wpoos-pro' ),
				),
				'max_price'     => array(
					'type'        => 'number',
					'description' => __( 'Maximum price filter for the search action (inclusive).', 'mcp-ai-wpoos-pro' ),
				),
				'categories'    => array(
					'type'        => 'string',
					'description' => __( 'Comma-separated category filter for the search action, e.g. "Electronics,Audio".', 'mcp-ai-wpoos-pro' ),
				),
				'country_code'  => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 alpha-2 country code to filter search results by merchant shipping destination, e.g. "US", "CA", "GB".', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 2,
					'maxLength'   => 2,
				),
				'shop_ids'      => array(
					'type'        => 'string',
					'description' => __( 'Limit search results to specific Shopify stores. Accepts a numeric shop ID (e.g. "12345"), a Shop GID (e.g. "gid://shopify/Shop/12345"), or a comma-separated list for multiple stores. .myshopify.com domain names are not accepted — use the numeric ID found in the Shopify admin URL.', 'mcp-ai-wpoos-pro' ),
				),
				'ships_from'    => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 alpha-2 country code to filter search results by merchant location (where the item ships from), e.g. "US", "GB", "DE".', 'mcp-ai-wpoos-pro' ),
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
			'requires-credentials', // Requires Shopify shpss_ catalog credentials.
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

		// Resolve the Shopify connection — auto-resolves from assistant context when not provided.
		// Catalog tool requires catalog_api mode.
		$connection_id = $this->resolve_shopify_connection_id( $arguments, $context, 'catalog_api' );
		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_no_manager', __( 'Remote Sites Manager is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			$available = $this->get_available_shopify_connections( $context, 'catalog_api' );
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
		if ( 'catalog_api' !== $api_mode ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_catalog_wrong_mode',
				__( 'This tool requires a Shopify connection configured in catalog_api mode (shpss_ credentials). The specified connection uses admin_api mode.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-client.php';
		}

		$client = new WP_MCP_AI_Shopify_Client( $connection_id );
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'search';

		switch ( $action ) {
			case 'search':
				return $this->handle_search( $client, $arguments );

			case 'lookup':
				return $this->handle_lookup( $client, $arguments );

			case 'lookup_by_variant':
				return $this->handle_lookup_by_variant( $client, $arguments );

			default:
				return new WP_Error( 'wp_mcp_ai_shopify_invalid_action', __( 'Invalid action specified. Use: search, lookup, lookup_by_variant.', 'mcp-ai-wpoos-pro' ) );
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

		$count = count( $products );

		$result = array(
			'success'  => true,
			'action'   => 'search',
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

		return array(
			'success' => true,
			'action'  => 'lookup',
			'upid'    => $upid,
			'product' => $response,
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

		return array(
			'success' => true,
			'action'  => 'lookup_by_variant',
			'vid'     => $vid,
			'variant' => $response,
		);
	}
}
