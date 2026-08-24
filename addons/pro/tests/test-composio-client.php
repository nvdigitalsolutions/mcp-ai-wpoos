<?php
/**
 * Tests for the Composio REST API client.
 *
 * @package WP_MCP_AI_Pro
 */

require_once WP_MCP_AI_PRO_PATH . 'includes/composio/class-wp-mcp-ai-composio-client.php';

/**
 * Test the Composio API client.
 */
class Test_Composio_Client extends WP_UnitTestCase {

	/**
	 * Last request URL captured by the pre_http_request mock.
	 *
	 * @var string
	 */
	private $last_url = '';

	/**
	 * Last request args captured by the pre_http_request mock.
	 *
	 * @var array
	 */
	private $last_args = array();

	/**
	 * Number of HTTP requests performed.
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		$this->last_url      = '';
		$this->last_args     = array();
		$this->request_count = 0;

		parent::tearDown();
	}

	/**
	 * Register a mock that returns a canned response.
	 *
	 * @param int   $status HTTP status code.
	 * @param array $body   JSON body array.
	 * @param array $headers Extra headers.
	 * @return void
	 */
	private function mock_response( $status = 200, $body = array(), $headers = array() ) {
		$this->request_count = 0;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $status, $body, $headers ) {
				$this->last_url  = $url;
				$this->last_args = $args;
				++$this->request_count;

				return array(
					'headers'  => array_merge( array( 'content-type' => 'application/json' ), $headers ),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Register a mock that simulates a transport-level failure.
	 *
	 * @param string $code    WP_Error code.
	 * @param string $message WP_Error message.
	 * @return void
	 */
	private function mock_transport_error( $code, $message ) {
		$this->request_count = 0;

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $code, $message ) {
				$this->last_url  = $url;
				$this->last_args = $args;
				++$this->request_count;

				return new WP_Error( $code, $message );
			},
			10,
			3
		);
	}

	/**
	 * Test that a missing API key fails fast without an HTTP request.
	 */
	public function test_missing_api_key_returns_error() {
		$client = new WP_MCP_AI_Composio_Client( '' );

		$result = $client->test_connection();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_missing_api_key', $result->get_error_code() );
		$this->assertSame( 0, $this->request_count );
	}

	/**
	 * Test that requests carry the x-api-key header and build the pinned URL.
	 */
	public function test_request_sends_x_api_key_header() {
		$this->mock_response(
			200,
			array( 'enum' => 'GMAIL_SEND_EMAIL,SLACK_SEND_MESSAGE' )
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test_123' );
		$result = $client->test_connection();

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['connected'] );
		$this->assertSame( 2, $result['tools_count'] );

		$this->assertStringContainsString( 'https://backend.composio.dev/api/v3.1/tools/enum', $this->last_url );
		$this->assertSame( 'ak_test_123', $this->last_args['headers']['x-api-key'] );
		$this->assertSame( 'GET', $this->last_args['method'] );
	}

