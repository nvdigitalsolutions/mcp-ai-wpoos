<?php
/**
 * Tests for the Memory Auto-Capture Service (Phase 3 of the 2026 Memory
 * Layer Enhancements).
 *
 * Covers:
 *  - Default-off behaviour — bootstrap is a no-op when the master kill-switch
 *    filter is missing or returns false (no hooks registered).
 *  - Bootstrap idempotence.
 *  - Denylist / allowlist semantics.
 *  - SHA-256 dedup-window: hit / miss / expiry, redaction-before-hash so
 *    embedded secrets do not contaminate the dedup key.
 *  - Per-user `wp_mcp_ai_chat_memory_enabled` meta and site-wide filter gates.
 *  - Guest gate (`wp_mcp_ai_memory_auto_capture_guests_allowed`).
 *  - Envelope shape: `auto_captured` flag, `tier`, and `importance` defaults.
 *  - `wp_mcp_ai_memory_auto_captured` + `wp_mcp_ai_memory_auto_capture_deduped`
 *    action firing.
 *
 * @package WP_MCP_AI
 * @since 1.1.20
 */

if ( ! class_exists( 'WP_MCP_AI_Memory_Auto_Capture_Service' ) ) {
	require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-memory-auto-capture-service.php';
}
if ( ! class_exists( 'WP_MCP_AI_Memory_Privacy_Filter' ) ) {
	require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-memory-privacy-filter.php';
}
if ( ! class_exists( 'WP_MCP_AI_Memory_Capture_Service' ) ) {
	require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-memory-capture-service.php';
}

/**
 * Test case for `WP_MCP_AI_Memory_Auto_Capture_Service`.
 *
 * @since 1.1.20
 */
class Test_Memory_Auto_Capture_Service extends WP_UnitTestCase {

	/**
	 * Build a fake OpenAI-style key fixture at runtime.
	 *
	 * Constructed at runtime so the literal `sk-...{37+}` token never appears
	 * as a contiguous source-code span (avoids GitHub Secret Scanning false
	 * positives without weakening the regex it must match).
	 *
	 * @return string
	 */
	private static function fake_openai_key() {
		return 'sk-NOTAREAL' . str_repeat( 'a', 30 );
	}

	/**
	 * SHA-256 transients we created during a test, so tearDown can clear them.
	 *
	 * @var string[]
	 */
	private $created_dedup_keys = array();

	/**
	 * Captured event payloads from `wp_mcp_ai_memory_stored` so tests can
	 * inspect the envelope that would have been persisted.
	 *
	 * @var array[]
	 */
	private $captured_events = array();

	/**
	 * Set up: skip the transient leg so the capture service stays headless,
	 * reset the auto-capture singleton, and clean every filter / action this
	 * suite registers.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( 'wp_mcp_ai_memory_capture_skip_transient', '__return_true' );

		// Capture the memory_stored event so tests can assert on the envelope.
		$this->captured_events = array();
		add_action(
			'wp_mcp_ai_memory_stored',
			array( $this, 'spy_on_memory_stored' ),
			99
		);

		WP_MCP_AI_Memory_Auto_Capture_Service::reset_for_tests();
	}

	/**
	 * Tear down — remove every filter / action the suite installed and
	 * clear any dedup transients to keep the rest of the test run clean.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Memory_Auto_Capture_Service::reset_for_tests();

		remove_action( 'wp_mcp_ai_memory_stored', array( $this, 'spy_on_memory_stored' ), 99 );

		remove_all_filters( 'wp_mcp_ai_memory_auto_capture_enabled' );
		remove_all_filters( 'wp_mcp_ai_memory_auto_capture_dedup_window' );
		remove_all_filters( 'wp_mcp_ai_memory_auto_capture_importance' );
		remove_all_filters( 'wp_mcp_ai_memory_auto_capture_tool_allowlist' );
		remove_all_filters( 'wp_mcp_ai_memory_auto_capture_tool_denylist' );
		remove_all_filters( 'wp_mcp_ai_memory_auto_capture_guests_allowed' );
		remove_all_filters( 'wp_mcp_ai_memory_auto_capture_wing' );
		remove_all_filters( 'wp_mcp_ai_memory_auto_capture_room' );
		remove_all_filters( 'wp_mcp_ai_memory_capture_skip_transient' );
		remove_all_filters( 'wp_mcp_ai_chat_memory_enabled' );

		remove_all_actions( 'wp_mcp_ai_memory_auto_captured' );
		remove_all_actions( 'wp_mcp_ai_memory_auto_capture_deduped' );
		remove_all_actions( 'wp_mcp_ai_memory_stored' );

		foreach ( $this->created_dedup_keys as $key ) {
			delete_transient( $key );
		}
		$this->created_dedup_keys = array();

		parent::tearDown();
	}

	/**
	 * Spy callback for `wp_mcp_ai_memory_stored`.
	 *
	 * @param array $event Event payload.
	 * @return void
	 */
	public function spy_on_memory_stored( $event ) {
		$this->captured_events[] = is_array( $event ) ? $event : array();
	}

