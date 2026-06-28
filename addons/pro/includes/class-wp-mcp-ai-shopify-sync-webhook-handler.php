<?php
/**
 * Shopify Sync Webhook Handler.
 *
 * REST endpoint for receiving Shopify webhooks. Performs HMAC-SHA256
 * verification, domain-to-connection resolution, and topic routing for
 * real-time inventory/product sync updates.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler' ) ) {

	/**
	 * Shopify Sync Webhook Handler.
	 *
	 * Handles incoming Shopify webhook POST requests at
	 * /wp-json/mcp-ai/v1/shopify/webhook.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_Shopify_Sync_Webhook_Handler {

		/**
		 * REST API namespace.
		 *
		 * @var string
		 */
		const REST_NAMESPACE = 'mcp-ai/v1';

		/**
		 * REST API route.
		 *
		 * @var string
		 */
		const REST_ROUTE = '/shopify/webhook';

		/**
		 * Initialize the webhook handler.
		 *
		 * Registers the REST route and Action Scheduler callbacks.
		 *
		 * @since 1.3.0
		 */
		public static function init() {
			add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		}

		/**
		 * Register the webhook REST endpoint.
		 *
		 * @since 1.3.0
		 */
		public static function register_route() {
			register_rest_route(
				self::REST_NAMESPACE,
				self::REST_ROUTE,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle_webhook' ),
					'permission_callback' => '__return_true', // HMAC verification handles auth.
				)
			);
		}

		/**
		 * Handle an incoming Shopify webhook.
		 *
		 * @since 1.3.0
		 *
		 * @param WP_REST_Request $request The incoming request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function handle_webhook( $request ) {
			$hmac_header = $request->get_header( 'x-shopify-hmac-sha256' );
			$topic       = $request->get_header( 'x-shopify-topic' );
			$shop_domain = $request->get_header( 'x-shopify-shop-domain' );
			$body        = $request->get_body();

			if ( empty( $hmac_header ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_missing_hmac',
					__( 'Missing X-Shopify-Hmac-SHA256 header.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 401 )
				);
			}

			if ( empty( $shop_domain ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_missing_domain',
					__( 'Missing X-Shopify-Shop-Domain header.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			// Step 1: Identify which connection this webhook belongs to.
			$connection_id = self::find_connection_by_domain( $shop_domain );
			if ( ! $connection_id ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_unknown_shop',
					sprintf(
						/* translators: %s: shop domain */
						__( 'Shop "%s" is not recognized. Please configure a Shopify Remote Sites connection with this domain.', 'mcp-ai-wpoos-pro' ),
						esc_html( $shop_domain )
					),
					array( 'status' => 404 )
				);
			}

			// Check if this connection is enabled for sync.
			$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
			$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();
			if ( ! in_array( $connection_id, $sync_connections, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_not_synced',
					__( 'This Shopify connection is not configured for sync.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}

			// Step 2: HMAC verification.
			if ( ! self::verify_hmac( $body, $hmac_header, $connection_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_hmac_mismatch',
					__( 'HMAC verification failed. The webhook signature does not match.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 401 )
				);
			}

			// Step 3: Topic routing.
			$payload = json_decode( $body, true );
			if ( null === $payload ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_invalid_json',
					__( 'Webhook body is not valid JSON.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			$result = self::route_topic( $topic, $payload, $connection_id );

			if ( is_wp_error( $result ) ) {
				return new WP_REST_Response(
					array(
						'status' => 'error',
						'error'  => $result->get_error_message(),
					),
					500
				);
			}

			/**
			 * Fires after a Shopify webhook is successfully processed.
			 *
			 * @since 1.3.0
			 *
			 * @param string $topic         The webhook topic.
			 * @param array  $payload       The webhook payload.
			 * @param string $connection_id The Shopify connection ID.
			 * @param mixed  $result        The processing result.
			 */
			do_action( 'wp_mcp_ai_shopify_webhook_processed', $topic, $payload, $connection_id, $result );

			return new WP_REST_Response(
				array(
					'status' => 'processed',
					'topic'  => $topic,
				),
				200
			);
		}

		/**
		 * Find a Remote Sites connection by Shopify domain.
		 *
		 * @since 1.3.0
		 *
		 * @param string $shop_domain Shopify store domain (e.g. mystore.myshopify.com).
		 * @return string|null Connection ID or null if not found.
		 */
		protected static function find_connection_by_domain( $shop_domain ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				return null;
			}

			$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

			foreach ( $all_connections as $conn ) {
				if ( empty( $conn['connection_type'] ) || 'shopify' !== $conn['connection_type'] ) {
					continue;
				}

				if ( empty( $conn['enabled'] ) ) {
					continue;
				}

				$conn_url    = isset( $conn['url'] ) ? $conn['url'] : '';
				$conn_domain = wp_parse_url( $conn_url, PHP_URL_HOST );

				if ( $conn_domain === $shop_domain ) {
					return $conn['id'];
				}
			}

			return null;
		}

		/**
		 * Verify the HMAC-SHA256 signature of a webhook request.
		 *
		 * @since 1.3.0
		 *
		 * @param string $body          Raw request body.
		 * @param string $hmac_header   Base64-encoded HMAC from X-Shopify-Hmac-SHA256 header.
		 * @param string $connection_id Remote Sites connection ID.
		 * @return bool True if signature is valid.
		 */
		protected static function verify_hmac( $body, $hmac_header, $connection_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				return false;
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( ! $connection ) {
				return false;
			}

			// Use the decrypted API key as the HMAC secret.
			$secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value(
				isset( $connection['api_key'] ) ? $connection['api_key'] : ''
			);

			if ( empty( $secret ) ) {
				return false;
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for Shopify HMAC-SHA256 webhook verification.
			$computed = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );

			return hash_equals( $computed, $hmac_header );
		}

		/**
		 * Route a webhook to the appropriate handler based on topic.
		 *
		 * @since 1.3.0
		 *
		 * @param string $topic         Webhook topic.
		 * @param array  $payload       Webhook payload.
		 * @param string $connection_id Remote Sites connection ID.
		 * @return mixed|WP_Error Processing result or WP_Error.
		 */
		protected static function route_topic( $topic, $payload, $connection_id ) {
			switch ( $topic ) {
				case 'products/update':
					return self::handle_product_update( $payload, $connection_id );

				case 'products/delete':
					return self::handle_product_delete( $payload, $connection_id );

				case 'inventory_levels/update':
					return self::handle_inventory_update( $payload, $connection_id );

				default:
					return new WP_Error(
						'wp_mcp_ai_shopify_webhook_unknown_topic',
						sprintf(
							/* translators: %s: webhook topic */
							__( 'Unhandled webhook topic: %s', 'mcp-ai-wpoos-pro' ),
							$topic
						)
					);
			}
		}

		/**
		 * Handle a products/update webhook.
		 *
		 * Syncs the single product from Shopify into the CCT.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $payload       Webhook payload.
		 * @param string $connection_id Remote Sites connection ID.
		 * @return array|WP_Error
		 */
		protected static function handle_product_update( $payload, $connection_id ) {
			$product_gid = isset( $payload['id'] ) ? sanitize_text_field( $payload['id'] ) : '';

			if ( empty( $product_gid ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_missing_product_id',
					__( 'Product GID missing in webhook payload.', 'mcp-ai-wpoos-pro' )
				);
			}

			$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );

			return $cct_manager->sync_single_product( $product_gid );
		}

		/**
		 * Handle a products/delete webhook.
		 *
		 * Marks items as deleted in the CCT.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $payload       Webhook payload.
		 * @param string $connection_id Remote Sites connection ID.
		 * @return true|WP_Error
		 */
		protected static function handle_product_delete( $payload, $connection_id ) {
			$product_gid = isset( $payload['id'] ) ? sanitize_text_field( $payload['id'] ) : '';

			if ( empty( $product_gid ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_missing_product_id',
					__( 'Product GID missing in webhook payload.', 'mcp-ai-wpoos-pro' )
				);
			}

			$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );
			$cct_manager->mark_deleted_by_product_gid( $product_gid );

			return true;
		}

		/**
		 * Handle an inventory_levels/update webhook.
		 *
		 * Updates the available quantity in the CCT for the affected inventory item.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $payload       Webhook payload.
		 * @param string $connection_id Remote Sites connection ID.
		 * @return true|WP_Error
		 */
		protected static function handle_inventory_update( $payload, $connection_id ) {
			$inventory_item_id = isset( $payload['inventory_item_id'] ) ? sanitize_text_field( $payload['inventory_item_id'] ) : '';
			$location_id       = isset( $payload['location_id'] ) ? sanitize_text_field( $payload['location_id'] ) : '';
			$available         = isset( $payload['available'] ) ? absint( $payload['available'] ) : null;

			if ( empty( $inventory_item_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_missing_inventory_item',
					__( 'Inventory item ID missing in webhook payload.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( null === $available ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_missing_available',
					__( 'Available quantity missing in webhook payload.', 'mcp-ai-wpoos-pro' )
				);
			}

			$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );

			return $cct_manager->update_inventory_delta( $inventory_item_id, $location_id, $available );
		}

		// ------------------------------------------------------------------ //
		// Webhook Registration (Admin GraphQL mutations)                       //
		// ------------------------------------------------------------------ //

		/**
		 * Register Shopify webhooks for a connection.
		 *
		 * Creates webhook subscriptions via the Admin GraphQL API.
		 *
		 * @since 1.3.0
		 *
		 * @param string $connection_id Remote Sites connection ID.
		 * @return array|WP_Error Registration result or WP_Error.
		 */
		public static function register_webhooks( $connection_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_no_client',
					__( 'Shopify Client is not available.', 'mcp-ai-wpoos-pro' )
				);
			}

			$client      = new WP_MCP_AI_Shopify_Client( $connection_id );
			$webhook_url = rest_url( self::REST_NAMESPACE . self::REST_ROUTE );

			$topics = array(
				'products/update',
				'products/delete',
				'inventory_levels/update',
			);

			$results = array();

			foreach ( $topics as $topic ) {
				$mutation = '
					mutation CreateWebhook($topic: WebhookSubscriptionTopic!, $callbackUrl: URL!) {
						webhookSubscriptionCreate(
							topic: $topic,
							webhookSubscription: { callbackUrl: $callbackUrl, format: JSON }
						) {
							webhookSubscription { id }
							userErrors { field message }
						}
					}';

				$result = $client->graphql(
					$mutation,
					array(
						'topic'       => $topic,
						'callbackUrl' => $webhook_url,
					)
				);

				if ( is_wp_error( $result ) ) {
					$results[ $topic ] = array(
						'status' => 'error',
						'error'  => $result->get_error_message(),
					);
				} elseif ( ! empty( $result['data']['webhookSubscriptionCreate']['userErrors'] ) ) {
					$first_error       = $result['data']['webhookSubscriptionCreate']['userErrors'][0];
					$results[ $topic ] = array(
						'status' => 'error',
						'error'  => sprintf( '%s: %s', $first_error['field'], $first_error['message'] ),
					);
				} else {
					$results[ $topic ] = array(
						'status'          => 'registered',
						'subscription_id' => isset( $result['data']['webhookSubscriptionCreate']['webhookSubscription']['id'] )
							? $result['data']['webhookSubscriptionCreate']['webhookSubscription']['id']
							: '',
					);
				}
			}

			$all_success = true;
			foreach ( $results as $r ) {
				if ( 'registered' !== $r['status'] ) {
					$all_success = false;
					break;
				}
			}

			update_option( 'wp_mcp_ai_shopify_webhook_registered_' . $connection_id, $all_success );

			return array(
				'connection_id' => $connection_id,
				'webhook_url'   => $webhook_url,
				'all_success'   => $all_success,
				'results'       => $results,
			);
		}

		/**
		 * Unregister all Shopify webhooks for a connection.
		 *
		 * Lists all webhook subscriptions and deletes those matching our callback URL.
		 *
		 * @since 1.3.0
		 *
		 * @param string $connection_id Remote Sites connection ID.
		 * @return array|WP_Error
		 */
		public static function unregister_webhooks( $connection_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Shopify_Client' ) ) {
				return new WP_Error(
					'wp_mcp_ai_shopify_webhook_no_client',
					__( 'Shopify Client is not available.', 'mcp-ai-wpoos-pro' )
				);
			}

			$client      = new WP_MCP_AI_Shopify_Client( $connection_id );
			$webhook_url = rest_url( self::REST_NAMESPACE . self::REST_ROUTE );

			// List all webhook subscriptions.
			$list_query  = 'query { webhookSubscriptions(first: 50) { edges { node { id endpoint { __typename ... on WebhookHttpEndpoint { callbackUrl } } } } } }';
			$list_result = $client->graphql( $list_query );

			if ( is_wp_error( $list_result ) ) {
				return $list_result;
			}

			$deleted       = 0;
			$subscriptions = isset( $list_result['data']['webhookSubscriptions']['edges'] )
				? $list_result['data']['webhookSubscriptions']['edges']
				: array();

			foreach ( $subscriptions as $edge ) {
				$node     = isset( $edge['node'] ) ? $edge['node'] : array();
				$callback = isset( $node['endpoint']['callbackUrl'] ) ? $node['endpoint']['callbackUrl'] : '';
				$sub_id   = isset( $node['id'] ) ? $node['id'] : '';

				if ( $callback === $webhook_url && ! empty( $sub_id ) ) {
					$delete_mutation = 'mutation DeleteWebhook($id: ID!) { webhookSubscriptionDelete(id: $id) { deletedWebhookSubscriptionId userErrors { field message } } }';
					$delete_result   = $client->graphql( $delete_mutation, array( 'id' => $sub_id ) );

					if ( ! is_wp_error( $delete_result ) && empty( $delete_result['data']['webhookSubscriptionDelete']['userErrors'] ) ) {
						++$deleted;
					}
				}
			}

			update_option( 'wp_mcp_ai_shopify_webhook_registered_' . $connection_id, false );

			return array(
				'connection_id' => $connection_id,
				'deleted'       => $deleted,
				'total_found'   => count( $subscriptions ),
			);
		}
	}
}
