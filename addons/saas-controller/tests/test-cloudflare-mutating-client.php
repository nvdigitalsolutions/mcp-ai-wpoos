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
 * @covers NVOOS_SaaS_Controller_Cloudflare_Mutating_Client
 */
class Test_NVOOS_SaaS_Controller_Cloudflare_Mutating_Client extends WP_UnitTestCase {

	private $captured = array();
	private $canned   = array();

	public function setUp(): void {
		parent::setUp();
		$this->captured = array();
		$this->canned   = array();
		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		parent::tearDown();
	}

	public function intercept( $preempt, $args, $url ) {
		$this->captured[] = array( 'url' => $url, 'args' => $args );
		foreach ( $this->canned as $needle => $response ) {
			if ( false !== strpos( $url, $needle ) ) {
				return $response;
			}
		}
		return new WP_Error( 'no_canned', 'No canned response for ' . $url );
	}

	private function ok( $result, $status = 200 ) {
		return array(
			'response' => array( 'code' => $status, 'message' => 'OK' ),
			'body'     => wp_json_encode( array( 'success' => true, 'result' => $result ) ),
			'headers'  => array(),
		);
	}

	private function err( $status = 400, $cf_code = 7003, $msg = 'Could not route to /d1' ) {
		return array(
			'response' => array( 'code' => $status, 'message' => 'Bad' ),
			'body'     => wp_json_encode( array(
				'success' => false,
				'errors'  => array( array( 'code' => $cf_code, 'message' => $msg ) ),
			) ),
			'headers'  => array(),
		);
	}

	public function test_create_d1_database_success_records_audit_entry() {
		$this->canned['/d1/database'] = $this->ok( array(
			'uuid' => 'cccc-1111',
			'name' => 'mcp-oos',
		) );

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

	public function test_create_d1_rejects_empty_name_without_http_call() {
		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->create_d1_database( '' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_name', $result->get_error_code() );
		$this->assertCount( 0, $this->captured, 'No HTTP request should be issued for empty input.' );
	}

	public function test_create_kv_namespace_success() {
		$this->canned['/storage/kv/namespaces'] = $this->ok( array(
			'id'    => 'ns_42',
			'title' => 'cache',
		) );

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->create_kv_namespace( 'cache' );

		$this->assertSame( 'ns_42', $result['id'] );
		$body = json_decode( $this->captured[0]['args']['body'], true );
		$this->assertSame( 'cache', $body['title'] );
	}

	public function test_create_ai_gateway_success() {
		$this->canned['/ai-gateway/gateways'] = $this->ok( array(
			'id'   => 'gw_1',
			'slug' => 'mcp-router',
		) );

		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->create_ai_gateway( 'mcp-router' );

		$this->assertSame( 'mcp-router', $result['slug'] );
		$body = json_decode( $this->captured[0]['args']['body'], true );
		$this->assertSame( 'mcp-router', $body['id'] );
	}

	public function test_upload_worker_script_success_persists_etag_and_audit() {
		$this->canned['/workers/scripts/mcp-oos-worker'] = array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => wp_json_encode( array(
				'success' => true,
				'result'  => array( 'id' => 'mcp-oos-worker', 'modified_on' => '2026-05-05T00:00:00Z' ),
			) ),
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

	public function test_upload_worker_script_rejects_empty_body_without_http_call() {
		$client = new NVOOS_SaaS_Controller_Cloudflare_Mutating_Client( 'acct', 'tok' );
		$result = $client->upload_worker_script( 'mcp-oos-worker', '', array( 'main_module' => 'index.js' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'empty_script', $result->get_error_code() );
		$this->assertCount( 0, $this->captured );
	}

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

	public function test_from_credential_store_missing_creds() {
		// Fresh credential store has no values.
		$result = NVOOS_SaaS_Controller_Cloudflare_Mutating_Client::from_credential_store();
		$this->assertWPError( $result );
		$this->assertSame( 'missing_credentials', $result->get_error_code() );
	}
}