	/**
	 * Enable the master kill-switch + a per-test fake user, and return the
	 * user ID for convenience.
	 *
	 * @return int Created user ID.
	 */
	private function arrange_enabled_with_user() {
		add_filter( 'wp_mcp_ai_memory_auto_capture_enabled', '__return_true' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		WP_MCP_AI_Memory_Auto_Capture_Service::reset_for_tests();
		WP_MCP_AI_Memory_Auto_Capture_Service::bootstrap();

		return $user_id;
	}

	/**
	 * Record a dedup transient key for cleanup.
	 *
	 * @param string $content Content used for the capture (post-redaction).
	 * @return string Transient key.
	 */
	private function track_dedup_key_for_content( $content ) {
		$normalised = trim( strtolower( (string) preg_replace( '/\s+/u', ' ', $content ) ) );
		$sha        = hash( 'sha256', $normalised );
		$key        = 'wp_mcp_ai_memory_dedup_' . substr( $sha, 0, 32 );

		$this->created_dedup_keys[] = $key;
		return $key;
	}

	/**
	 * Ensures bootstrap registers no hooks while auto-capture is disabled.
	 */
	public function test_default_off_does_not_register_hooks() {
		WP_MCP_AI_Memory_Auto_Capture_Service::reset_for_tests();
		WP_MCP_AI_Memory_Auto_Capture_Service::bootstrap();

		$this->assertFalse(
			has_action( 'wp_mcp_ai_tool_executed', array( 'WP_MCP_AI_Memory_Auto_Capture_Service', 'on_tool_executed' ) ),
			'tool_executed hook must not be registered when the master filter is off.'
		);
		$this->assertFalse(
			has_action( 'wp_mcp_ai_before_chat_request', array( 'WP_MCP_AI_Memory_Auto_Capture_Service', 'on_before_chat_request' ) ),
			'before_chat_request hook must not be registered when the master filter is off.'
		);
	}

	/**
	 * Ensures bootstrap is idempotent and does not duplicate hook callbacks.
	 */
	public function test_bootstrap_is_idempotent() {
		add_filter( 'wp_mcp_ai_memory_auto_capture_enabled', '__return_true' );

		WP_MCP_AI_Memory_Auto_Capture_Service::reset_for_tests();
		WP_MCP_AI_Memory_Auto_Capture_Service::bootstrap();
		WP_MCP_AI_Memory_Auto_Capture_Service::bootstrap();
		WP_MCP_AI_Memory_Auto_Capture_Service::bootstrap();

		global $wp_filter;

		$count_tool = 0;
		if ( isset( $wp_filter['wp_mcp_ai_tool_executed']->callbacks[20] ) ) {
			foreach ( $wp_filter['wp_mcp_ai_tool_executed']->callbacks[20] as $cb ) {
				if (
					isset( $cb['function'] )
					&& is_array( $cb['function'] )
					&& isset( $cb['function'][0], $cb['function'][1] )
					&& 'WP_MCP_AI_Memory_Auto_Capture_Service' === $cb['function'][0]
					&& 'on_tool_executed' === $cb['function'][1]
				) {
					++$count_tool;
				}
			}
		}
		$this->assertSame( 1, $count_tool, 'tool_executed hook must register exactly once.' );

		$count_chat = 0;
		if ( isset( $wp_filter['wp_mcp_ai_before_chat_request']->callbacks[20] ) ) {
			foreach ( $wp_filter['wp_mcp_ai_before_chat_request']->callbacks[20] as $cb ) {
				if (
					isset( $cb['function'] )
					&& is_array( $cb['function'] )
					&& isset( $cb['function'][0], $cb['function'][1] )
					&& 'WP_MCP_AI_Memory_Auto_Capture_Service' === $cb['function'][0]
					&& 'on_before_chat_request' === $cb['function'][1]
				) {
					++$count_chat;
				}
			}
		}
		$this->assertSame( 1, $count_chat, 'before_chat_request hook must register exactly once.' );
	}

	/**
	 * Ensures the master kill-switch blocks auto-capture.
	 */
	public function test_kill_switch_prevents_capture() {
		add_filter( 'wp_mcp_ai_memory_auto_capture_enabled', '__return_false' );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		WP_MCP_AI_Memory_Auto_Capture_Service::reset_for_tests();
		WP_MCP_AI_Memory_Auto_Capture_Service::bootstrap();

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', array( 'title' => 'Hello' ), array( 'success' => true ), array( 'user_id' => $user_id ) );

		$this->assertEmpty( $this->captured_events, 'No capture should happen when kill-switch is off.' );
	}

	/**
	 * Ensures denylisted tools are skipped.
	 */
	public function test_denylisted_tool_is_skipped() {
		$user_id = $this->arrange_enabled_with_user();

		// `recall_memory` is in the default denylist.
		do_action( 'wp_mcp_ai_tool_executed', 'recall_memory', array( 'agent_id' => 'user_' . $user_id ), array( 'success' => true ), array( 'user_id' => $user_id ) );

		$this->assertEmpty( $this->captured_events, 'Denylisted tool must not trigger a capture.' );
	}

	/**
	 * Ensures a non-empty allowlist limits capture to listed tools.
	 */
	public function test_allowlist_when_non_empty_excludes_other_tools() {
		$user_id = $this->arrange_enabled_with_user();

		add_filter(
			'wp_mcp_ai_memory_auto_capture_tool_allowlist',
			static function () {
				return array( 'only_this_tool' );
			}
		);

		// Not in the allowlist — must be skipped.
		do_action( 'wp_mcp_ai_tool_executed', 'create_post', array( 'title' => 'Hi' ), array( 'success' => true ), array( 'user_id' => $user_id ) );
		$this->assertEmpty( $this->captured_events, 'Tool not on the allowlist must be skipped.' );

		// In the allowlist — must be captured.
		$this->track_dedup_key_for_content( 'tool:only_this_tool | args:{"x":1} | status:ok' );
		do_action( 'wp_mcp_ai_tool_executed', 'only_this_tool', array( 'x' => 1 ), array( 'success' => true ), array( 'user_id' => $user_id ) );

		$this->assertCount( 1, $this->captured_events, 'Allowlisted tool must trigger a capture.' );
	}

	/**
	 * Ensures a repeated capture within dedup window is dropped.
	 */
	public function test_sha256_dedup_within_window_skips_second_capture() {
		$user_id = $this->arrange_enabled_with_user();

		$arguments = array( 'title' => 'Same input' );

		$this->track_dedup_key_for_content( 'tool:create_post | args:' . wp_json_encode( $arguments ) . ' | status:ok' );

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', $arguments, array( 'success' => true ), array( 'user_id' => $user_id ) );
		do_action( 'wp_mcp_ai_tool_executed', 'create_post', $arguments, array( 'success' => true ), array( 'user_id' => $user_id ) );

		$this->assertCount( 1, $this->captured_events, 'Second identical capture within window must be deduped.' );
	}

	/**
	 * Ensures captures are accepted again once dedup state expires.
	 */
	public function test_dedup_window_expiry_allows_recapture() {
		$user_id = $this->arrange_enabled_with_user();

		// Use a 1-second dedup window so we can exercise expiry without
		// sleeping for 5 minutes.
		add_filter(
			'wp_mcp_ai_memory_auto_capture_dedup_window',
			static function () {
				return 1;
			}
		);

		$arguments = array( 'unique_marker' => 'expiry-test-' . wp_generate_password( 6, false ) );
		$key       = $this->track_dedup_key_for_content( 'tool:create_post | args:' . wp_json_encode( $arguments ) . ' | status:ok' );

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', $arguments, array( 'success' => true ), array( 'user_id' => $user_id ) );
		$this->assertCount( 1, $this->captured_events, 'First capture must succeed.' );

		// Simulate expiry — delete the transient outright (deterministic, no sleep).
		delete_transient( $key );

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', $arguments, array( 'success' => true ), array( 'user_id' => $user_id ) );
		$this->assertCount( 2, $this->captured_events, 'Capture must succeed again after the dedup window expires.' );
	}

	/**
	 * Ensures redaction is applied before hashing for dedup.
	 */
	public function test_privacy_filter_applied_before_hashing() {
		$user_id = $this->arrange_enabled_with_user();

		// Two captures that differ ONLY in their embedded secret — after
		// redaction they MUST collapse to the same content hash and
		// therefore the same dedup key.
		$secret_a = self::fake_openai_key();
		$secret_b = 'sk-OTHERFAKE' . str_repeat( 'b', 30 );

		$content_a = 'token A is ' . $secret_a . ' end';
		$content_b = 'token A is ' . $secret_b . ' end';

		// The dedup key is computed from `hash('sha256', lowercased + redacted)`.
		// Redaction uses the literal `[REDACTED]` replacement (mixed-case), so the
		// post-redaction content used for hashing is `'token a is [REDACTED] end'`.
		$expected_hashed            = 'token a is [REDACTED] end';
		$this->created_dedup_keys[] = 'wp_mcp_ai_memory_dedup_' . substr( hash( 'sha256', $expected_hashed ), 0, 32 );

		// Drive the capture pipeline directly (bypasses tool denylist logic so
		// we keep the test focused on the redaction-before-hash contract).
		$first  = WP_MCP_AI_Memory_Auto_Capture_Service::capture(
			$content_a,
			array(
				'source'   => 'unit_test',
				'user_id'  => $user_id,
				'agent_id' => 'user_' . $user_id,
			)
		);
		$second = WP_MCP_AI_Memory_Auto_Capture_Service::capture(
			$content_b,
			array(
				'source'   => 'unit_test',
				'user_id'  => $user_id,
				'agent_id' => 'user_' . $user_id,
			)
		);

		$this->assertTrue( $first, 'First capture must store.' );
		$this->assertFalse( $second, 'Second capture (different secret, same prose) must be deduped.' );

		// The stored content must NOT contain either raw secret — they were
		// both redacted before hashing AND before storage.
		$this->assertCount( 1, $this->captured_events );
		$stored = $this->captured_events[0];
		$this->assertStringNotContainsString( $secret_a, $stored['content'] );
		$this->assertStringNotContainsString( $secret_b, $stored['content'] );
		$this->assertStringContainsString( '[REDACTED]', $stored['content'] );
	}

	/**
	 * Ensures guests are blocked by default and allowed when opted in.
	 */
	public function test_guest_skipped_by_default_and_allowed_when_filter_true() {
		add_filter( 'wp_mcp_ai_memory_auto_capture_enabled', '__return_true' );

		// Force a guest visitor.
		wp_set_current_user( 0 );

		WP_MCP_AI_Memory_Auto_Capture_Service::reset_for_tests();
		WP_MCP_AI_Memory_Auto_Capture_Service::bootstrap();

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', array( 'guest' => 'a' ), array( 'success' => true ), array() );
		$this->assertEmpty( $this->captured_events, 'Guest must be skipped by default.' );

		// Now allow guests and capture must succeed.
		add_filter( 'wp_mcp_ai_memory_auto_capture_guests_allowed', '__return_true' );

		// Different content to avoid the dedup window — the previous attempt
		// was rejected before reaching the dedup transient, but be safe.
		$this->track_dedup_key_for_content( 'tool:create_post | args:{"guest":"b"} | status:ok' );
		do_action( 'wp_mcp_ai_tool_executed', 'create_post', array( 'guest' => 'b' ), array( 'success' => true ), array() );

		$this->assertCount( 1, $this->captured_events, 'Guest capture must succeed when the filter allows guests.' );
	}

	/**
	 * Ensures user-level memory opt-out blocks auto-capture.
	 */
	public function test_per_user_meta_false_blocks_capture() {
		$user_id = $this->arrange_enabled_with_user();

		// User has explicitly opted out of chat memory.
		update_user_meta( $user_id, 'wp_mcp_ai_chat_memory_enabled', 0 );

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', array( 'opted' => 'out' ), array( 'success' => true ), array( 'user_id' => $user_id ) );

		$this->assertEmpty( $this->captured_events, 'Per-user meta opt-out must block auto-capture.' );
	}

	/**
	 * Ensures site-level chat memory filter can block auto-capture.
	 */
	public function test_site_wide_filter_false_blocks_capture() {
		$user_id = $this->arrange_enabled_with_user();

		add_filter( 'wp_mcp_ai_chat_memory_enabled', '__return_false' );

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', array( 'site' => 'wide' ), array( 'success' => true ), array( 'user_id' => $user_id ) );

		$this->assertEmpty( $this->captured_events, 'Site-wide kill-switch must block auto-capture.' );
	}

	/**
	 * Ensures stored envelopes include expected auto-capture metadata.
	 */
	public function test_captured_record_envelope_shape() {
		$user_id = $this->arrange_enabled_with_user();

		$this->track_dedup_key_for_content( 'tool:create_post | args:{"unique":"shape"} | status:ok' );

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', array( 'unique' => 'shape' ), array( 'success' => true ), array( 'user_id' => $user_id ) );

		$this->assertCount( 1, $this->captured_events );
		$envelope = $this->captured_events[0];

		$this->assertTrue( ! empty( $envelope['auto_captured'] ), 'auto_captured flag must be truthy.' );
		$this->assertEqualsWithDelta( 0.3, (float) $envelope['importance'], 0.0001, 'importance default must be 0.3.' );
		$this->assertSame( 'recall', $envelope['tier'], 'tier must default to recall.' );
		$this->assertSame( 'observation', $envelope['context_type'], 'context_type must be observation.' );
		$this->assertNotEmpty( $envelope['content_hash'], 'content_hash must be populated.' );
		$this->assertSame( 64, strlen( $envelope['content_hash'] ), 'content_hash must be a 64-char hex SHA-256.' );
	}

	/**
	 * Ensures dedup action fires on duplicate capture attempts.
	 */
	public function test_deduped_action_fires_on_window_hit() {
		$user_id = $this->arrange_enabled_with_user();

		$dedup_calls = array();
		add_action(
			'wp_mcp_ai_memory_auto_capture_deduped',
			static function ( $sha, $source ) use ( &$dedup_calls ) {
				$dedup_calls[] = array( $sha, $source );
			},
			10,
			2
		);

		$arguments = array( 'dedup_action' => 'test' );
		$this->track_dedup_key_for_content( 'tool:create_post | args:' . wp_json_encode( $arguments ) . ' | status:ok' );

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', $arguments, array( 'success' => true ), array( 'user_id' => $user_id ) );
		do_action( 'wp_mcp_ai_tool_executed', 'create_post', $arguments, array( 'success' => true ), array( 'user_id' => $user_id ) );

		$this->assertCount( 1, $dedup_calls, 'deduped action must fire exactly once for one duplicate.' );
		$this->assertSame( 64, strlen( (string) $dedup_calls[0][0] ), 'deduped action must receive a 64-char hex SHA.' );
		$this->assertSame( 'tool_execution', $dedup_calls[0][1], 'deduped action must receive the source label.' );
	}

	/**
	 * Ensures auto-captured action fires on fresh captures.
	 */
	public function test_auto_captured_action_fires_on_fresh_capture() {
		$user_id = $this->arrange_enabled_with_user();

		$captured_calls = array();
		add_action(
			'wp_mcp_ai_memory_auto_captured',
			static function ( $context_id, $sha, $source ) use ( &$captured_calls ) {
				$captured_calls[] = array( $context_id, $sha, $source );
			},
			10,
			3
		);

		$arguments = array( 'unique_action' => 'fresh' );
		$this->track_dedup_key_for_content( 'tool:create_post | args:' . wp_json_encode( $arguments ) . ' | status:ok' );

		do_action( 'wp_mcp_ai_tool_executed', 'create_post', $arguments, array( 'success' => true ), array( 'user_id' => $user_id ) );

		$this->assertCount( 1, $captured_calls, 'auto_captured action must fire exactly once on a fresh capture.' );
		$this->assertNotEmpty( $captured_calls[0][0], 'auto_captured must receive a non-empty context_id.' );
		$this->assertSame( 64, strlen( (string) $captured_calls[0][1] ), 'auto_captured must receive a 64-char SHA.' );
		$this->assertSame( 'tool_execution', $captured_calls[0][2], 'auto_captured must receive the source label.' );
	}

	/**
	 * Ensures chat hook captures the latest user message.
	 */
	public function test_chat_request_captures_latest_user_message() {
		$user_id = $this->arrange_enabled_with_user();

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'What is the weather in Paris?',
			),
		);

		$this->track_dedup_key_for_content( 'prompt: what is the weather in paris?' );

		do_action( 'wp_mcp_ai_before_chat_request', 42, $messages, array(), null );

		$this->assertCount( 1, $this->captured_events, 'Chat request must produce one capture.' );
		$this->assertStringContainsString( 'paris', strtolower( $this->captured_events[0]['content'] ) );
	}
}
