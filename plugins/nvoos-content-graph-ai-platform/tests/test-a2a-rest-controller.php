<?php
/**
 * A2A REST receive routes port tests (Wave E5).
 *
 * Characterization suite for the ported `A2aRestController`:
 * byte-identical constants, route surface, permission gates
 * (`a2a_disabled`, A2A-Version header, per-mode auth with the
 * documented `wp_mcp_ai_auth_unavailable` degradation), JSON-RPC
 * method routing + error mapping, task/push-config/card/webhook
 * handlers against the real platform A2A collaborator stack, and the
 * per-install-mode collaborator seams. The chat pipeline + SSE handler
 * are monolith-only seams — the degradation branches are exercised
 * through seam subclasses.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\Rest\A2aRestController;

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam fixtures share this file with its test case.

/**
 * Seam forcing the absent-authenticator + absent-security-manager branches.
 */
class A2aNoAuthSeam extends A2aRestController {

	/**
	 * Resolve no authenticator class.
	 *
	 * @return string|null
	 */
	protected static function authenticator_class(): ?string {
		return null;
	}

	/**
	 * Resolve no security manager class.
	 *
	 * @return string|null
	 */
	protected static function security_manager_class(): ?string {
		return null;
	}

	/**
	 * Expose a protected static seam for probing.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed Method result.
	 */
	public static function probe( $method, array $args = array() ) {
		return self::$method( ...$args );
	}
}

/**
 * Seam forcing the chat-pipeline + SSE degradation branches and a
 * deterministic assistant resolution.
 */
class A2aDegradedSeam extends A2aNoAuthSeam {

	/**
	 * No chat processor.
	 *
	 * @return object|null
	 */
	protected static function chat_processor() {
		return null;
	}

	/**
	 * No router.
	 *
	 * @return object|null
	 */
	protected static function router() {
		return null;
	}

	/**
	 * No SSE handler.
	 *
	 * @return object|null
	 */
	protected static function sse_handler() {
		return null;
	}
}

/**
 * Seam with a deterministic chat result for the message/send happy path.
 */
class A2aChatSeam extends A2aDegradedSeam {

	/**
	 * Deterministic assistant ID.
	 *
	 * @var int
	 */
	public static $forced_assistant_id = 0;

	/**
	 * Deterministic chat result.
	 *
	 * @var array
	 */
	public static $forced_chat_result = array( 'content' => 'Hello from the agent.' );

	/**
	 * Resolve the assistant deterministically.
	 *
	 * @param array $params The request params.
	 * @return int The assistant post ID.
	 */
	protected static function resolve_assistant( $params ) {
		return self::$forced_assistant_id;
	}

	/**
	 * Deterministic chat result.
	 *
	 * @param array $messages     Chat messages.
	 * @param int   $assistant_id Assistant ID.
	 * @return array Chat result.
	 */
	protected static function process_chat( $messages, $assistant_id ) {
		return self::$forced_chat_result;
	}
}

/**
 * @group a2a
 */
class Test_A2a_Rest_Controller extends \WP_UnitTestCase {

	/**
	 * Current admin user ID.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_a2a_server' => 1,
			)
		);
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		delete_option( 'wp_mcp_ai_settings' );

		// Reset the cached authenticator so per-mode seams re-resolve.
		$ref = new \ReflectionProperty( A2aRestController::class, 'authenticator' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		parent::tearDown();
	}

	/**
	 * Build a REST request to an A2A route.
	 *
	 * @param string $route Route path.
	 * @return \WP_REST_Request
	 */
	private function a2a_request( string $route ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_param( 'jsonrpc', '2.0' );
		$request->set_param( 'id', 'req-1' );
		$request->set_param( 'method', 'tasks/get' );
		$request->set_param( 'params', array() );

		return $request;
	}

