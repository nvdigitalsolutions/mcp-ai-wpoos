<?php
/**
 * Tests for SSE Handler reconnection and duplicate prevention.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test SSE Handler reconnection features.
 */
class Test_SSE_Reconnection extends WP_UnitTestCase {

	/**
	 * SSE handler instance.
	 *
	 * @var WP_MCP_AI_SSE_Handler
	 */
	private $handler;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-sse-handler.php';
		$this->handler = new WP_MCP_AI_SSE_Handler();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		// Clear all SSE session transients.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_mcp_ai_sse_session_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_mcp_ai_sse_session_%'" );
		parent::tearDown();
	}

	/**
	 * Test get_last_event_id extracts ID from request header.
	 */
	public function test_get_last_event_id_from_header() {
		$request = new WP_REST_Request();
		$request->set_header( 'Last-Event-ID', '12345' );

		$result = $this->handler->get_last_event_id( $request );

		$this->assertSame( '12345', $result );
	}

	/**
	 * Test get_last_event_id returns empty string when header missing.
	 */
	public function test_get_last_event_id_missing_header() {
		$request = new WP_REST_Request();

		$result = $this->handler->get_last_event_id( $request );

		$this->assertSame( '', $result );
	}

	/**
	 * Test store and retrieve session state.
	 */
	public function test_store_and_get_session_state() {
		$session_id = 'test_session_123';
		$event_id   = 'event_456';
		$state_data = array( 'tool_executed' => true );

		// Store session state.
		$stored = $this->handler->store_session_state( $session_id, $event_id, $state_data );
		$this->assertTrue( $stored );

		// Retrieve session state.
		$state = $this->handler->get_session_state( $session_id );

		$this->assertIsArray( $state );
		$this->assertSame( $event_id, $state['last_event_id'] );
		$this->assertArrayHasKey( 'timestamp', $state );
		$this->assertArrayHasKey( 'data', $state );
		$this->assertTrue( $state['data']['tool_executed'] );
	}

	/**
	 * Test is_duplicate_event detects duplicate events.
	 */
	public function test_is_duplicate_event() {
		$session_id = 'test_session_456';
		$event_id   = 'event_789';

		// Initially, event should not be duplicate.
		$this->assertFalse( $this->handler->is_duplicate_event( $session_id, $event_id ) );

		// Store event.
		$this->handler->store_session_state( $session_id, $event_id );

		// Now it should be detected as duplicate.
		$this->assertTrue( $this->handler->is_duplicate_event( $session_id, $event_id ) );

		// Different event ID should not be duplicate.
		$this->assertFalse( $this->handler->is_duplicate_event( $session_id, 'different_event' ) );
	}

	/**
	 * Test clear_session_state removes state.
	 */
	public function test_clear_session_state() {
		$session_id = 'test_session_clear';
		$event_id   = 'event_clear';

		// Store session state.
		$this->handler->store_session_state( $session_id, $event_id );

		// Verify it exists.
		$state = $this->handler->get_session_state( $session_id );
		$this->assertNotNull( $state );

		// Clear session state.
		$cleared = $this->handler->clear_session_state( $session_id );
		$this->assertTrue( $cleared );

		// Verify it's gone.
		$state = $this->handler->get_session_state( $session_id );
		$this->assertNull( $state );
	}

	/**
	 * Test session state with different sessions doesn't conflict.
	 */
	public function test_multiple_sessions_independent() {
		$session1 = 'session_1';
		$session2 = 'session_2';
		$event1   = 'event_1';
		$event2   = 'event_2';

		// Store different states for different sessions.
		$this->handler->store_session_state( $session1, $event1, array( 'session' => 1 ) );
		$this->handler->store_session_state( $session2, $event2, array( 'session' => 2 ) );

		// Retrieve and verify each session has its own state.
		$state1 = $this->handler->get_session_state( $session1 );
		$state2 = $this->handler->get_session_state( $session2 );

		$this->assertSame( $event1, $state1['last_event_id'] );
		$this->assertSame( 1, $state1['data']['session'] );

		$this->assertSame( $event2, $state2['last_event_id'] );
		$this->assertSame( 2, $state2['data']['session'] );
	}
}
