<?php
/**
 * Tests for Shopify Sync toolkit hardening.
 *
 * Regression coverage for the shopify-sync cluster audit:
 * - shopify_sync_orders get_order_analytics counted $edges on the API
 *   failure path where $edges was never initialised — a fatal
 *   count(null) TypeError on exactly the error path that is meant to
 *   degrade gracefully (network failure, missing token, rate limit).
 * - The Shopify Sync MCP server advertised a sync hook the engine never
 *   schedules (per-connection suffixed hooks), read its sync interval
 *   from a slug-derived option the toolkit never writes (and in the
 *   wrong unit), and derived connection status from an option the
 *   toolkit never populates — descriptor-level wiring mismatches.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 * @group shopify-sync
 * @group pro
 */

/**
 * Shopify Sync toolkit hardening test case.
 */
class Test_Shopify_Sync_Toolkit_Hardening extends WP_UnitTestCase {

	/**
	 * Mock connection ID.
	 *
	 * @var string
	 */
	protected $connection_id = 'conn_test_shopify_001';

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon is not loaded.' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-client.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/shopify-sync/class-wp-mcp-ai-pro-tool-shopify-sync-orders.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
		require_once dirname( __DIR__ ) . '/includes/mcp-servers/mcp-servers-init.php';

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Seed toolkit settings with a synced connection.
		update_option(
			'wp_mcp_ai_shopify_sync_toolkit_settings',
			array(
				'sync_connections' => array( $this->connection_id ),
				'sync_interval'    => 15,
				'sync_direction'   => 'shopify_to_woo',
			)
		);

