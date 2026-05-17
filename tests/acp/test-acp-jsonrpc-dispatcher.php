<?php
/**
 * Class Test_ACP_JSONRPC_Dispatcher
 *
 * @package WP_MCP_AI
 */

class Test_ACP_JSONRPC_Dispatcher extends WP_UnitTestCase {

	/**
	 * Dispatcher instance.
	 *
	 * @var WP_MCP_AI_ACP_JSONRPC_Dispatcher
	 */
	protected $dispatcher;

	/**
	 * Session manager mock.
	 *
	 * @var WP_MCP_AI_ACP_Session_Manager|PHPUnit\Framework\MockObject\MockObject
	 */
	protected $session_manager;

	/**
	 * Session bridge mock.
	 *
	 * @var WP_MCP_AI_ACP_Session_Bridge|PHPUnit\Framework\MockObject\MockObject
	 */
	protected $session_bridge;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		
		require_once WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-session-manager.php';
		require_once WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-session-bridge.php';
		require_once WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-jsonrpc-dispatcher.php';

		$this->session_manager = $this->createMock( 'WP_MCP_AI_ACP_Session_Manager' );
		$this->session_bridge  = $this->createMock( 'WP_MCP_AI_ACP_Session_Bridge' );

		$this->dispatcher = new WP_MCP_AI_ACP_JSONRPC_Dispatcher(
			$this->session_manager,
			$this->session_bridge
		);
	}

	/**
	 * Test JSON-RPC validation.
	 */
	public function test_invalid_jsonrpc_version() {
		$request = array(
			'jsonrpc' => '1.0',
			'method'  => 'initialize',
		);

		$response = $this->dispatcher->dispatch( $request );

		$this->assertArrayHasKey( 'error', $response );
		$this->assertEquals( -32600, $response['error']['code'] );
	}

	/**
	 * Test initialize capability negotiation.
	 */
	public function test_initialize_negotiation() {
		$request = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(),
		);

		$response = $this->dispatcher->dispatch( $request );

		$this->assertEquals( 1, $response['id'] );
		$this->assertArrayHasKey( 'result', $response );
		$this->assertEquals( 1, $response['result']['protocolVersion'] );
		$this->assertContains( 'wp_nonce', $response['result']['authMethods'] );
		$this->assertContains( 'bearer_credential', $response['result']['authMethods'] );
	}

	/**
	 * Test session creation delegation.
	 */
	public function test_session_new_delegation() {
		$this->session_manager->expects( $this->once() )
			->method( 'create_session' )
			->willReturn( array( 'sessionId' => 'sess_123' ) );

		$request = array(
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'session/new',
			'params'  => array(),
		);

		$response = $this->dispatcher->dispatch( $request );

		$this->assertEquals( 2, $response['id'] );
		$this->assertEquals( 'sess_123', $response['result']['sessionId'] );
	}
}
