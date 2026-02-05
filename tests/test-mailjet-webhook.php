<?php
/**
 * Mailjet Webhook Handler Tests
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-mailjet-webhook-handler.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Tests for the Mailjet webhook handler.
 */
class WP_MCP_AI_Mailjet_Webhook_Handler_Test extends WP_UnitTestCase {

	/**
	 * Webhook handler instance.
	 *
	 * @var WP_MCP_AI_Mailjet_Webhook_Handler
	 */
	protected $handler;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->handler = new WP_MCP_AI_Mailjet_Webhook_Handler();

		// Clear stored events.
		delete_option( 'wp_mcp_ai_mailjet_events' );

		// Reset settings.
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_mailjet_events' );
		parent::tearDown();
	}

	/**
	 * Test that webhook handler registers REST routes.
	 */
	public function test_register_routes() {
		$this->handler->register_routes();

		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/mcp-ai/v1/webhooks/mailjet', $routes );
	}

	/**
	 * Test webhook verification when no secret is configured.
	 */
	public function test_verify_webhook_without_secret() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/mailjet' );
		$request->set_body( wp_json_encode( array( array( 'event' => 'open' ) ) ) );

		$result = $this->handler->verify_webhook_request( $request );

		$this->assertTrue( $result, 'Webhook should be verified when no secret is configured' );
	}

	/**
	 * Test webhook verification with valid signature.
	 */
	public function test_verify_webhook_with_valid_signature() {
		// Configure webhook secret.
		$settings                           = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['mailjet_webhook_secret'] = 'test-secret';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$body      = wp_json_encode( array( array( 'event' => 'open' ) ) );
		$signature = hash_hmac( 'sha256', $body, 'test-secret' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/mailjet' );
		$request->set_header( 'X-Mailjet-Signature', $signature );
		$request->set_body( $body );

		$result = $this->handler->verify_webhook_request( $request );

		$this->assertTrue( $result, 'Webhook should be verified with valid signature' );
	}

	/**
	 * Test webhook verification with invalid signature.
	 */
	public function test_verify_webhook_with_invalid_signature() {
		// Configure webhook secret.
		$settings                           = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['mailjet_webhook_secret'] = 'test-secret';
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$body = wp_json_encode( array( array( 'event' => 'open' ) ) );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/mailjet' );
		$request->set_header( 'X-Mailjet-Signature', 'invalid-signature' );
		$request->set_body( $body );

		$result = $this->handler->verify_webhook_request( $request );

		$this->assertFalse( $result, 'Webhook should not be verified with invalid signature' );
	}

	/**
	 * Test webhook handler processes events correctly.
	 */
	public function test_handle_webhook_processes_events() {
		$events = array(
			array(
				'event' => 'open',
				'email' => 'test@example.com',
				'time'  => time(),
			),
			array(
				'event' => 'click',
				'email' => 'test2@example.com',
				'time'  => time(),
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/mailjet' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $events ) );

		$response = $this->handler->handle_webhook( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertEquals( 2, $data['processed'] );

		// Verify events were stored.
		$stored = get_option( 'wp_mcp_ai_mailjet_events', array() );
		$this->assertCount( 2, $stored );
	}

	/**
	 * Test that stored events are limited to 100.
	 */
	public function test_event_storage_limit() {
		// Create 110 events.
		$events = array();
		for ( $i = 0; $i < 110; $i++ ) {
			$events[] = array(
				'event' => 'open',
				'email' => "test{$i}@example.com",
				'time'  => time() + $i,
			);
		}

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/mailjet' );
		$request->set_body( wp_json_encode( $events ) );

		$this->handler->handle_webhook( $request );

		// Verify only last 100 are stored.
		$stored = get_option( 'wp_mcp_ai_mailjet_events', array() );
		$this->assertCount( 100, $stored );
	}

	/**
	 * Test get_recent_events method.
	 */
	public function test_get_recent_events() {
		// Store some events.
		$events = array(
			array(
				'event'      => 'open',
				'email'      => 'test1@example.com',
				'time'       => time(),
				'data'       => array(),
				'created_at' => current_time( 'mysql' ),
			),
			array(
				'event'      => 'click',
				'email'      => 'test2@example.com',
				'time'       => time(),
				'data'       => array(),
				'created_at' => current_time( 'mysql' ),
			),
		);

		update_option( 'wp_mcp_ai_mailjet_events', $events );

		$recent = WP_MCP_AI_Mailjet_Webhook_Handler::get_recent_events( 5 );

		$this->assertCount( 2, $recent );
		$this->assertEquals( 'click', $recent[0]['event'] ); // Most recent first.
		$this->assertEquals( 'open', $recent[1]['event'] );
	}

	/**
	 * Test webhook action hooks are fired.
	 */
	public function test_webhook_action_hooks() {
		$generic_fired  = 0;
		$specific_fired = 0;

		add_action(
			'wp_mcp_ai_mailjet_event',
			function ( $event_type, $email, $event ) use ( &$generic_fired ) {
				$generic_fired++;
			},
			10,
			3
		);

		add_action(
			'wp_mcp_ai_mailjet_event_open',
			function ( $email, $event ) use ( &$specific_fired ) {
				$specific_fired++;
			},
			10,
			2
		);

		$events = array(
			array(
				'event' => 'open',
				'email' => 'test@example.com',
				'time'  => time(),
			),
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/mailjet' );
		$request->set_body( wp_json_encode( $events ) );

		$this->handler->handle_webhook( $request );

		$this->assertEquals( 1, $generic_fired, 'Generic action hook should fire' );
		$this->assertEquals( 1, $specific_fired, 'Specific action hook should fire' );
	}
}
