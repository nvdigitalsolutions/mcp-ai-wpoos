<?php
/**
 * Tests for NVOOS_SaaS_Controller_Deployment_Config.
 *
 * @package NV_oOS_SaaS_Controller
 */

/**
 * Tests for deployment configuration persistence and sanitisation.
 *
 * @covers NVOOS_SaaS_Controller_Deployment_Config
 */
class Test_NVOOS_SaaS_Controller_Deployment_Config extends WP_UnitTestCase {

	/**
	 * Set up test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		delete_option( NVOOS_SaaS_Controller_Deployment_Config::OPTION_NAME );
	}

	/**
	 * Tear down test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( NVOOS_SaaS_Controller_Deployment_Config::OPTION_NAME );
		parent::tearDown();
	}

	/**
	 * Test that defaults are returned when unset.
	 *
	 * @return void
	 */
	public function test_defaults_returned_when_unset() {
		$config = NVOOS_SaaS_Controller_Deployment_Config::instance()->get();
		$this->assertSame( '', $config['worker_name'] );
		$this->assertSame( array(), $config['d1_databases'] );
		$this->assertSame( array(), $config['kv_namespaces'] );
	}

	/**
	 * Test that set persists sanitised values.
	 *
	 * @return void
	 */
	public function test_set_persists_sanitised_values() {
		$instance = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$saved    = $instance->set(
			array(
				'worker_name' => 'mcp-OOS-Worker',
				'account_id'  => 'ABCDEF0123456789ABCDEF0123456789',
				'd1_databases' => array(
					array(
						'name' => 'main_db',
						'binding' => 'DB',
					),
				),
			)
		);
		$this->assertSame( 'mcp-oos-worker', $saved['worker_name'] );
		$this->assertSame( 'abcdef0123456789abcdef0123456789', $saved['account_id'] );
		$this->assertCount( 1, $saved['d1_databases'] );
		$this->assertSame( 'DB', $saved['d1_databases'][0]['binding'] );
	}

	/**
	 * Test that an invalid worker name is dropped.
	 *
	 * @return void
	 */
	public function test_invalid_worker_name_dropped() {
		$saved = NVOOS_SaaS_Controller_Deployment_Config::instance()->set(
			array( 'worker_name' => 'Has Spaces!' )
		);
		$this->assertSame( '', $saved['worker_name'] );
	}

	/**
	 * Test that an invalid binding drops the row.
	 *
	 * @return void
	 */
	public function test_invalid_binding_drops_row() {
		$saved = NVOOS_SaaS_Controller_Deployment_Config::instance()->set(
			array(
				'd1_databases' => array(
					array(
						'name' => 'ok',
						'binding' => 'lowercase',
					),
					array(
						'name' => 'ok2',
						'binding' => 'GOOD_ONE',
					),
				),
			)
		);
		$this->assertCount( 1, $saved['d1_databases'] );
		$this->assertSame( 'GOOD_ONE', $saved['d1_databases'][0]['binding'] );
	}

	/**
	 * Test that clear resets to defaults.
	 *
	 * @return void
	 */
	public function test_clear_resets_to_defaults() {
		$instance = NVOOS_SaaS_Controller_Deployment_Config::instance();
		$instance->set( array( 'worker_name' => 'live' ) );
		$instance->clear();
		$config = $instance->get();
		$this->assertSame( '', $config['worker_name'] );
	}

	/**
	 * Test that KV namespaces are sanitised.
	 *
	 * @return void
	 */
	public function test_kv_namespaces_sanitisation() {
		$saved = NVOOS_SaaS_Controller_Deployment_Config::instance()->set(
			array(
				'kv_namespaces' => array(
					array(
						'title' => 'cache',
						'binding' => 'CACHE',
					),
					array(
						'title' => '',
						'binding' => 'EMPTY',
					),
					array( 'binding' => 'NOTITLE' ),
				),
			)
		);
		$this->assertCount( 1, $saved['kv_namespaces'] );
		$this->assertSame( 'cache', $saved['kv_namespaces'][0]['title'] );
	}

