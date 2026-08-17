<?php
/**
 * Tests for the Composio webhook receiver.
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-client.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-composio-webhook-controller.php';

/**
 * Test the Composio webhook controller.
 */
class Test_Composio_Webhook_Controller extends WP_UnitTestCase {

	/**
	 * Connection under test.
	 *
	 * @var array
	 */
	private $connection = array();

	/**
	 * Webhook signing secret used in fixtures.
	 *
	 * @var string
	 */
	private $secret = 'whsec_test_secret';

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );

		if ( ! class_exists( 'WP_REST_Request' ) ) {
			$this->markTestSkipped( 'WP_REST_Request not available.' );
		}

		$id = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
			array(
				'name'            => 'Composio Webhook Test',
				'url'             => 'https://backend.composio.dev',
				'connection_type' => 'composio',
				'auth_type'       => 'none',
				'api_key'         => 'ak_test_123',
				'webhook_secret'  => $this->secret,
				'enabled'         => true,
			)
		);

		$this->assertNotWPError( $id );
		$this->connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Build a webhook request fixture.
	 *
	 * @param string $body      Raw JSON body.
	 * @param string $signature Signature header value (empty = compute valid hex).
	 * @return WP_REST_Request
	 */
	private function build_request( $body, $signature = '' ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/composio/' . $this->connection['id'] );
		$request->set_param( 'connection_id', $this->connection['id'] );
		$request->set_body( $body );

		if ( '' === $signature ) {
			$signature = hash_hmac( 'sha256', $body, $this->secret, false );
		}
		$request->set_header( 'x-composio-signature', $signature );

		return $request;
	}

	/**
	 * Test that a valid signature passes validation.
	 */
	public function test_valid_signature_passes() {
		$controller = new WP_MCP_AI_Composio_Webhook_Controller();
		$request    = $this->build_request( '{"event":"composio.trigger.message"}' );

		$this->assertTrue( $controller->validate_signature( $request ) );
	}

	/**
	 * Test that a tampered body is rejected.
	 */
	public function test_tampered_body_is_rejected() {
		$controller = new WP_MCP_AI_Composio_Webhook_Controller();

		$good = '{"event":"composio.trigger.message"}';
		$bad  = '{"event":"composio.trigger.message","spoof":1}';

		// Sign the good body but send the bad one.
		$request = $this->build_request( $bad, hash_hmac( 'sha256', $good, $this->secret, false ) );

		$this->assertFalse( $controller->validate_signature( $request ) );
	}

	/**
	 * Test that a missing signature header is rejected.
	 */
	public function test_missing_signature_is_rejected() {
		$controller = new WP_MCP_AI_Composio_Webhook_Controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/webhooks/composio/' . $this->connection['id'] );
		$request->set_param( 'connection_id', $this->connection['id'] );
		$request->set_body( '{"event":"composio.trigger.message"}' );

		$result = $controller->validate_signature( $request );

		$this->assertWPError( $result );
	}

	/**
	 * Test that unknown connection IDs are rejected.
	 */
	public function test_unknown_connection_is_rejected() {
		$controller = new WP_MCP_AI_Composio_Webhook_Controller();
		$request    = $this->build_request( '{"event":"composio.trigger.message"}' );
		$request->set_param( 'connection_id', 'conn_nope' );

		$result = $controller->validate_signature( $request );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Test that trigger messages dispatch the wp_mcp_ai_composio_trigger action.
	 */
	public function test_trigger_message_dispatches_action() {
		$controller = new WP_MCP_AI_Composio_Webhook_Controller();

		$payload = array(
			'event'   => 'composio.trigger.message',
			'id'      => 'evt_123',
			'payload' => array(
				'triggerName' => 'gmail.message.new',
				'data'        => array( 'subject' => 'Hello' ),
			),
		);

		$request = $this->build_request( wp_json_encode( $payload ) );

		$dispatched = 0;
		add_action(
			'wp_mcp_ai_composio_trigger',
			function () use ( &$dispatched ) {
				++$dispatched;
			}
		);

		$response = $controller->handle_webhook( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $dispatched );
	}

	/**
	 * Test that duplicate event IDs are deduped.
	 */
	public function test_duplicate_event_ids_are_deduped() {
		$controller = new WP_MCP_AI_Composio_Webhook_Controller();

		$payload = array(
			'event'   => 'composio.trigger.message',
			'id'      => 'evt_dup',
			'payload' => array( 'triggerName' => 'gmail.message.new' ),
		);

		$request = $this->build_request( wp_json_encode( $payload ) );

		$dispatched = 0;
		add_action(
			'wp_mcp_ai_composio_trigger',
			function () use ( &$dispatched ) {
				++$dispatched;
			}
		);

		$controller->handle_webhook( $request );
		$controller->handle_webhook( $request );

		$this->assertSame( 1, $dispatched );
	}

	/**
	 * Test that account-expired events dispatch the matching action.
	 */
	public function test_account_expired_dispatches_action() {
		$controller = new WP_MCP_AI_Composio_Webhook_Controller();

		$payload = array(
			'event'   => 'composio.connected_account.expired',
			'id'      => 'evt_exp',
			'payload' => array( 'connected_account_id' => 'ca_xyz' ),
		);

		$request = $this->build_request( wp_json_encode( $payload ) );

		$dispatched = 0;
		add_action(
			'wp_mcp_ai_composio_account_expired',
			function () use ( &$dispatched ) {
				++$dispatched;
			}
		);

		$controller->handle_webhook( $request );

		$this->assertSame( 1, $dispatched );
	}
}
