<?php

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-remote-tester.php';

/**
 * Tests for the remote MCP API connectivity tester.
 */
class WP_MCP_AI_Remote_Tester_Test extends WP_UnitTestCase {
    /**
     * The tester instance under test.
     *
     * @var WP_MCP_AI_Remote_Tester
     */
    protected $tester;

    /**
     * Set up the tester for each test.
     */
    public function setUp(): void {
        parent::setUp();

        $this->tester = new WP_MCP_AI_Remote_Tester();
    }

    /**
     * Ensure invalid base URLs are rejected.
     */
    public function test_probe_rejects_invalid_base_url() {
        $result = $this->tester->probe( '' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wp_mcp_ai_remote_invalid_base_url', $result->get_error_code() );
    }

    /**
     * The tester should surface assistant counts and token scope for successful requests.
     */
    public function test_probe_successful_request_returns_assistant_count_and_scope() {
        $body = wp_json_encode(
            array(
                'assistants' => array(
                    array( 'id' => 1, 'title' => 'Alpha' ),
                    array( 'id' => 2, 'title' => 'Beta' ),
                ),
                'token_scope' => array(
                    'type'        => 'local_token',
                    'assistant_id'=> 1,
                ),
            )
        );

        $captured_url = null;

        $callback = static function ( $preempt, $args, $url ) use ( $body, &$captured_url ) {
            $captured_url = $url;

            return array(
                'body'     => $body,
                'response' => array(
                    'code'    => 200,
                    'message' => 'OK',
                ),
                'headers'  => array(),
                'cookies'  => array(),
            );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        try {
            $result = $this->tester->probe( 'https://example.com/wp-json/mcp-ai/v1' );
        } finally {
            remove_filter( 'pre_http_request', $callback, 10 );
        }

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertSame( 'https://example.com/wp-json/mcp-ai/v1', $result['base_url'] );
        $this->assertSame( 'https://example.com/wp-json/mcp-ai/v1/assistants', $captured_url );
        $this->assertNotEmpty( $result['checks'] );

        $check = $result['checks'][0];
        $this->assertSame( 'success', $check['status'] );
        $this->assertSame( 200, $check['http_code'] );
        $this->assertSame( 2, $check['details']['assistant_count'] );
        $this->assertSame( 'local_token', $check['details']['token_scope']['type'] );
    }

    /**
     * HTTP transport failures should be surfaced with the WP_Error message and code.
     */
    public function test_probe_reports_wp_error_responses() {
        $callback = static function ( $preempt, $args, $url ) {
            return new WP_Error( 'http_request_failed', 'Timed out' );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        try {
            $result = $this->tester->probe( 'https://example.com/wp-json/mcp-ai/v1' );
        } finally {
            remove_filter( 'pre_http_request', $callback, 10 );
        }

        $this->assertIsArray( $result );
        $this->assertFalse( $result['success'] );
        $this->assertNotEmpty( $result['checks'] );

        $check = $result['checks'][0];
        $this->assertSame( 'error', $check['status'] );
        $this->assertNull( $check['http_code'] );
        $this->assertSame( 'http_request_failed', $check['details']['error_code'] );
        $this->assertStringContainsString( 'Timed out', $check['message'] );
    }

    /**
     * REST error payloads should be propagated when the response is not successful.
     */
    public function test_probe_includes_rest_error_details_on_failure() {
        $body = wp_json_encode(
            array(
                'code'    => 'wp_mcp_ai_missing_credentials',
                'message' => 'Authentication is required.',
                'data'    => array( 'status' => 401 ),
            )
        );

        $callback = static function ( $preempt, $args, $url ) use ( $body ) {
            return array(
                'body'     => $body,
                'response' => array(
                    'code'    => 403,
                    'message' => 'Forbidden',
                ),
                'headers'  => array(),
                'cookies'  => array(),
            );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        try {
            $result = $this->tester->probe( 'https://example.com/wp-json/mcp-ai/v1' );
        } finally {
            remove_filter( 'pre_http_request', $callback, 10 );
        }

        $this->assertIsArray( $result );
        $this->assertFalse( $result['success'] );

        $check = $result['checks'][0];
        $this->assertSame( 'error', $check['status'] );
        $this->assertSame( 403, $check['http_code'] );
        $this->assertSame( 'wp_mcp_ai_missing_credentials', $check['details']['rest_error_code'] );
        $this->assertSame( 'Authentication is required.', $check['details']['rest_error_message'] );
        $this->assertSame( 401, $check['details']['rest_error_status'] );
    }

    /**
     * Headers, timeout, and assistant hints should be forwarded to the request.
     */
    public function test_probe_applies_custom_headers_and_timeout() {
        $body = wp_json_encode(
            array(
                'assistants' => array(),
            )
        );

        $captured_args = null;
        $captured_url  = null;

        $callback = static function ( $preempt, $args, $url ) use ( $body, &$captured_args, &$captured_url ) {
            $captured_args = $args;
            $captured_url  = $url;

            return array(
                'body'     => $body,
                'response' => array(
                    'code'    => 200,
                    'message' => 'OK',
                ),
                'headers'  => array(),
                'cookies'  => array(),
            );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        try {
            $result = $this->tester->probe(
                'https://example.com/wp-json/mcp-ai/v1',
                array(
                    'timeout'      => 25,
                    'verify_ssl'   => false,
                    'token'        => 'test-token',
                    'guest_token'  => 'guest-123',
                    'nonce'        => 'nonce-456',
                    'assistant_id' => 88,
                    'user_agent'   => 'Custom-UA',
                )
            );
        } finally {
            remove_filter( 'pre_http_request', $callback, 10 );
        }

        $this->assertIsArray( $result );
        $this->assertTrue( $result['success'] );
        $this->assertIsArray( $captured_args );
        $this->assertSame( 25, $captured_args['timeout'] );
        $this->assertFalse( $captured_args['sslverify'] );
        $this->assertSame( 'Custom-UA', $captured_args['user-agent'] );
        $this->assertSame( 'Bearer test-token', $captured_args['headers']['Authorization'] );
        $this->assertSame( 'guest-123', $captured_args['headers']['X-WP-MCP-AI-Guest'] );
        $this->assertSame( 'nonce-456', $captured_args['headers']['X-WP-Nonce'] );
        $this->assertStringContainsString( 'assistant_id=88', $captured_url );
    }
}
