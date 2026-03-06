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
	 * Essential WooCommerce product fields to retrieve.
	 *
	 * Excludes verbose fields like meta_data, related_ids, etc. to reduce token usage.
	 * Fields included: id, name, slug, permalink, sku, prices, stock info, type, status,
	 * categories, images, attributes, variations, parent_id, descriptions.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const PRODUCT_FIELDS = 'id,name,slug,permalink,sku,price,regular_price,sale_price,on_sale,' .
		'stock_status,stock_quantity,manage_stock,backorders_allowed,type,status,' .
		'categories,images,attributes,variations,parent_id,description,short_description';

	/**
	 * Essential WooCommerce product variation fields to retrieve.
	 *
	 * Fields included: id, sku, prices, stock info, attributes, image.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const VARIATION_FIELDS = 'id,sku,price,regular_price,sale_price,on_sale,' .
		'stock_status,stock_quantity,manage_stock,backorders_allowed,attributes,image';

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
		return __( 'Remote WordPress/WooCommerce Connection', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Access and manage remote WordPress and WooCommerce sites. Supports reading posts, pages, media, products, orders, and other data, plus creating, updating, and deleting content when the connection allows it. IMPORTANT: When using get_wc_products with include_variations enabled (default), variable products are represented ONLY by their variations (not the parent product) to provide accurate stock quantities. Products are automatically sorted with in-stock items first and return only essential fields to optimize token usage. Each variation includes parent_id and parent_name for reference. You do NOT need to make a separate call to get_wc_product_variations unless you want variations for a specific product only. WORKFLOW: Always call with action="list_connections" FIRST to discover available connection IDs, then use those IDs in subsequent calls. Never attempt get_posts, get_media, etc. without first calling list_connections. NOTE: Write operations (create/update/delete) require the connection to have those operations explicitly enabled by the site administrator.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'             => array(
					'type'        => 'string',
					'description' => __( 'The action to perform. IMPORTANT: Always call with "list_connections" FIRST to discover available connection IDs before any other action. Write actions (create_post, update_post, delete_post, create_wc_product, update_wc_product, delete_wc_product, update_wc_order) require the connection to have those operations enabled by the site administrator.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array(
						'list_connections',
						'test_connection',
						'get_posts',
						'get_post',
						'get_pages',
						'get_media',
						'get_wc_products',
						'get_wc_product',
						'get_wc_product_variations',
						'get_wc_orders',
						'get_wc_order',
						'get_wc_customers',
						'get_wc_categories',
						'create_post',
						'update_post',
						'delete_post',
						'create_wc_product',
						'update_wc_product',
						'delete_wc_product',
						'update_wc_order',
					),
					'default'     => 'list_connections',
				),
				'connection_id'      => array(
					'type'        => 'string',
					'description' => __( 'REQUIRED (except for list_connections action). The connection ID obtained from calling list_connections first. Format: conn_XXXX. You must call list_connections before using any other action to get this ID.', 'mcp-ai-wpoos-pro' ),
				),
				'post_type'          => array(
					'type'        => 'string',
					'description' => __( 'Post type to query (for get_posts action). Defaults to "post".', 'mcp-ai-wpoos-pro' ),
					'default'     => 'post',
				),
				'post_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Post or product ID for single item queries.', 'mcp-ai-wpoos-pro' ),
				),
				'order_id'           => array(
					'type'        => 'integer',
					'description' => __( 'WooCommerce order ID for order queries.', 'mcp-ai-wpoos-pro' ),
				),
				'per_page'           => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to retrieve per page. Default: 25 for get_wc_products (10 for other actions), Max: 100. Products are automatically sorted with in-stock items first.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'               => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'search'             => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter results.', 'mcp-ai-wpoos-pro' ),
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'Filter by status (publish, draft, etc. for posts; completed, processing, etc. for orders).', 'mcp-ai-wpoos-pro' ),
				),
				'sku'                => array(
					'type'        => 'string',
					'description' => __( 'Product SKU for WooCommerce product queries.', 'mcp-ai-wpoos-pro' ),
				),
				'stock_status'       => array(
					'type'        => 'string',
					'description' => __( 'Filter products by stock status (e.g., instock, outofstock, onbackorder) for WooCommerce product queries. When used with variable products, automatically filters variations to only show those matching the stock status.', 'mcp-ai-wpoos-pro' ),
				),
				'include_variations' => array(
					'type'        => 'boolean',
					'description' => __( 'For get_wc_products: Whether to include product variations in results. AUTOMATICALLY ENABLED BY DEFAULT (true). When enabled, variable products are represented ONLY by their variations (not the parent product) to avoid stock confusion. Each variation includes parent_id, parent_name, stock_quantity, stock_status, sku, price, and attributes. Set to false only if you want parent products without variations. To get variations for a specific product, use get_wc_product_variations instead.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'category'           => array(
					'type'        => 'string',
					'description' => __( 'Filter products by category slug or ID for WooCommerce product queries.', 'mcp-ai-wpoos-pro' ),
				),
				'type'               => array(
					'type'        => 'string',
					'description' => __( 'Filter products by type (e.g., simple, variable, grouped, external) for WooCommerce product queries. Also used as the product type when creating a WooCommerce product (create_wc_product).', 'mcp-ai-wpoos-pro' ),
				),
				'title'              => array(
					'type'        => 'string',
					'description' => __( 'Post title or WooCommerce product name. Required for create_post and create_wc_product actions; optional for update_post and update_wc_product.', 'mcp-ai-wpoos-pro' ),
				),
				'content'            => array(
					'type'        => 'string',
					'description' => __( 'Post body content (create_post, update_post) or WooCommerce product description (create_wc_product, update_wc_product). HTML is allowed.', 'mcp-ai-wpoos-pro' ),
				),
				'excerpt'            => array(
					'type'        => 'string',
					'description' => __( 'Post excerpt (create_post, update_post) or WooCommerce short description (create_wc_product, update_wc_product).', 'mcp-ai-wpoos-pro' ),
				),
				'fields'             => array(
					'type'        => 'object',
					'description' => __( 'Additional key-value fields for WooCommerce create/update operations. Supported keys for products: sku, regular_price, sale_price, stock_quantity, manage_stock (true/false), stock_status (instock/outofstock/onbackorder). Supported keys for orders: status, customer_note.', 'mcp-ai-wpoos-pro' ),
				),
				'force'              => array(
					'type'        => 'boolean',
					'description' => __( 'When true, permanently deletes the item instead of moving it to trash (delete_post, delete_wc_product). Default: false (moves to trash).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
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
				__( 'You do not have permission to access remote WordPress sites.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' )
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
					__( 'Connection ID is required for action "%1$s".%2$s You must provide the connection_id parameter with one of the available connection IDs.', 'mcp-ai-wpoos-pro' ),
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
					__( 'Invalid connection ID "%1$s".%2$s Use one of the available connection IDs.', 'mcp-ai-wpoos-pro' ),
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
					__( 'Connection "%s" is disabled. Please ask the user to enable it in the WordPress admin under NV oOS → Remote Sites.', 'mcp-ai-wpoos-pro' ),
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
					__( 'Connection "%s" is not enabled for this assistant. Please ask the user to enable it in the assistant editor under Remote Site Connections metabox.', 'mcp-ai-wpoos-pro' ),
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

			case 'get_wc_product_variations':
				return $this->get_wc_product_variations( $connection, $arguments );

			case 'get_wc_orders':
				return $this->get_wc_orders( $connection, $arguments );

			case 'get_wc_order':
				return $this->get_wc_order( $connection, $arguments );

			case 'get_wc_customers':
				return $this->get_wc_customers( $connection, $arguments );

			case 'get_wc_categories':
				return $this->get_wc_categories( $connection, $arguments );

			case 'create_post':
				return $this->create_post( $connection, $arguments );

			case 'update_post':
				return $this->update_post( $connection, $arguments );

			case 'delete_post':
				return $this->delete_post( $connection, $arguments );

			case 'create_wc_product':
				return $this->create_wc_product( $connection, $arguments );

			case 'update_wc_product':
				return $this->update_wc_product( $connection, $arguments );

			case 'delete_wc_product':
				return $this->delete_wc_product( $connection, $arguments );

			case 'update_wc_order':
				return $this->update_wc_order( $connection, $arguments );

			default:
				return new WP_Error(
					'wp_mcp_ai_pro_invalid_action',
					__( 'Invalid action specified.', 'mcp-ai-wpoos-pro' )
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
				__( 'Found %d remote site connection(s)', 'mcp-ai-wpoos-pro' ),
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
		$per_page  = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page  = min( max( $per_page, 1 ), 100 );
		$page      = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		// Enforce post type access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, $post_type, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				sprintf(
					/* translators: %s: post type slug */
					__( 'Read access to post type "%s" is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' ),
					$post_type
				)
			);
		}

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
				__( 'Retrieved %d post(s)', 'mcp-ai-wpoos-pro' ),
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
				__( 'Post ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$post_id   = absint( $arguments['post_id'] );
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		// Enforce post type access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, $post_type, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				sprintf(
					/* translators: %s: post type slug */
					__( 'Read access to post type "%s" is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' ),
					$post_type
				)
			);
		}

		$endpoint = 'wp/v2/' . $post_type . '/' . $post_id;

		$post = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return array(
			'summary' => __( 'Post retrieved successfully', 'mcp-ai-wpoos-pro' ),
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
		// Enforce attachment (media) access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, 'attachment', 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Read access to media (attachment) is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

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
				__( 'Retrieved %d media item(s)', 'mcp-ai-wpoos-pro' ),
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
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enforce WooCommerce products access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'products', 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Read access to WooCommerce products is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 25;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

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

		if ( ! empty( $arguments['category'] ) ) {
			$params['category'] = sanitize_text_field( $arguments['category'] );
		}

		if ( ! empty( $arguments['type'] ) ) {
			$params['type'] = sanitize_key( $arguments['type'] );
		}

		if ( ! empty( $arguments['stock_status'] ) ) {
			$params['stock_status'] = sanitize_key( $arguments['stock_status'] );
		}

		// Exclude verbose fields to reduce token usage.
		// Keep only essential product information including description (will be truncated).
		$params['_fields'] = self::PRODUCT_FIELDS;

		$endpoint = 'wc/v3/products';

		if ( ! empty( $params ) ) {
			$endpoint = add_query_arg( $params, $endpoint );
		}

		$products = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $products ) ) {
			return $products;
		}

		// Optimize images to save tokens (limit to 3 images, keep only src and alt).
		$products = $this->optimize_product_images( $products );

		// Truncate descriptions to save tokens while keeping essential info.
		$products = $this->truncate_product_descriptions( $products );

		// Sort products by stock status (in-stock first) since WooCommerce API doesn't support
		// orderby=stock_status. We sort client-side after fetching.
		$products = $this->sort_products_by_stock_status( $products );

		// Check if we should include variations.
		$include_variations = isset( $arguments['include_variations'] ) ? (bool) $arguments['include_variations'] : true;

		// Get stock_status filter if provided.
		$filter_stock_status = ! empty( $arguments['stock_status'] ) ? sanitize_key( $arguments['stock_status'] ) : '';

		$all_products    = array();
		$variation_count = 0;
		$parent_count    = 0;

		if ( $include_variations ) {
			// Optimize: Collect all variable product IDs first, then fetch variations in batch.
			$variable_product_ids  = array();
			$variable_products_map = array();
			$simple_products       = array();

			foreach ( $products as $product ) {
				$is_variable    = isset( $product->type ) && 'variable' === $product->type;
				$has_product_id = isset( $product->id );

				if ( $is_variable && $has_product_id ) {
					$variable_product_ids[]                = $product->id;
					$variable_products_map[ $product->id ] = $product;
				} else {
					// Non-variable products (simple, grouped, external, etc.).
					$simple_products[] = $product;
				}
			}

			// Fetch all variations in optimized batch mode if there are variable products.
			if ( ! empty( $variable_product_ids ) ) {
				$all_variations = $this->fetch_all_product_variations_batch( $connection, $variable_product_ids );

				// Process variable products with their variations.
				foreach ( $variable_product_ids as $product_id ) {
					$product = $variable_products_map[ $product_id ];

					if ( isset( $all_variations[ $product_id ] ) && ! empty( $all_variations[ $product_id ] ) ) {
						$has_matching_variations = false;

						// Add each variation with parent context.
						foreach ( $all_variations[ $product_id ] as $variation ) {
							if ( isset( $variation->id ) ) {
								// Filter variations by stock_status if specified.
								if ( $filter_stock_status ) {
									$variation_stock_status = isset( $variation->stock_status ) ? $variation->stock_status : '';
									if ( $variation_stock_status !== $filter_stock_status ) {
										// Skip variations that don't match the stock_status filter.
										continue;
									}
								}

								$variation->parent_id   = $product->id;
								$variation->parent_name = isset( $product->name ) ? $product->name : '';
								$all_products[]         = $variation;
								++$variation_count;
								$has_matching_variations = true;
							}
						}

						if ( $has_matching_variations ) {
							++$parent_count;
						}
					} else {
						// If fetching variations failed or no variations exist, include the parent product.
						$all_products[] = $product;
						++$parent_count;
					}
				}
			}

			// Add all simple/non-variable products.
			foreach ( $simple_products as $product ) {
				$all_products[] = $product;
				++$parent_count;
			}
		} else {
			// If variations are not requested, just add all products as-is.
			foreach ( $products as $product ) {
				$all_products[] = $product;
				++$parent_count;
			}
		}

		// Build summary message.
		if ( $variation_count > 0 ) {
			$summary = sprintf(
				/* translators: 1: number of product groups (variable products counted as groups, simple products as individual), 2: number of variations */
				__( 'Retrieved %1$d product(s) with %2$d variation(s). Note: Variable products are represented by their variations only, not the parent product.', 'mcp-ai-wpoos-pro' ),
				$parent_count,
				$variation_count
			);
		} else {
			$summary = sprintf(
				/* translators: %d: number of products */
				__( 'Retrieved %d product(s)', 'mcp-ai-wpoos-pro' ),
				$parent_count
			);
		}

		return array(
			'summary'         => $summary,
			'products'        => $all_products,
			'count'           => count( $all_products ),
			'parent_count'    => $parent_count,
			'variation_count' => $variation_count,
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
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enforce WooCommerce products access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'products', 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Read access to WooCommerce products is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_product_id',
				__( 'Product ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product_id = absint( $arguments['post_id'] );
		$endpoint   = 'wc/v3/products/' . $product_id;

		$product = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		// Optimize the single product to reduce token usage.
		if ( is_object( $product ) ) {
			$product_array = array( $product );
			$product_array = $this->optimize_product_images( $product_array );
			$product_array = $this->truncate_product_descriptions( $product_array );
			$product       = $product_array[0];
		}

		return array(
			'summary' => __( 'Product retrieved successfully', 'mcp-ai-wpoos-pro' ),
			'product' => $product,
		);
	}

	/**
	 * Get WooCommerce product variations from remote site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Query arguments.
	 * @return array|WP_Error Variations data.
	 */
	protected function get_wc_product_variations( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enforce WooCommerce products access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'products', 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Read access to WooCommerce products is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_product_id',
				__( 'Product ID is required for fetching variations.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product_id = absint( $arguments['post_id'] );
		$variations = $this->fetch_product_variations( $connection, $product_id );

		if ( is_wp_error( $variations ) ) {
			return $variations;
		}

		return array(
			'summary'    => sprintf(
				/* translators: 1: number of variations, 2: product ID */
				__( 'Retrieved %1$d variation(s) for product ID %2$d', 'mcp-ai-wpoos-pro' ),
				count( $variations ),
				$product_id
			),
			'variations' => $variations,
			'count'      => count( $variations ),
			'product_id' => $product_id,
		);
	}

	/**
	 * Fetch product variations from remote site.
	 *
	 * Helper method to retrieve all variations for a given product ID.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param int   $product_id Product ID.
	 * @return array|WP_Error Array of variations or WP_Error on failure.
	 */
	protected function fetch_product_variations( $connection, $product_id ) {
		$product_id = absint( $product_id );

		if ( ! $product_id ) {
			return new WP_Error(
				'wp_mcp_ai_pro_invalid_product_id',
				__( 'Invalid product ID for fetching variations.', 'mcp-ai-wpoos-pro' )
			);
		}

		$endpoint = 'wc/v3/products/' . $product_id . '/variations';

		// Get variations (max 100 per page, first page only).
		// Exclude verbose fields to reduce token usage.
		$params = array(
			'per_page' => 100,
			'_fields'  => self::VARIATION_FIELDS,
		);

		$endpoint = add_query_arg( $params, $endpoint );

		$variations = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $variations ) ) {
			return $variations;
		}

		if ( ! is_array( $variations ) ) {
			return array();
		}

		// Optimize variation images to save tokens.
		$variations = $this->optimize_product_images( $variations );

		return $variations;
	}

	/**
	 * Fetch product variations for multiple products in optimized batch mode.
	 *
	 * This method optimizes variation fetching by checking cache first and reducing
	 * redundant processing. While WordPress HTTP API doesn't support truly parallel
	 * requests, this method minimizes overhead by batching the logic and leveraging
	 * the existing caching layer in the Remote Site Manager.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection        Connection data.
	 * @param array $product_ids       Array of product IDs to fetch variations for.
	 * @return array Associative array mapping product_id => variations array.
	 */
	protected function fetch_all_product_variations_batch( $connection, $product_ids ) {
		if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
			return array();
		}

		$results = array();

		// Fetch variations for all products.
		// The Remote Site Manager's make_request method will handle caching,
		// so subsequent requests for the same product will be fast.
		foreach ( $product_ids as $product_id ) {
			$product_id = absint( $product_id );
			if ( ! $product_id ) {
				continue;
			}

			$endpoint = 'wc/v3/products/' . $product_id . '/variations';
			$params   = array(
				'per_page' => 100,
				'_fields'  => self::VARIATION_FIELDS,
			);
			$endpoint = add_query_arg( $params, $endpoint );

			// Use the existing make_request which handles caching and authentication.
			$variations = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

			if ( is_wp_error( $variations ) || ! is_array( $variations ) ) {
				// On error or invalid response, store empty array for this product.
				$results[ $product_id ] = array();
				continue;
			}

			// Optimize variation images to save tokens.
			$variations = $this->optimize_product_images( $variations );

			$results[ $product_id ] = $variations;
		}

		return $results;
	}

	/**
	 * Optimize image arrays to reduce token usage.
	 *
	 * Reduces image data to only essential fields (src and alt), removing verbose date fields.
	 * Limits to first 3 images.
	 *
	 * @since 1.0.0
	 *
	 * @param array $products Array of product objects.
	 * @return array Products with optimized image arrays.
	 */
	protected function optimize_product_images( $products ) {
		if ( ! is_array( $products ) ) {
			return $products;
		}

		foreach ( $products as $product ) {
			// Optimize images array - keep only src and alt, removing all date fields.
			if ( isset( $product->images ) && is_array( $product->images ) ) {
				$optimized_images = array();
				$image_count      = 0;

				foreach ( $product->images as $image ) {
					if ( $image_count >= 3 ) {
						break; // Limit to first 3 images to save tokens.
					}

					if ( is_object( $image ) && isset( $image->src ) ) {
						$optimized_images[] = (object) array(
							'src' => $image->src,
							'alt' => isset( $image->alt ) ? $image->alt : '',
						);
					} elseif ( is_array( $image ) && isset( $image['src'] ) ) {
						$optimized_images[] = array(
							'src' => $image['src'],
							'alt' => isset( $image['alt'] ) ? $image['alt'] : '',
						);
					}

					++$image_count;
				}

				$product->images = $optimized_images;
			}

			// Optimize single image field for variations.
			if ( isset( $product->image ) && is_object( $product->image ) && isset( $product->image->src ) ) {
				$product->image = (object) array(
					'src' => $product->image->src,
					'alt' => isset( $product->image->alt ) ? $product->image->alt : '',
				);
			} elseif ( isset( $product->image ) && is_array( $product->image ) && isset( $product->image['src'] ) ) {
				$product->image = array(
					'src' => $product->image['src'],
					'alt' => isset( $product->image['alt'] ) ? $product->image['alt'] : '',
				);
			}
		}

		return $products;
	}

	/**
	 * Truncate product descriptions to reduce token usage.
	 *
	 * Limits descriptions to 2-3 sentences while preserving essential information.
	 *
	 * @since 1.0.0
	 *
	 * @param array $products Array of product objects.
	 * @return array Products with truncated descriptions.
	 */
	protected function truncate_product_descriptions( $products ) {
		if ( ! is_array( $products ) ) {
			return $products;
		}

		foreach ( $products as $product ) {
			// Truncate description (long version).
			if ( isset( $product->description ) && ! empty( $product->description ) ) {
				$product->description = $this->truncate_to_sentences( $product->description, 3 );
			}

			// Truncate short_description.
			if ( isset( $product->short_description ) && ! empty( $product->short_description ) ) {
				$product->short_description = $this->truncate_to_sentences( $product->short_description, 2 );
			}
		}

		return $products;
	}

	/**
	 * Sort products by stock status and product type.
	 *
	 * Sorts products client-side to show in-stock items first, with variable products
	 * prioritized over simple products within each stock status. Since the WooCommerce
	 * REST API v3 doesn't support complex sorting. Sorting order:
	 * 1. instock variable
	 * 2. instock simple (and other types)
	 * 3. onbackorder variable
	 * 4. onbackorder simple (and other types)
	 * 5. outofstock variable
	 * 6. outofstock simple (and other types)
	 *
	 * @since 1.0.0
	 *
	 * @param array $products Array of product objects.
	 * @return array Products sorted by stock status and type.
	 */
	protected function sort_products_by_stock_status( $products ) {
		if ( ! is_array( $products ) || empty( $products ) ) {
			return $products;
		}

		// Define stock status priority (lower number = higher priority).
		$stock_priority = array(
			'instock'     => 1,
			'onbackorder' => 2,
			'outofstock'  => 3,
		);

		// Define product type priority (lower number = higher priority).
		$type_priority = array(
			'variable' => 1,
			'simple'   => 2,
		);

		usort(
			$products,
			function ( $a, $b ) use ( $stock_priority, $type_priority ) {
				$stock_a = isset( $a->stock_status ) ? $a->stock_status : 'outofstock';
				$stock_b = isset( $b->stock_status ) ? $b->stock_status : 'outofstock';

				// Get priority for each stock status (default to 999 if unknown).
				$priority_a = isset( $stock_priority[ $stock_a ] ) ? $stock_priority[ $stock_a ] : 999;
				$priority_b = isset( $stock_priority[ $stock_b ] ) ? $stock_priority[ $stock_b ] : 999;

				// If stock status is different, sort by stock status.
				if ( $priority_a !== $priority_b ) {
					return $priority_a - $priority_b;
				}

				// Stock status is the same, sort by product type.
				$type_a = isset( $a->type ) ? $a->type : 'simple';
				$type_b = isset( $b->type ) ? $b->type : 'simple';

				$type_priority_a = isset( $type_priority[ $type_a ] ) ? $type_priority[ $type_a ] : 999;
				$type_priority_b = isset( $type_priority[ $type_b ] ) ? $type_priority[ $type_b ] : 999;

				return $type_priority_a - $type_priority_b;
			}
		);

		return $products;
	}

	/**
	 * Truncate text to a specific number of sentences.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text            Text to truncate.
	 * @param int    $sentence_count  Number of sentences to keep.
	 * @return string Truncated text.
	 */
	protected function truncate_to_sentences( $text, $sentence_count = 3 ) {
		if ( empty( $text ) ) {
			return $text;
		}

		// Strip HTML tags first.
		$text = wp_strip_all_tags( $text );

		// Split by sentence endings with intelligent boundary detection.
		// Regex explained:
		// - (?<=[.!?]) = Positive lookbehind: Must be preceded by sentence-ending punctuation
		// - (?=\s+[A-Z]) = Positive lookahead: Must be followed by whitespace + capital letter
		// - | = OR
		// - (?<=[.!?])$ = Positive lookbehind + end of string anchor
		//
		// This pattern:
		// ✓ Splits on: "sentence. Next" or "sentence! Next" or "sentence? Next"
		// ✗ Does NOT split on: "Mr. Smith" or "$19.99" or "U.S.A." (no capital after space)
		$sentences = preg_split( '/(?<=[.!?])(?=\s+[A-Z])|(?<=[.!?])$/', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $sentences ) || count( $sentences ) <= 1 ) {
			// No sentence boundaries found or only one sentence.
			return $text;
		}

		// Reconstruct with limited number of sentences.
		$result_sentences = array_slice( $sentences, 0, $sentence_count );
		$result           = implode( ' ', array_map( 'trim', $result_sentences ) );

		// If we truncated (more sentences exist than we included), add ellipsis.
		if ( count( $sentences ) > $sentence_count ) {
			$result = rtrim( $result ) . '...';
		}

		return trim( $result );
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
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enforce WooCommerce orders access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'orders', 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Read access to WooCommerce orders is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

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
				__( 'Retrieved %d order(s)', 'mcp-ai-wpoos-pro' ),
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
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enforce WooCommerce orders access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'orders', 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Read access to WooCommerce orders is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['order_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_order_id',
				__( 'Order ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$order_id = absint( $arguments['order_id'] );
		$endpoint = 'wc/v3/orders/' . $order_id;

		$order = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint );

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		return array(
			'summary' => __( 'Order retrieved successfully', 'mcp-ai-wpoos-pro' ),
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
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enforce WooCommerce customers access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'customers', 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Read access to WooCommerce customers is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

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
				__( 'Retrieved %d customer(s)', 'mcp-ai-wpoos-pro' ),
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
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Enforce WooCommerce categories access controls.
		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'categories', 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Read access to WooCommerce product categories is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$per_page = min( max( $per_page, 1 ), 100 );
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

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
				__( 'Retrieved %d product categor(ies)', 'mcp-ai-wpoos-pro' ),
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
					__( 'Remote site request rate limit exceeded. Maximum %d requests per minute allowed.', 'mcp-ai-wpoos-pro' ),
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
			'external-api',         // Makes external API calls.
			'requires-capability',  // Requires 'edit_posts' capability.
			'cacheable',            // GET results can be cached.
			'network-dependent',    // Requires internet connectivity.
			'may-timeout',          // External API calls may timeout.
			'large-response',       // May return large data sets.
			'paginated',            // Supports pagination.
			'rate-limited',         // Subject to rate limiting (30 requests/min/user).
			'supports-compression', // Supports gzip/deflate compression.
			'write-capable',        // Can perform write operations when enabled by admin.
		);
	}

	/**
	 * Create a post on the remote WordPress site.
	 *
	 * Requires 'create' operation to be enabled for the post type in the connection's
	 * post_type_access configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Tool arguments including title, content, status, post_type.
	 * @return array|WP_Error Result array or WP_Error on failure.
	 */
	protected function create_post( $connection, $arguments ) {
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, $post_type, 'create' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				sprintf(
					/* translators: %s: post type slug */
					__( 'Create access to post type "%s" is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' ),
					$post_type
				)
			);
		}

		if ( empty( $arguments['title'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_title',
				__( 'Post title is required for create_post.', 'mcp-ai-wpoos-pro' )
			);
		}

		$body = array(
			'title'  => sanitize_text_field( $arguments['title'] ),
			'status' => ! empty( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'draft',
		);

		if ( ! empty( $arguments['content'] ) ) {
			$body['content'] = wp_kses_post( $arguments['content'] );
		}

		if ( ! empty( $arguments['excerpt'] ) ) {
			$body['excerpt'] = sanitize_textarea_field( $arguments['excerpt'] );
		}

		$endpoint = 'wp/v2/' . $post_type;
		$result   = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint, 'POST', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => __( 'Post created successfully', 'mcp-ai-wpoos-pro' ),
			'post'    => $result,
		);
	}

	/**
	 * Update a post on the remote WordPress site.
	 *
	 * Requires 'update' operation to be enabled for the post type in the connection's
	 * post_type_access configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Tool arguments including post_id, and at least one of: title, content, excerpt, status.
	 * @return array|WP_Error Result array or WP_Error on failure.
	 */
	protected function update_post( $connection, $arguments ) {
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, $post_type, 'update' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				sprintf(
					/* translators: %s: post type slug */
					__( 'Update access to post type "%s" is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' ),
					$post_type
				)
			);
		}

		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_post_id',
				__( 'Post ID is required for update_post.', 'mcp-ai-wpoos-pro' )
			);
		}

		$post_id = absint( $arguments['post_id'] );
		$body    = array();

		if ( ! empty( $arguments['title'] ) ) {
			$body['title'] = sanitize_text_field( $arguments['title'] );
		}

		if ( ! empty( $arguments['content'] ) ) {
			$body['content'] = wp_kses_post( $arguments['content'] );
		}

		if ( ! empty( $arguments['excerpt'] ) ) {
			$body['excerpt'] = sanitize_textarea_field( $arguments['excerpt'] );
		}

		if ( ! empty( $arguments['status'] ) ) {
			$body['status'] = sanitize_key( $arguments['status'] );
		}

		if ( empty( $body ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_fields',
				__( 'At least one of title, content, excerpt, or status is required for update_post.', 'mcp-ai-wpoos-pro' )
			);
		}

		$endpoint = 'wp/v2/' . $post_type . '/' . $post_id;
		$result   = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint, 'POST', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => __( 'Post updated successfully', 'mcp-ai-wpoos-pro' ),
			'post'    => $result,
		);
	}

	/**
	 * Delete a post on the remote WordPress site.
	 *
	 * Requires 'delete' operation to be enabled for the post type in the connection's
	 * post_type_access configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Tool arguments including post_id. Optional: force (bool), post_type.
	 * @return array|WP_Error Result array or WP_Error on failure.
	 */
	protected function delete_post( $connection, $arguments ) {
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_post_type_operation_allowed( $connection, $post_type, 'delete' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				sprintf(
					/* translators: %s: post type slug */
					__( 'Delete access to post type "%s" is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' ),
					$post_type
				)
			);
		}

		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_post_id',
				__( 'Post ID is required for delete_post.', 'mcp-ai-wpoos-pro' )
			);
		}

		$post_id  = absint( $arguments['post_id'] );
		$endpoint = 'wp/v2/' . $post_type . '/' . $post_id;

		if ( ! empty( $arguments['force'] ) ) {
			$endpoint = add_query_arg( 'force', 'true', $endpoint );
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint, 'DELETE' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => __( 'Post deleted successfully', 'mcp-ai-wpoos-pro' ),
			'result'  => $result,
		);
	}

	/**
	 * Create a WooCommerce product on the remote site.
	 *
	 * Requires 'create' operation to be enabled for the 'products' resource in the
	 * connection's wc_resource_access configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Tool arguments including title. Optional: content, excerpt, status, type, fields.
	 * @return array|WP_Error Result array or WP_Error on failure.
	 */
	protected function create_wc_product( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'products', 'create' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Create access to WooCommerce products is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['title'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_title',
				__( 'Product name (title) is required for create_wc_product.', 'mcp-ai-wpoos-pro' )
			);
		}

		$body = array(
			'name'   => sanitize_text_field( $arguments['title'] ),
			'status' => ! empty( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'draft',
			'type'   => ! empty( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : 'simple',
		);

		if ( ! empty( $arguments['content'] ) ) {
			$body['description'] = wp_kses_post( $arguments['content'] );
		}

		if ( ! empty( $arguments['excerpt'] ) ) {
			$body['short_description'] = wp_kses_post( $arguments['excerpt'] );
		}

		// Merge in optional structured fields (sku, prices, stock, etc.).
		$allowed_fields = array( 'sku', 'regular_price', 'sale_price', 'stock_quantity', 'manage_stock', 'stock_status' );

		if ( ! empty( $arguments['fields'] ) && is_array( $arguments['fields'] ) ) {
			foreach ( $allowed_fields as $field ) {
				if ( isset( $arguments['fields'][ $field ] ) ) {
					// manage_stock must be boolean per WooCommerce REST API spec.
					if ( 'manage_stock' === $field ) {
						$body[ $field ] = (bool) $arguments['fields'][ $field ];
					} else {
						$body[ $field ] = sanitize_text_field( (string) $arguments['fields'][ $field ] );
					}
				}
			}
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, 'wc/v3/products', 'POST', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => __( 'WooCommerce product created successfully', 'mcp-ai-wpoos-pro' ),
			'product' => $result,
		);
	}

	/**
	 * Update a WooCommerce product on the remote site.
	 *
	 * Requires 'update' operation to be enabled for the 'products' resource in the
	 * connection's wc_resource_access configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Tool arguments including post_id. Optional: title, content, excerpt, status, fields.
	 * @return array|WP_Error Result array or WP_Error on failure.
	 */
	protected function update_wc_product( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'products', 'update' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Update access to WooCommerce products is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_product_id',
				__( 'Product ID is required for update_wc_product.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product_id = absint( $arguments['post_id'] );
		$body       = array();

		if ( ! empty( $arguments['title'] ) ) {
			$body['name'] = sanitize_text_field( $arguments['title'] );
		}

		if ( ! empty( $arguments['content'] ) ) {
			$body['description'] = wp_kses_post( $arguments['content'] );
		}

		if ( ! empty( $arguments['excerpt'] ) ) {
			$body['short_description'] = wp_kses_post( $arguments['excerpt'] );
		}

		if ( ! empty( $arguments['status'] ) ) {
			$body['status'] = sanitize_key( $arguments['status'] );
		}

		// Merge in optional structured fields (sku, prices, stock, etc.).
		$allowed_fields = array( 'sku', 'regular_price', 'sale_price', 'stock_quantity', 'manage_stock', 'stock_status' );

		if ( ! empty( $arguments['fields'] ) && is_array( $arguments['fields'] ) ) {
			foreach ( $allowed_fields as $field ) {
				if ( isset( $arguments['fields'][ $field ] ) ) {
					// manage_stock must be boolean per WooCommerce REST API spec.
					if ( 'manage_stock' === $field ) {
						$body[ $field ] = (bool) $arguments['fields'][ $field ];
					} else {
						$body[ $field ] = sanitize_text_field( (string) $arguments['fields'][ $field ] );
					}
				}
			}
		}

		if ( empty( $body ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_fields',
				__( 'At least one field must be provided for update_wc_product.', 'mcp-ai-wpoos-pro' )
			);
		}

		$endpoint = 'wc/v3/products/' . $product_id;
		$result   = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint, 'PUT', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => __( 'WooCommerce product updated successfully', 'mcp-ai-wpoos-pro' ),
			'product' => $result,
		);
	}

	/**
	 * Delete a WooCommerce product on the remote site.
	 *
	 * Requires 'delete' operation to be enabled for the 'products' resource in the
	 * connection's wc_resource_access configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Tool arguments including post_id. Optional: force (bool).
	 * @return array|WP_Error Result array or WP_Error on failure.
	 */
	protected function delete_wc_product( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'products', 'delete' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Delete access to WooCommerce products is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['post_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_product_id',
				__( 'Product ID is required for delete_wc_product.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product_id = absint( $arguments['post_id'] );
		$endpoint   = 'wc/v3/products/' . $product_id;

		if ( ! empty( $arguments['force'] ) ) {
			$endpoint = add_query_arg( 'force', 'true', $endpoint );
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint, 'DELETE' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => __( 'WooCommerce product deleted successfully', 'mcp-ai-wpoos-pro' ),
			'result'  => $result,
		);
	}

	/**
	 * Update a WooCommerce order on the remote site.
	 *
	 * Requires 'update' operation to be enabled for the 'orders' resource in the
	 * connection's wc_resource_access configuration.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @param array $arguments  Tool arguments including order_id. Optional: status, fields (customer_note).
	 * @return array|WP_Error Result array or WP_Error on failure.
	 */
	protected function update_wc_order( $connection, $arguments ) {
		if ( empty( $connection['has_woocommerce'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_no_woocommerce',
				__( 'This connection does not have WooCommerce enabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! WP_MCP_AI_Pro_Remote_Site_Manager::is_wc_resource_operation_allowed( $connection, 'orders', 'update' ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_access_denied',
				__( 'Update access to WooCommerce orders is not permitted for this connection. The site administrator must enable it under Remote Sites → Access Controls.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['order_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_order_id',
				__( 'Order ID is required for update_wc_order.', 'mcp-ai-wpoos-pro' )
			);
		}

		$order_id = absint( $arguments['order_id'] );
		$body     = array();

		if ( ! empty( $arguments['status'] ) ) {
			$body['status'] = sanitize_key( $arguments['status'] );
		}

		// Allow updating customer_note via fields.
		if ( ! empty( $arguments['fields'] ) && is_array( $arguments['fields'] ) ) {
			if ( isset( $arguments['fields']['customer_note'] ) ) {
				$body['customer_note'] = sanitize_textarea_field( $arguments['fields']['customer_note'] );
			}
		}

		if ( empty( $body ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_missing_fields',
				__( 'At least one field (status or customer_note) is required for update_wc_order.', 'mcp-ai-wpoos-pro' )
			);
		}

		$endpoint = 'wc/v3/orders/' . $order_id;
		$result   = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint, 'PUT', $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => __( 'WooCommerce order updated successfully', 'mcp-ai-wpoos-pro' ),
			'order'   => $result,
		);
	}
}
