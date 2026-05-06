<?php
/**
 * Tests for NVOOS_SaaS_Controller_Smoke_Tester.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Stub plan generator: returns whatever was injected into the constructor.
 */
class NVOOS_SaaS_Stub_Plan_Generator extends NVOOS_SaaS_Controller_Plan_Generator {
	public $payload;

	public function __construct( $payload = null ) {
		// Intentionally do not call parent::__construct() — we don't need
		// the live Cloudflare client for stubbing.
		$this->payload = $payload;
	}

	public function generate( array $desired ) {
		if ( null !== $this->payload ) {
			return $this->payload;
		}
		return array(
			'creates' => array(),
			'updates' => array(),
			'noops'   => array(),
			'orphans' => array(),
			'errors'  => array(),
			'summary' => array(),
		);
	}
}

/**
 * Stub Cloudflare client whose `list_workers` response is configurable.
 */
class NVOOS_SaaS_Stub_Cloudflare_Client_For_Smoke extends NVOOS_SaaS_Controller_Cloudflare_Client {
	public $workers_payload;

	public function __construct( $workers_payload ) {
		// Skip parent constructor — we don't need credentials.
		$this->workers_payload = $workers_payload;
	}

	public function list_workers() {
		return $this->workers_payload;
	}
}

/**
 * @covers NVOOS_SaaS_Controller_Smoke_Tester
 */
class Test_NVOOS_SaaS_Controller_Smoke_Tester extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Smoke_Tester::LAST_RESULT_OPTION );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
		// Set Cloudflare credentials so check 1 passes by default.
		NVOOS_SaaS_Controller_Credential_Store::instance()->set(
			array(
				'cloudflare_account_id' => 'abcdef0123456789abcdef0123456789',
				'cloudflare_api_token'  => 'token-' . wp_generate_password( 16, false ),
			)
		);
	}

	public function tearDown(): void {
		delete_option( NVOOS_SaaS_Controller_Smoke_Tester::LAST_RESULT_OPTION );
		delete_option( NVOOS_SaaS_Controller_Audit_Log::OPTION );
		NVOOS_SaaS_Controller_Credential_Store::instance()->clear_all();
		NVOOS_SaaS_Controller_Audit_Log::reset_for_tests();
		parent::tearDown();
	}

	public function test_run_returns_full_result_shape() {
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		$tester->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Smoke( array( array( 'id' => 'w1' ) ) ) );
		$tester->set_plan_generator( new NVOOS_SaaS_Stub_Plan_Generator() );

		$result = $tester->run();

		$this->assertArrayHasKey( 'ok', $result );
		$this->assertArrayHasKey( 'checks', $result );
		$this->assertArrayHasKey( 'duration_ms', $result );
		$this->assertArrayHasKey( 'ts', $result );
		$this->assertCount( 4, $result['checks'] );
		$names = wp_list_pluck( $result['checks'], 'name' );
		$this->assertSame(
			array( 'cloudflare_credentials', 'cloudflare_workers', 'plan_dry_run', 'base_plugin_alive' ),
			$names
		);
	}

	public function test_all_checks_pass_when_cloudflare_and_plan_succeed() {
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		$tester->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Smoke( array( array( 'id' => 'w1' ) ) ) );
		$tester->set_plan_generator( new NVOOS_SaaS_Stub_Plan_Generator() );

		$result = $tester->run();
		$this->assertTrue( $result['ok'] );
		foreach ( $result['checks'] as $check ) {
			$this->assertTrue( $check['ok'], 'check ' . $check['name'] . ' should pass' );
		}
	}

	public function test_missing_credentials_fails_credential_check() {
		NVOOS_SaaS_Controller_Credential_Store::instance()->clear_all();
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		$tester->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Smoke( array() ) );
		$tester->set_plan_generator( new NVOOS_SaaS_Stub_Plan_Generator() );

		$result = $tester->run();
		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'cloudflare_credentials', $result['checks'][0]['name'] );
		$this->assertFalse( $result['checks'][0]['ok'] );
	}

	public function test_cloudflare_error_fails_workers_check_but_others_continue() {
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		$tester->set_cloudflare_client(
			new NVOOS_SaaS_Stub_Cloudflare_Client_For_Smoke(
				new WP_Error( 'cloudflare_http_403', 'forbidden' )
			)
		);
		$tester->set_plan_generator( new NVOOS_SaaS_Stub_Plan_Generator() );

		$result = $tester->run();
		$this->assertFalse( $result['ok'] );
		$by_name = array();
		foreach ( $result['checks'] as $c ) {
			$by_name[ $c['name'] ] = $c;
		}
		$this->assertFalse( $by_name['cloudflare_workers']['ok'] );
		$this->assertTrue( $by_name['plan_dry_run']['ok'] );
		$this->assertTrue( $by_name['base_plugin_alive']['ok'] );
	}

	public function test_plan_with_errors_fails_plan_check() {
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		$tester->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Smoke( array() ) );
		$tester->set_plan_generator(
			new NVOOS_SaaS_Stub_Plan_Generator(
				array(
					'creates' => array(),
					'updates' => array(),
					'noops'   => array(),
					'orphans' => array(),
					'errors'  => array( array( 'kind' => 'd1', 'message' => 'cloudflare 500' ) ),
					'summary' => array(),
				)
			)
		);

		$result  = $tester->run();
		$by_name = array();
		foreach ( $result['checks'] as $c ) {
			$by_name[ $c['name'] ] = $c;
		}
		$this->assertFalse( $by_name['plan_dry_run']['ok'] );
		$this->assertStringContainsString( 'cloudflare 500', $by_name['plan_dry_run']['message'] );
	}

	public function test_run_caches_last_result() {
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		$tester->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Smoke( array() ) );
		$tester->set_plan_generator( new NVOOS_SaaS_Stub_Plan_Generator() );
		$result = $tester->run();

		$cached = ( new NVOOS_SaaS_Controller_Smoke_Tester() )->get_last_result();
		$this->assertNotNull( $cached );
		$this->assertSame( $result['ok'], $cached['ok'] );
	}

	public function test_get_last_result_is_null_when_never_run() {
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		$this->assertNull( $tester->get_last_result() );
	}

	public function test_each_check_is_recorded_in_audit_log() {
		$tester = new NVOOS_SaaS_Controller_Smoke_Tester();
		$tester->set_cloudflare_client( new NVOOS_SaaS_Stub_Cloudflare_Client_For_Smoke( array() ) );
		$tester->set_plan_generator( new NVOOS_SaaS_Stub_Plan_Generator() );
		$tester->run();

		$entries = NVOOS_SaaS_Controller_Audit_Log::instance()->get_recent( 50 );
		$actions = wp_list_pluck( $entries, 'action' );
		$this->assertContains( 'smoke_test:cloudflare_credentials', $actions );
		$this->assertContains( 'smoke_test:cloudflare_workers', $actions );
		$this->assertContains( 'smoke_test:plan_dry_run', $actions );
		$this->assertContains( 'smoke_test:base_plugin_alive', $actions );
	}
}
