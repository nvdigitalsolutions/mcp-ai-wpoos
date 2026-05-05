<?php
/**
 * Tests for NVOOS_SaaS_Controller_Drift_Detector.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Stub Cloudflare client whose `get_worker_script` response is configurable.
 */
class NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift extends NVOOS_SaaS_Controller_Cloudflare_Client {
	public $script_payload;

	public function __construct( $script_payload ) {
		// Intentionally skip parent constructor — no credentials needed.
		$this->script_payload = $script_payload;
	}

	public function get_worker_script( $name ) {
		return $this->script_payload;
	}
}

/**
 * @covers NVOOS_SaaS_Controller_Drift_Detector
 */
class Test_NVOOS_SaaS_Controller_Drift_Detector extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Drift_Detector::LAST_RESULT_OPTION );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		delete_option( NVOOS_SaaS_Controller_Deployment_Config::OPTION_NAME );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
	}

	public function tearDown(): void {
		delete_option( NVOOS_SaaS_Controller_Drift_Detector::LAST_RESULT_OPTION );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		delete_option( NVOOS_SaaS_Controller_Deployment_Config::OPTION_NAME );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
		parent::tearDown();
	}

	public function test_unknown_when_manifest_has_no_pinned_fingerprint() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_sha256' => null, 'expected_etag' => null ) );

		$result = $detector->check();

		$this->assertSame( 'unknown', $result['status'] );
		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['expected_sha256'] );
		$this->assertNull( $result['expected_etag'] );
		$this->assertNull( $result['actual_sha256'] );
		$this->assertStringContainsString( 'No pinned fingerprint', $result['message'] );
	}

	public function test_synced_when_etag_matches() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array(
			'expected_etag'   => 'abc123',
			'expected_sha256' => null,
			'version'         => '1.0.0',
		) );
		$detector->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift( array(
			'body'        => 'console.log("hello");',
			'etag'        => 'abc123',
			'modified_on' => 'Mon, 01 Jan 2026 00:00:00 GMT',
			'size'        => 21,
		) ) );

		$result = $detector->check();

		$this->assertSame( 'synced', $result['status'] );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'abc123', $result['expected_etag'] );
		$this->assertSame( 'abc123', $result['actual_etag'] );
		$this->assertSame( '1.0.0', $result['manifest_version'] );
	}

	public function test_drift_when_etag_differs() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_etag' => 'abc123', 'expected_sha256' => null ) );
		$detector->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift( array(
			'body' => 'different',
			'etag' => 'xyz789',
			'modified_on' => '',
			'size' => 9,
		) ) );

		$result = $detector->check();

		$this->assertSame( 'drift', $result['status'] );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'abc123', $result['expected_etag'] );
		$this->assertSame( 'xyz789', $result['actual_etag'] );
		$this->assertStringContainsString( 'differs', $result['message'] );
	}

	public function test_falls_back_to_sha256_when_etag_not_pinned() {
		$body            = 'console.log("payload");';
		$expected_sha256 = hash( 'sha256', $body );

		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_etag' => null, 'expected_sha256' => $expected_sha256 ) );
		$detector->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift( array(
			'body' => $body,
			'etag' => 'whatever',
			'modified_on' => '',
			'size' => strlen( $body ),
		) ) );

		$result = $detector->check();

		$this->assertSame( 'synced', $result['status'] );
		$this->assertSame( $expected_sha256, $result['actual_sha256'] );
	}

	public function test_drift_when_sha256_differs() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array(
			'expected_etag'   => null,
			'expected_sha256' => str_repeat( 'a', 64 ),
		) );
		$detector->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift( array(
			'body' => 'different bytes',
			'etag' => '',
			'modified_on' => '',
			'size' => 15,
		) ) );

		$result = $detector->check();

		$this->assertSame( 'drift', $result['status'] );
		$this->assertNotSame( $result['expected_sha256'], $result['actual_sha256'] );
	}

	public function test_etag_takes_precedence_over_sha256_when_both_pinned() {
		// SHA matches the body, but etag does not — etag wins → drift.
		$body = 'matching body';
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array(
			'expected_etag'   => 'abc',
			'expected_sha256' => hash( 'sha256', $body ),
		) );
		$detector->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift( array(
			'body' => $body,
			'etag' => 'xyz',
			'modified_on' => '',
			'size' => strlen( $body ),
		) ) );

		$result = $detector->check();

		$this->assertSame( 'drift', $result['status'] );
	}

	public function test_unknown_when_worker_returns_404() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_etag' => 'abc', 'expected_sha256' => null ) );
		$detector->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift(
			new WP_Error( 'cloudflare_http_404', 'Not Found', array( 'status' => 404 ) )
		) );

		$result = $detector->check();

		$this->assertSame( 'unknown', $result['status'] );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'not deployed', $result['message'] );
	}

	public function test_error_status_when_cloudflare_returns_5xx() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_etag' => 'abc', 'expected_sha256' => null ) );
		$detector->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift(
			new WP_Error( 'cloudflare_http_503', 'Service Unavailable', array( 'status' => 503 ) )
		) );

		$result = $detector->check();

		$this->assertSame( 'error', $result['status'] );
		$this->assertStringContainsString( 'Service Unavailable', $result['message'] );
	}

	public function test_check_persists_last_result_to_option() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_etag' => null, 'expected_sha256' => null ) );

		$detector->check();
		$cached = get_option( NVOOS_SaaS_Controller_Drift_Detector::LAST_RESULT_OPTION );

		$this->assertIsArray( $cached );
		$this->assertSame( 'unknown', $cached['status'] );
		$this->assertSame( $cached, $detector->get_last_result() );
	}

	public function test_check_records_audit_entry() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_etag' => 'abc', 'expected_sha256' => null ) );
		$detector->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift( array(
			'body' => 'x',
			'etag' => 'abc',
			'modified_on' => '',
			'size' => 1,
		) ) );

		$detector->check();

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertNotEmpty( $entries );
		$this->assertSame( 'drift_check', $entries[0]['action'] );
		$this->assertSame( 'internal', $entries[0]['channel'] );
		$this->assertSame( 'ok', $entries[0]['status'] );
	}

	public function test_check_records_error_audit_entry_on_drift() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_etag' => 'abc', 'expected_sha256' => null ) );
		$detector->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Drift( array(
			'body' => 'x', 'etag' => 'xyz', 'modified_on' => '', 'size' => 1,
		) ) );

		$detector->check();

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 10 );
		$this->assertSame( 'drift_check', $entries[0]['action'] );
		$this->assertSame( 'error', $entries[0]['status'] );
	}

	public function test_default_worker_name_used_when_deployment_config_empty() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_etag' => null, 'expected_sha256' => null ) );

		$result = $detector->check();

		$this->assertSame( NVOOS_SaaS_Controller_Drift_Detector::DEFAULT_WORKER_NAME, $result['worker_name'] );
	}

	public function test_worker_name_pulled_from_deployment_config_when_set() {
		NVOOS_SaaS_Controller_Deployment_Config::instance()->set( array( 'worker_name' => 'custom-worker' ) );

		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$detector->set_manifest( array( 'expected_etag' => null, 'expected_sha256' => null ) );

		$result = $detector->check();

		$this->assertSame( 'custom-worker', $result['worker_name'] );
	}

	public function test_get_last_result_returns_null_before_first_run() {
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$this->assertNull( $detector->get_last_result() );
	}

	public function test_loads_manifest_from_disk_when_no_override() {
		// The shipped manifest has both fingerprints null, so the result
		// should be unknown — that confirms the on-disk loader works.
		$detector = new NVOOS_SaaS_Controller_Drift_Detector();
		$result   = $detector->check();
		$this->assertSame( 'unknown', $result['status'] );
	}
}
