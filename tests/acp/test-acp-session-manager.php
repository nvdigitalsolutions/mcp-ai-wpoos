<?php
/**
 * Class Test_ACP_Session_Manager
 *
 * @package WP_MCP_AI
 */

class Test_ACP_Session_Manager extends WP_UnitTestCase {

	/**
	 * Manager instance.
	 *
	 * @var WP_MCP_AI_ACP_Session_Manager
	 */
	protected $manager;

	/**
	 * Current user ID for testing.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		
		require_once WP_MCP_AI_PATH . 'includes/acp/class-wp-mcp-ai-acp-session-manager.php';

		$this->manager = new WP_MCP_AI_ACP_Session_Manager();
		$this->user_id = $this->factory->user->create();
		wp_set_current_user( $this->user_id );
	}

	/**
	 * Clean up.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test creating a new session.
	 */
	public function test_create_session() {
		$result = $this->manager->create_session( array() );
		
		$this->assertArrayHasKey( 'sessionId', $result );
		$this->assertStringStartsWith( 'sess_', $result['sessionId'] );

		// Verify session data was saved to transient
		$session_data = get_transient( WP_MCP_AI_ACP_Session_Manager::TRANSIENT_PREFIX . $result['sessionId'] );
		$this->assertNotFalse( $session_data );
		$this->assertEquals( $this->user_id, $session_data['user_id'] );
		$this->assertIsArray( $session_data['messages'] );

		// Verify added to user meta
		$user_sessions = get_user_meta( $this->user_id, '_acp_sessions', true );
		$this->assertContains( $result['sessionId'], $user_sessions );
	}

	/**
	 * Test loading an existing session.
	 */
	public function test_load_session() {
		// Create a session directly via transient
		$session_id = 'sess_test123';
		$session_data = array(
			'id'      => $session_id,
			'user_id' => $this->user_id,
		);
		set_transient( WP_MCP_AI_ACP_Session_Manager::TRANSIENT_PREFIX . $session_id, $session_data, 300 );

		$result = $this->manager->load_session( $session_id );
		
		$this->assertNotWPError( $result );
		$this->assertEquals( $session_id, $result['sessionId'] );
	}

	/**
	 * Test loading session belonging to another user.
	 */
	public function test_load_session_unauthorized() {
		// Create a session for a different user
		$other_user_id = $this->factory->user->create();
		$session_id    = 'sess_unauth';
		$session_data  = array(
			'id'      => $session_id,
			'user_id' => $other_user_id,
		);
		set_transient( WP_MCP_AI_ACP_Session_Manager::TRANSIENT_PREFIX . $session_id, $session_data, 300 );

		$result = $this->manager->load_session( $session_id );
		
		$this->assertWPError( $result );
		$this->assertEquals( -32002, $result->get_error_code() );
	}

	/**
	 * Test session cancellation flag.
	 */
	public function test_cancel_session() {
		$session_id = 'sess_cancel_test';
		
		$this->assertFalse( $this->manager->is_cancelled( $session_id ) );
		
		$this->manager->cancel_session( $session_id );
		
		$this->assertTrue( $this->manager->is_cancelled( $session_id ) );
		
		$this->manager->clear_cancellation( $session_id );
		
		$this->assertFalse( $this->manager->is_cancelled( $session_id ) );
	}
}
