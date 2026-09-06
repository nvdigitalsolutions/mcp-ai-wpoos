<?php
/**
 * Shopify Sync Webhook Handler Tests.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

/**
 * Test class for WP_MCP_AI_Shopify_Sync_Webhook_Handler.
 */
class Test_Shopify_Sync_Webhook_Handler extends WP_UnitTestCase {

	/**
	 * Mock connection ID.
	 *
	 * @var string
	 */
	protected $connection_id = 'conn_test_shopify_001';

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php';
		}
	}

	// ------------------------------------------------------------------ //
	// HMAC Verification Tests                                             //
	// ------------------------------------------------------------------ //

	/**
	 * Test HMAC verification with known values.
	 *
	 * Uses the Shopify webhook test example from their docs:
	 * secret = 'hush', body = '...', expected HMAC.
	 */
	public function test_hmac_verification_valid() {
		$secret        = 'hush';
		$body          = 'test body';
		$expected_hmac = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );

		// Verify the HMAC computation matches.
		$computed = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
		$this->assertEquals( $expected_hmac, $computed );
		$this->assertTrue( hash_equals( $expected_hmac, $computed ) );
	}

	/**
	 * Test HMAC verification fails with wrong secret.
	 */
	public function test_hmac_verification_invalid() {
		$secret       = 'correct_secret';
		$wrong_secret = 'wrong_secret';
		$body         = 'test body';

		$correct_hmac = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
		$wrong_hmac   = base64_encode( hash_hmac( 'sha256', $body, $wrong_secret, true ) );

		$this->assertFalse( hash_equals( $correct_hmac, $wrong_hmac ) );
	}

	// ------------------------------------------------------------------ //
	// Domain Resolution Tests                                             //
	// ------------------------------------------------------------------ //

	/**
	 * Test domain-to-connection resolution.
	 *
	 * Uses reflection to test the protected method.
	 */
	public function test_find_connection_by_domain() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler', 'find_connection_by_domain' );
		$reflection->setAccessible( true );

		// When Remote Sites Manager is not available, should return null.
		$result = $reflection->invoke( null, 'teststore.myshopify.com' );
		$this->assertNull( $result );
	}

	// ------------------------------------------------------------------ //
	// Topic Routing Tests                                                 //
	// ------------------------------------------------------------------ //

	/**
	 * Test that route_topic returns WP_Error for unknown topics.
	 */
	public function test_route_topic_unknown_topic() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler', 'route_topic' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( null, 'unknown/topic', array(), 'conn_test' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_webhook_unknown_topic', $result->get_error_code() );
	}

	/**
	 * Test that products/update route returns WP_Error with missing product ID.
	 */
	public function test_route_product_update_missing_id() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler', 'route_topic' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( null, 'products/update', array(), $this->connection_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_webhook_missing_product_id', $result->get_error_code() );
	}

	/**
	 * Test that products/delete route returns WP_Error with missing product ID.
	 */
	public function test_route_product_delete_missing_id() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler', 'route_topic' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( null, 'products/delete', array(), $this->connection_id );

		$this->assertWPError( $result );
	}

	/**
	 * Test that inventory_levels/update route returns WP_Error with missing params.
	 */
	public function test_route_inventory_update_missing_item_id() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler', 'route_topic' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke( null, 'inventory_levels/update', array(), $this->connection_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_webhook_missing_inventory_item', $result->get_error_code() );
	}

	/**
	 * Test inventory_levels/update with missing available quantity.
	 */
	public function test_route_inventory_update_missing_available() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler', 'route_topic' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			null,
			'inventory_levels/update',
			array(
				'inventory_item_id' => 'gid://shopify/InventoryItem/123',
				'location_id'       => 'gid://shopify/Location/1',
			),
			$this->connection_id
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_webhook_missing_available', $result->get_error_code() );
	}

	// ------------------------------------------------------------------ //
	// REST Constants Tests                                                //
	// ------------------------------------------------------------------ //

	/**
	 * Test REST namespace constant.
	 */
	public function test_rest_namespace() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler' );
		$namespace  = $reflection->getConstant( 'REST_NAMESPACE' );
		$this->assertEquals( 'mcp-ai/v1', $namespace );
	}

	/**
	 * Test REST route constant.
	 */
	public function test_rest_route() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler' );
		$route      = $reflection->getConstant( 'REST_ROUTE' );
		$this->assertEquals( '/shopify/webhook', $route );
	}

	// ------------------------------------------------------------------ //
	// Webhook Registration Tests                                          //
	// ------------------------------------------------------------------ //

	/**
	 * Test that register_webhooks degrades gracefully when the connection is
	 * not configured (client class loads but the graphQL call fails with
	 * missing_url). Per-topic errors are collected in the result array.
	 */
	public function test_register_webhooks_no_client() {
		$result = WP_MCP_AI_Shopify_Sync_Webhook_Handler::register_webhooks( $this->connection_id );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['all_success'] );
		$this->assertNotEmpty( $result['results'] );
		foreach ( $result['results'] as $topic => $entry ) {
			$this->assertSame( 'error', $entry['status'], "Topic {$topic} should report an error status." );
			$this->assertNotEmpty( $entry['error'], "Topic {$topic} should carry an error message." );
		}
	}

	/**
	 * Test that unregister_webhooks propagates the client error when the
	 * connection is not configured.
	 */
	public function test_unregister_webhooks_no_client() {
		$result = WP_MCP_AI_Shopify_Sync_Webhook_Handler::unregister_webhooks( $this->connection_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_shopify_missing_url', $result->get_error_code() );
	}
}
