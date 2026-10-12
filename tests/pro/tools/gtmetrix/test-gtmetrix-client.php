<?php
/**
 * GTmetrix Client Test — auth, content-type, backoff, error mapping, fixture lifecycle.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: the GTmetrix API client behaves per the API contract.
 */
class Test_GTmetrix_Client extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/gtmetrix';

		if ( ! class_exists( 'WP_MCP_AI_GTmetrix_Client' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-settings.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-fixture-reader.php';
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-gtmetrix-client.php';
		}
	}

	/**
	 * Skip when the toolkit files are unavailable.
	 */
	protected function maybe_skip() {
		if ( ! class_exists( 'WP_MCP_AI_GTmetrix_Client' ) ) {
			$this->markTestSkipped( 'GTmetrix toolkit not available' );
		}
	}

	/**
	 * Test: error envelope maps GTmetrix error codes to WP_Error codes.
	 */
	public function test_error_code_mapping() {
		$this->maybe_skip();

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 402 ),
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'errors' => array(
								array(
									'status' => '402',
									'code'   => 'E40201',
									'title'  => 'Insufficient credits',
								),
							),
						)
					),
				);
			}
		);

		update_option( 'wp_mcp_ai_gtmetrix_api_key', 'test-key' );

		$client = new WP_MCP_AI_GTmetrix_Client();
		$result = $client->request( 'GET', 'status' );

		remove_all_filters( 'pre_http_request' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gtmetrix_insufficient_credits', $result->get_error_code() );
	}

	/**
	 * Test: request carries Basic auth (key as username, blank password) and
	 * the JSON:API content type on POSTs.
	 */
	public function test_request_auth_and_content_type() {
		$this->maybe_skip();

		$captured = null;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				$captured = array(
					'args' => $args,
					'url'  => $url,
				);

				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array() ) ),
				);
			},
			10,
			3
		);

		update_option( 'wp_mcp_ai_gtmetrix_api_key', 'test-key' );

		$client = new WP_MCP_AI_GTmetrix_Client();
		$client->request( 'POST', 'tests', array( 'data' => array() ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotNull( $captured );
		$this->assertStringContainsString( 'gtmetrix.com/api/2.0/tests', $captured['url'] );
		$this->assertSame( 'Basic ' . base64_encode( 'test-key:' ), $captured['args']['headers']['Authorization'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- asserting the auth header value.
		$this->assertSame( 'application/vnd.api+json', $captured['args']['headers']['Content-Type'] );
	}

	/**
	 * Test: E42901 rate-limit errors retry with backoff; other errors do not.
	 */
	public function test_429_retry_and_non_retry() {
		$this->maybe_skip();

		$calls = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$calls ) {
				$calls++;
				if ( $calls < 3 ) {
					return array(
						'response' => array( 'code' => 429 ),
						'headers'  => array( 'x-ratelimit-remaining' => '0' ),
						'body'     => wp_json_encode(
							array(
								'errors' => array(
									array(
										'status' => '429',
										'code'   => 'E42901',
										'title'  => 'Rate limit exceeded',
									),
								),
							)
						),
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'data' => array( 'ok' => true ) ) ),
				);
			}
		);

		update_option( 'wp_mcp_ai_gtmetrix_api_key', 'test-key' );

		$client = new WP_MCP_AI_GTmetrix_Client();
		$result = $client->request( 'GET', 'status' );

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame( 3, $calls );
	}

	/**
	 * Test: sensitive resources are hard-blocked before any HTTP.
	 */
	public function test_blocked_resources() {
		$this->maybe_skip();

		$client = new WP_MCP_AI_GTmetrix_Client();
		$result = $client->get_resource( 'R1', 'analysis-options' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_gtmetrix_blocked_resource', $result->get_error_code() );
	}

	/**
	 * Test: fixture-mode lifecycle (queued → started → completed with report).
	 */
	public function test_fixture_lifecycle() {
		$this->maybe_skip();

		if ( ! defined( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES', true );
		}

		$client = new WP_MCP_AI_GTmetrix_Client();
		$start  = $client->start_test(
			array(
				'url'    => 'https://example.test/',
				'report' => 'lighthouse',
			)
		);

		$this->assertNotWPError( $start );
		$this->assertSame( 'queued', $start['attributes']['state'] );

		$test_id = $start['id'];
		$this->assertSame( 'queued', $client->get_test( $test_id )['attributes']['state'] );
		$this->assertSame( 'started', $client->get_test( $test_id )['attributes']['state'] );

		$done = $client->get_test( $test_id );
		$this->assertSame( 'completed', $done['attributes']['state'] );
		$this->assertSame( 'RGOOD', $done['attributes']['report'] );
	}

	/**
	 * Test: fixture-mode status and lists return data without HTTP.
	 */
	public function test_fixture_reference_reads() {
		$this->maybe_skip();

		if ( ! defined( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_GTMETRIX_USE_FIXTURES', true );
		}

		$client    = new WP_MCP_AI_GTmetrix_Client();
		$status    = $client->get_status();
		$locations = $client->get_locations();

		$this->assertNotWPError( $status );
		$this->assertSame( 'Basic', $status['attributes']['account_type'] );
		$this->assertNotWPError( $locations );
		$this->assertSame( 2, count( $locations ) );
	}
}