	/**
	 * Test that Stripe products are sanitised.
	 *
	 * @return void
	 */
	public function test_stripe_products_sanitised() {
		$saved = NVOOS_SaaS_Controller_Deployment_Config::instance()->set(
			array(
				'stripe_products' => array(
					array(
						'id' => 'prod_basic',
						'name' => 'Basic',
						'description' => 'Basic plan',
					),
					array(
						'id' => '',
						'name' => 'Empty id',
					),
					array(
						'id' => 'prod_with bad chars!',
						'name' => 'X',
					),
					array( 'id' => 'prod_no_name' ),
				),
			)
		);
		$this->assertCount( 1, $saved['stripe_products'] );
		$this->assertSame( 'prod_basic', $saved['stripe_products'][0]['id'] );
		$this->assertSame( 'Basic plan', $saved['stripe_products'][0]['description'] );
	}

	/**
	 * Test that Stripe prices require a full tuple.
	 *
	 * @return void
	 */
	public function test_stripe_prices_require_full_tuple() {
		$saved = NVOOS_SaaS_Controller_Deployment_Config::instance()->set(
			array(
				'stripe_prices' => array(
					// Valid: monthly recurring.
					array(
						'lookup_key'         => 'pro_monthly',
						'product_id'         => 'prod_pro',
						'currency'           => 'USD',
						'unit_amount'        => 1500,
						'recurring_interval' => 'month',
					),
					// Invalid: missing currency.
					array(
						'lookup_key'  => 'starter',
						'product_id' => 'prod_starter',
						'unit_amount' => 100,
					),
					// Invalid: zero amount.
					array(
						'lookup_key'  => 'free',
						'product_id'  => 'prod_free',
						'currency'    => 'usd',
						'unit_amount' => 0,
					),
					// Invalid: bad currency.
					array(
						'lookup_key'  => 'bad_curr',
						'product_id'  => 'prod_x',
						'currency'    => 'xxx_too_long',
						'unit_amount' => 500,
					),
				),
			)
		);
		$this->assertCount( 1, $saved['stripe_prices'] );
		$this->assertSame( 'pro_monthly', $saved['stripe_prices'][0]['lookup_key'] );
		// Currency lower-cased.
		$this->assertSame( 'usd', $saved['stripe_prices'][0]['currency'] );
		$this->assertSame( 'month', $saved['stripe_prices'][0]['recurring_interval'] );
	}

	/**
	 * Test that OpenRouter keys are sanitised and limit validated.
	 *
	 * @return void
	 */
	public function test_openrouter_keys_sanitised_and_limit_validated() {
		$saved = NVOOS_SaaS_Controller_Deployment_Config::instance()->set(
			array(
				'openrouter_keys' => array(
					array(
						'label' => 'production',
						'limit_usd' => 250.0,
					),
					array( 'label' => 'staging' ),
					array(
						'label' => '',
						'limit_usd' => 100,
					),
					array(
						'label' => 'with-zero-limit',
						'limit_usd' => -5,
					),
				),
			)
		);
		$this->assertCount( 3, $saved['openrouter_keys'] );
		$this->assertSame( 'production', $saved['openrouter_keys'][0]['label'] );
		$this->assertSame( 250.0, $saved['openrouter_keys'][0]['limit_usd'] );
		$this->assertSame( 'staging', $saved['openrouter_keys'][1]['label'] );
		$this->assertArrayNotHasKey( 'limit_usd', $saved['openrouter_keys'][1] );
		// Negative limit dropped, but label preserved.
		$this->assertSame( 'with-zero-limit', $saved['openrouter_keys'][2]['label'] );
		$this->assertArrayNotHasKey( 'limit_usd', $saved['openrouter_keys'][2] );
	}
}