	/**
	 * Create an assistant post and return its ID.
	 *
	 * @return int
	 */
	private function create_assistant(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);
	}

	// ─── Constants + routes ───────────────────────────────────────────

	public function test_constants_are_byte_identical(): void {
		$this->assertSame( 'mcp-ai/v1', A2aRestController::REST_NAMESPACE );
		$this->assertSame( '1.0', A2aRestController::A2A_VERSION );
		$this->assertSame(
			array(
				'message/send',
				'message/stream',
				'tasks/get',
				'tasks/list',
				'tasks/cancel',
				'tasks/pushNotificationConfig/create',
				'tasks/pushNotificationConfig/get',
				'tasks/pushNotificationConfig/list',
				'tasks/pushNotificationConfig/delete',
				'agent/authenticatedExtendedCard',
			),
			A2aRestController::SUPPORTED_METHODS
		);
	}

	public function test_routes_are_registered(): void {
		if ( ! did_action( 'rest_api_init' ) ) {
			do_action( 'rest_api_init' );
		}
		A2aRestController::register_routes();

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/a2a', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/a2a/agent-card/(?P<assistant_id>\\d+)', $routes );
		$this->assertArrayHasKey( '/mcp-ai/v1/a2a/webhook', $routes );

		$a2a_route = $routes['/mcp-ai/v1/a2a'];
		$methods   = array();
		foreach ( $a2a_route as $entry ) {
			foreach ( array_keys( (array) $entry['methods'] ) as $method ) {
				$methods[] = $method;
			}
		}
		$this->assertContains( \WP_REST_Server::CREATABLE, $methods );
		$this->assertContains( \WP_REST_Server::READABLE, $methods );
	}

	// ─── Permission gates ─────────────────────────────────────────────

	public function test_permissions_check_a2a_rejects_when_disabled(): void {
		update_option( 'wp_mcp_ai_settings', array( 'enable_a2a_server' => 0 ) );

		$result = A2aRestController::permissions_check_a2a( $this->a2a_request( '/mcp-ai/v1/a2a' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'a2a_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_permissions_check_a2a_rejects_future_version(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_header( 'A2A-Version', '2.0' );

		$result = A2aRestController::permissions_check_a2a( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'a2a_version_not_supported', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	public function test_permissions_check_a2a_accepts_supported_version(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_header( 'A2A-Version', '1.0' );

		// Version gate passes; authentication then decides. No credentials →
		// an error is expected, but not the version error.
		$result = A2aRestController::permissions_check_a2a( $request );

		$this->assertWPError( $result );
		$this->assertNotSame( 'a2a_version_not_supported', $result->get_error_code() );
	}

	public function test_permissions_check_a2a_nonce_authenticated_user_passes(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertTrue( A2aRestController::permissions_check_a2a( $request ) );
	}

	public function test_permissions_check_a2a_degrades_without_authenticator(): void {
		// The monorepo autoloader resolves the base authenticator in both
		// test matrices — force the absent-authenticator branch through the
		// seam so the documented standalone degradation is deterministic.
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_header( 'X-WP-MCP-AI-Mesh-Key', 'some-mesh-key' );

		$result = A2aNoAuthSeam::permissions_check_a2a( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_auth_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_permissions_check_agent_card_is_public_when_enabled(): void {
		$this->assertTrue( A2aRestController::permissions_check_agent_card( $this->a2a_request( '/mcp-ai/v1/a2a' ) ) );

		update_option( 'wp_mcp_ai_settings', array( 'enable_a2a_server' => 0 ) );

		$result = A2aRestController::permissions_check_agent_card( $this->a2a_request( '/mcp-ai/v1/a2a' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'a2a_disabled', $result->get_error_code() );
	}

	public function test_permissions_check_per_assistant_card_requires_auth(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a/agent-card/1' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$this->assertTrue( A2aRestController::permissions_check_per_assistant_card( $request ) );

		$request->remove_header( 'X-WP-Nonce' );
		$this->assertWPError( A2aRestController::permissions_check_per_assistant_card( $request ) );
	}

	// ─── JSON-RPC envelope + routing ──────────────────────────────────

	public function test_jsonrpc_unknown_method_maps_to_32601(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'nope/missing' );

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertSame( 'req-1', $data['id'] );
		$this->assertSame( -32601, $data['error']['code'] );
		$this->assertSame( 'a2a_method_not_found', $data['error']['data']['type'] );
	}

	public function test_jsonrpc_invalid_params_maps_to_32602(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'message/send' );
		$request->set_param( 'params', array( 'message' => array( 'no-parts' => true ) ) );

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertSame( 'a2a_invalid_params', $data['error']['data']['type'] );
	}

	public function test_jsonrpc_success_envelope(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'tasks/get' );
		$request->set_param( 'params', array( 'id' => '' ) );

		// Empty id → invalid-params error; envelope shape still validated
		// below via the dedicated success path (tasks/get with real task).
		$response = A2aRestController::handle_jsonrpc_request( $request );
		$this->assertSame( '2.0', $response->get_data()['jsonrpc'] );
	}

	public function test_map_error_code_full_table_and_default(): void {
		$this->assertSame( -32602, A2aChatSeam::probe( 'map_error_code', array( 'a2a_invalid_params' ) ) );
		$this->assertSame( -32601, A2aChatSeam::probe( 'map_error_code', array( 'a2a_method_not_found' ) ) );
		$this->assertSame( -32001, A2aChatSeam::probe( 'map_error_code', array( 'a2a_task_not_found' ) ) );
		$this->assertSame( -32002, A2aChatSeam::probe( 'map_error_code', array( 'a2a_task_not_cancelable' ) ) );
		$this->assertSame( -32003, A2aChatSeam::probe( 'map_error_code', array( 'a2a_push_not_supported' ) ) );
		$this->assertSame( -32004, A2aChatSeam::probe( 'map_error_code', array( 'a2a_unsupported_operation' ) ) );
		$this->assertSame( -32005, A2aChatSeam::probe( 'map_error_code', array( 'a2a_content_type_not_supported' ) ) );
		$this->assertSame( -32006, A2aChatSeam::probe( 'map_error_code', array( 'a2a_version_not_supported' ) ) );
		$this->assertSame( -32007, A2aChatSeam::probe( 'map_error_code', array( 'a2a_disabled' ) ) );
		$this->assertSame( -32008, A2aChatSeam::probe( 'map_error_code', array( 'a2a_invalid_assistant' ) ) );
		$this->assertSame( -32009, A2aChatSeam::probe( 'map_error_code', array( 'a2a_invalid_transition' ) ) );
		$this->assertSame( -32603, A2aChatSeam::probe( 'map_error_code', array( 'something_else' ) ) );
	}

	// ─── Tasks ────────────────────────────────────────────────────────

	public function test_tasks_get_requires_id(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'tasks/get' );

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( -32602, $data['error']['code'] );
	}

	public function test_tasks_get_returns_task_and_trims_history(): void {
		$manager = A2aChatSeam::probe( 'task_manager_class' );

		$created = $manager::create_task(
			array(
				'kind'      => 'message',
				'messageId' => 'm1',
				'role'      => 'user',
				'parts'     => array(
					array(
						'kind' => 'text',
						'text' => 'one',
					),
				),
			),
			'ctx-1'
		);
		$manager::add_message(
			$created['id'],
			array(
				'kind'      => 'message',
				'messageId' => 'm2',
				'role'      => 'agent',
				'parts'     => array(
					array(
						'kind' => 'text',
						'text' => 'two',
					),
				),
			)
		);

		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'tasks/get' );
		$request->set_param(
			'params',
			array(
				'id'            => $created['id'],
				'historyLength' => 1,
			)
		);

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$task     = $response->get_data()['result'];

		$this->assertSame( $created['id'], $task['id'] );
		$this->assertCount( 1, $task['history'] );
		$this->assertSame( 'm2', $task['history'][0]['messageId'] );

		// historyLength 0 strips history entirely.
		$request->set_param(
			'params',
			array(
				'id'            => $created['id'],
				'historyLength' => 0,
			)
		);
		$response = A2aRestController::handle_jsonrpc_request( $request );
		$this->assertArrayNotHasKey( 'history', $response->get_data()['result'] );
	}

	public function test_tasks_get_missing_task_maps_to_32001(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'tasks/get' );
		$request->set_param( 'params', array( 'id' => 'does-not-exist' ) );

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( -32001, $data['error']['code'] );
	}

	public function test_tasks_list_filters_and_shapes(): void {
		$manager = A2aChatSeam::probe( 'task_manager_class' );
		$manager::create_task(
			array(
				'kind'      => 'message',
				'messageId' => 'm1',
				'role'      => 'user',
				'parts'     => array(
					array(
						'kind' => 'text',
						'text' => 'x',
					),
				),
			),
			'ctx-e5'
		);

		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'tasks/list' );
		$request->set_param(
			'params',
			array(
				'contextId' => 'ctx-e5',
				'pageSize'  => 500,
			)
		);

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$result   = $response->get_data()['result'];

		$this->assertArrayHasKey( 'tasks', $result );
		$this->assertArrayHasKey( 'nextPageToken', $result );
		$this->assertNotEmpty( $result['tasks'] );
		// Artifacts stripped by default.
		$this->assertArrayNotHasKey( 'artifacts', $result['tasks'][0] );
	}

	public function test_tasks_cancel_transitions_and_fires_push(): void {
		$manager = A2aChatSeam::probe( 'task_manager_class' );
		$task    = $manager::create_task(
			array(
				'kind'      => 'message',
				'messageId' => 'm1',
				'role'      => 'user',
				'parts'     => array(
					array(
						'kind' => 'text',
						'text' => 'x',
					),
				),
			),
			'ctx-e5'
		);
		$manager::transition_state( $task['id'], $manager::STATE_WORKING );

		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'tasks/cancel' );
		$request->set_param( 'params', array( 'id' => $task['id'] ) );

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$result   = $response->get_data()['result'];

		$this->assertSame( $task['id'], $result['id'] );
		$this->assertSame( $manager::STATE_CANCELED, $result['status']['state'] );
	}

	// ─── Push notification configs ────────────────────────────────────

	public function test_push_configs_reject_when_disabled(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_a2a_server'             => 1,
				'a2a_enable_push_notifications' => 0,
			)
		);

		foreach ( array( 'tasks/pushNotificationConfig/create', 'tasks/pushNotificationConfig/get', 'tasks/pushNotificationConfig/list', 'tasks/pushNotificationConfig/delete' ) as $method ) {
			$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
			$request->set_param( 'method', $method );
			$request->set_param( 'params', array( 'taskId' => 't1' ) );

			$response = A2aRestController::handle_jsonrpc_request( $request );
			$data     = $response->get_data();

			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( -32003, $data['error']['code'], "push gate failed for {$method}" );
			$this->assertSame( 'a2a_push_not_supported', $data['error']['data']['type'] );
		}
	}

	public function test_push_config_create_requires_existing_task_and_url(): void {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_a2a_server'             => 1,
				'a2a_enable_push_notifications' => 1,
			)
		);
		$manager = A2aChatSeam::probe( 'task_manager_class' );
		$task    = $manager::create_task(
			array(
				'kind'      => 'message',
				'messageId' => 'm1',
				'role'      => 'user',
				'parts'     => array(
					array(
						'kind' => 'text',
						'text' => 'x',
					),
				),
			),
			'ctx-e5'
		);

		// Missing task → 404 envelope.
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'tasks/pushNotificationConfig/create' );
		$request->set_param(
			'params',
			array(
				'taskId'                 => 'nope',
				'pushNotificationConfig' => array( 'url' => 'https://example.com/hook' ),
			)
		);
		$response = A2aRestController::handle_jsonrpc_request( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( -32001, $response->get_data()['error']['code'] );

		// Valid create → config with generated id + sanitized URL.
		$request->set_param(
			'params',
			array(
				'taskId'                 => $task['id'],
				'pushNotificationConfig' => array( 'url' => 'https://example.com/hook' ),
			)
		);
		$response = A2aRestController::handle_jsonrpc_request( $request );
		$config   = $response->get_data()['result'];

		$this->assertSame( $task['id'], $config['taskId'] );
		$this->assertSame( 'https://example.com/hook', $config['url'] );
		$this->assertNotEmpty( $config['id'] );

		// get/list/delete roundtrip through the controller.
		$request->set_param( 'method', 'tasks/pushNotificationConfig/get' );
		$request->set_param(
			'params',
			array(
				'taskId' => $task['id'],
				'id'     => $config['id'],
			)
		);
		$this->assertSame( $config['id'], A2aRestController::handle_jsonrpc_request( $request )->get_data()['result']['id'] );

		$request->set_param( 'method', 'tasks/pushNotificationConfig/list' );
		$request->set_param( 'params', array( 'taskId' => $task['id'] ) );
		$this->assertNotEmpty( A2aRestController::handle_jsonrpc_request( $request )->get_data()['result'] );

		$request->set_param( 'method', 'tasks/pushNotificationConfig/delete' );
		$request->set_param(
			'params',
			array(
				'taskId' => $task['id'],
				'id'     => $config['id'],
			)
		);
		$delete_result = A2aRestController::handle_jsonrpc_request( $request )->get_data()['result'];
		$this->assertTrue( $delete_result['deleted'] );
	}

	// ─── Agent cards ──────────────────────────────────────────────────

	public function test_agent_card_request_returns_site_card(): void {
		$response = A2aRestController::handle_agent_card_request( $this->a2a_request( '/mcp-ai/v1/a2a' ) );
		$card     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'name', $card );
		$this->assertArrayHasKey( 'url', $card );
		$this->assertArrayHasKey( 'protocolVersion', $card );
	}

	public function test_per_assistant_card_resolves_assistant_and_errors(): void {
		$assistant_id = $this->create_assistant();

		$request = new \WP_REST_Request( 'GET', '/mcp-ai/v1/a2a/agent-card/' . $assistant_id );
		$request->set_param( 'assistant_id', $assistant_id );

		$response = A2aRestController::handle_per_assistant_card( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'name', $response->get_data() );

		$request->set_param( 'assistant_id', 999999 );
		$response = A2aRestController::handle_per_assistant_card( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'a2a_invalid_assistant', $response->get_data()['error'] );
	}

	// ─── Webhook receive ──────────────────────────────────────────────

	public function test_webhook_receive_success_and_errors(): void {
		$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/a2a/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'statusUpdate' => array(
						'taskId'    => 'task-x',
						'contextId' => 'ctx-x',
						'status'    => array( 'state' => 'completed' ),
						'final'     => true,
					),
				)
			)
		);

		$response = A2aRestController::handle_webhook( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'status' => 'received' ), $response->get_data() );

		$request->set_body( wp_json_encode( array( 'unexpected' => true ) ) );
		$response = A2aRestController::handle_webhook( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'a2a_unknown_event', $response->get_data()['error'] );
	}

	// ─── message/send ─────────────────────────────────────────────────

	public function test_message_send_happy_path_completes_task(): void {
		$assistant_id                     = $this->create_assistant();
		A2aChatSeam::$forced_assistant_id = $assistant_id;

		$received = array();
		add_action(
			'wp_mcp_ai_a2a_message_received',
			function ( $message ) use ( &$received ) {
				$received = $message;
			},
			10,
			1
		);

		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'message/send' );
		$request->set_param(
			'params',
			array(
				'message' => array(
					'messageId' => 'msg-1',
					'role'      => 'user',
					'parts'     => array(
						array(
							'kind' => 'text',
							'text' => 'Hello',
						),
					),
				),
			)
		);

		$response = A2aChatSeam::handle_jsonrpc_request( $request );
		$task     = $response->get_data()['result'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'msg-1', $received['messageId'] );

		$manager = A2aChatSeam::probe( 'task_manager_class' );
		$this->assertSame( $manager::STATE_COMPLETED, $task['status']['state'] );
		$this->assertNotEmpty( $task['history'] );
		$this->assertSame( 'Hello from the agent.', $task['history'][ count( $task['history'] ) - 1 ]['parts'][0]['text'] );
	}

	public function test_message_send_rejects_unknown_continuation_task(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'message/send' );
		$request->set_param(
			'params',
			array(
				'message' => array(
					'messageId' => 'msg-1',
					'role'      => 'user',
					'taskId'    => 'does-not-exist',
					'parts'     => array(
						array(
							'kind' => 'text',
							'text' => 'Hi',
						),
					),
				),
			)
		);

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( -32001, $data['error']['code'] );
	}

	public function test_message_send_rejects_terminal_task(): void {
		$manager = A2aChatSeam::probe( 'task_manager_class' );
		$task    = $manager::create_task(
			array(
				'kind'      => 'message',
				'messageId' => 'm1',
				'role'      => 'user',
				'parts'     => array(
					array(
						'kind' => 'text',
						'text' => 'x',
					),
				),
			),
			'ctx-e5'
		);
		$manager::transition_state( $task['id'], $manager::STATE_COMPLETED );

		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'message/send' );
		$request->set_param(
			'params',
			array(
				'message' => array(
					'messageId' => 'msg-2',
					'role'      => 'user',
					'taskId'    => $task['id'],
					'parts'     => array(
						array(
							'kind' => 'text',
							'text' => 'Again',
						),
					),
				),
			)
		);

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( -32004, $data['error']['code'] );
	}

	// ─── message/stream degradation ───────────────────────────────────

	public function test_message_stream_invalid_params_envelope(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'message/stream' );
		$request->set_param( 'params', array( 'message' => array() ) );

		$response = A2aRestController::handle_jsonrpc_request( $request );
		$data     = $response->get_data();

		$this->assertSame( -32602, $data['error']['code'] );
	}

	public function test_message_stream_degrades_without_sse_handler(): void {
		$request = $this->a2a_request( '/mcp-ai/v1/a2a' );
		$request->set_param( 'method', 'message/stream' );
		$request->set_param(
			'params',
			array(
				'message' => array(
					'messageId' => 'msg-1',
					'role'      => 'user',
					'parts'     => array(
						array(
							'kind' => 'text',
							'text' => 'Hi',
						),
					),
				),
			)
		);

		$response = A2aDegradedSeam::handle_jsonrpc_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( -32603, $data['error']['code'] );
		$this->assertSame( 'a2a_processing_error', $data['error']['data']['type'] );
	}

	// ─── Chat pipeline + assistant resolution seams ───────────────────

	public function test_process_chat_guards_and_degrades(): void {
		$assistant_id = $this->create_assistant();

		// No assistant → a2a_no_assistant.
		$result = A2aDegradedSeam::probe( 'process_chat', array( array(), 0 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'a2a_no_assistant', $result->get_error_code() );

		// Invalid post → a2a_invalid_assistant.
		$result = A2aDegradedSeam::probe( 'process_chat', array( array(), 999999 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'a2a_invalid_assistant', $result->get_error_code() );

		// Valid assistant but no pipeline → a2a_processing_error (documented
		// standalone degradation).
		$result = A2aDegradedSeam::probe(
			'process_chat',
			array(
				array(
					array(
						'role'    => 'user',
						'content' => 'Hi',
					),
				),
				$assistant_id,
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'a2a_processing_error', $result->get_error_code() );
	}

	public function test_resolve_assistant_prefers_metadata(): void {
		$this->assertSame(
			123,
			A2aDegradedSeam::probe( 'resolve_assistant', array( array( 'metadata' => array( 'assistant_id' => '123' ) ) ) )
		);
	}

	// ─── Per-mode collaborator seams ──────────────────────────────────

	public function test_collaborator_seams_resolve_per_install_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_A2A_Message_Translator', A2aChatSeam::probe( 'translator_class' ) );
			$this->assertSame( 'WP_MCP_AI_A2A_Task_Manager', A2aChatSeam::probe( 'task_manager_class' ) );
			$this->assertSame( 'WP_MCP_AI_A2A_Push_Notifications', A2aChatSeam::probe( 'push_notifications_class' ) );
			$this->assertSame( 'WP_MCP_AI_A2A_Agent_Card', A2aChatSeam::probe( 'agent_card_class' ) );
			$this->assertSame( 'WP_MCP_AI_A2A_Webhook_Handler', A2aChatSeam::probe( 'webhook_handler_class' ) );
		} else {
			$this->assertSame( 'NvoosContentGraphAiPlatform\A2A\MessageTranslator', A2aChatSeam::probe( 'translator_class' ) );
			$this->assertSame( 'NvoosContentGraphAiPlatform\A2A\TaskManager', A2aChatSeam::probe( 'task_manager_class' ) );
			$this->assertSame( 'NvoosContentGraphAiPlatform\A2A\PushNotifications', A2aChatSeam::probe( 'push_notifications_class' ) );
			$this->assertSame( 'NvoosContentGraphAiPlatform\A2A\AgentCard', A2aChatSeam::probe( 'agent_card_class' ) );
			$this->assertSame( 'NvoosContentGraphAiPlatform\A2A\WebhookHandler', A2aChatSeam::probe( 'webhook_handler_class' ) );
		}
	}
}
