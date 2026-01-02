<?php
/**
 * Remote WordPress/WooCommerce Connection Tool.
 *
 * Enables read-only access to remote WordPress and WooCommerce sites
 * for querying posts, media, products, orders, and other data.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';

/**
 * Remote WordPress/WooCommerce Connection Tool.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Remote_WP_Connection implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'remote_wp_connection';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Remote WordPress/WooCommerce Connection', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Access remote WordPress and WooCommerce sites to retrieve posts, pages, media, products, orders, and other data in read-only mode. WORKFLOW: Always call with action="list_connections" FIRST to discover available connection IDs, then use those IDs in subsequent calls. Never attempt get_posts, get_media, etc. without first calling list_connections.', 'wp-mcp-ai-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'The action to perform. IMPORTANT: Always call with "list_connections" FIRST to discover available connection IDs before any other action.', 'wp-mcp-ai-pro' ),
					'enum'        => array(
						'list_connections',
						'test_connection',
						'get_posts',
						'get_post',
						'get_pages',
						'get_media',
						'get_wc_products',
						'get_wc_product',
						'get_wc_orders',
						'get_wc_order',
						'get_wc_customers',
						'get_wc_categories',
					),
					'default'     => 'list_connections',
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'REQUIRED (except for list_connections action). The connection ID obtained from calling list_connections first. Format: conn_XXXX. You must call list_connections before using any other action to get this ID.', 'wp-mcp-ai-pro' ),
				),
				'post_type'     => array(
					'type'        => 'string',
					'description' => __( 'Post type to query (for get_posts action). Defaults to "post".', 'wp-mcp-ai-pro' ),
					'default'     => 'post',
				),
				'post_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Post or product ID for single item queries.', 'wp-mcp-ai-pro' ),
				),
				'order_id'      => array(
					'type'        => 'integer',
					'description' => __( 'WooCommerce order ID for order queries.', 'wp-mcp-ai-pro' ),
				),
				'per_page'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to retrieve per page. Default: 10, Max: 100.', 'wp-mcp-ai-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'          => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter results.', 'wp-mcp-ai-pro' ),
				),
				'status'        => array(
					'type'        => 'string',
					'description' => __( 'Filter by status (publish, draft, etc. for posts; completed, processing, etc. for orders).', 'wp-mcp-ai-pro' ),
				),
				'sku'           => array(
					'type'        => 'string',
					'description' => __( 'Product SKU for WooCommerce product queries.', 'wp-mcp-ai-pro' ),
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check user permissions.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to access remote WordPress sites.', 'wp-mcp-ai-pro' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_connections';

		// Check rate limiting (except for list_connections which is lightweight).
		if ( 'list_connections' !== $action ) {
			$rate_limit_check = $this->check_rate_limit( $user_id );
			if ( is_wp_error( $rate_limit_check ) ) {
				return $rate_limit_check;
			}
		}

		// Handle listing connections (no connection_id needed).
		if ( 'list_connections' === $action ) {
			return $this->list_connections( $context );
		}

		// Get connection.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		if ( empty( $connection_id ) ) {
			// Get available connections to include in error message.
			$available_connections = $this->list_connections( $context );
			$connection_list       = '';
			
			if ( ! is_wp_error( $available_connections ) && ! empty( $available_connections['connections'] ) ) {
				$connections_formatted = array();
				foreach ( $available_connections['connections'] as $conn ) {
					$connections_formatted[] = sprintf( '%s (%s)', $conn['id'], $conn['name'] );
				}
				$connection_list = ' Available connections: ' . implode( ', ', $connections_formatted ) . '.';
			}

			return new WP_Error(
				'wp_mcp_ai_pro_missing_connection',
				sprintf(
					/* translators: 1: action name, 2: list of available connections */
					__( 'Connection ID is required for action "%1$s".%2$s You must provide the connection_id parameter with one of the available connection IDs.', 'wp-mcp-ai-pro' ),
					$action,
					$connection_list
				)
			);
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( null === $connection ) {
			// Get available connections to include in error message.
			$available_connections = $this->list_connections( $context );
			$connection_list       = '';
			
			if ( ! is_wp_error( $available_connections ) && ! empty( $available_connections['connections'] ) ) {
				$connections_formatted = array();
				foreach ( $available_connections['connections'] as $conn ) {
					$connections_formatted[] = sprintf( '%s (%s)', $conn['id'], $conn['name'] );
				}
				$connection_list = ' Available connections: ' . implode( ', ', $connections_formatted ) . '.';
			}

			return new WP_Error(
				'wp_mcp_ai_pro_invalid_connection',
				sprintf(
					/* translators: 1: connection ID, 2: list of available connections */
					__( 'Invalid connection ID "%1$s".%2$s Use one of the available connection IDs.', 'wp-mcp-ai-pro' ),
					$connection_id,
					$connection_list
				)
			);
		}

		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_disabled_connection',
				sprintf(
					/* translators: %s: connection name */
					__( 'Connection "%s" is disabled. Please ask the user to enable it in the WordPress admin under NV oOS → Remote Sites.', 'wp-mcp-ai-pro' ),
					isset( $connection['name'] ) ? $connection['name'] : $connection_id
				)
			);
		}

		// Check if this connection is enabled for the current assistant.
		if ( ! $this->is_connection_enabled_for_assistant( $connection_id, $context ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_connection_not_enabled',
				sprintf(
					/* translators: %s: connection name */
					__( 'Connection "%s" is not enabled for this assistant. Please ask the user to enable it in the assistant editor under Remote Site Connections metabox.', 'wp-mcp-ai-pro' ),
					isset( $connection['name'] ) ? $connection['name'] : $connection_id
				)
			);
		}

		// Route to appropriate handler.
		switch ( $action ) {
			case 'test_connection':
				return $this->test_connection( $connection );

			case 'get_posts':
				return $this->get_posts( $connection, $arguments );

			case 'get_post':
				return $this->get_post( $connection, $arguments );

			case 'get_pages':
				return $this->get_pages( $connection, $arguments );

			case 'get_media':
				return $this->get_media( $connection, $arguments );

			case 'get_wc_products':
				return $this->get_wc_products( $connection, $arguments );

			case 'get_wc_product':
				return $this->get_wc_product( $connection, $arguments );

			case 'get_wc_orders':
				return $this->get_wc_orders( $connection, $arguments );

			case 'get_wc_order':
				return $this->get_wc_order( $connection, $arguments );

			case 'get_wc_customers':
				return $this->get_wc_customers( $connection, $arguments );

			case 'get_wc_categories':
				return $this->get_wc_categories( $connection, $arguments );

			default:
				return new WP_Error(
					'wp_mcp_ai_pro_invalid_action',
					__( 'Invalid action specified.', 'wp-mcp-ai-pro' )
				);
		}
	}

	/**
	 * List all available connections.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Execution context including assistant_id.
	 * @return array Connections list.
	 */
	protected function list_connections( $context = array() ) {
		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		// Get assistant ID from context to filter connections.
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		// Get enabled connections for this assistant.
		$enabled_connections = array();
		if ( $assistant_id ) {
			$enabled_connections = get_post_meta( $assistant_id, '_wp_mcp_ai_pro_remote_connections', true );
			if ( ! is_array( $enabled_connections ) ) {
				$enabled_connections = array();
			}
		}

		$result = array();

		foreach ( $connections as $connection ) {
			// Skip if not enabled globally.
			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			// If assistant context is provided and connections are configured,
			// only include connections enabled for this assistant.
			if ( $assistant_id && ! empty( $enabled_connections ) && ! in_array( $connection['id'], $enabled_connections, true ) ) {
				continue;
			}

			$result[] = array(
				'id'              => $connection['id'],
				'name'            => $connection['name'],
				'url'             => $connection['url'],
				'has_woocommerce' => ! empty( $connection['has_woocommerce'] ),
				'enabled'         => ! empty( $connection['enabled'] ),
			);
		}

		$response = array(
			'summary'     => sprintf(
				/* translators: %d: number of connections */
				__( 'Found %d remote site connection(s)', 'wp-mcp-ai-pro' ),
				count( $result )
			),
			'connections' => $result,
			'count'       => count( $result ),
		);

		/**
		 * Filter the list_connections response.
		 *
		 * Allows modification of connection list before returning to AI.
		 *
		 * @since 1.0.0
		 *
		 * @param array $response Connection list response.
		 * @param array $context  Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_pro_remote_connections_list', $response, $context );
	}

	/**
	 * Test a connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return array|WP_Error Test results.
	 */
	protected function test_connection( $connection ) {
		return WP_MCP_AI_Pro_Remote_Site_Manager::test_connection( $connection );
	}

	/**
	 * Get posts from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Posts data.
	 */
	protected function get_posts( $connection, $arguments ) {
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		$params = array(
			'per_page' => $per_page,
			'page'     => $page,
		);

		if ( ! empty( $arguments['search'] ) ) {
			$params['search'] = sanitize_text_field( $arguments['search'] );
		}

		if ( ! empty( $arguments['status'] ) ) {
			$params['status'] = sanitize_key( $arguments['status'] );
		}

		$endpoint = 'wp/v2/' . $post_type;

		if ( ! empty( $params ) ) {
			$endpoint = add_query_arg( $params, $endpoint );
		}

		$posts = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		return array(
			'summary' => sprintf(
				/* translators: %d: number of posts */
				__( 'Retrieved %d post(s)', 'wp-mcp-ai-pro' ),
				count( $posts )
			),
			'posts'   => $posts,
			'count'   => count( $posts ),
		);
	}

	/**
	 * Get a single post from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Post data.
	 */
	protected function get_post( $connection, $arguments ) {
		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_post_id',
				__( 'Post ID is required.', 'wp-mcp-ai-pro' )
			);
		}

		$post_id = absint( $arguments['post_id'] );
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		$endpoint = 'wp/v2/' . $post_type . '/' . $post_id;

		$post = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'summary' => __( 'Post retrieved successfully', 'wp-mcp-ai-pro' ),
			'post'    => $post,
		);
	}

	/**
	 * Get pages from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Pages data.
	 */
	protected function get_pages( $connection, $arguments ) {
		$arguments['post_type'] = 'pages';
		return $this->get_posts( $connection, $arguments );
	}

	/**
	 * Get media items from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Media data.
	 */
	protected function get_media( $connection, $arguments ) {
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$params = array(
			'per_page' => $per_page,
			'page'     => $page,
		);

		$endpoint = 'wp/v2/media';

		if ( ! empty( $params ) ) {
			$endpoint = add_query_arg( $params, $endpoint );
		}

		$media = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $media ) ) {
			return $media;
		}

		return array(
			'summary' => sprintf(
				/* translators: %d: number of media items */
				__( 'Retrieved %d media item(s)', 'wp-mcp-ai-pro' ),
				count( $media )
			),
			'media'   => $media,
			'count'   => count( $media ),
		);
	}

	/**
	 * Get WooCommerce products from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Products data.
	 */
	protected function get_wc_products( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'wp-mcp-ai-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$params = array(
			'per_page' => $per_page,
			'page'     => $page,
		);

		if ( ! empty( $arguments['search'] ) ) {
			$params['search'] = sanitize_text_field( $arguments['search'] );
		}

		if ( ! empty( $arguments['sku'] ) ) {
			$params['sku'] = sanitize_text_field( $arguments['sku'] );
		}

		if ( ! empty( $arguments['status'] ) ) {
			$params['status'] = sanitize_key( $arguments['status'] );
		}

		$endpoint = 'wc/v3/products';

		if ( ! empty( $params ) ) {
			$endpoint = add_query_arg( $params, $endpoint );
		}

		$products = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $products ) ) {
			return $products;
		}

		return array(
			'summary'  => sprintf(
				/* translators: %d: number of products */
				__( 'Retrieved %d product(s)', 'wp-mcp-ai-pro' ),
				count( $products )
			),
			'products' => $products,
			'count'    => count( $products ),
		);
	}

	/**
	 * Get a single WooCommerce product from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Product data.
	 */
	protected function get_wc_product( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'wp-mcp-ai-pro' )
			);
		}

		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_product_id',
				__( 'Product ID is required.', 'wp-mcp-ai-pro' )
			);
		}

		$product_id = absint( $arguments['post_id'] );
		$endpoint = 'wc/v3/products/' . $product_id;

		$product = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		return array(
			'summary' => __( 'Product retrieved successfully', 'wp-mcp-ai-pro' ),
			'product' => $product,
		);
	}

	/**
	 * Get WooCommerce orders from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Orders data.
	 */
	protected function get_wc_orders( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'wp-mcp-ai-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$params = array(
			'per_page' => $per_page,
			'page'     => $page,
		);

		if ( ! empty( $arguments['status'] ) ) {
			$params['status'] = sanitize_key( $arguments['status'] );
		}

		$endpoint = 'wc/v3/orders';

		if ( ! empty( $params ) ) {
			$endpoint = add_query_arg( $params, $endpoint );
		}

		$orders = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $orders ) ) {
			return $orders;
		}

		return array(
			'summary' => sprintf(
				/* translators: %d: number of orders */
				__( 'Retrieved %d order(s)', 'wp-mcp-ai-pro' ),
				count( $orders )
			),
			'orders'  => $orders,
			'count'   => count( $orders ),
		);
	}

	/**
	 * Get a single WooCommerce order from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Order data.
	 */
	protected function get_wc_order( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'wp-mcp-ai-pro' )
			);
		}

		if ( empty( $arguments['order_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_order_id',
				__( 'Order ID is required.', 'wp-mcp-ai-pro' )
			);
		}

		$order_id = absint( $arguments['order_id'] );
		$endpoint = 'wc/v3/orders/' . $order_id;

		$order = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return array(
			'summary' => __( 'Order retrieved successfully', 'wp-mcp-ai-pro' ),
			'order'   => $order,
		);
	}

	/**
	 * Get WooCommerce customers from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Customers data.
	 */
	protected function get_wc_customers( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'wp-mcp-ai-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$params = array(
			'per_page' => $per_page,
			'page'     => $page,
		);

		$endpoint = 'wc/v3/customers';

		if ( ! empty( $params ) ) {
			$endpoint = add_query_arg( $params, $endpoint );
		}

		$customers = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $customers ) ) {
			return $customers;
		}

		return array(
			'summary'   => sprintf(
				/* translators: %d: number of customers */
				__( 'Retrieved %d customer(s)', 'wp-mcp-ai-pro' ),
				count( $customers )
			),
			'customers' => $customers,
			'count'     => count( $customers ),
		);
	}

	/**
	 * Get WooCommerce product categories from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Categories data.
	 */
	protected function get_wc_categories( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'wp-mcp-ai-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$params = array(
			'per_page' => $per_page,
			'page'     => $page,
		);

		$endpoint = 'wc/v3/products/categories';

		if ( ! empty( $params ) ) {
			$endpoint = add_query_arg( $params, $endpoint );
		}

		$categories = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		return array(
			'summary'    => sprintf(
				/* translators: %d: number of categories */
				__( 'Retrieved %d product categor(ies)', 'wp-mcp-ai-pro' ),
				count( $categories )
			),
			'categories' => $categories,
			'count'      => count( $categories ),
		);
	}

	/**
	 * Check if a connection is enabled for the current assistant.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param array  $context       Execution context.
	 * @return bool True if enabled, false otherwise.
	 */
	protected function is_connection_enabled_for_assistant( $connection_id, $context ) {
		// Get assistant ID from context.
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		if ( ! $assistant_id ) {
			// If no assistant context, allow access (e.g., direct API call).
			return true;
		}

		// Get enabled connections for this assistant.
		$enabled_connections = get_post_meta( $assistant_id, '_wp_mcp_ai_pro_remote_connections', true );

		if ( ! is_array( $enabled_connections ) ) {
			// If not configured, allow all connections.
			return true;
		}

		return in_array( $connection_id, $enabled_connections, true );
	}

	/**
	 * Check rate limiting for remote site requests.
	 *
	 * Prevents abuse and reduces load on remote sites.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
	 */
	protected function check_rate_limit( $user_id ) {
		$user_id        = absint( $user_id );
		$transient_key  = 'wp_mcp_ai_pro_remote_wp_' . $user_id;
		$current_count  = get_transient( $transient_key );
		$max_per_minute = 30; // Allow up to 30 remote requests per minute per user.

		/**
		 * Filter the maximum remote site requests allowed per minute per user.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_per_minute Maximum requests per minute (default: 30).
		 * @param int $user_id        User ID.
		 */
		$max_per_minute = apply_filters( 'wp_mcp_ai_pro_remote_wp_rate_limit', $max_per_minute, $user_id );

		if ( false === $current_count ) {
			// First request, start counting.
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $max_per_minute ) {
			return new WP_Error(
				'wp_mcp_ai_pro_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum requests allowed per minute */
					__( 'Remote site request rate limit exceeded. Maximum %d requests per minute allowed.', 'wp-mcp-ai-pro' ),
					$max_per_minute
				)
			);
		}

		// Increment counter.
		set_transient( $transient_key, $current_count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro feature.
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls.
			'requires-capability',  // Requires 'edit_posts' capability.
			'cacheable',            // Results can be cached.
			'network-dependent',    // Requires internet connectivity.
			'may-timeout',          // External API calls may timeout.
			'large-response',       // May return large data sets.
			'paginated',            // Supports pagination.
			'rate-limited',         // Subject to rate limiting (30 requests/min/user).
			'supports-compression', // Supports gzip/deflate compression.
		);
	}
}
