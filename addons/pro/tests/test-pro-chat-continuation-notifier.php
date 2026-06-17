<?php
/**
 * Test_Pro_Chat_Continuation_Notifier
 *
 * Slice 5 — Pro multi-channel webhook notifier for async-chat continuations.
 *
 * Covers:
 *  1.  Notifier registers the dispatched action hook.
 *  2.  No request is sent when the webhook URL option is empty.
 *  3.  No request is sent when the runtime kill-switch returns false.
 *  4.  Payload includes all required fields.
 *  5.  HMAC signature header is added when a secret is configured.
 *  6.  No signature header when no secret is set.
 *  7.  wp_mcp_ai_pro_continuation_notified fires on HTTP 200 response.
 *  8.  wp_mcp_ai_pro_continuation_notify_failed fires on HTTP 500 response.
 *  9.  wp_mcp_ai_pro_continuation_notify_failed fires on WP_Error.
 * 10.  wp_mcp_ai_pro_continuation_notify_payload filter can modify payload.
 * 11.  Empty payload after filter suppresses HTTP request.
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/services/class-wp-mcp-ai-pro-chat-continuation-notifier.php';

/**
 * PHPUnit test case for the Pro continuation notifier.
 */
class Test_Pro_Chat_Continuation_Notifier extends WP_UnitTestCase {

	// ── Fixtures ───────────────────────────────────────────────────────────────

	/**
	 * Test webhook URL.
	 *
	 * @var string
	 */
	private static $test_url = 'https://hooks.example.com/continuation';

	/**
	 * Minimal continuation snapshot.
	 *
	 * @var array
	 */
	private static $snapshot = array(
		'job_id'          => 'notify_job_001',
		'chat_session_id' => 'sess_notify_abc',
		'assistant_id'    => 10,
		'user_id'         => 5,
		'tool_name'       => 'render_animation',
		'terminal_at'     => 1715600090,
	);

