<?php
/**
 * Shopify Products Tool — manage products on a connected Shopify store via the Admin GraphQL API.
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
 * Provides product management operations for Shopify stores.
 *
 * Supports listing, searching, retrieving, creating, and updating products
 * through the Shopify Admin GraphQL API (2025-01+).
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Shopify_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;
	use WP_MCP_AI_Tool_Product_Card;
	use WP_MCP_AI_Shopify_Smart_Search;
	use WP_MCP_AI_Shopify_Product_Normalizers;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'shopify_products';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Shopify Products', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manage products on a connected Shopify store. Mode-aware: with an admin_api connection this tool lists, searches, retrieves, creates, and updates products via the Admin GraphQL API. With a Storefront Catalog MCP or Global Catalog MCP connection (keyless UCP, live agent-query mode) it performs live catalog queries only — search_catalog, lookup_catalog, get_product — and rejects create/update with a hint; UCP usage guidelines prohibit caching catalog results, so every call is live and nothing is stored. With the deprecated REST catalog_api connection it performs live Catalog API search and lookup. Every product result includes image URLs (images[]) and a chat-rendered product card with the product image.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Listing, searching, creating, or updating products on a connected Shopify store via the Admin API.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Live cross-merchant catalog discovery; use shopify_catalog, and shopify_inventory for stock levels.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'shopify_catalog', 'shopify_inventory', 'shopify_orders' ),
			'notes'           => __( 'Actions: list, get, create, update, search. Admin writes need an admin_api connection; UCP catalog modes stay read-only.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'   => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for the Shopify store. If omitted, automatically uses the Shopify connection configured for this assistant.', 'mcp-ai-wpoos-pro' ),
				),
				'action'          => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list, get, create, update, search.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'get', 'create', 'update', 'search' ),
					'default'     => 'list',
				),
				'product_id'      => array(
					'type'        => 'string',
					'description' => __( 'Shopify product GID (e.g. gid://shopify/Product/123456789) for get/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'first'           => array(
					'type'        => 'integer',
					'description' => __( 'Number of products to return (1–250). Default: 10.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 250,
				),
				'after'           => array(
					'type'        => 'string',
					'description' => __( 'Pagination cursor (endCursor from a previous Admin API response, or the opaque UCP pagination cursor in Storefront/Global Catalog modes).', 'mcp-ai-wpoos-pro' ),
				),
				'query'           => array(
					'type'        => 'string',
					'description' => __( 'Shopify search query string for list/search actions. Supports Shopify filter syntax, e.g. "status:active vendor:Acme".', 'mcp-ai-wpoos-pro' ),
				),
				'title'           => array(
					'type'        => 'string',
					'description' => __( 'Product title for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'body_html'       => array(
					'type'        => 'string',
					'description' => __( 'Product description HTML for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'vendor'          => array(
					'type'        => 'string',
					'description' => __( 'Product vendor for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'product_type'    => array(
					'type'        => 'string',
					'description' => __( 'Product type for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'            => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Array of tags for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'status'          => array(
					'type'        => 'string',
					'description' => __( 'Product status: ACTIVE, DRAFT, or ARCHIVED.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ACTIVE', 'DRAFT', 'ARCHIVED' ),
				),
				'variants'        => array(
					'type'        => 'array',
					'description' => __( 'Array of variant input objects for create/update. Each variant can include: price, compareAtPrice, sku, barcode, inventoryQuantity, weight, weightUnit, options.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'object' ),
				),
				'seo_title'       => array(
					'type'        => 'string',
					'description' => __( 'SEO page title for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'seo_description' => array(
					'type'        => 'string',
					'description' => __( 'SEO meta description for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'smart_search'    => array(
					'type'        => 'boolean',
					'description' => __( 'Enable smart search for list/search actions (default: true). When the full query returns zero results, automatically decomposes it into smaller keyword groups and merges results. Set to false to disable.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'context'         => array(
					'type'                 => 'object',
					'description'          => __( 'Buyer context for live UCP catalog queries (Storefront/Global Catalog modes). Optional keys: address_country (2-letter ISO country, e.g. "US"), language (BCP-47 code, e.g. "en"), currency (ISO 4217 code, e.g. "USD"), intent (free-text buyer intent, e.g. "looking for a birthday gift"). Passed through to Shopify so results are localized and ranked to the buyer. Ignored in admin_api mode.', 'mcp-ai-wpoos-pro' ),
					'properties'           => array(
						'address_country' => array( 'type' => 'string' ),
						'language'        => array( 'type' => 'string' ),
						'currency'        => array( 'type' => 'string' ),
						'intent'          => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
				'selected'        => array(
					'type'        => 'array',
					'description' => __( 'Option selections for the get action in UCP catalog modes, e.g. [{"name":"Color","label":"Blue"}]. Narrows the returned product to matching variants with availability signals.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'object' ),
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
			'requires-credentials', // Requires Shopify API credentials.
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
		$action   = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		// Telegram Mini App storefront contexts create users with the
		// subscriber role which lacks edit_posts.  Allow read-only product
		// operations with just the "read" capability so the storefront
		// works for all TMA visitors.
		$is_tma                 = isset( $context['source'] ) && 'telegram_mini_app' === $context['source'];
		$tma_storefront_actions = array( 'list', 'get', 'search', 'collections' );
		$default_cap            = ( $is_tma && in_array( $action, $tma_storefront_actions, true ) )
			? 'read'
			: 'edit_posts';

		$required_capability = apply_filters( 'wp_mcp_ai_shopify_products_required_capability', $default_cap, $context );

		// Allow guest users when the assistant is configured for public access.
		if ( ! $is_guest && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_forbidden', __( 'You do not have permission to manage Shopify products.', 'mcp-ai-wpoos-pro' ) );
		}

		// Resolve the Shopify connection — auto-resolves from assistant context when not provided.
		$connection_id = $this->resolve_shopify_connection_id( $arguments, $context );
		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_no_manager', __( 'Remote Sites Manager is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			$available = $this->get_available_shopify_connections( $context );
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

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-client.php';
		}

		$client   = new WP_MCP_AI_Shopify_Client( $connection_id );
		$action   = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';
		$api_mode = $client->get_api_mode();

		// Catalog API mode — route read-only operations to the Catalog API
		// and reject write operations that are not supported.
		if ( 'catalog_api' === $api_mode ) {
			switch ( $action ) {
				case 'list':
				case 'search':
					return $this->handle_catalog_list( $client, $arguments, $connection );

				case 'get':
					return $this->handle_catalog_get( $client, $arguments );

				case 'create':
				case 'update':
					return new WP_Error(
						'wp_mcp_ai_shopify_catalog_read_only',
						__( 'Product creation and updates are not supported in catalog_api mode. Switch to an admin_api connection for write operations.', 'mcp-ai-wpoos-pro' )
					);

				default:
					return new WP_Error( 'wp_mcp_ai_shopify_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Global Catalog MCP mode — route read-only operations to the keyless
		// UCP Global Catalog endpoint and reject unsupported write operations.
		if ( 'global_catalog' === $api_mode ) {
			switch ( $action ) {
				case 'list':
				case 'search':
					return $this->handle_global_catalog_list( $client, $arguments, $connection );

				case 'get':
					return $this->handle_global_catalog_get( $client, $arguments );

				case 'create':
				case 'update':
					return new WP_Error(
						'wp_mcp_ai_shopify_global_catalog_read_only',
						__( 'Product creation and updates are not supported in global_catalog mode. Switch to an admin_api connection for write operations.', 'mcp-ai-wpoos-pro' )
					);

				default:
					return new WP_Error( 'wp_mcp_ai_shopify_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Storefront Catalog MCP mode — route read-only operations to the
		// keyless UCP Storefront Catalog endpoint on the store itself and
		// reject unsupported write operations.
		if ( 'storefront_catalog' === $api_mode ) {
			switch ( $action ) {
				case 'list':
				case 'search':
					return $this->handle_storefront_catalog_list( $client, $arguments, $connection );

				case 'get':
					return $this->handle_storefront_catalog_get( $client, $arguments );

				case 'create':
				case 'update':
					return new WP_Error(
						'wp_mcp_ai_shopify_storefront_catalog_read_only',
						__( 'Product creation and updates are not supported in storefront_catalog mode. UCP catalog modes are live agent-query modes: switch to an admin_api connection for write operations.', 'mcp-ai-wpoos-pro' )
					);

				default:
					return new WP_Error( 'wp_mcp_ai_shopify_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		switch ( $action ) {
			case 'list':
			case 'search':
				return $this->handle_list( $client, $arguments );

			case 'get':
				return $this->handle_get( $client, $arguments );

			case 'create':
				return $this->handle_create( $client, $arguments );

			case 'update':
				return $this->handle_update( $client, $arguments );

			default:
				return new WP_Error( 'wp_mcp_ai_shopify_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Handle list/search action.
	 *
	 * When the initial query returns zero results and the query has enough
	 * tokens, automatically decomposes the query into smaller keyword groups
	 * and merges the results (progressive query relaxation).
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_list( $client, array $arguments ) {
		$first = isset( $arguments['first'] ) ? max( 1, min( 250, absint( $arguments['first'] ) ) ) : 10;
		$after = isset( $arguments['after'] ) ? sanitize_text_field( $arguments['after'] ) : '';
		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

		// --- Primary search: try the full original query first. ---
		$response = $client->get_products( $first, $after, $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$products = array();
		$edges    = isset( $response['data']['products']['edges'] ) ? $response['data']['products']['edges'] : array();

		foreach ( $edges as $edge ) {
			$node       = isset( $edge['node'] ) ? $edge['node'] : array();
			$products[] = $this->normalize_product( $node );
		}

		// --- Progressive relaxation: decompose when no results and query is present. ---
		$smart_search = ! isset( $arguments['smart_search'] ) || ! empty( $arguments['smart_search'] );
		$decomposed   = false;

		if ( empty( $products ) && ! empty( $query ) && $smart_search && $this->should_decompose_query( $query ) ) {
			$tokens      = $this->extract_search_tokens( $query );
			$sub_queries = $this->generate_sub_queries( $tokens, $query );

			if ( ! empty( $sub_queries ) ) {
				$result_sets = array();

				foreach ( $sub_queries as $sub_query ) {
					$sub_response = $client->get_products( $first, '', $sub_query );

					if ( is_wp_error( $sub_response ) ) {
						continue;
					}
					if ( isset( $sub_response['errors'] ) && ! empty( $sub_response['errors'] ) ) {
						continue;
					}

					$sub_edges    = isset( $sub_response['data']['products']['edges'] ) ? $sub_response['data']['products']['edges'] : array();
					$sub_products = array();
					foreach ( $sub_edges as $edge ) {
						$node           = isset( $edge['node'] ) ? $edge['node'] : array();
						$sub_products[] = $this->normalize_product( $node );
					}
					if ( ! empty( $sub_products ) ) {
						$result_sets[] = $sub_products;
					}
				}

				if ( ! empty( $result_sets ) ) {
					$products   = $this->merge_and_rank_products(
						$result_sets,
						function ( $product ) {
							return isset( $product['id'] ) ? $product['id'] : '';
						},
						$first
					);
					$decomposed = true;
				}
			}
		}

		// Generate rich product cards for chat display.
		$cards_message = $this->format_product_cards( $products, 'shopify' );

		$result = array(
			'success'   => true,
			'message'   => ! empty( $cards_message ) ? $cards_message : sprintf(
				/* translators: %d: number of products */
				__( 'Retrieved %d product(s) from Shopify', 'mcp-ai-wpoos-pro' ),
				count( $products )
			),
			'products'  => $products,
			'count'     => count( $products ),
			'page_info' => isset( $response['data']['products']['pageInfo'] ) ? $response['data']['products']['pageInfo'] : array(),
		);

		if ( $decomposed ) {
			$result['smart_search'] = true;
			$result['note']         = sprintf(
				/* translators: %1$d: number of results, %2$s: original query */
				__( 'The original query "%2$s" returned 0 results. Smart search decomposed the query into smaller keywords and found %1$d product(s).', 'mcp-ai-wpoos-pro' ),
				count( $products ),
				$query
			);
		}

		return $result;
	}

	/**
	 * Handle get action.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_get( $client, array $arguments ) {
		$product_id = isset( $arguments['product_id'] ) ? sanitize_text_field( $arguments['product_id'] ) : '';
		if ( empty( $product_id ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_missing_product_id', __( 'product_id is required for the get action.', 'mcp-ai-wpoos-pro' ) );
		}

		// Accept bare numeric ID and convert to GID.
		if ( is_numeric( $product_id ) ) {
			$product_id = 'gid://shopify/Product/' . $product_id;
		}

		$response = $client->get_product( $product_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$node = isset( $response['data']['product'] ) ? $response['data']['product'] : null;
		if ( ! $node ) {
			return new WP_Error( 'wp_mcp_ai_shopify_not_found', __( 'Product not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Generate rich product card for chat display.
		$normalized   = $this->normalize_product( $node );
		$card_message = $this->format_single_product_card( $normalized, 'shopify', array( 'max_description' => 200 ) );

		return array(
			'success' => true,
			'message' => ! empty( $card_message ) ? $card_message : __( 'Product retrieved successfully.', 'mcp-ai-wpoos-pro' ),
			'product' => $normalized,
		);
	}

	/**
	 * Handle create action.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_create( $client, array $arguments ) {
		$title = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		if ( empty( $title ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_missing_title', __( 'title is required for the create action.', 'mcp-ai-wpoos-pro' ) );
		}

		$input = array( 'title' => $title );

		if ( isset( $arguments['body_html'] ) ) {
			$input['descriptionHtml'] = wp_kses_post( $arguments['body_html'] );
		}
		if ( isset( $arguments['vendor'] ) ) {
			$input['vendor'] = sanitize_text_field( $arguments['vendor'] );
		}
		if ( isset( $arguments['product_type'] ) ) {
			$input['productType'] = sanitize_text_field( $arguments['product_type'] );
		}
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$input['tags'] = array_map( 'sanitize_text_field', $arguments['tags'] );
		}
		if ( isset( $arguments['status'] ) && in_array( $arguments['status'], array( 'ACTIVE', 'DRAFT', 'ARCHIVED' ), true ) ) {
			$input['status'] = $arguments['status'];
		}
		if ( isset( $arguments['variants'] ) && is_array( $arguments['variants'] ) ) {
			$input['variants'] = $arguments['variants'];
		}
		if ( isset( $arguments['seo_title'] ) || isset( $arguments['seo_description'] ) ) {
			$input['seo'] = array(
				'title'       => isset( $arguments['seo_title'] ) ? sanitize_text_field( $arguments['seo_title'] ) : '',
				'description' => isset( $arguments['seo_description'] ) ? sanitize_text_field( $arguments['seo_description'] ) : '',
			);
		}

		$response = $client->create_product( $input );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$user_errors = isset( $response['data']['productCreate']['userErrors'] ) ? $response['data']['productCreate']['userErrors'] : array();
		if ( ! empty( $user_errors ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_user_error', $user_errors[0]['message'] ?? __( 'Shopify validation error.', 'mcp-ai-wpoos-pro' ) );
		}

		$node = isset( $response['data']['productCreate']['product'] ) ? $response['data']['productCreate']['product'] : null;

		return array(
			'success' => true,
			'product' => $node,
			'message' => __( 'Product created successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Handle update action.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_update( $client, array $arguments ) {
		$product_id = isset( $arguments['product_id'] ) ? sanitize_text_field( $arguments['product_id'] ) : '';
		if ( empty( $product_id ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_missing_product_id', __( 'product_id is required for the update action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_numeric( $product_id ) ) {
			$product_id = 'gid://shopify/Product/' . $product_id;
		}

		$input = array();

		if ( isset( $arguments['title'] ) ) {
			$input['title'] = sanitize_text_field( $arguments['title'] );
		}
		if ( isset( $arguments['body_html'] ) ) {
			$input['descriptionHtml'] = wp_kses_post( $arguments['body_html'] );
		}
		if ( isset( $arguments['vendor'] ) ) {
			$input['vendor'] = sanitize_text_field( $arguments['vendor'] );
		}
		if ( isset( $arguments['product_type'] ) ) {
			$input['productType'] = sanitize_text_field( $arguments['product_type'] );
		}
		if ( isset( $arguments['tags'] ) && is_array( $arguments['tags'] ) ) {
			$input['tags'] = array_map( 'sanitize_text_field', $arguments['tags'] );
		}
		if ( isset( $arguments['status'] ) && in_array( $arguments['status'], array( 'ACTIVE', 'DRAFT', 'ARCHIVED' ), true ) ) {
			$input['status'] = $arguments['status'];
		}
		if ( isset( $arguments['variants'] ) && is_array( $arguments['variants'] ) ) {
			$input['variants'] = $arguments['variants'];
		}
		if ( isset( $arguments['seo_title'] ) || isset( $arguments['seo_description'] ) ) {
			$input['seo'] = array(
				'title'       => isset( $arguments['seo_title'] ) ? sanitize_text_field( $arguments['seo_title'] ) : '',
				'description' => isset( $arguments['seo_description'] ) ? sanitize_text_field( $arguments['seo_description'] ) : '',
			);
		}

		if ( empty( $input ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_nothing_to_update', __( 'No fields provided to update.', 'mcp-ai-wpoos-pro' ) );
		}

		$response = $client->update_product( $product_id, $input );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $response['errors'][0]['message'] ?? __( 'GraphQL error.', 'mcp-ai-wpoos-pro' ) );
		}

		$user_errors = isset( $response['data']['productUpdate']['userErrors'] ) ? $response['data']['productUpdate']['userErrors'] : array();
		if ( ! empty( $user_errors ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_user_error', $user_errors[0]['message'] ?? __( 'Shopify validation error.', 'mcp-ai-wpoos-pro' ) );
		}

		$node = isset( $response['data']['productUpdate']['product'] ) ? $response['data']['productUpdate']['product'] : null;

		return array(
			'success' => true,
			'product' => $node,
			'message' => __( 'Product updated successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Normalize a product node from the GraphQL response.
	 *
	 * @param array $node Raw GraphQL product node.
	 * @return array Normalized product array.
	 */
	protected function normalize_product( array $node ) {
		$variants = array();
		if ( isset( $node['variants']['edges'] ) ) {
			foreach ( $node['variants']['edges'] as $edge ) {
				$variants[] = isset( $edge['node'] ) ? $edge['node'] : array();
			}
		}

		$images = array();
		if ( isset( $node['images']['edges'] ) ) {
			foreach ( $node['images']['edges'] as $edge ) {
				$images[] = isset( $edge['node'] ) ? $edge['node'] : array();
			}
		}

		return array(
			'id'              => isset( $node['id'] ) ? $node['id'] : '',
			'title'           => isset( $node['title'] ) ? $node['title'] : '',
			'handle'          => isset( $node['handle'] ) ? $node['handle'] : '',
			'status'          => isset( $node['status'] ) ? $node['status'] : '',
			'vendor'          => isset( $node['vendor'] ) ? $node['vendor'] : '',
			'product_type'    => isset( $node['productType'] ) ? $node['productType'] : '',
			'tags'            => isset( $node['tags'] ) ? $node['tags'] : array(),
			'created_at'      => isset( $node['createdAt'] ) ? $node['createdAt'] : '',
			'updated_at'      => isset( $node['updatedAt'] ) ? $node['updatedAt'] : '',
			'price_range'     => isset( $node['priceRangeV2'] ) ? $node['priceRangeV2'] : array(),
			'total_inventory' => isset( $node['totalInventory'] ) ? $node['totalInventory'] : 0,
			'variants'        => $variants,
			'images'          => $images,
		);
	}

	// ------------------------------------------------------------------ //
	// Catalog API helpers                                                  //
	// ------------------------------------------------------------------ //

	/**
	 * Handle list/search for Catalog API mode connections.
	 *
	 * The Catalog API uses natural-language search (no GraphQL). Wildcard
	 * queries like "*" return zero results, so when no explicit query is
	 * provided we build a browse-friendly default from the connection name
	 * or store URL.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client     Shopify client.
	 * @param array                    $arguments  Tool arguments.
	 * @param array                    $connection Connection data array.
	 * @return array|WP_Error
	 */
	protected function handle_catalog_list( $client, array $arguments, array $connection ) {
		$limit = isset( $arguments['first'] )
			? max( 1, min( 10, absint( $arguments['first'] ) ) )
			: 10;

		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

		// Build a browse-friendly default query when none is provided.
		if ( empty( $query ) || '*' === $query ) {
			$query = $this->build_catalog_browse_query( $connection );
		}

		// Build filters — scope to the configured store when a Shop ID is set.
		$filters = array();
		$shop_id = $client->get_catalog_shop_id();
		if ( ! empty( $shop_id ) ) {
			$filters['shop_ids'] = $shop_id;
		}

		// --- Primary search. ---
		$response = $client->catalog_search( $query, $limit, $filters );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// The Catalog API returns a bare JSON array of product objects.
		$raw_products = is_array( $response ) && ! isset( $response['products'] )
			? $response
			: ( isset( $response['products'] ) ? $response['products'] : array() );

		$products = array_map( array( $this, 'normalize_catalog_product' ), $raw_products );

		// --- Progressive relaxation: decompose when no results and query is present. ---
		$smart_search = ! isset( $arguments['smart_search'] ) || ! empty( $arguments['smart_search'] );
		$decomposed   = false;

		if ( empty( $products ) && ! empty( $query ) && $smart_search && $this->should_decompose_query( $query ) ) {
			$tokens      = $this->extract_search_tokens( $query );
			$sub_queries = $this->generate_sub_queries( $tokens, $query );

			if ( ! empty( $sub_queries ) ) {
				$result_sets = array();

				foreach ( $sub_queries as $sub_query ) {
					$sub_response = $client->catalog_search( $sub_query, $limit, $filters );

					if ( is_wp_error( $sub_response ) ) {
						continue;
					}

					$sub_raw = is_array( $sub_response ) && ! isset( $sub_response['products'] )
						? $sub_response
						: ( isset( $sub_response['products'] ) ? $sub_response['products'] : array() );

					$sub_products = array_map( array( $this, 'normalize_catalog_product' ), $sub_raw );
					if ( ! empty( $sub_products ) ) {
						$result_sets[] = $sub_products;
					}
				}

				if ( ! empty( $result_sets ) ) {
					$products   = $this->merge_and_rank_products(
						$result_sets,
						function ( $product ) {
							return isset( $product['id'] ) ? $product['id'] : '';
						},
						$limit
					);
					$decomposed = true;
				}
			}
		}

		// Generate rich product cards for chat display.
		$cards_message = $this->format_product_cards( $products, 'shopify' );

		$result = array(
			'success'  => true,
			'message'  => ! empty( $cards_message ) ? $cards_message : sprintf(
				/* translators: %d: number of products */
				__( 'Retrieved %d product(s) from Shopify', 'mcp-ai-wpoos-pro' ),
				count( $products )
			),
			'products' => $products,
			'count'    => count( $products ),
			'raw'      => $raw_products,
		);

		if ( $decomposed ) {
			$result['smart_search'] = true;
			$result['note']         = sprintf(
				/* translators: %1$d: number of results, %2$s: original query */
				__( 'The original query "%2$s" returned 0 results. Smart search decomposed the query into smaller keywords and found %1$d product(s).', 'mcp-ai-wpoos-pro' ),
				count( $products ),
				$query
			);
		}

		return $result;
	}

	/**
	 * Handle single product lookup for Catalog API mode.
	 *
	 * Accepts a Universal Product ID (UPID) via product_id or upid arguments.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_catalog_get( $client, array $arguments ) {
		$upid = '';
		if ( isset( $arguments['upid'] ) ) {
			$upid = sanitize_text_field( $arguments['upid'] );
		} elseif ( isset( $arguments['product_id'] ) ) {
			$upid = sanitize_text_field( $arguments['product_id'] );
		}

		if ( empty( $upid ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_missing_product_id',
				__( 'product_id (UPID) is required for the get action in catalog_api mode.', 'mcp-ai-wpoos-pro' )
			);
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
			'product' => $product,
			'message' => $message,
		);
	}

	/**
	 * Handle list/search for Global Catalog MCP (UCP) mode connections.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client     Shopify client.
	 * @param array                    $arguments  Tool arguments.
	 * @param array                    $connection Connection data array.
	 * @return array|WP_Error
	 */
	protected function handle_global_catalog_list( $client, array $arguments, array $connection ) {
		return $this->handle_ucp_catalog_list( $client, $arguments, $connection, 'global' );
	}

	/**
	 * Handle list/search for Storefront Catalog MCP (UCP) mode connections.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client     Shopify client.
	 * @param array                    $arguments  Tool arguments.
	 * @param array                    $connection Connection data array.
	 * @return array|WP_Error
	 */
	protected function handle_storefront_catalog_list( $client, array $arguments, array $connection ) {
		return $this->handle_ucp_catalog_list( $client, $arguments, $connection, 'storefront' );
	}

	/**
	 * Shared live list/search core for UCP catalog modes.
	 *
	 * The UCP catalogs return products in the structuredContent envelope.
	 * UCP usage guidelines prohibit caching, so every call is live (no
	 * transients, no CCT writes); smart-search decomposition still applies
	 * at runtime. Buyer context and the opaque UCP pagination cursor are
	 * passed through so agents can localize results and page forward.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client     Shopify client.
	 * @param array                    $arguments  Tool arguments.
	 * @param array                    $connection Connection data array.
	 * @param string                   $mode       'global' or 'storefront'.
	 * @return array|WP_Error
	 */
	protected function handle_ucp_catalog_list( $client, array $arguments, array $connection, $mode ) {
		$is_global = 'global' === $mode;
		$max_limit = $is_global ? 50 : 250;

		$limit = isset( $arguments['first'] )
			? max( 1, min( $max_limit, absint( $arguments['first'] ) ) )
			: 10;

		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

		// The UCP catalogs have no wildcard browse; derive a browse-friendly default.
		if ( empty( $query ) || '*' === $query ) {
			$query = $this->build_catalog_browse_query( $connection );
		}

		$context = $this->build_ucp_context( $arguments );
		$cursor  = isset( $arguments['after'] ) ? sanitize_text_field( $arguments['after'] ) : '';

		$response = $this->ucp_catalog_search_call( $client, $is_global, $query, $limit, $context, $cursor );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_products = $this->extract_ucp_products( $response );
		$products     = array_map( array( $this, 'normalize_ucp_product' ), $raw_products );

		// --- Progressive relaxation: decompose when no results and query is present. ---
		$smart_search = ! isset( $arguments['smart_search'] ) || ! empty( $arguments['smart_search'] );
		$decomposed   = false;

		if ( empty( $products ) && ! empty( $query ) && $smart_search && $this->should_decompose_query( $query ) ) {
			$tokens      = $this->extract_search_tokens( $query );
			$sub_queries = $this->generate_sub_queries( $tokens, $query );

			if ( ! empty( $sub_queries ) ) {
				$result_sets = array();

				foreach ( $sub_queries as $sub_query ) {
					$sub_response = $this->ucp_catalog_search_call( $client, $is_global, $sub_query, $limit, $context, '' );

					if ( is_wp_error( $sub_response ) ) {
						continue;
					}

					$sub_products = array_map(
						array( $this, 'normalize_ucp_product' ),
						$this->extract_ucp_products( $sub_response )
					);
					if ( ! empty( $sub_products ) ) {
						$result_sets[] = $sub_products;
					}
				}

				if ( ! empty( $result_sets ) ) {
					$products   = $this->merge_and_rank_products(
						$result_sets,
						function ( $product ) {
							return isset( $product['id'] ) ? $product['id'] : '';
						},
						$limit
					);
					$decomposed = true;
				}
			}
		}

		$cards_message = $this->format_product_cards( $products, 'shopify' );
		$catalog_label = $is_global
			? __( 'Shopify Global Catalog', 'mcp-ai-wpoos-pro' )
			: __( 'Shopify Storefront Catalog', 'mcp-ai-wpoos-pro' );

		$result = array(
			'success'    => true,
			'live'       => true, // UCP usage guidelines: live query, nothing cached.
			'message'    => ! empty( $cards_message ) ? $cards_message : sprintf(
				/* translators: 1: catalog label, 2: number of products */
				__( 'Retrieved %2$d product(s) from the %1$s', 'mcp-ai-wpoos-pro' ),
				$catalog_label,
				count( $products )
			),
			'products'   => $products,
			'count'      => count( $products ),
			'pagination' => $this->extract_ucp_pagination( $response ),
			'raw'        => $raw_products,
		);

		if ( $decomposed ) {
			$result['smart_search'] = true;
			$result['note']         = sprintf(
				/* translators: %1$d: number of results, %2$s: original query */
				__( 'The original query "%2$s" returned 0 results. Smart search decomposed the query into smaller keywords and found %1$d product(s).', 'mcp-ai-wpoos-pro' ),
				count( $products ),
				$query
			);
		}

		return $result;
	}

	/**
	 * Handle single product lookup for Global Catalog MCP (UCP) mode.
	 *
	 * Accepts a product or variant identifier via product_id or upid.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_global_catalog_get( $client, array $arguments ) {
		return $this->handle_ucp_catalog_get( $client, $arguments, 'global' );
	}

	/**
	 * Handle single product lookup for Storefront Catalog MCP (UCP) mode.
	 *
	 * Accepts a product or variant identifier via product_id or upid.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_storefront_catalog_get( $client, array $arguments ) {
		return $this->handle_ucp_catalog_get( $client, $arguments, 'storefront' );
	}

	/**
	 * Shared live single-product lookup core for UCP catalog modes.
	 *
	 * Without option selections the canonical lookup_catalog tool resolves
	 * the identifier; with `selected` option selections the canonical
	 * get_product tool narrows variants and reports availability signals.
	 * Unresolved identifiers surface in `not_found` per the UCP spec.
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @param string                   $mode      'global' or 'storefront'.
	 * @return array|WP_Error
	 */
	protected function handle_ucp_catalog_get( $client, array $arguments, $mode ) {
		$is_global = 'global' === $mode;

		$id = '';
		if ( isset( $arguments['product_id'] ) ) {
			$id = sanitize_text_field( $arguments['product_id'] );
		} elseif ( isset( $arguments['upid'] ) ) {
			$id = sanitize_text_field( $arguments['upid'] );
		}

		if ( empty( $id ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_missing_product_id',
				__( 'product_id is required for the get action in UCP catalog modes.', 'mcp-ai-wpoos-pro' )
			);
		}

		$context = $this->build_ucp_context( $arguments );

		// With explicit option selections, use the canonical get_product tool
		// so Shopify narrows the variants and reports availability signals.
		$selected = isset( $arguments['selected'] ) && is_array( $arguments['selected'] )
			? array_slice( $arguments['selected'], 0, 25 )
			: array();

		if ( ! empty( $selected ) ) {
			$response = $is_global
				? $client->global_catalog_get_product( $id, $selected, $context )
				: $client->storefront_catalog_get_product( $id, $selected, $context );

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
				'live'    => true, // UCP usage guidelines: live query, nothing cached.
				'product' => $normalized,
				'message' => $message,
			);
		}

		$response = $is_global
			? $client->global_catalog_lookup( array( $id ), $context )
			: $client->storefront_catalog_lookup( array( $id ), $context );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$products = $this->extract_ucp_products( $response );
		$product  = ! empty( $products ) ? $this->normalize_ucp_product( $products[0] ) : array();

		if ( empty( $product ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_product_not_found',
				__( 'The requested product was not found in the Shopify catalog.', 'mcp-ai-wpoos-pro' )
			);
		}

		$message = __( 'Product retrieved successfully.', 'mcp-ai-wpoos-pro' );
		if ( ! empty( $product['title'] ) || ! empty( $product['images'] ) ) {
			$message = $this->format_single_product_card( $product, 'shopify', array( 'max_description' => 200 ) );
		}

		$result = array(
			'success' => true,
			'live'    => true, // UCP usage guidelines: live query, nothing cached.
			'product' => $product,
			'message' => $message,
		);

		$not_found = $this->extract_ucp_not_found( $response );
		if ( ! empty( $not_found ) ) {
			$result['not_found'] = $not_found;
		}

		return $result;
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
	 * UCP search results carry result.structuredContent.pagination with a
	 * cursor, has_next_page, and total_count — surfaced so agents can page
	 * through live results without re-querying.
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
	 * @param WP_MCP_AI_Shopify_Client $client   Shopify client.
	 * @param bool                     $is_global True for Global Catalog, false for Storefront Catalog.
	 * @param string                   $query    Free-text query.
	 * @param int                      $limit    Result limit.
	 * @param array                    $context  UCP buyer context.
	 * @param string                   $cursor   Optional pagination cursor.
	 * @return array|WP_Error Decoded UCP MCP result or WP_Error.
	 */
	protected function ucp_catalog_search_call( $client, $is_global, $query, $limit, array $context, $cursor ) {
		if ( $is_global ) {
			return $client->global_catalog_search( $query, $limit, $context, array(), $cursor );
		}
		return $client->storefront_catalog_search( $query, $limit, $context, $cursor );
	}



	/**
	 * Build a browse-friendly default query for the Catalog API.
	 *
	 * The Catalog API uses NLP-based search and does not support wildcard
	 * queries.  We derive a human-readable phrase from the connection name
	 * or store URL so that a bare "list" action returns meaningful products.
	 *
	 * @param array $connection Connection data array.
	 * @return string NLP-friendly browse query.
	 */
	protected function build_catalog_browse_query( array $connection ) {
		// Try the connection name first (e.g. "My Awesome Store").
		if ( ! empty( $connection['name'] ) ) {
			$name = sanitize_text_field( $connection['name'] );
			// Strip common suffixes that don't help search.
			$name = preg_replace( '/\s*(connection|api|catalog|shopify)\s*/i', ' ', $name );
			$name = trim( $name );
			if ( strlen( $name ) >= 2 ) {
				return $name;
			}
		}

		// Derive from the store URL slug (e.g. "my-store" from https://my-store.myshopify.com).
		if ( ! empty( $connection['url'] ) ) {
			$host = wp_parse_url( $connection['url'], PHP_URL_HOST );
			if ( $host ) {
				$parts = explode( '.', $host );
				$slug  = str_replace( array( '-', '_' ), ' ', $parts[0] );
				$slug  = trim( $slug );
				if ( strlen( $slug ) >= 2 ) {
					return $slug;
				}
			}
		}

		// Final fallback — generic keyword.
		return 'products';
	}
}
