<?php
/**
 * Tests for NVOOS_SaaS_Controller_Cloudflare_Mutating_Client.
 *
 * Uses `pre_http_request` to stub all outbound HTTP traffic, mirroring the
 * pattern used by the read-only client tests.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Tests for Cloudflare mutating client operations.
 *
 * @covers NVOOS_SaaS_Controller_Cloudflare_Mutating_Client
 */
class Test_NVOOS_SaaS_Controller_Cloudflare_Mutating_Client extends WP_UnitTestCase {

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
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
	}

	/**
	 * Tear down test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
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
	private function err( $status = 400, $cf_code = 7003, $msg = 'Could not route to /d1' ) {
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
	 * Test that create_d1_database success records an audit entry.
	 *
	 * @return void
	 */
	public function test_create_d1_database_success_records_audit_entry() {
		$this->canned['/d1/database'] = $this->ok(
			array(
				'uuid' => 'cccc-1111',
				'name' => 'mcp-oos',
			)
		);

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->create_d1_database( 'mcp-oos' );

		$this->assertIsArray( $result );
		$this->assertSame( 'cccc-1111', $result['uuid'] );
		$this->assertSame( 'POST', $this->captured[0]['args']['method'] );
		$this->assertSame( 'Bearer tok', $this->captured[0]['args']['headers']['Authorization'] );

		$body = json_decode( $this->captured[0]['args']['body'], true );
		$this->assertSame( 'mcp-oos', $body['name'] );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'cloudflare', $entries[0]['channel'] );
		$this->assertSame( 'create_d1_database', $entries[0]['action'] );
		$this->assertSame( 'mcp-oos', $entries[0]['target'] );
		$this->assertSame( 'ok', $entries[0]['status'] );
	}

	/**
	 * Test that create_d1_database error records an error audit entry.
	 *
	 * @return void
	 */
	public function test_create_d1_database_error_records_error_audit_entry() {
		$this->canned['/d1/database'] = $this->err( 401, 9109, 'Invalid access token' );

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->create_d1_database( 'mcp-oos' );

		$this->assertWPError( $result );
		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'error', $entries[0]['status'] );
		$this->assertStringContainsString( 'Invalid access token', $entries[0]['message'] );
	}

	/**
	 * Test that create_d1 rejects empty name without HTTP call.
	 *
	 * @return void
	 */
	public function test_create_d1_rejects_empty_name_without_http_call() {
		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->create_d1_database( '' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_name', $result->get_error_code() );
		$this->assertCount( 0, $this->captured, 'No HTTP request should be issued for empty input.' );
	}

	/**
	 * Test that create_kv_namespace succeeds.
	 *
	 * @return void
	 */
	public function test_create_kv_namespace_success() {
		$this->canned['/storage/kv/namespaces'] = $this->ok(
			array(
				'id'    => 'ns_42',
				'title' => 'cache',
			)
		);

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->create_kv_namespace( 'cache' );

		$this->assertSame( 'ns_42', $result['id'] );
		$body = json_decode( $this->captured[0]['args']['body'], true );
		$this->assertSame( 'cache', $body['title'] );
	}

	/**
	 * Test that create_ai_gateway succeeds.
	 *
	 * @return void
	 */
	public function test_create_ai_gateway_success() {
		$this->canned['/ai-gateway/gateways'] = $this->ok(
			array(
				'id'   => 'gw_1',
				'slug' => 'mcp-router',
			)
		);

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->create_ai_gateway( 'mcp-router' );

		$this->assertSame( 'mcp-router', $result['slug'] );
		$body = json_decode( $this->captured[0]['args']['body'], true );
		$this->assertSame( 'mcp-router', $body['id'] );
	}

	/**
	 * Test that upload_worker_script success persists etag and audits.
	 *
	 * @return void
	 */
	public function test_upload_worker_script_success_persists_etag_and_audit() {
		$this->canned['/workers/scripts/mcp-oos-worker'] = array(
			'response' => array(
				'code' => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'success' => true,
					'result'  => array(
						'id' => 'mcp-oos-worker',
						'modified_on' => '2026-05-05T00:00:00Z',
					),
				)
			),
			'headers'  => array( 'etag' => '"abc123def456"' ),
		);

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->upload_worker_script(
			'mcp-oos-worker',
			"export default { fetch() { return new Response('ok'); } };\n",
			array(
				'main_module'        => 'index.js',
				'compatibility_date' => '2024-12-30',
				'bindings'           => array(),
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'mcp-oos-worker', $result['id'] );
		$this->assertSame( 'abc123def456', $result['etag'], 'etag header should be surfaced and unquoted.' );

		// Multipart preconditions.
		$args = $this->captured[0]['args'];
		$this->assertSame( 'PUT', $args['method'] );
		$this->assertSame( 'Bearer tok', $args['headers']['Authorization'] );
		$this->assertStringStartsWith( 'multipart/form-data; boundary="', $args['headers']['Content-Type'] );
		$this->assertStringContainsString( 'Content-Disposition: form-data; name="metadata"', $args['body'] );
		$this->assertStringContainsString( 'Content-Disposition: form-data; name="index.js"', $args['body'] );
		$this->assertStringContainsString( '"main_module":"index.js"', $args['body'] );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'cloudflare', $entries[0]['channel'] );
		$this->assertSame( 'upload_worker_script', $entries[0]['action'] );
		$this->assertSame( 'mcp-oos-worker', $entries[0]['target'] );
		$this->assertSame( 'ok', $entries[0]['status'] );
	}

	/**
	 * Test that upload_worker_script rejects empty body without HTTP call.
	 *
	 * @return void
	 */
	public function test_upload_worker_script_rejects_empty_body_without_http_call() {
		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->upload_worker_script( 'mcp-oos-worker', '', array( 'main_module' => 'index.js' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'empty_script', $result->get_error_code() );
		$this->assertCount( 0, $this->captured );
	}

	/**
	 * Test that upload_worker_script records error on 4xx.
	 *
	 * @return void
	 */
	public function test_upload_worker_script_records_error_on_4xx() {
		$this->canned['/workers/scripts/mcp-oos-worker'] = $this->err( 403, 10000, 'Forbidden' );

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->upload_worker_script(
			'mcp-oos-worker',
			"export default {};\n",
			array( 'main_module' => 'index.js' )
		);

		$this->assertWPError( $result );
		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'error', $entries[0]['status'] );
		$this->assertSame( 'upload_worker_script', $entries[0]['action'] );
	}

	/**
	 * Test that from_credential_store returns WP_Error when creds missing.
	 *
	 * @return void
	 */
	public function test_from_credential_store_missing_creds() {
		// Fresh credential store has no values.
		$result = NVOOS_SaaS_Controller_Cloudflare_Mutating_Client::from_credential_store();
		$this->assertWPError( $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
	}

	/**
	 * Test that delete_d1_database records audit entry on success.
	 *
	 * @return void
	 */
	public function test_delete_d1_database_records_audit_entry_on_success() {
		$this->canned['/d1/database/cccc-1111'] = $this->ok( null );

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->delete_d1_database( 'cccc-1111', 'mcp-oos' );

		$this->assertIsArray( $result );
		$this->assertSame( 'cccc-1111', $result['uuid'] );
		$this->assertSame( 'DELETE', $this->captured[0]['args']['method'] );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertNotEmpty( $entries );
		$this->assertSame( 'cloudflare', $entries[0]['channel'] );
		$this->assertSame( 'delete_d1_database', $entries[0]['action'] );
		$this->assertSame( 'mcp-oos', $entries[0]['target'] );
		$this->assertSame( 'ok', $entries[0]['status'] );
	}

	/**
	 * Test that delete_d1_database rejects empty uuid.
	 *
	 * @return void
	 */
	public function test_delete_d1_database_rejects_empty_uuid() {
		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->delete_d1_database( '', 'mcp-oos' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_uuid', $result->get_error_code() );
		$this->assertEmpty( $this->captured );
	}

	/**
	 * Test that delete_d1_database records error audit on 4xx.
	 *
	 * @return void
	 */
	public function test_delete_d1_database_records_error_audit_on_4xx() {
		$this->canned['/d1/database/cccc-1111'] = $this->err( 404, 7404, 'D1 not found' );
		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->delete_d1_database( 'cccc-1111', 'mcp-oos' );
		$this->assertWPError( $result );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'error', $entries[0]['status'] );
		$this->assertSame( 'delete_d1_database', $entries[0]['action'] );
	}

	/**
	 * Test that delete_kv_namespace records audit entry on success.
	 *
	 * @return void
	 */
	public function test_delete_kv_namespace_records_audit_entry_on_success() {
		$this->canned['/storage/kv/namespaces/ns-id'] = $this->ok( null );

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->delete_kv_namespace( 'ns-id', 'cache' );

		$this->assertSame( 'ns-id', $result['id'] );
		$this->assertSame( 'DELETE', $this->captured[0]['args']['method'] );
		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'delete_kv_namespace', $entries[0]['action'] );
		$this->assertSame( 'cache', $entries[0]['target'] );
	}

	/**
	 * Test that delete_kv_namespace rejects empty id.
	 *
	 * @return void
	 */
	public function test_delete_kv_namespace_rejects_empty_id() {
		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->delete_kv_namespace( '' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_namespace_id', $result->get_error_code() );
	}

	/**
	 * Test that delete_ai_gateway records audit entry on success.
	 *
	 * @return void
	 */
	public function test_delete_ai_gateway_records_audit_entry_on_success() {
		$this->canned['/ai-gateway/gateways/mcp-router'] = $this->ok( null );

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->delete_ai_gateway( 'mcp-router' );

		$this->assertSame( 'mcp-router', $result['slug'] );
		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'delete_ai_gateway', $entries[0]['action'] );
		$this->assertSame( 'mcp-router', $entries[0]['target'] );
	}

	/**
	 * Test that delete_ai_gateway rejects empty slug.
	 *
	 * @return void
	 */
	public function test_delete_ai_gateway_rejects_empty_slug() {
		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->delete_ai_gateway( '' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_slug', $result->get_error_code() );
	}
}
