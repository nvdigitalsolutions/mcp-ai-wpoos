<?php
/**
 * Tests for NVOOS_SaaS_Controller_Cloudflare_Client.
 *
 * Uses `pre_http_request` to stub all outbound HTTP traffic.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Tests for Cloudflare read-only client operations.
 *
 * @covers NVOOS_SaaS_Controller_Cloudflare_Client
 */
class Test_NVOOS_SaaS_Controller_Cloudflare_Client extends WP_UnitTestCase {

	/**
	 * Captured HTTP requests.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Canned HTTP responses keyed by URL needle.
	 *
	 * @var array
	 */
	private $canned   = array();

	/**
	 * Set up test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->captured = array();
		$this->canned   = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Tear down test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		parent::tearDown();
	}

	/**
	 * Intercept HTTP requests and return canned responses.
	 *
	 * @param mixed  $preempt Preempt filter value.
	 * @param array  $args    Request arguments.
	 * @param string $url     Request URL.
	 * @return mixed Canned response or WP_Error.
	 */
	public function intercept( $preempt, $args, $url ) {
		$this->captured[] = array(
			'url' => $url,
			'args' => $args,
		);
		foreach ( $this->canned as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return $response;
			}
		}
		return new WP_Error( 'no_canned', 'No canned response for ' . $url );
	}

	/**
	 * Build a successful HTTP response.
	 *
	 * @param mixed $result The result payload.
	 * @param int   $status HTTP status code.
	 * @return array HTTP response array.
	 */
	private function ok( $result, $status = 200 ) {
		return array(
			'response' => array(
				'code' => $status,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'success' => true,
					'result' => $result,
				)
			),
			'headers'  => array(),
		);
	}

	/**
	 * Build an error HTTP response.
	 *
	 * @param int    $status  HTTP status code.
	 * @param int    $cf_code Cloudflare error code.
	 * @param string $msg     Error message.
	 * @return array HTTP response array.
	 */
	private function err( $status, $cf_code = 9109, $msg = 'Invalid access token' ) {
		return array(
			'response' => array(
				'code' => $status,
				'message' => 'Bad',
			),
			'body'     => wp_json_encode(
				array(
					'success' => false,
					'errors'  => array(
						array(
							'code' => $cf_code,
							'message' => $msg,
						),
					),
				)
			),
			'headers'  => array(),
		);
	}

	/**
	 * Test that list_d1_databases returns successful results.
	 *
	 * @return void
	 */
	public function test_list_d1_databases_success() {
		$this->canned['/d1/database'] = $this->ok(
			array(
				array(
					'uuid' => 'aaaaaaaa-1111',
					'name' => 'main',
				),
				array(
					'uuid' => 'bbbbbbbb-2222',
					'name' => 'edge',
				),
			)
		);
		$client = new NVOOS_SaaS_Controller_Cloudflare_Client( 'acct', 'tok' );
		$result = $client->list_d1_databases();
		$this->assertCount( 2, $result );
		$this->assertSame( 'main', $result[0]['name'] );
		$this->assertSame( 'Bearer tok', $this->captured[0]['args']['headers']['Authorization'] );
	}

	/**
	 * Test that list_d1_databases 4xx returns WP_Error.
	 *
	 * @return void
	 */
	public function test_list_d1_4xx_returns_wp_error() {
		$this->canned['/d1/database'] = $this->err( 401 );
		$client = new NVOOS_SaaS_Controller_Cloudflare_Client( 'acct', 'tok' );
		$result = $client->list_d1_databases();
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Invalid access token', $result->get_error_message() );
	}

	/**
	 * Test that list_kv_namespaces returns successful results.
	 *
	 * @return void
	 */
	public function test_list_kv_namespaces_success() {
		$this->canned['/storage/kv/namespaces'] = $this->ok(
			array(
				array(
					'id' => 'ns_1',
					'title' => 'cache',
				),
			)
		);
		$client = new NVOOS_SaaS_Controller_Cloudflare_Client( 'acct', 'tok' );
		$result = $client->list_kv_namespaces();
		$this->assertCount( 1, $result );
		$this->assertSame( 'cache', $result[0]['title'] );
	}

	/**
	 * Test that list_workers returns successful results.
	 *
	 * @return void
	 */
	public function test_list_workers_success() {
		$this->canned['/workers/scripts'] = $this->ok(
			array(
				array(
					'id' => 'mcp-oos-worker',
					'modified_on' => '2026-01-01T00:00:00Z',
				),
			)
		);
		$client = new NVOOS_SaaS_Controller_Cloudflare_Client( 'acct', 'tok' );
		$result = $client->list_workers();
		$this->assertSame( 'mcp-oos-worker', $result[0]['id'] );
	}

	/**
	 * Test that list_ai_gateways returns successful results.
	 *
	 * @return void
	 */
	public function test_list_ai_gateways_success() {
		$this->canned['/ai-gateway/gateways'] = $this->ok(
			array(
				array(
					'id' => 'gw_1',
					'slug' => 'mcp-router',
				),
			)
		);
		$client = new NVOOS_SaaS_Controller_Cloudflare_Client( 'acct', 'tok' );
		$result = $client->list_ai_gateways();
		$this->assertSame( 'mcp-router', $result[0]['slug'] );
	}

	/**
	 * Test that an unsuccessful envelope returns WP_Error.
	 *
	 * @return void
	 */
	public function test_unsuccessful_envelope_returns_wp_error() {
		$this->canned['/d1/database'] = array(
			'response' => array(
				'code' => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'success' => false,
					'errors' => array( array( 'message' => 'Forbidden zone' ) ),
				)
			),
			'headers'  => array(),
		);
		$client = new NVOOS_SaaS_Controller_Cloudflare_Client( 'acct', 'tok' );
		$result = $client->list_d1_databases();
		$this->assertWPError( $result );
	}

	/**
	 * Test that from_credential_store returns WP_Error when creds missing.
	 *
	 * @return void
	 */
	public function test_from_credential_store_missing_creds() {
		// Credential store empty by default in fresh test.
		$result = NVOOS_SaaS_Controller_Cloudflare_Client::from_credential_store();
		$this->assertWPError( $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
	}
}
