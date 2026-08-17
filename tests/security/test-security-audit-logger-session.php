<?php
/**
 * Tests for session-log-sourced audit events (Proposal 029, Phase 5.8 —
 * telemetry single-path).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audit-logger session-event tests.
 */
class Test_WP_MCP_AI_Security_Audit_Logger_Session extends WP_UnitTestCase {

	/**
	 * Captured security events.
	 *
	 * @var array<int, array{0: string, 1: int, 2: string, 3: array}>
	 */
	private $captured = array();

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->captured = array();

		// The SQLite-backed test driver cannot run dbDelta; mark the audit
		// table as current so log_event() skips schema creation. The
		// insert itself is fail-soft and the security_event action still
		// fires — which is what these tests observe.
		update_option( 'wp_mcp_ai_security_log_table_version', WP_MCP_AI_Security_Audit_Logger::TABLE_VERSION );

		global $wpdb;
		$this->suppress = $wpdb->suppress_errors( true );

		add_action( 'wp_mcp_ai_security_event', array( $this, 'capture' ), 10, 4 );
	}

	/**
	 * Whether wpdb error output was suppressed by this test.
	 *
	 * @var bool
	 */
	private $suppress = false;

	/**
	 * Teardown.
	 */
	public function tearDown(): void {
		remove_action( 'wp_mcp_ai_security_event', array( $this, 'capture' ), 10 );
		$this->captured = array();

		global $wpdb;
		$wpdb->suppress_errors( $this->suppress );

		parent::tearDown();
	}



	/**
	 * Capture a security event.
	 *
	 * @param string $event_type Event type.
	 * @param int    $user_id    User id.
	 * @param string $ip_address IP address.
	 * @param array  $details    Details.
	 * @return void
	 */
	public function capture( $event_type, $user_id, $ip_address, $details ) {
		$this->captured[] = array( $event_type, $user_id, $ip_address, $details );
	}

	/**
	 * A tool_result entry audits as a tool_execution event.
	 */
	public function test_tool_result_audits_as_tool_execution() {
		WP_MCP_AI_Security_Audit_Logger::on_session_log_event(
			'tool_result',
			array(
				'name'         => 'create_post',
				'outcome'      => 'success',
				'duration_ms'  => 9.5,
				'assistant_id' => 7,
				'user_id'      => 3,
			),
			4,
			1000.0
		);

		$this->assertCount( 1, $this->captured );

		list( $event_type, $user_id, , $details ) = $this->captured[0];

		$this->assertSame( WP_MCP_AI_Security_Audit_Logger::EVENT_TOOL_EXECUTION, $event_type );
		$this->assertSame( 3, $user_id );
		$this->assertSame( 'create_post', $details['tool_slug'] );
		$this->assertSame( 'success', $details['outcome'] );
		$this->assertSame( 9.5, $details['duration_ms'] );
		$this->assertSame( 'session_log', $details['source'] );
	}

	/**
	 * Turn boundaries audit as chat_turn events.
	 */
	public function test_turn_boundaries_audit_as_chat_turn() {
		WP_MCP_AI_Security_Audit_Logger::on_session_log_event(
			'turn_started',
			array(
				'assistant_id' => 9,
				'user_id'      => 2,
			),
			1,
			1000.0
		);

		WP_MCP_AI_Security_Audit_Logger::on_session_log_event(
			'turn_ended',
			array(
				'assistant_id' => 9,
				'reason'       => 'iteration_limit',
				'iterations'   => 5,
			),
			10,
			1002.0
		);

		$this->assertCount( 2, $this->captured );

		$this->assertSame( WP_MCP_AI_Security_Audit_Logger::EVENT_CHAT_TURN, $this->captured[0][0] );
		$this->assertSame( 'started', $this->captured[0][3]['phase'] );
		$this->assertSame( WP_MCP_AI_Security_Audit_Logger::EVENT_CHAT_TURN, $this->captured[1][0] );
		$this->assertSame( 'ended', $this->captured[1][3]['phase'] );
		$this->assertSame( 'iteration_limit', $this->captured[1][3]['reason'] );
		$this->assertSame( 5, $this->captured[1][3]['iterations'] );
	}

	/**
	 * Irrelevant entry types produce no audit rows.
	 */
	public function test_irrelevant_types_are_ignored() {
		WP_MCP_AI_Security_Audit_Logger::on_session_log_event(
			'assistant_message',
			array( 'content' => 'hi' ),
			3,
			1000.0
		);

		$this->assertCount( 0, $this->captured );
	}

	/**
	 * The audit-from-log subscription is off by default.
	 */
	public function test_subscription_off_by_default() {
		$this->assertFalse(
			has_action( 'wp_mcp_ai_session_log_event', array( 'WP_MCP_AI_Security_Audit_Logger', 'on_session_log_event' ) ),
			'The session-log audit subscription must be opt-in.',
		);
	}
}
