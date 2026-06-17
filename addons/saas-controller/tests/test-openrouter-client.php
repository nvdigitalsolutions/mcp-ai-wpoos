<?php
/**
 * Tests for NVOOS_SaaS_Controller_OpenRouter_Client (Phase 6).
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Tests for the OpenRouter client.
 *
 * @covers NVOOS_SaaS_Controller_OpenRouter_Client
 */
class Test_NVOOS_SaaS_Controller_OpenRouter_Client extends WP_UnitTestCase {

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
	 * Build a successful JSON HTTP response.
	 *
	 * @param mixed $payload The response payload.
	 * @return array HTTP response array.
	 */
	private function ok_json( $payload ) {
		return array(
			'response' => array(
				'code' => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode( $payload ),
			'headers'  => array(),
		);
	}

	/**
	 * Build an error JSON HTTP response.
	 *
	 * @param int    $status HTTP status code.
	 * @param string $code   Error code.
	 * @param string $msg    Error message.
	 * @return array HTTP response array.
	 */
	private function err_json( $status, $code, $msg ) {
		return array(
			'response' => array(
				'code' => $status,
				'message' => 'Bad',
			),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => $code,
						'message' => $msg,
					),
				)
			),
			'headers'  => array(),
		);
	}

	/**
	 * Test that list_keys returns a keyed map.
	 *
	 * @return void
	 */
	public function test_list_keys_returns_keyed_map() {
		$this->canned['/api/v1/keys'] = $this->ok_json(
			array(
				'data' => array(
					array(
						'name' => 'production',
						'hash' => 'h1',
						'disabled' => false,
						'limit' => 100,
					),
					array(
						'name' => 'staging',
						'hash' => 'h2',
						'disabled' => false,
					),
				),
			)
		);

		$client = new NVOOS_SaaS_Controller_OpenRouter_Client( 'pk_xxx' );
		$out    = $client->list_keys();

		$this->assertArrayHasKey( 'production', $out );
		$this->assertSame( 'h1', $out['production']['hash'] );
		$this->assertSame( 'Bearer pk_xxx', $this->captured[0]['args']['headers']['Authorization'] );
	}

	/**
	 * Test that create_key returns plaintext and audits.
	 *
	 * @return void
	 */
	public function test_create_key_returns_plaintext_and_audits() {
		$this->canned['/api/v1/keys'] = $this->ok_json(
			array(
				'key'  => 'sk-or-PLAINTEXT-VALUE',
				'data' => array(
					'name' => 'production',
					'hash' => 'h-prod',
				),
			)
		);

		$client = new NVOOS_SaaS_Controller_OpenRouter_Client( 'pk_xxx' );
		$out    = $client->create_key( 'production', 250.0 );

		$this->assertSame( 'sk-or-PLAINTEXT-VALUE', $out['key'] );
		$this->assertSame( 'production', $out['label'] );
		$this->assertSame( 'h-prod', $out['hash'] );

		// JSON body, not form.
		$body = json_decode( $this->captured[0]['args']['body'], true );
		$this->assertSame( 'production', $body['name'] );
		$this->assertSame( 250.0, $body['limit'] );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'openrouter', $entries[0]['channel'] );
		$this->assertSame( 'create_openrouter_key', $entries[0]['action'] );
		$this->assertSame( 'ok', $entries[0]['status'] );
	}

	/**
	 * Test that create_key records an error audit on 4xx.
	 *
	 * @return void
	 */
	public function test_create_key_records_error_audit_on_4xx() {
		$this->canned['/api/v1/keys'] = $this->err_json( 401, 'unauthorized', 'Provisioning key invalid.' );

		$client = new NVOOS_SaaS_Controller_OpenRouter_Client( 'pk_bad' );
		$out    = $client->create_key( 'foo' );

		$this->assertWPError( $out );
		$this->assertSame( 'openrouter_unauthorized', $out->get_error_code() );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'error', $entries[0]['status'] );
	}

	/**
	 * Test that create_key rejects an empty label.
	 *
	 * @return void
	 */
	public function test_create_key_rejects_empty_label() {
		$client = new NVOOS_SaaS_Controller_OpenRouter_Client( 'pk_xxx' );
		$out    = $client->create_key( '' );
		$this->assertWPError( $out );
		$this->assertSame( 'invalid_label', $out->get_error_code() );
		$this->assertCount( 0, $this->captured );
	}

	/**
	 * Test that from_credential_store returns null when provisioning key is unset.
	 *
	 * @return void
	 */
	public function test_from_credential_store_returns_null_when_provisioning_key_unset() {
		NVOOS_SaaS_Controller_Credential_Store::instance()->clear_all();
		$this->assertNull( NVOOS_SaaS_Controller_OpenRouter_Client::from_credential_store() );
	}

	/**
	 * Test that from_credential_store builds client when provisioning key is set.
	 *
	 * @return void
	 */
	public function test_from_credential_store_builds_client_when_provisioning_key_set() {
		NVOOS_SaaS_Controller_Credential_Store::instance()->set( array( 'openrouter_provisioning_key' => 'pk_real' ) );
		$client = NVOOS_SaaS_Controller_OpenRouter_Client::from_credential_store();
		$this->assertInstanceOf( NVOOS_SaaS_Controller_OpenRouter_Client::class, $client );
		NVOOS_SaaS_Controller_Credential_Store::instance()->clear_all();
	}

	/**
	 * Test that delete_key succeeds and records an audit entry.
	 *
	 * @return void
	 */
	public function test_delete_key_succeeds_and_records_audit() {
		$this->canned['/keys/abc123'] = $this->ok_json( array( 'data' => array( 'hash' => 'abc123' ) ) );
		$client = new NVOOS_SaaS_Controller_OpenRouter_Client( 'pk_x' );
		$out    = $client->delete_key( 'abc123', 'tenant-acme' );

		$this->assertIsArray( $out );
		$this->assertSame( 'abc123', $out['hash'] );
		$this->assertSame( 'tenant-acme', $out['label'] );
		$this->assertSame( 'DELETE', $this->captured[0]['args']['method'] );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'openrouter', $entries[0]['channel'] );
		$this->assertSame( 'delete_openrouter_key', $entries[0]['action'] );
		$this->assertSame( 'tenant-acme', $entries[0]['target'] );
		$this->assertSame( 'ok', $entries[0]['status'] );
	}

	/**
	 * Test that delete_key rejects an empty hash.
	 *
	 * @return void
	 */
	public function test_delete_key_rejects_empty_hash() {
		$client = new NVOOS_SaaS_Controller_OpenRouter_Client( 'pk_x' );
		$out    = $client->delete_key( '' );
		$this->assertWPError( $out );
		$this->assertSame( 'invalid_hash', $out->get_error_code() );
	}

	/**
	 * Test that delete_key records an error audit on 4xx.
	 *
	 * @return void
	 */
	public function test_delete_key_records_error_audit_on_4xx() {
		$this->canned['/keys/abc123'] = $this->err_json( 404, 'not_found', 'No such key' );
		$client = new NVOOS_SaaS_Controller_OpenRouter_Client( 'pk_x' );
		$out    = $client->delete_key( 'abc123', 'tenant-acme' );
		$this->assertWPError( $out );

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'error', $entries[0]['status'] );
		$this->assertSame( 'delete_openrouter_key', $entries[0]['action'] );
	}
}
