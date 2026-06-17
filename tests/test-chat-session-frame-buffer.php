<?php
/**
 * Tests for WP_MCP_AI_Chat_Session_Frame_Buffer.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test the chat session frame buffer.
 */
class Test_Chat_Session_Frame_Buffer extends WP_UnitTestCase {

	/**
	 * Unique session prefix per test run.
	 *
	 * @var string
	 */
	private $session_prefix;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->session_prefix = 'test_' . wp_generate_uuid4();
	}

	/**
	 * Tear down: flush any transients created during the test.
	 */
	public function tearDown(): void {
		// Nothing to clean — WP_UnitTestCase rolls back the DB.
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// sanitize_session_id
	// -----------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_sanitize_session_id_allows_alphanumeric_dash_underscore() {
		$this->assertSame( 'abc-123_XYZ', WP_MCP_AI_Chat_Session_Frame_Buffer::sanitize_session_id( 'abc-123_XYZ' ) );
	}

	/**
	 * @test
	 */
	public function test_sanitize_session_id_strips_special_chars() {
		$this->assertSame( 'abc123', WP_MCP_AI_Chat_Session_Frame_Buffer::sanitize_session_id( 'abc<>123!@#' ) );
	}

	/**
	 * @test
	 */
	public function test_sanitize_session_id_truncates_to_64_chars() {
		$long = str_repeat( 'a', 100 );
		$this->assertSame( 64, strlen( WP_MCP_AI_Chat_Session_Frame_Buffer::sanitize_session_id( $long ) ) );
	}

	/**
	 * @test
	 */
	public function test_sanitize_session_id_returns_empty_on_non_string() {
		$this->assertSame( '', WP_MCP_AI_Chat_Session_Frame_Buffer::sanitize_session_id( null ) );
		$this->assertSame( '', WP_MCP_AI_Chat_Session_Frame_Buffer::sanitize_session_id( 123 ) );
	}

	// -----------------------------------------------------------------------
	// push / get_frames_since
	// -----------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_push_returns_monotonic_ids() {
		$session = $this->session_prefix . '_mono';
		$id1     = WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array( 'msg' => 'a' ) );
		$id2     = WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array( 'msg' => 'b' ) );
		$this->assertGreaterThan( 0, $id1 );
		$this->assertGreaterThan( $id1, $id2 );
	}

	/**
	 * @test
	 */
	public function test_get_frames_since_returns_all_when_since_zero() {
		$session = $this->session_prefix . '_since0';
		WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array( 'x' => 1 ) );
		WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'ping', array( 'ts' => 2 ) );

		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $session, 0 );
		$this->assertCount( 2, $frames );
	}

	/**
	 * @test
	 */
	public function test_get_frames_since_filters_by_id() {
		$session = $this->session_prefix . '_filter';
		$id1     = WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array( 'x' => 1 ) );
		WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array( 'x' => 2 ) );
		WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array( 'x' => 3 ) );

		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $session, $id1 );
		$this->assertCount( 2, $frames );
		foreach ( $frames as $f ) {
			$this->assertGreaterThan( $id1, $f['id'] );
		}
	}

	/**
	 * @test
	 */
	public function test_get_frames_since_returns_empty_for_unknown_session() {
		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $this->session_prefix . '_unknown', 0 );
		$this->assertIsArray( $frames );
		$this->assertCount( 0, $frames );
	}

	/**
	 * @test
	 */
	public function test_frame_structure_is_correct() {
		$session = $this->session_prefix . '_struct';
		WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array( 'message' => 'hello' ) );
		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $session, 0 );
		$this->assertCount( 1, $frames );
		$frame = $frames[0];
		$this->assertArrayHasKey( 'id', $frame );
		$this->assertArrayHasKey( 'event', $frame );
		$this->assertArrayHasKey( 'data', $frame );
		$this->assertArrayHasKey( 'ts', $frame );
		$this->assertSame( 'chat:resumed', $frame['event'] );
		$this->assertSame( 'hello', $frame['data']['message'] );
	}

	// -----------------------------------------------------------------------
	// Ring-buffer cap
	// -----------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_ring_buffer_cap_is_enforced() {
		$cap     = 5;
		$session = $this->session_prefix . '_cap';
		add_filter(
			'wp_mcp_ai_chat_session_buffer_size',
			function () use ( $cap ) {
				return $cap;
			}
		);

		for ( $i = 1; $i <= 8; $i++ ) {
			WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'ping', array( 'n' => $i ) );
		}

		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $session, 0 );
		$this->assertCount( $cap, $frames );
		// Should be the 3 most-recently-dropped (n=4..8) — i.e. n >= 4.
		foreach ( $frames as $f ) {
			$this->assertGreaterThanOrEqual( 4, $f['data']['n'] );
		}

		remove_all_filters( 'wp_mcp_ai_chat_session_buffer_size' );
	}

	// -----------------------------------------------------------------------
	// latest_id
	// -----------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_latest_id_returns_zero_for_empty_session() {
		$this->assertSame( 0, WP_MCP_AI_Chat_Session_Frame_Buffer::latest_id( $this->session_prefix . '_empty' ) );
	}

	/**
	 * @test
	 */
	public function test_latest_id_matches_last_pushed_id() {
		$session = $this->session_prefix . '_lid';
		WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array() );
		$last_id = WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'ping', array() );
		$this->assertSame( $last_id, WP_MCP_AI_Chat_Session_Frame_Buffer::latest_id( $session ) );
	}

	// -----------------------------------------------------------------------
	// flush
	// -----------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_flush_clears_all_frames() {
		$session = $this->session_prefix . '_flush';
		WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array( 'a' => 1 ) );
		WP_MCP_AI_Chat_Session_Frame_Buffer::push( $session, 'chat:resumed', array( 'a' => 2 ) );

		WP_MCP_AI_Chat_Session_Frame_Buffer::flush( $session );

		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( $session, 0 );
		$this->assertCount( 0, $frames );
		$this->assertSame( 0, WP_MCP_AI_Chat_Session_Frame_Buffer::latest_id( $session ) );
	}

	// -----------------------------------------------------------------------
	// push with invalid session ID
	// -----------------------------------------------------------------------

	/**
	 * @test
	 */
	public function test_push_returns_zero_for_empty_session_id() {
		$result = WP_MCP_AI_Chat_Session_Frame_Buffer::push( '', 'chat:resumed', array() );
		$this->assertSame( 0, $result );
	}

	/**
	 * @test
	 */
	public function test_push_strips_invalid_chars_from_session_id() {
		// This should NOT throw — it silently sanitizes.
		$result = WP_MCP_AI_Chat_Session_Frame_Buffer::push( 'valid<>invalid', 'ping', array( 'ok' => true ) );
		$this->assertGreaterThan( 0, $result );
		// Verify the frame is accessible via the sanitized key.
		$frames = WP_MCP_AI_Chat_Session_Frame_Buffer::get_frames_since( 'validinvalid', 0 );
		$this->assertCount( 1, $frames );
	}
}
