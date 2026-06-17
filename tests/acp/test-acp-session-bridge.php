<?php
/**
 * Class Test_ACP_Session_Bridge
 *
 * @package WP_MCP_AI
 */

class Test_ACP_Session_Bridge extends WP_UnitTestCase {

	/**
	 * Bridge instance.
	 *
	 * @var WP_MCP_AI_ACP_Session_Bridge
	 */
	protected $bridge;

	/**
	 * Chat service mock.
	 *
	 * @var WP_MCP_AI_Chat_Service|PHPUnit\Framework\MockObject\MockObject
	 */
	protected $chat_service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		
		require_once WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-session-manager.php';
		require_once WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-session-bridge.php';

		$this->bridge       = new WP_MCP_AI_ACP_Session_Bridge();
		
		// Create a basic mock for the chat service
		$this->chat_service = $this->getMockBuilder( 'WP_MCP_AI_Chat_Service' )
			->disableOriginalConstructor()
			->getMock();

		$this->bridge->set_chat_service( $this->chat_service );
		
		wp_set_current_user( $this->factory->user->create() );
	}

	/**
	 * Test handling a prompt turn.
	 */
	public function test_handle_prompt_success() {
		$session_manager = new WP_MCP_AI_ACP_Session_Manager();
		$session         = $session_manager->create_session( array() );
		$session_id      = $session['sessionId'];

		// Mock a successful chat service response
		$this->chat_service->expects( $this->once() )
			->method( 'process_chat_request' )
			->willReturn( array(
				'response' => 'This is a mock LLM response.',
			) );

		$params = array(
			'sessionId' => $session_id,
			'prompt'    => array(
				array(
					'type' => 'text',
					'text' => 'Hello, world!',
				),
			),
		);

		$result = $this->bridge->handle_prompt( $params );

		// Validate the stopReason
		$this->assertArrayHasKey( 'stopReason', $result );
		$this->assertEquals( 'end_turn', $result['stopReason'] );

		// Validate the queued update
		$updates = get_transient( 'acp_updates_' . $session_id );
		$this->assertNotEmpty( $updates );
		$this->assertEquals( 'session/update', $updates[0]['method'] );
		$this->assertEquals( 'agent_message_chunk', $updates[0]['params']['update']['sessionUpdate'] );
		$this->assertEquals( 'This is a mock LLM response.', $updates[0]['params']['update']['content']['text'] );
	}

	/**
	 * Test handling a prompt turn with a chat service error.
	 */
	public function test_handle_prompt_error() {
		$session_manager = new WP_MCP_AI_ACP_Session_Manager();
		$session         = $session_manager->create_session( array() );
		$session_id      = $session['sessionId'];

		// Mock an error chat service response
		$this->chat_service->expects( $this->once() )
			->method( 'process_chat_request' )
			->willReturn( new WP_Error( 'test_error', 'LLM failure mock' ) );

		$params = array(
			'sessionId' => $session_id,
			'prompt'    => array(
				array(
					'type' => 'text',
					'text' => 'Make an error.',
				),
			),
		);

		$result = $this->bridge->handle_prompt( $params );

		// Validate the stopReason
		$this->assertEquals( 'refusal', $result['stopReason'] );

		// Validate the queued update contains the error message
		$updates = get_transient( 'acp_updates_' . $session_id );
		$this->assertNotEmpty( $updates );
		$this->assertStringContainsString( 'LLM failure mock', $updates[0]['params']['update']['content']['text'] );
	}
}
