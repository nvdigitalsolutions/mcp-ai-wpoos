<?php
/**
 * Tests for NVOOS_SaaS_Controller_Deployment_Config.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * @covers NVOOS_SaaS_Controller_Deployment_Config
 */
class Test_NVOOS_SaaS_Controller_Deployment_Config extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Deployment_Config::OPTION_NAME );
	}

	public function tearDown(): void {
		delete_option( NVOOS_SaaS_Controller_Deployment_Config::OPTION_NAME );
		parent::tearDown();
	}

	public function test_defaults_returned_when_unset() {
		$config = NVOOS_SaaS_Controller_Deployment_Config::instance()->get();
		$this->assertSame( '', $config['worker_name'] );
		$this->assertSame( array(), $config['d1_databases'] );
		$this->assertSame( array(), $config['kv_namespaces'] );
	}

	public function test_set_persists_sanitised_values() {
		$instance = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$saved    = $instance->set(
			array(
				'worker_name' => 'mcp-OOS-Worker',
				'account_id'  => 'ABCDEF0123456789ABCDEF0123456789',
				'd1_databases' => array(
					array( 'name' => 'main_db', 'binding' => 'DB' ),
				),
			)
		);
		$this->assertSame( 'mcp-oos-worker', $saved['worker_name'] );
		$this->assertSame( 'abcdef0123456789abcdef0123456789', $saved['account_id'] );
		$this->assertCount( 1, $saved['d1_databases'] );
		$this->assertSame( 'DB', $saved['d1_databases'][0]['binding'] );
	}

	public function test_invalid_worker_name_dropped() {
		$saved = NVOOS_SaaS_Controller_Deployment_Config::instance()->set(
			array( 'worker_name' => 'Has Spaces!' )
		);
		$this->assertSame( '', $saved['worker_name'] );
	}

	public function test_invalid_binding_drops_row() {
		$saved = NVOOS_SaaS_Controller_Deployment_Config::instance()->set(
			array(
				'd1_databases' => array(
					array( 'name' => 'ok', 'binding' => 'lowercase' ),
					array( 'name' => 'ok2', 'binding' => 'GOOD_ONE' ),
				),
			)
		);
		$this->assertCount( 1, $saved['d1_databases'] );
		$this->assertSame( 'GOOD_ONE', $saved['d1_databases'][0]['binding'] );
	}

	public function test_clear_resets_to_defaults() {
		$instance = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$instance->set( array( 'worker_name' => 'live' ) );
		$instance->clear();
		$config = $instance->get();
		$this->assertSame( '', $config['worker_name'] );
	}

	public function test_kv_namespaces_sanitisation() {
		$saved = NVOOS_SaaS_Controller_Deployment_Config::instance()->set(
			array(
				'kv_namespaces' => array(
					array( 'title' => 'cache', 'binding' => 'CACHE' ),
					array( 'title' => '', 'binding' => 'EMPTY' ),
					array( 'binding' => 'NOTITLE' ),
				),
			)
		);
		$this->assertCount( 1, $saved['kv_namespaces'] );
		$this->assertSame( 'cache', $saved['kv_namespaces'][0]['title'] );
	}
}