	/**
	 * Test that HTTP errors are normalised into WP_Error with the upstream message.
	 */
	public function test_http_error_returns_wp_error() {
		$this->mock_response(
			401,
			array( 'message' => 'Invalid API key' )
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_bad' );
		$result = $client->list_connected_accounts();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_http_401', $result->get_error_code() );
		$this->assertStringContainsString( 'Invalid API key', $result->get_error_message() );
	}

	/**
	 * Test that Composio's documented nested error body is surfaced.
	 */
	public function test_http_error_extracts_nested_error_body() {
		$this->mock_response(
			400,
			array(
				'error' => array(
					'message'       => 'No connected account found for this user and toolkit',
					'status'        => 400,
					'request_id'    => 'req_abc123',
					'suggested_fix' => 'Connect the user to the toolkit first',
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->execute_tool( 'GMAIL_SEND_EMAIL', 'ca_1', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_http_400', $result->get_error_code() );
		$this->assertStringContainsString( 'No connected account found', $result->get_error_message() );
		$this->assertStringContainsString( 'Connect the user to the toolkit first', $result->get_error_message() );
	}

	/**
	 * Test that a bodyless 401 still gets an actionable dashboard hint.
	 */
	public function test_http_401_without_body_appends_dashboard_hint() {
		$this->mock_response( 401, array() );

		$client = new WP_MCP_AI_Composio_Client( 'ak_bad' );
		$result = $client->test_connection();

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'HTTP 401', $result->get_error_message() );
		$this->assertStringContainsString( 'Settings → API Keys', $result->get_error_message() );
	}

	/**
	 * Test that transport failures carry a hosting-egress hint.
	 */
	public function test_transport_failure_appends_egress_hint() {
		$this->mock_transport_error(
			'http_request_failed',
			'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->test_connection();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_request_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'cURL error 28', $result->get_error_message() );
		$this->assertStringContainsString( 'backend.composio.dev', $result->get_error_message() );
		$this->assertStringContainsString( 'outbound HTTPS', $result->get_error_message() );
	}

	/**
	 * Test that the API key is trimmed before being sent.
	 */
	public function test_api_key_is_trimmed() {
		$this->mock_response(
			200,
			array( 'enum' => 'GMAIL_SEND_EMAIL' )
		);

		$client = new WP_MCP_AI_Composio_Client( "  ak_trimmed_123\n" );
		$result = $client->test_connection();

		$this->assertNotWPError( $result );
		$this->assertSame( 'ak_trimmed_123', $this->last_args['headers']['x-api-key'] );
	}

	/**
	 * Test that a 429 response sets a cooldown and returns an error.
	 */
	public function test_rate_limit_sets_cooldown() {
		$this->mock_response(
			429,
			array( 'message' => 'Too many requests' ),
			array( 'retry-after' => '45' )
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_test' );
		$result = $client->list_tools();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_rate_limited', $result->get_error_code() );

		// A second call must be blocked by the cooldown without another request.
		$before = $this->request_count;
		$again  = $client->list_tools();
		$this->assertWPError( $again );
		$this->assertSame( 'wp_mcp_ai_composio_rate_limited', $again->get_error_code() );
		$this->assertSame( $before, $this->request_count );
	}

	/**
	 * Test that GET responses are cached for the configured TTL.
	 */
	public function test_get_responses_are_cached() {
		$this->mock_response(
			200,
			array(
				array(
					'tool_slug' => 'GMAIL_SEND_EMAIL',
					'toolkit'   => 'gmail',
				),
			)
		);

		$client = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_cache' );
		$first  = $client->list_tools();
		$second = $client->list_tools();

		$this->assertNotWPError( $first );
		$this->assertSame( $first, $second );
		$this->assertSame( 1, $this->request_count );
	}

	/**
	 * Test webhook signature verification (hex + base64 encodings).
	 */
	public function test_verify_webhook_signature() {
		$body   = '{"event":"composio.trigger.message"}';
		$secret = 'whsec_test_secret';

		$hex = hash_hmac( 'sha256', $body, $secret, false );
		$b64 = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture for signature comparison.

		$this->assertTrue( WP_MCP_AI_Composio_Client::verify_webhook_signature( $body, $hex, $secret ) );
		$this->assertTrue( WP_MCP_AI_Composio_Client::verify_webhook_signature( $body, $b64, $secret ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::verify_webhook_signature( $body . 'x', $hex, $secret ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::verify_webhook_signature( $body, $hex, 'other_secret' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::verify_webhook_signature( '', $hex, $secret ) );
	}

	/**
	 * Test that tool slugs are validated.
	 */
	public function test_tool_slug_validation() {
		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );

		$result = $client->get_tool_schema( 'not-a-valid-slug' );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_invalid_tool_slug', $result->get_error_code() );

		$result = $client->get_tool_schema( '' );
		$this->assertWPError( $result );

		$result = $client->execute_tool( 'GMAIL_SEND_EMAIL', '', array() );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_composio_missing_account', $result->get_error_code() );
	}

	/**
	 * Test that POST requests encode the JSON body.
	 */
	public function test_post_request_encodes_body() {
		$this->mock_response( 200, array( 'redirect_url' => 'https://auth.composio.dev/xyz' ) );

		$client = new WP_MCP_AI_Composio_Client( 'ak_test' );
		$result = $client->create_connect_link( 'gmail', 'wp-1', 'https://example.test/callback' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'https://auth.composio.dev/xyz', $result['redirect_url'] );

		$this->assertSame( 'POST', $this->last_args['method'] );
		$this->assertSame( 'application/json', $this->last_args['headers']['Content-Type'] );

		$sent = json_decode( $this->last_args['body'], true );
		$this->assertSame( 'gmail', $sent['toolkit'] );
		$this->assertSame( 'wp-1', $sent['user_id'] );
	}

	/**
	 * Test that listing connected accounts caches a count for admin badges.
	 */
	public function test_connected_account_count_is_cached() {
		$this->mock_response(
			200,
			array(
				array(
					'id'      => 'ca_1',
					'toolkit' => 'gmail',
					'status'  => 'active',
				),
				array(
					'id'      => 'ca_2',
					'toolkit' => 'slack',
					'status'  => 'active',
				),
			)
		);

		$client   = new WP_MCP_AI_Composio_Client( 'ak_test', '', 'conn_count' );
		$accounts = $client->list_connected_accounts();

		$this->assertNotWPError( $accounts );
		$this->assertSame( 2, WP_MCP_AI_Composio_Client::get_cached_account_count( 'conn_count' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::get_cached_account_count( 'conn_other' ) );
		$this->assertFalse( WP_MCP_AI_Composio_Client::get_cached_account_count( '' ) );
	}
}
