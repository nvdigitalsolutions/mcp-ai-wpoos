<?php
/**
 * Shopify Products Tool — manage products on a connected Shopify store via the Admin GraphQL API.
 *
 * @package WP_MCP_AI_Pro
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
					'description' => __( 'Remote Sites connection ID for the Shopify store (connection_type must be "shopify").', 'mcp-ai-wpoos-pro' ),
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
			),
			'required'             => array( 'connection_id', 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( WP_MCP_AI_Tool_Capability_Flags_Interface::FLAG_EXTERNAL_API );
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

		$required_capability = apply_filters( 'wp_mcp_ai_shopify_products_required_capability', 'edit_posts', $context );

		if ( ! $user_id || ! user_can( $user_id, $required_capability ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_forbidden', __( 'You do not have permission to manage Shopify products.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		if ( empty( $connection_id ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_missing_connection', __( 'A Remote Sites connection ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_no_manager', __( 'Remote Sites Manager is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection ) {
			return new WP_Error( 'wp_mcp_ai_shopify_connection_not_found', __( 'The specified connection was not found.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $connection['connection_type'] ) || 'shopify' !== $connection['connection_type'] ) {
			return new WP_Error( 'wp_mcp_ai_shopify_wrong_type', __( 'The specified connection is not a Shopify connection.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error( 'wp_mcp_ai_shopify_disabled', __( 'This Shopify connection is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-client.php';
		}

		$client = new WP_MCP_AI_Shopify_Client( $connection_id );
		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

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
	 * @param WP_MCP_AI_Shopify_Client $client    Shopify client.
	 * @param array                    $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_list( $client, array $arguments ) {
		$first = isset( $arguments['first'] ) ? max( 1, min( 250, absint( $arguments['first'] ) ) ) : 10;
		$after = isset( $arguments['after'] ) ? sanitize_text_field( $arguments['after'] ) : '';
		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

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

		return array(
			'success'   => true,
			'products'  => $products,
			'count'     => count( $products ),
			'page_info' => isset( $response['data']['products']['pageInfo'] ) ? $response['data']['products']['pageInfo'] : array(),
		);
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

		return array(
			'success' => true,
			'product' => $this->normalize_product( $node ),
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
}