		// Seed a Shopify Remote Sites connection (plaintext api_key passes
		// through decrypt_value() unchanged for test purposes).
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				$this->connection_id => array(
					'id'              => $this->connection_id,
					'name'            => 'Test Shopify Store',
					'url'             => 'https://test-store.myshopify.com',
					'connection_type' => 'shopify',
					'enabled'         => true,
					'api_key'         => 'test-access-token',
				),
			)
		);
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'wp_mcp_ai_shopify_sync_toolkit_settings' );
		delete_option( 'wp_mcp_ai_pro_remote_sites' );
		delete_option( 'wp_mcp_ai_shopify_last_sync_' . $this->connection_id );
		delete_option( 'wp_mcp_ai_shopify_last_sync_conn_test_shopify_002' );
		parent::tearDown();
	}

	/**
	 * Skip orders-tool tests when WooCommerce is unavailable.
	 */
	private function require_woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not active.' );
		}
	}

	// ------------------------------------------------------------------ //
	// Orders analytics — error-path regression                            //
	// ------------------------------------------------------------------ //

	/**
	 * Test that get_order_analytics degrades to an envelope on API failure.
	 *
	 * Previously the method counted $edges, which was only assigned inside
	 * the success branch — a fatal count(null) TypeError whenever the
	 * orders request failed.
	 */
	public function test_order_analytics_api_failure_returns_envelope() {
		$this->require_woocommerce();

		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Simulated Shopify outage.' );
			},
			10,
			1
		);

		$tool = new WP_MCP_AI_Pro_Tool_Shopify_Sync_Orders();

		$result = $tool->execute(
			array(
				'action'        => 'get_order_analytics',
				'connection_id' => $this->connection_id,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['data']['total_orders_analyzed'] );
		$this->assertSame( 0.0, $result['data']['total_revenue'] );
		$this->assertSame( array(), $result['data']['status_breakdown'] );
		$this->assertSame( 'USD', $result['data']['currency'] );
	}

	/**
	 * Test that get_order_analytics aggregates a healthy orders response.
	 */
	public function test_order_analytics_happy_path_aggregates() {
		$this->require_woocommerce();

		add_filter(
			'pre_http_request',
			function ( $pre, $args ) {
				$body    = isset( $args['body'] ) ? $args['body'] : '';
				$decoded = json_decode( $body, true );
				$query   = is_array( $decoded ) && isset( $decoded['query'] ) ? $decoded['query'] : '';

				if ( false !== strpos( $query, 'GetOrders' ) ) {
					$payload = array(
						'data' => array(
							'orders' => array(
								'edges' => array(
									array(
										'node' => array(
											'displayFinancialStatus' => 'PAID',
											'totalPriceSet'          => array(
												'shopMoney' => array(
													'amount'       => '42.50',
													'currencyCode' => 'USD',
												),
											),
										),
									),
									array(
										'node' => array(
											'displayFinancialStatus' => 'REFUNDED',
											'totalPriceSet'          => array(
												'shopMoney' => array(
													'amount'       => '10.00',
													'currencyCode' => 'USD',
												),
											),
										),
									),
								),
							),
						),
					);
				} else {
					$payload = array(
						'data' => array(
							'shop' => array(
								'currencyCode' => 'EUR',
							),
						),
					);
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $payload ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			2
		);

		$tool = new WP_MCP_AI_Pro_Tool_Shopify_Sync_Orders();

		$result = $tool->execute(
			array(
				'action'        => 'get_order_analytics',
				'connection_id' => $this->connection_id,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['data']['total_orders_analyzed'] );
		$this->assertSame( 52.5, $result['data']['total_revenue'] );
		$this->assertSame( 'EUR', $result['data']['currency'] );
		$this->assertSame(
			array(
				'PAID'     => 1,
				'REFUNDED' => 1,
			),
			$result['data']['status_breakdown']
		);
	}

	// ------------------------------------------------------------------ //
	// MCP server — sync wiring                                            //
	// ------------------------------------------------------------------ //

	/**
	 * Test that the advertised sync hook matches the engine's hook prefix.
	 *
	 * The engine schedules per-connection hooks (HOOK_FULL_SYNC . '_' .
	 * $connection_id); the server previously advertised an unrelated,
	 * never-scheduled hook name.
	 */
	public function test_sync_hook_name_matches_engine_prefix() {
		$server = new WP_MCP_AI_Shopify_Sync_MCP_Server();

		$this->assertSame(
			WP_MCP_AI_Shopify_Sync_Engine::HOOK_FULL_SYNC,
			$server->get_sync_hook_name()
		);
	}

	/**
	 * Test that the sync interval reads minutes from the toolkit option.
	 */
	public function test_sync_interval_reads_minutes_from_toolkit_settings() {
		update_option(
			'wp_mcp_ai_shopify_sync_toolkit_settings',
			array(
				'sync_connections' => array( $this->connection_id ),
				'sync_interval'    => 60,
			)
		);

		$server = new WP_MCP_AI_Shopify_Sync_MCP_Server();

		$this->assertSame( 60 * MINUTE_IN_SECONDS, $server->get_sync_interval() );
	}

	/**
	 * Test that the sync interval defaults to 15 minutes when unconfigured.
	 */
	public function test_sync_interval_defaults_to_fifteen_minutes() {
		delete_option( 'wp_mcp_ai_shopify_sync_toolkit_settings' );

		$server = new WP_MCP_AI_Shopify_Sync_MCP_Server();

		$this->assertSame( 15 * MINUTE_IN_SECONDS, $server->get_sync_interval() );
	}

	/**
	 * Test that sync status aggregates per-connection last-sync options.
	 */
	public function test_sync_status_aggregates_across_connections() {
		update_option(
			'wp_mcp_ai_shopify_sync_toolkit_settings',
			array(
				'sync_connections' => array( $this->connection_id, 'conn_test_shopify_002' ),
				'sync_interval'    => 15,
			)
		);

		$recent = date( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Test fixture timestamp.
		$older  = date( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Test fixture timestamp.

		update_option( 'wp_mcp_ai_shopify_last_sync_' . $this->connection_id, $older );
		update_option( 'wp_mcp_ai_shopify_last_sync_conn_test_shopify_002', $recent );

		$server = new WP_MCP_AI_Shopify_Sync_MCP_Server();
		$status = $server->get_sync_status();

		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( strtotime( $recent ), $status['last_sync'] );
		$this->assertIsInt( $status['row_count'] );
	}

	/**
	 * Test that sync status is unknown when nothing has ever synced.
	 */
	public function test_sync_status_unknown_without_synced_connections() {
		delete_option( 'wp_mcp_ai_shopify_sync_toolkit_settings' );

		$server = new WP_MCP_AI_Shopify_Sync_MCP_Server();
		$status = $server->get_sync_status();

		$this->assertSame( 'unknown', $status['status'] );
		$this->assertSame( 0, $status['last_sync'] );
		$this->assertSame( 0, $status['row_count'] );
	}

	/**
	 * Test that sync status reports stale outside the configured interval.
	 */
	public function test_sync_status_stale_when_outside_interval() {
		update_option(
			'wp_mcp_ai_shopify_sync_toolkit_settings',
			array(
				'sync_connections' => array( $this->connection_id ),
				'sync_interval'    => 5,
			)
		);

		update_option(
			'wp_mcp_ai_shopify_last_sync_' . $this->connection_id,
			date( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS ) // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Test fixture timestamp.
		);

		$server = new WP_MCP_AI_Shopify_Sync_MCP_Server();
		$status = $server->get_sync_status();

		$this->assertSame( 'stale', $status['status'] );
	}

	// ------------------------------------------------------------------ //
	// MCP server — connection status                                      //
	// ------------------------------------------------------------------ //

	/**
	 * Test that connection status requires an enabled, synced Shopify site.
	 */
	public function test_connection_status_true_for_enabled_synced_connection() {
		$server = new WP_MCP_AI_Shopify_Sync_MCP_Server();

		$this->assertTrue( $server->get_connection_status() );
	}

	/**
	 * Test that connection status is false when no connections are synced.
	 */
	public function test_connection_status_false_without_synced_connections() {
		delete_option( 'wp_mcp_ai_shopify_sync_toolkit_settings' );

		$server = new WP_MCP_AI_Shopify_Sync_MCP_Server();

		$this->assertFalse( $server->get_connection_status() );
	}

	/**
	 * Test that connection status is false for non-Shopify connections.
	 */
	public function test_connection_status_false_for_wrong_connection_type() {
		update_option(
			'wp_mcp_ai_pro_remote_sites',
			array(
				$this->connection_id => array(
					'id'              => $this->connection_id,
					'name'            => 'Not Shopify',
					'url'             => 'https://example.com',
					'connection_type' => 'rest',
					'enabled'         => true,
					'api_key'         => 'test-token',
				),
			)
		);

		$server = new WP_MCP_AI_Shopify_Sync_MCP_Server();

		$this->assertFalse( $server->get_connection_status() );
	}
}
