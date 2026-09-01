<?php
/**
 * ACP ported-class tests.
 *
 * Verifies the extraction port (src/ACP/) preserves the public behaviour of
 * the base plugin's ACP subsystem (mcp-ai-wpoos/includes/acp/).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\ACP\JsonRpcDispatcher;
use NvoosContentGraphAiPlatform\ACP\SessionBridge;
use NvoosContentGraphAiPlatform\ACP\SessionManager;
use NvoosContentGraphAiPlatform\ACP\TransportHttp;

/**
 * @group acp
 */
class Test_Platform_ACP extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	public function test_dispatcher_rejects_invalid_jsonrpc_version(): void {
		$dispatcher = new JsonRpcDispatcher( new SessionManager(), new SessionBridge() );

		$response = $dispatcher->dispatch( array( 'method' => 'initialize' ) );
		$this->assertArrayHasKey( 'error', $response );
		$this->assertSame( -32600, $response['error']['code'] );
	}

	public function test_dispatcher_unknown_method(): void {
		$dispatcher = new JsonRpcDispatcher( new SessionManager(), new SessionBridge() );

		$response = $dispatcher->dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'session/unknown',
			)
		);
		$this->assertSame( -32601, $response['error']['code'] );
		$this->assertSame( 7, $response['id'] );
	}

	public function test_dispatcher_initialize_capabilities(): void {
		$dispatcher = new JsonRpcDispatcher( new SessionManager(), new SessionBridge() );

		$response = $dispatcher->dispatch(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
			)
		);
		$this->assertArrayHasKey( 'result', $response );
		$this->assertSame( 1, $response['result']['protocolVersion'] );
		$this->assertTrue( $response['result']['agentCapabilities']['loadSession'] );
	}

	public function test_session_manager_lifecycle(): void {
		$manager = new SessionManager();

		$created = $manager->create_session( array( 'config' => array( 'assistant_id' => 12 ) ) );
		$this->assertArrayHasKey( 'sessionId', $created );

		$loaded = $manager->load_session( $created['sessionId'] );
		$this->assertSame( $created['sessionId'], $loaded['sessionId'] );

		$data = $manager->get_session_data( $created['sessionId'] );
		$this->assertSame( 12, $data['config']['assistant_id'] );

		$listing = $manager->list_sessions();
		$this->assertNotEmpty( $listing['sessions'] );

		$manager->cancel_session( $created['sessionId'] );
		$this->assertTrue( $manager->is_cancelled( $created['sessionId'] ) );
		$manager->clear_cancellation( $created['sessionId'] );
		$this->assertFalse( $manager->is_cancelled( $created['sessionId'] ) );
	}

	public function test_session_manager_load_missing_session_errors(): void {
		$manager = new SessionManager();
		$result  = $manager->load_session( 'sess_missing' );
		$this->assertWPError( $result );
	}

	public function test_session_bridge_unknown_session_errors(): void {
		$bridge = new SessionBridge();

		$result = $bridge->handle_prompt(
			array(
				'sessionId' => 'sess_missing',
				'prompt'    => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
				),
			)
		);
		$this->assertWPError( $result );
	}

	public function test_session_bridge_stub_response_without_chat_service(): void {
		$manager = new SessionManager();
		$created = $manager->create_session( array() );

		$bridge = new SessionBridge();
		$result = $bridge->handle_prompt(
			array(
				'sessionId' => $created['sessionId'],
				'prompt'    => array(
					array(
						'type' => 'text',
						'text' => 'Hello',
					),
				),
			)
		);

		$this->assertSame( array( 'stopReason' => 'end_turn' ), $result );

		// History persisted.
		$data = $manager->get_session_data( $created['sessionId'] );
		$this->assertNotEmpty( $data['messages'] );
		$this->assertSame( 'user', $data['messages'][0]['role'] );
	}

	public function test_transport_permission_requires_logged_in_editor(): void {
		$transport = new TransportHttp( new JsonRpcDispatcher( new SessionManager(), new SessionBridge() ) );
		$request   = new \WP_REST_Request( 'POST', '/mcp-ai/v1/acp' );

		wp_set_current_user( 0 );
		$result = $transport->check_permissions( $request );
		$this->assertWPError( $result );
	}

	public function test_transport_handle_request_dispatches(): void {
		$transport = new TransportHttp( new JsonRpcDispatcher( new SessionManager(), new SessionBridge() ) );
		$request   = new \WP_REST_Request( 'POST', '/mcp-ai/v1/acp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
				)
			)
		);

		$response = $transport->handle_request( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
	}
}
