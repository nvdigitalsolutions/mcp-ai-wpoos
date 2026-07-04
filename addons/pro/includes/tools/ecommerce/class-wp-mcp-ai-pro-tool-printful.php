<?php
/**
 * Printful Print-on-Demand AI Tool.
 *
 * Enables AI assistants to browse the Printful catalog, manage sync products,
 * create and track orders, generate mockups, and retrieve store statistics.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Printful' ) ) {

	/**
	 * Printful Print-on-Demand AI Tool.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Pro_Tool_Printful implements WP_MCP_AI_Tool_Interface {

		/**
		 * {@inheritdoc}
		 */
		public function get_slug() {
			return 'printful';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_name() {
			return __( 'Printful (Print-on-Demand)', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_description() {
			return __( 'Access and manage Printful print-on-demand services. Browse the product catalog, manage store products and variants, create and track orders, generate mockups, calculate shipping rates, and view store statistics. IMPORTANT: Always call with action="list_connections" FIRST to discover available Printful connection IDs, then use those IDs in subsequent calls. WORFKLOW: Use get_catalog_products and get_catalog_variant to browse available products, create_sync_product to add products to your store, then create_order to place orders with those products.', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_parameters_schema() {
			return array(
				'type'                 => 'object',
				'properties'           => array(
					'action'            => array(
						'type'        => 'string',
						'description' => __( 'The action to perform. IMPORTANT: Always call with "list_connections" FIRST to discover available connection IDs.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array(
							'list_connections',
							'get_catalog_products',
							'get_catalog_product',
							'get_catalog_variant',
							'get_categories',
							'get_countries',
							'get_sync_products',
							'create_sync_product',
							'get_sync_product',
							'update_sync_product',
							'delete_sync_product',
							'get_orders',
							'create_order',
							'get_order',
							'update_order',
							'cancel_order',
							'confirm_order',
							'estimate_costs',
							'get_shipping_rates',
							'get_stores',
							'get_statistics',
							'get_warehouse_products',
							'create_mockup_task',
							'get_mockup_task',
						),
						'default'     => 'list_connections',
					),
					'connection_id'     => array(
						'type'        => 'string',
						'description' => __( 'REQUIRED (except for list_connections action). The Printful connection ID obtained from calling list_connections first.', 'mcp-ai-wpoos-pro' ),
					),
					// Catalog filters.
					'category_id'       => array(
						'type'        => 'integer',
						'description' => __( 'Filter products by Printful category ID. Use get_categories to discover available category IDs.', 'mcp-ai-wpoos-pro' ),
					),
					'product_id'        => array(
						'type'        => 'integer',
						'description' => __( 'Printful product ID for catalog lookups.', 'mcp-ai-wpoos-pro' ),
					),
					'variant_id'        => array(
						'type'        => 'integer',
						'description' => __( 'Printful variant ID for variant lookups.', 'mcp-ai-wpoos-pro' ),
					),
					'sync_product_id'   => array(
						'type'        => array( 'string', 'integer' ),
						'description' => __( 'Sync product ID (numeric) or external ID (prefixed with @).', 'mcp-ai-wpoos-pro' ),
					),
					'sync_variant_id'   => array(
						'type'        => array( 'string', 'integer' ),
						'description' => __( 'Sync variant ID (numeric) or external ID (prefixed with @).', 'mcp-ai-wpoos-pro' ),
					),
					'order_id'          => array(
						'type'        => array( 'string', 'integer' ),
						'description' => __( 'Order ID (numeric) or external ID (prefixed with @).', 'mcp-ai-wpoos-pro' ),
					),
					'status'            => array(
						'type'        => 'string',
						'description' => __( 'Filter orders by status (draft, pending, failed, canceled, inprocess, onhold, partial, fulfilled).', 'mcp-ai-wpoos-pro' ),
					),
					'product_status'    => array(
						'type'        => 'string',
						'description' => __( 'Filter sync products by status (all, synced, unsynced, ignored, imported, discontinued, out_of_stock).', 'mcp-ai-wpoos-pro' ),
					),
					'offset'            => array(
						'type'        => 'integer',
						'description' => __( 'Pagination offset (default: 0).', 'mcp-ai-wpoos-pro' ),
						'default'     => 0,
					),
					'limit'             => array(
						'type'        => 'integer',
						'description' => __( 'Number of items per page (default: 10, max: 100).', 'mcp-ai-wpoos-pro' ),
						'default'     => 10,
						'minimum'     => 1,
						'maximum'     => 100,
					),
					// Order creation / data payloads.
					'order_data'        => array(
						'type'        => 'object',
						'description' => __( 'Order data for create_order, update_order, or estimate_costs. Must include recipient and items. See Printful API docs for full field reference.', 'mcp-ai-wpoos-pro' ),
					),
					'sync_product_data' => array(
						'type'        => 'object',
						'description' => __( 'Product data for create_sync_product or update_sync_product. Must include sync_product and sync_variants. See Printful API docs.', 'mcp-ai-wpoos-pro' ),
					),
					'shipping_data'     => array(
						'type'        => 'object',
						'description' => __( 'Shipping rate calculation data. Must include recipient (with country_code) and items.', 'mcp-ai-wpoos-pro' ),
					),
					'mockup_data'       => array(
						'type'        => 'object',
						'description' => __( 'Mockup generation data. Must include variant_ids and files.', 'mcp-ai-wpoos-pro' ),
					),
					'task_key'          => array(
						'type'        => 'string',
						'description' => __( 'Mockup generation task key returned by create_mockup_task.', 'mcp-ai-wpoos-pro' ),
					),
					// Order flags.
					'confirm'           => array(
						'type'        => 'boolean',
						'description' => __( 'When true, auto-confirms the order for fulfillment instead of creating a draft. Default: false.', 'mcp-ai-wpoos-pro' ),
						'default'     => false,
					),
					// Statistics.
					'date_from'         => array(
						'type'        => 'string',
						'description' => __( 'Start date for statistics in Y-m-d format (e.g. 2024-01-01).', 'mcp-ai-wpoos-pro' ),
					),
					'date_to'           => array(
						'type'        => 'string',
						'description' => __( 'End date for statistics in Y-m-d format (e.g. 2024-01-31). Max 6 months range.', 'mcp-ai-wpoos-pro' ),
					),
					'report_types'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => __( 'Report types for statistics (e.g. ["sales_and_costs", "profit"]). Available: sales_and_costs, sales_and_costs_summary, printful_costs, profit, total_paid_orders, costs_by_amount, costs_by_product, costs_by_variant, average_fulfillment_time.', 'mcp-ai-wpoos-pro' ),
					),
					'currency'          => array(
						'type'        => 'string',
						'description' => __( '3-letter currency code for statistics (e.g. USD, EUR).', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'             => array( 'action' ),
				'additionalProperties' => false,
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
		 * @param array $context   Execution context including user_id.
		 * @return array|WP_Error Tool results or error.
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			$action  = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list_connections';

			// Check user permissions.
			if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to access Printful services.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_site',
					__( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}

			// Handle listing connections (no connection_id needed).
			if ( 'list_connections' === $action ) {
				return $this->list_connections( $context );
			}

			// Get connection.
			$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

			if ( empty( $connection_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_missing_connection',
					sprintf(
						/* translators: %s: action name */
						__( 'Connection ID is required for action "%s". Call list_connections first to discover available Printful connections.', 'mcp-ai-wpoos-pro' ),
						$action
					),
					array( 'status' => 400 )
				);
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_invalid_connection',
					sprintf(
						/* translators: %s: connection ID */
						__( 'Invalid connection ID "%s". Use list_connections to discover available connections.', 'mcp-ai-wpoos-pro' ),
						$connection_id
					),
					array( 'status' => 404 )
				);
			}

			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_disabled_connection',
					sprintf(
						/* translators: %s: connection name */
						__( 'Connection "%s" is disabled. Please enable it in the WordPress admin under NV oOS → Remote Sites.', 'mcp-ai-wpoos-pro' ),
						isset( $connection['name'] ) ? $connection['name'] : $connection_id
					),
					array( 'status' => 403 )
				);
			}

			// Check if this connection is enabled for the current assistant.
			if ( ! $this->is_connection_enabled_for_assistant( $connection_id, $context ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_enabled',
					sprintf(
						/* translators: %s: connection name */
						__( 'Connection "%s" is not enabled for this assistant. Please enable it in the assistant editor under Remote Site Connections.', 'mcp-ai-wpoos-pro' ),
						isset( $connection['name'] ) ? $connection['name'] : $connection_id
					),
					array( 'status' => 403 )
				);
			}

			// Ensure the client is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Printful_Client' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-printful-client.php';
			}

			$client = new WP_MCP_AI_Printful_Client( $connection_id );

			// Rate limiting check.
			if ( ! $this->check_rate_limit( $user_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_rate_limited',
					__( 'Rate limit exceeded for Printful API requests. Please wait before making more requests.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 429 )
				);
			}

			// Route to appropriate handler.
			switch ( $action ) {
				case 'get_catalog_products':
					return $client->get_catalog_products( $this->build_catalog_options( $arguments ) );

				case 'get_catalog_product':
					return $client->get_catalog_product( absint( $arguments['product_id'] ) );

				case 'get_catalog_variant':
					return $client->get_catalog_variant( absint( $arguments['variant_id'] ) );

				case 'get_categories':
					return $client->get_categories();

				case 'get_countries':
					return $client->get_countries();

				case 'get_sync_products':
					return $client->get_sync_products( $this->build_pagination_options( $arguments, 'product_status', 'status' ) );

				case 'create_sync_product':
					return $client->create_sync_product( $arguments['sync_product_data'] ?? array() );

				case 'get_sync_product':
					return $client->get_sync_product( $arguments['sync_product_id'] ?? '' );

				case 'update_sync_product':
					return $client->update_sync_product(
						$arguments['sync_product_id'] ?? '',
						$arguments['sync_product_data'] ?? array()
					);

				case 'delete_sync_product':
					return $client->delete_sync_product( $arguments['sync_product_id'] ?? '' );

				case 'get_orders':
					return $client->get_orders( $this->build_pagination_options( $arguments, 'status', 'status' ) );

				case 'create_order':
					return $client->create_order(
						$arguments['order_data'] ?? array(),
						! empty( $arguments['confirm'] )
					);

				case 'get_order':
					return $client->get_order( $arguments['order_id'] ?? '' );

				case 'update_order':
					return $client->update_order(
						$arguments['order_id'] ?? '',
						$arguments['order_data'] ?? array(),
						! empty( $arguments['confirm'] )
					);

				case 'cancel_order':
					return $client->cancel_order( $arguments['order_id'] ?? '' );

				case 'confirm_order':
					return $client->confirm_order( $arguments['order_id'] ?? '' );

				case 'estimate_costs':
					return $client->estimate_costs( $arguments['order_data'] ?? array() );

				case 'get_shipping_rates':
					return $client->get_shipping_rates( $arguments['shipping_data'] ?? array() );

				case 'get_stores':
					return $client->get_stores();

				case 'get_statistics':
					return $client->get_statistics(
						array(
							'date_from'    => $arguments['date_from'] ?? '',
							'date_to'      => $arguments['date_to'] ?? '',
							'currency'     => $arguments['currency'] ?? '',
							'report_types' => $arguments['report_types'] ?? array(),
						)
					);

				case 'get_warehouse_products':
					return $client->get_warehouse_products( $this->build_pagination_options( $arguments ) );

				case 'create_mockup_task':
					return $client->create_mockup_task(
						absint( $arguments['product_id'] ?? 0 ),
						$arguments['mockup_data'] ?? array()
					);

				case 'get_mockup_task':
					return $client->get_mockup_task( $arguments['task_key'] ?? '' );

				default:
					return new WP_Error(
						'wp_mcp_ai_pro_invalid_action',
						sprintf(
							/* translators: %s: action name */
							__( 'Unknown action "%s" for Printful tool.', 'mcp-ai-wpoos-pro' ),
							$action
						),
						array( 'status' => 400 )
					);
			}
		}

		/**
		 * List available Printful connections.
		 *
		 * @param array $context Execution context.
		 * @return array Connection list.
		 */
		protected function list_connections( $context ) {
			$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

			$connections = array();
			foreach ( $all_connections as $conn_id => $conn ) {
				if (
					isset( $conn['connection_type'] )
					&& 'printful' === $conn['connection_type']
					&& ! empty( $conn['enabled'] )
				) {
					// Check assistant access.
					if ( ! $this->is_connection_enabled_for_assistant( $conn_id, $context ) ) {
						continue;
					}

					$connections[] = array(
						'id'       => $conn_id,
						'name'     => isset( $conn['name'] ) ? $conn['name'] : $conn_id,
						'url'      => isset( $conn['url'] ) ? $conn['url'] : 'https://api.printful.com',
						'store_id' => isset( $conn['store_id'] ) ? $conn['store_id'] : '',
					);
				}
			}

			return array( 'connections' => $connections );
		}

		/**
		 * Check if the connection is enabled for the current assistant.
		 *
		 * @param string $connection_id Connection ID.
		 * @param array  $context       Execution context.
		 * @return bool
		 */
		protected function is_connection_enabled_for_assistant( $connection_id, $context ) {
			if ( empty( $context['assistant_id'] ) ) {
				return true; // No assistant context — allow (direct tool call).
			}

			$assistant_id        = absint( $context['assistant_id'] );
			$enabled_connections = get_post_meta( $assistant_id, '_wp_mcp_ai_enabled_remote_sites', true );

			if ( ! is_array( $enabled_connections ) ) {
				return true; // No restrictions configured.
			}

			return in_array( $connection_id, $enabled_connections, true );
		}

		/**
		 * Check rate limiting for Printful requests.
		 *
		 * @param int $user_id User ID.
		 * @return bool True if allowed, false if rate limited.
		 */
		protected function check_rate_limit( $user_id ) {
			$user_id        = absint( $user_id );
			$transient_key  = 'wp_mcp_ai_pro_printful_rl_' . $user_id;
			$current_count  = get_transient( $transient_key );
			$max_per_minute = 30;

			/**
			 * Filter the maximum Printful API requests allowed per minute per user.
			 *
			 * @since 1.0.0
			 *
			 * @param int $max_per_minute Maximum requests per minute (default: 30).
			 * @param int $user_id        User ID.
			 */
			$max_per_minute = apply_filters( 'wp_mcp_ai_pro_printful_rate_limit', $max_per_minute, $user_id );

			if ( false === $current_count ) {
				set_transient( $transient_key, 1, 60 );
				return true;
			}

			if ( $current_count >= $max_per_minute ) {
				return false;
			}

			set_transient( $transient_key, $current_count + 1, 60 );
			return true;
		}

		/**
		 * Build catalog query options from arguments.
		 *
		 * @param array $arguments Tool arguments.
		 * @return array
		 */
		protected function build_catalog_options( $arguments ) {
			$options = array();
			if ( ! empty( $arguments['category_id'] ) ) {
				$options['category_id'] = absint( $arguments['category_id'] );
			}
			return $options;
		}

		/**
		 * Build pagination options from arguments.
		 *
		 * @param array  $arguments   Tool arguments.
		 * @param string $status_key  Key for status in arguments.
		 * @param string $status_out  Key for status in options output.
		 * @return array
		 */
		protected function build_pagination_options( $arguments, $status_key = '', $status_out = 'status' ) {
			$options = array();

			if ( $status_key && ! empty( $arguments[ $status_key ] ) ) {
				$options[ $status_out ] = sanitize_key( $arguments[ $status_key ] );
			}
			if ( isset( $arguments['offset'] ) ) {
				$options['offset'] = absint( $arguments['offset'] );
			}
			if ( isset( $arguments['limit'] ) ) {
				$options['limit'] = max( 1, min( 100, absint( $arguments['limit'] ) ) );
			}

			return $options;
		}
	}
}