	// ── Setup ──────────────────────────────────────────────────────────────────

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Start from a clean state.
		delete_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL );
		delete_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_SECRET );
		delete_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_ENABLED );

		// Re-register hooks for each test.
		WP_MCP_AI_Pro_Chat_Continuation_Notifier::init();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL );
		delete_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_SECRET );
		delete_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_ENABLED );
		remove_all_filters( 'wp_mcp_ai_pro_continuation_notify_enabled' );
		remove_all_filters( 'wp_mcp_ai_pro_continuation_notify_payload' );
		remove_all_filters( 'wp_mcp_ai_pro_continuation_notify_args' );
		parent::tearDown();
	}

	// ── Tests ──────────────────────────────────────────────────────────────────

	/**
	 * Test dispatched hook is registered after init.
	 *
	 * @test
	 */
	public function test_dispatched_hook_is_registered_after_init() {
		$this->assertGreaterThan(
			0,
			has_action(
				'wp_mcp_ai_chat_continuation_dispatched',
				array( 'WP_MCP_AI_Pro_Chat_Continuation_Notifier', 'on_continuation_dispatched' )
			)
		);
	}

	/**
	 * Test no request when URL not configured.
	 *
	 * @test
	 */
	public function test_no_request_when_url_not_configured() {
		// No URL in option — no HTTP request must be fired.
		$requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$requests ) {
				$requests[] = $url;
				return new WP_Error( 'blocked', 'Test should not reach here' );
			},
			10,
			3
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'job_no_url',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );
		$this->assertEmpty( $requests );
	}

	/**
	 * Test no request when kill switch returns false.
	 *
	 * @test
	 */
	public function test_no_request_when_kill_switch_returns_false() {
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL, self::$test_url );
		add_filter( 'wp_mcp_ai_pro_continuation_notify_enabled', '__return_false', 10, 2 );

		$requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$requests ) {
				$requests[] = $url;
				return new WP_Error( 'blocked', 'Should not send' );
			},
			10,
			3
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'job_kill_switch',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );
		$this->assertEmpty( $requests );
	}

	/**
	 * Test payload contains required fields.
	 *
	 * @test
	 */
	public function test_payload_contains_required_fields() {
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL, self::$test_url );

		$captured_body = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_body ) {
				$captured_body = json_decode( $args['body'], true );
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			3
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'notify_job_001',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $captured_body );
		$this->assertSame( 'chat.continuation.dispatched', $captured_body['event'] );
		$this->assertSame( 'notify_job_001', $captured_body['job_id'] );
		$this->assertSame( 'sess_notify_abc', $captured_body['session_id'] );
		$this->assertSame( 10, $captured_body['assistant_id'] );
		$this->assertSame( 'completed', $captured_body['terminal_status'] );
		$this->assertArrayHasKey( 'site_url', $captured_body );
	}

	/**
	 * Test HMAC signature header present when secret configured.
	 *
	 * @test
	 */
	public function test_hmac_signature_header_present_when_secret_configured() {
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL, self::$test_url );
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_SECRET, 'super_secret_key' );

		$captured_headers = null;
		$captured_body    = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_headers, &$captured_body ) {
				$captured_headers = $args['headers'];
				$captured_body    = $args['body'];
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			3
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'notify_job_sig',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertArrayHasKey( 'X-WP-MCP-AI-Signature', $captured_headers );
		$expected = 'sha256=' . hash_hmac( 'sha256', $captured_body, 'super_secret_key' );
		$this->assertSame( $expected, $captured_headers['X-WP-MCP-AI-Signature'] );
	}

	/**
	 * Test no signature header when no secret.
	 *
	 * @test
	 */
	public function test_no_signature_header_when_no_secret() {
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL, self::$test_url );

		$captured_headers = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured_headers ) {
				$captured_headers = $args['headers'];
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			3
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'notify_no_secret',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );
		$this->assertArrayNotHasKey( 'X-WP-MCP-AI-Signature', $captured_headers );
	}

	/**
	 * Test notified action fires on success.
	 *
	 * @test
	 */
	public function test_notified_action_fires_on_success() {
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL, self::$test_url );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			}
		);

		$fired = false;
		add_action(
			'wp_mcp_ai_pro_continuation_notified',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'notify_job_success',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );
		$this->assertTrue( $fired );
	}

	/**
	 * Test notify failed action fires on HTTP 500.
	 *
	 * @test
	 */
	public function test_notify_failed_action_fires_on_http_500() {
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL, self::$test_url );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array(),
					'body'     => 'Server Error',
					'response' => array(
						'code'    => 500,
						'message' => 'Internal Server Error',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			}
		);

		$failed = false;
		add_action(
			'wp_mcp_ai_pro_continuation_notify_failed',
			function () use ( &$failed ) {
				$failed = true;
			}
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'notify_job_500',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );
		$this->assertTrue( $failed );
	}

	/**
	 * Test notify failed action fires on WP_Error.
	 *
	 * @test
	 */
	public function test_notify_failed_action_fires_on_wp_error() {
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL, self::$test_url );

		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'cURL timeout' );
			}
		);

		$failed = false;
		add_action(
			'wp_mcp_ai_pro_continuation_notify_failed',
			function () use ( &$failed ) {
				$failed = true;
			}
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'notify_job_curl_err',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );
		$this->assertTrue( $failed );
	}

	/**
	 * Test payload filter can modify payload.
	 *
	 * @test
	 */
	public function test_payload_filter_can_modify_payload() {
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL, self::$test_url );

		add_filter(
			'wp_mcp_ai_pro_continuation_notify_payload',
			function ( $payload ) {
				$payload['custom_field'] = 'added_by_filter';
				return $payload;
			}
		);

		$captured = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$captured ) {
				$captured = json_decode( $args['body'], true );
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => '',
				);
			},
			10,
			3
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'notify_filter_job',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );
		$this->assertSame( 'added_by_filter', $captured['custom_field'] );
	}

	/**
	 * Test empty payload after filter suppresses request.
	 *
	 * @test
	 */
	public function test_empty_payload_after_filter_suppresses_request() {
		update_option( WP_MCP_AI_Pro_Chat_Continuation_Notifier::OPTION_URL, self::$test_url );

		add_filter( 'wp_mcp_ai_pro_continuation_notify_payload', '__return_empty_array' );

		$requests = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$requests ) {
				$requests[] = $url;
				return new WP_Error( 'blocked', 'Should not send' );
			},
			10,
			3
		);

		WP_MCP_AI_Pro_Chat_Continuation_Notifier::on_continuation_dispatched(
			'notify_empty_payload',
			self::$snapshot,
			'completed'
		);

		remove_all_filters( 'pre_http_request' );
		$this->assertEmpty( $requests );
	}
}
