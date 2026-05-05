<?php
/**
 * Tests for NVOOS_SaaS_Controller_Plan_Generator.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Stub Cloudflare client returning fixed payloads.
 */
class NVOOS_SaaS_Stub_Cloudflare_Client extends NVOOS_SaaS_Controller_Cloudflare_Client {
	public $d1 = array();
	public $kv = array();
	public $workers = array();
	public $ai_gateways = array();
	public $errors = array();

	public function __construct() { /* no super */ } // phpcs:ignore Generic.Classes.OpeningBraceSameLine

	public function list_d1_databases() {
		return isset( $this->errors['d1'] ) ? $this->errors['d1'] : $this->d1;
	}
	public function list_kv_namespaces() {
		return isset( $this->errors['kv'] ) ? $this->errors['kv'] : $this->kv;
	}
	public function list_workers() {
		return isset( $this->errors['workers'] ) ? $this->errors['workers'] : $this->workers;
	}
	public function list_ai_gateways() {
		return isset( $this->errors['ai_gateways'] ) ? $this->errors['ai_gateways'] : $this->ai_gateways;
	}
}

/**
 * @covers NVOOS_SaaS_Controller_Plan_Generator
 */
class Test_NVOOS_SaaS_Controller_Plan_Generator extends WP_UnitTestCase {

	public function test_empty_desired_marks_live_resources_as_orphans() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->d1 = array( array( 'uuid' => 'db1', 'name' => 'main' ) );
		$stub->kv = array( array( 'id' => 'ns1', 'title' => 'cache' ) );

		$gen  = new NVOOS_SaaS_Controller_Plan_Generator( $stub );
		$plan = $gen->generate( NVOOS_SaaS_Controller_Deployment_Config::defaults() );

		$this->assertSame( 0, $plan['summary']['creates'] );
		$this->assertSame( 2, $plan['summary']['orphans'] );
		$this->assertSame( 0, $plan['summary']['errors'] );
	}

	public function test_fully_synced_yields_only_noops() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->d1 = array( array( 'uuid' => 'db1', 'name' => 'main' ) );
		$stub->kv = array( array( 'id' => 'ns1', 'title' => 'cache' ) );
		$stub->ai_gateways = array( array( 'id' => 'gw1', 'slug' => 'router' ) );

		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'd1_databases'    => array( array( 'name' => 'main', 'binding' => 'DB' ) ),
				'kv_namespaces'   => array( array( 'title' => 'cache', 'binding' => 'CACHE' ) ),
				'ai_gateway_slug' => 'router',
			)
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub ) )->generate( $desired );

		$this->assertSame( 0, $plan['summary']['creates'] );
		$this->assertSame( 3, $plan['summary']['noops'] );
		$this->assertSame( 0, $plan['summary']['orphans'] );
	}

	public function test_missing_remote_resources_become_creates() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client(); // empty live
		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'worker_name'     => 'mcp-oos-worker',
				'd1_databases'    => array( array( 'name' => 'main', 'binding' => 'DB' ) ),
				'kv_namespaces'   => array( array( 'title' => 'cache', 'binding' => 'CACHE' ) ),
				'ai_gateway_slug' => 'router',
			)
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub ) )->generate( $desired );
		$this->assertSame( 4, $plan['summary']['creates'] );
		$kinds = array_column( $plan['creates'], 'kind' );
		$this->assertContains( 'd1', $kinds );
		$this->assertContains( 'kv', $kinds );
		$this->assertContains( 'worker', $kinds );
		$this->assertContains( 'ai_gateway', $kinds );
	}

	public function test_existing_worker_is_an_update() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->workers = array( array( 'id' => 'mcp-oos-worker', 'modified_on' => '2026-01-01T00:00:00Z' ) );

		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array( 'worker_name' => 'mcp-oos-worker' )
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub ) )->generate( $desired );

		$this->assertSame( 1, $plan['summary']['updates'] );
		$this->assertSame( 'worker', $plan['updates'][0]['kind'] );
	}

	public function test_cloudflare_errors_recorded_not_thrown() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->errors['d1'] = new WP_Error( 'cloudflare_http_401', 'Unauthorized' );

		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array( 'd1_databases' => array( array( 'name' => 'main', 'binding' => 'DB' ) ) )
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub ) )->generate( $desired );

		$this->assertSame( 1, $plan['summary']['errors'] );
		$this->assertSame( 'd1', $plan['errors'][0]['kind'] );
		$this->assertSame( 'Unauthorized', $plan['errors'][0]['message'] );
		// Other sections still ran.
		$this->assertArrayHasKey( 'creates', $plan );
	}

	public function test_unrelated_live_workers_are_not_orphans() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->workers = array( array( 'id' => 'someone-elses-worker', 'modified_on' => '' ) );
		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array( 'worker_name' => 'mcp-oos-worker' )
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub ) )->generate( $desired );
		$worker_orphans = array_filter( $plan['orphans'], function ( $r ) { return 'worker' === $r['kind']; } );
		$this->assertEmpty( $worker_orphans );
		// And the desired worker is a create.
		$worker_creates = array_filter( $plan['creates'], function ( $r ) { return 'worker' === $r['kind']; } );
		$this->assertCount( 1, $worker_creates );
	}
}
