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
class WP_MCP_AI_Pro_Tool_Shopify_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Shopify_Connection_Resolver;
	use WP_MCP_AI_Tool_Product_Card;
	use WP_MCP_AI_Shopify_Smart_Search;

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
		return __( 'Manage products on a connected Shopify store via the Admin GraphQL API. Supports listing, searching, retrieving details, creating, and updating products, variants, and inventory.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for the Shopify store. If omitted, automatically uses the Shopify connection configured for this assistant.', 'mcp-ai-wpoos-pro' ),
				),
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list, get, create, update, search.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list', 'get', 'create', 'update', 'search' ),
					'default'     => 'list',
				),
				'product_id'    => array(
					'type'        => 'string',
					'description' => __( 'Shopify product GID (e.g. gid://shopify/Product/123456789) for get/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'first'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of products to return (1–250). Default: 10.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 250,
				),
				'after'         => array(
					'type'        => 'string',
					'description' => __( 'Pagination cursor (endCursor from a previous response).', 'mcp-ai-wpoos-pro' ),
				),
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Shopify search query string for list/search actions. Supports Shopify filter syntax, e.g. "status:active vendor:Acme".', 'mcp-ai-wpoos-pro' ),
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Product title for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'body_html'     => array(
					'type'        => 'string',
					'description' => __( 'Product description HTML for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'vendor'        => array(
					'type'        => 'string',
					'description' => __( 'Product vendor for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'product_type'  => array(
					'type'        => 'string',
					'description' => __( 'Product type for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'tags'          => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Array of tags for create/update actions.', 'mcp-ai-wpoos-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Product status: ACTIVE, DRAFT, or ARCHIVED.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ACTIVE', 'DRAFT', 'ARCHIVED' ),
				),
				'variants'      => array(
					'type'        => 'array',
					'description' => __( 'Array of variant input objects for create/update. Each variant can include: price, compareAtPrice, sku, barcode, inventoryQuantity, weight, weightUnit, options.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'object' ),
				),
				'seo_title'     => array(
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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$action  = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

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

		if ( ! $user_id || ! user_can( $user_id, $required_capability ) ) {
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
			$available   = $this->get_available_shopify_connections( $context );
			$conn_list   = $this->format_available_connections_message( $available );
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
		$api_mode = isset( $connection['shopify_api_mode'] ) ? $connection['shopify_api_mode'] : 'admin_api';

		// Catalog API connections use different endpoints and response formats.
		// Route read-only actions through the Catalog API and normalize the
		// response so the caller always receives the same structure.
		if ( 'catalog_api' === $api_mode ) {
			return $this->handle_catalog_mode( $client, $action, $arguments );
		}

		switch ( $action ) {
			case 'list':
			case 'search':
				return $this->handle_list( $client, $arguments );

			case 'get':
				return $this->handle_get( $client, $arguments );

			case 'collections':
				return $this->handle_collections( $client, $arguments );

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

					$sub_edges = isset( $sub_response['data']['products']['edges'] ) ? $sub_response['data']['products']['edges'] : array();
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
		$normalized = $this->normalize_product( $node );
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
			'id'             => isset( $node['id'] ) ? $node['id'] : '',
			'title'          => isset( $node['title'] ) ? $node['title'] : '',
			'handle'         => isset( $node['handle'] ) ? $node['handle'] : '',
			'status'         => isset( $node['status'] ) ? $node['status'] : '',
			'vendor'         => isset( $node['vendor'] ) ? $node['vendor'] : '',
			'product_type'   => isset( $node['productType'] ) ? $node['productType'] : '',
			'tags'           => isset( $node['tags'] ) ? $node['tags'] : array(),
			'created_at'     => isset( $node['createdAt'] ) ? $node['createdAt'] : '',
			'updated_at'     => isset( $node['updatedAt'] ) ? $node['updatedAt'] : '',
			'price_range'    => isset( $node['priceRangeV2'] ) ? $node['priceRangeV2'] : array(),
			'total_inventory' => isset( $node['totalInventory'] ) ? $node['totalInventory'] : 0,
			'variants'       => $variants,
			'images'         => $images,
		);
	}

	/**
	 * Handle the collections action (Admin GraphQL API).
	 *
	 * @since 1.1.7
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_collections( $client, array $arguments ) {
		$first = isset( $arguments['first'] ) ? max( 1, min( 250, absint( $arguments['first'] ) ) ) : 25;

		$response = $client->get_collections( $first );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['errors'] ) && ! empty( $response['errors'] ) ) {
			$msg = isset( $response['errors'][0]['message'] ) ? $response['errors'][0]['message'] : __( 'GraphQL error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'wp_mcp_ai_shopify_gql_error', $msg );
		}

		$collections = array();
		$edges       = isset( $response['data']['collections']['edges'] ) ? $response['data']['collections']['edges'] : array();

		foreach ( $edges as $edge ) {
			$node = isset( $edge['node'] ) ? $edge['node'] : array();
			$collections[] = array(
				'id'             => isset( $node['id'] ) ? $node['id'] : '',
				'title'          => isset( $node['title'] ) ? $node['title'] : '',
				'handle'         => isset( $node['handle'] ) ? $node['handle'] : '',
				'description'    => isset( $node['description'] ) ? $node['description'] : '',
				'products_count' => isset( $node['productsCount'] ) ? $node['productsCount'] : 0,
				'image'          => isset( $node['image'] ) ? $node['image'] : null,
			);
		}

		return array(
			'success'     => true,
			'collections' => $collections,
			'count'       => count( $collections ),
		);
	}

	/**
	 * Handle actions for Catalog API mode connections.
	 *
	 * Routes list/search through the Catalog API search endpoint and normalizes
	 * responses to match the Admin API normalized format so callers (including
	 * TMA templates) always receive a consistent product structure.
	 *
	 * @since 1.1.7
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param string                   $action    Action to perform.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_catalog_mode( $client, $action, array $arguments ) {
		switch ( $action ) {
			case 'list':
			case 'search':
				return $this->handle_catalog_search( $client, $arguments );

			case 'collections':
				// Catalog API does not support collections.
				return array(
					'success'     => true,
					'collections' => array(),
					'count'       => 0,
				);

			case 'get':
				return $this->handle_catalog_lookup( $client, $arguments );

			default:
				return new WP_Error(
					'wp_mcp_ai_shopify_catalog_unsupported',
					__( 'This action is not supported for Catalog API connections. Only list, search, get, and collections are available.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Handle list/search via the Catalog API.
	 *
	 * @since 1.1.7
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_catalog_search( $client, array $arguments ) {
		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

		// The Catalog API enforces a maximum limit of 10 results per request.
		$limit = isset( $arguments['first'] ) ? max( 1, min( 10, absint( $arguments['first'] ) ) ) : 10;

		// The Catalog API requires a non-empty query.  For "list" (browse)
		// requests send a broad wildcard so the API returns results.
		if ( empty( $query ) ) {
			$query = '*';
		}

		$filters = array();
		if ( ! empty( $arguments['shop_ids'] ) ) {
			$filters['shop_ids'] = sanitize_text_field( $arguments['shop_ids'] );
		}

		$response = $client->catalog_search( $query, $limit, $filters );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw_products = isset( $response['products'] ) ? $response['products'] : array();
		$products     = array();

		foreach ( $raw_products as $raw ) {
			$products[] = $this->normalize_catalog_product( $raw );
		}

		$count  = count( $products );
		$result = array(
			'success'  => true,
			'products' => $products,
			'count'    => $count,
			'message'  => $this->build_product_summary( $products ),
		);

		return $result;
	}

	/**
	 * Handle single-product lookup via the Catalog API.
	 *
	 * @since 1.1.7
	 *
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_catalog_lookup( $client, array $arguments ) {
		$product_id = isset( $arguments['product_id'] ) ? sanitize_text_field( $arguments['product_id'] ) : '';

		if ( empty( $product_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_missing_product_id',
				__( 'product_id (UPID) is required for the get action on Catalog API connections.', 'mcp-ai-wpoos-pro' )
			);
		}

		$response = $client->catalog_lookup( $product_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$normalized = $this->normalize_catalog_product( $response );

		return array(
			'success' => true,
			'product' => $normalized,
		);
	}

	/**
	 * Normalize a Catalog API product into the same structure as normalize_product().
	 *
	 * Catalog API responses have a flat structure with fields like title, image_url,
	 * price, currency, in_stock.  This converts them into the nested format that
	 * the Admin API normalizer produces, so callers (including TMA templates) can
	 * use a single set of extractors.
	 *
	 * @since 1.1.7
	 *
	 * @param array $raw Raw Catalog API product object.
	 * @return array Normalized product array matching normalize_product() output.
	 */
	protected function normalize_catalog_product( array $raw ) {
		$title      = isset( $raw['title'] ) ? $raw['title'] : '';
		$price      = isset( $raw['price'] ) ? (string) $raw['price'] : '0';
		$currency   = isset( $raw['currency'] ) ? $raw['currency'] : '';
		$image_url  = isset( $raw['image_url'] ) ? $raw['image_url'] : '';
		$in_stock   = isset( $raw['in_stock'] ) ? (bool) $raw['in_stock'] : true;
		$handle     = isset( $raw['handle'] ) ? $raw['handle'] : '';
		$url        = isset( $raw['url'] ) ? $raw['url'] : '';

		// Resolve the unique product identifier (UPID preferred, then generic id).
		$id = '';
		if ( ! empty( $raw['upid'] ) ) {
			$id = $raw['upid'];
		} elseif ( ! empty( $raw['id'] ) ) {
			$id = $raw['id'];
		}

		// Resolve vendor from shop_name or vendor field.
		$vendor = '';
		if ( ! empty( $raw['shop_name'] ) ) {
			$vendor = $raw['shop_name'];
		} elseif ( ! empty( $raw['vendor'] ) ) {
			$vendor = $raw['vendor'];
		}

		// Build a single variant matching the shape produced by normalize_product().
		$variant = array(
			'id'             => isset( $raw['variant_id'] ) ? $raw['variant_id'] : '',
			'title'          => 'Default',
			'sku'            => isset( $raw['sku'] ) ? $raw['sku'] : '',
			'price'          => $price,
			'compareAtPrice' => isset( $raw['compare_at_price'] ) ? (string) $raw['compare_at_price'] : null,
		);

		$image = ! empty( $image_url ) ? array( 'url' => $image_url ) : array();
		$images = ! empty( $image ) ? array( $image ) : array();

		return array(
			'id'              => $id,
			'title'           => $title,
			'handle'          => $handle,
			'status'          => 'ACTIVE',
			'vendor'          => $vendor,
			'product_type'    => isset( $raw['product_type'] ) ? $raw['product_type'] : '',
			'tags'            => isset( $raw['tags'] ) ? (array) $raw['tags'] : array(),
			'created_at'      => '',
			'updated_at'      => '',
			'price_range'     => array(
				'minVariantPrice' => array(
					'amount'       => $price,
					'currencyCode' => $currency,
				),
			),
			'total_inventory' => $in_stock ? 1 : 0,
			'variants'        => array( $variant ),
			'images'          => $images,
			'url'             => $url,
		);
	}

	/**
	 * Build a brief human-readable summary of a product list.
	 *
	 * Reused by both Admin GraphQL and Catalog API paths to provide a
	 * consistent message field in the tool response.
	 *
	 * @since 1.1.7
	 *
	 * @param array $products Array of normalized products.
	 * @return string Summary message.
	 */
	protected function build_product_summary( array $products ) {
		$count = count( $products );
		if ( 0 === $count ) {
			return __( 'No products found.', 'mcp-ai-wpoos-pro' );
		}
		return sprintf(
			/* translators: %d: number of products */
			_n( 'Found %d product.', 'Found %d products.', $count, 'mcp-ai-wpoos-pro' ),
			$count
		);
	}
}
