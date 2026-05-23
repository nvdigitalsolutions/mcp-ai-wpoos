<?php
/**
 * Tests for NVOOS_SaaS_Controller_Plan_Generator.
 *
 * @package NV_oOS_SaaS_Controller
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Stub Cloudflare client returning fixed payloads.
 */
class NVOOS_SaaS_Stub_Cloudflare_Client extends NVOOS_SaaS_Controller_Cloudflare_Client {

	/**
	 * Canned D1 database list.
	 *
	 * @var array
	 */
	public $d1 = array();

	/**
	 * Canned KV namespace list.
	 *
	 * @var array
	 */
	public $kv = array();

	/**
	 * Canned worker list.
	 *
	 * @var array
	 */
	public $workers = array();

	/**
	 * Canned AI gateway list.
	 *
	 * @var array
	 */
	public $ai_gateways = array();

	/**
	 * Canned errors keyed by resource type.
	 *
	 * @var array
	 */
	public $errors = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		/* no super */ } // phpcs:ignore Generic.Classes.OpeningBraceSameLine

	/**
	 * List D1 databases.
	 *
	 * @return array|WP_Error
	 */
	public function list_d1_databases() {
		return isset( $this->errors['d1'] ) ? $this->errors['d1'] : $this->d1;
	}

	/**
	 * List KV namespaces.
	 *
	 * @return array|WP_Error
	 */
	public function list_kv_namespaces() {
		return isset( $this->errors['kv'] ) ? $this->errors['kv'] : $this->kv;
	}

	/**
	 * List workers.
	 *
	 * @return array|WP_Error
	 */
	public function list_workers() {
		return isset( $this->errors['workers'] ) ? $this->errors['workers'] : $this->workers;
	}

	/**
	 * List AI gateways.
	 *
	 * @return array|WP_Error
	 */
	public function list_ai_gateways() {
		return isset( $this->errors['ai_gateways'] ) ? $this->errors['ai_gateways'] : $this->ai_gateways;
	}
}

/**
 * Tests for the plan generator.
 *
 * @covers NVOOS_SaaS_Controller_Plan_Generator
 */
class Test_NVOOS_SaaS_Controller_Plan_Generator extends WP_UnitTestCase {

	/**
	 * Test that empty desired marks live resources as orphans.
	 *
	 * @return void
	 */
	public function test_empty_desired_marks_live_resources_as_orphans() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->d1 = array(
			array(
				'uuid' => 'db1',
				'name' => 'main',
			),
		);
		$stub->kv = array(
			array(
				'id' => 'ns1',
				'title' => 'cache',
			),
		);

		$gen  = new NVOOS_SaaS_Controller_Plan_Generator( $stub );
		$plan = $gen->generate( NVOOS_SaaS_Controller_Deployment_Config::defaults() );

		$this->assertSame( 0, $plan['summary']['creates'] );
		$this->assertSame( 2, $plan['summary']['orphans'] );
		$this->assertSame( 0, $plan['summary']['errors'] );
	}

	/**
	 * Test that fully synced yields only noops.
	 *
	 * @return void
	 */
	public function test_fully_synced_yields_only_noops() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->d1 = array(
			array(
				'uuid' => 'db1',
				'name' => 'main',
			),
		);
		$stub->kv = array(
			array(
				'id' => 'ns1',
				'title' => 'cache',
			),
		);
		$stub->ai_gateways = array(
			array(
				'id' => 'gw1',
				'slug' => 'router',
			),
		);

		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'd1_databases'    => array(
					array(
						'name' => 'main',
						'binding' => 'DB',
					),
				),
				'kv_namespaces'   => array(
					array(
						'title' => 'cache',
						'binding' => 'CACHE',
					),
				),
				'ai_gateway_slug' => 'router',
			)
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub ) )->generate( $desired );

		$this->assertSame( 0, $plan['summary']['creates'] );
		$this->assertSame( 3, $plan['summary']['noops'] );
		$this->assertSame( 0, $plan['summary']['orphans'] );
	}

	/**
	 * Test that missing remote resources become creates.
	 *
	 * @return void
	 */
	public function test_missing_remote_resources_become_creates() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client(); // Empty live state.
		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'worker_name'     => 'mcp-oos-worker',
				'd1_databases'    => array(
					array(
						'name' => 'main',
						'binding' => 'DB',
					),
				),
				'kv_namespaces'   => array(
					array(
						'title' => 'cache',
						'binding' => 'CACHE',
					),
				),
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

	/**
	 * Test that existing worker is an update.
	 *
	 * @return void
	 */
	public function test_existing_worker_is_an_update() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->workers = array(
			array(
				'id' => 'mcp-oos-worker',
				'modified_on' => '2026-01-01T00:00:00Z',
			),
		);

		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array( 'worker_name' => 'mcp-oos-worker' )
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub ) )->generate( $desired );

		$this->assertSame( 1, $plan['summary']['updates'] );
		$this->assertSame( 'worker', $plan['updates'][0]['kind'] );
	}

	/**
	 * Test that Cloudflare errors are recorded, not thrown.
	 *
	 * @return void
	 */
	public function test_cloudflare_errors_recorded_not_thrown() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->errors['d1'] = new WP_Error( 'cloudflare_http_401', 'Unauthorized' );

		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'd1_databases' => array(
					array(
						'name' => 'main',
						'binding' => 'DB',
					),
				),
			)
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub ) )->generate( $desired );

		$this->assertSame( 1, $plan['summary']['errors'] );
		$this->assertSame( 'd1', $plan['errors'][0]['kind'] );
		$this->assertSame( 'Unauthorized', $plan['errors'][0]['message'] );
		// Other sections still ran.
		$this->assertArrayHasKey( 'creates', $plan );
	}

	/**
	 * Test that unrelated live workers are not orphans.
	 *
	 * @return void
	 */
	public function test_unrelated_live_workers_are_not_orphans() {
		$stub = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stub->workers = array(
			array(
				'id' => 'someone-elses-worker',
				'modified_on' => '',
			),
		);
		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array( 'worker_name' => 'mcp-oos-worker' )
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub ) )->generate( $desired );
		$worker_orphans = array_filter(
			$plan['orphans'],
			function ( $r ) {
				return 'worker' === $r['kind'];
			}
		);
		$this->assertEmpty( $worker_orphans );
		// And the desired worker is a create.
		$worker_creates = array_filter(
			$plan['creates'],
			function ( $r ) {
				return 'worker' === $r['kind'];
			}
		);
		$this->assertCount( 1, $worker_creates );
	}

	/**
	 * Test that Stripe section skipped silently when no credential and no desired rows.
	 *
	 * @return void
	 */
	public function test_stripe_section_skipped_silently_when_no_credential_and_no_desired_rows() {
		$stub  = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$plan  = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub, null, null ) )
			->generate( NVOOS_SaaS_Controller_Deployment_Config::defaults() );
		$this->assertSame( 0, $plan['summary']['errors'] );
	}

	/**
	 * Test that Stripe section emits error row when desired rows but no credential.
	 *
	 * @return void
	 */
	public function test_stripe_section_emits_error_row_when_desired_rows_but_no_credential() {
		$stub    = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'stripe_products' => array(
					array(
						'id' => 'prod_x',
						'name' => 'X',
					),
				),
			)
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub, null, null ) )->generate( $desired );
		$this->assertSame( 1, $plan['summary']['errors'] );
		$this->assertSame( 'stripe_product', $plan['errors'][0]['kind'] );
	}

	/**
	 * Test that Stripe products diff creates vs noops.
	 *
	 * @return void
	 */
	public function test_stripe_products_diff_creates_vs_noops() {
		$stub   = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stripe = new NVOOS_SaaS_Stub_Stripe_Client();
		$stripe->products = array(
			'prod_existing' => array(
				'id' => 'prod_existing',
				'name' => 'Existing',
			),
		);
		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'stripe_products' => array(
					array(
						'id' => 'prod_existing',
						'name' => 'Existing',
					),
					array(
						'id' => 'prod_new',
						'name' => 'New',
					),
				),
			)
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub, $stripe, null ) )->generate( $desired );

		$noops = array_values(
			array_filter(
				$plan['noops'],
				function ( $r ) {
					return 'stripe_product' === $r['kind'];
				}
			)
		);
		$creates = array_values(
			array_filter(
				$plan['creates'],
				function ( $r ) {
					return 'stripe_product' === $r['kind'];
				}
			)
		);
		$this->assertCount( 1, $noops );
		$this->assertSame( 'prod_existing', $noops[0]['id'] );
		$this->assertCount( 1, $creates );
		$this->assertSame( 'prod_new', $creates[0]['id'] );
	}

	/**
	 * Test that Stripe prices match by lookup key.
	 *
	 * @return void
	 */
	public function test_stripe_prices_match_by_lookup_key() {
		$stub   = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stripe = new NVOOS_SaaS_Stub_Stripe_Client();
		$stripe->prices = array(
			'pro_monthly' => array(
				'id' => 'price_live',
				'lookup_key' => 'pro_monthly',
				'product' => 'prod_pro',
			),
		);
		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'stripe_prices' => array(
					array(
						'lookup_key' => 'pro_monthly',
						'product_id' => 'prod_pro',
						'currency' => 'usd',
						'unit_amount' => 1500,
					),
					array(
						'lookup_key' => 'starter_yearly',
						'product_id' => 'prod_starter',
						'currency' => 'usd',
						'unit_amount' => 9900,
					),
				),
			)
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub, $stripe, null ) )->generate( $desired );

		$noops   = array_values(
			array_filter(
				$plan['noops'],
				function ( $r ) {
					return 'stripe_price' === $r['kind'];
				}
			)
		);
		$creates = array_values(
			array_filter(
				$plan['creates'],
				function ( $r ) {
					return 'stripe_price' === $r['kind'];
				}
			)
		);
		$this->assertCount( 1, $noops );
		$this->assertSame( 'pro_monthly', $noops[0]['lookup_key'] );
		$this->assertCount( 1, $creates );
		$this->assertSame( 'starter_yearly', $creates[0]['lookup_key'] );
	}

	/**
	 * Test that OpenRouter keys match by label.
	 *
	 * @return void
	 */
	public function test_openrouter_keys_match_by_label() {
		$stub       = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$openrouter = new NVOOS_SaaS_Stub_OpenRouter_Client();
		$openrouter->keys = array(
			'production' => array(
				'label' => 'production',
				'hash' => 'h1',
			),
		);
		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'openrouter_keys' => array(
					array( 'label' => 'production' ),
					array(
						'label' => 'staging',
						'limit_usd' => 50.0,
					),
				),
			)
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub, null, $openrouter ) )->generate( $desired );

		$noops   = array_values(
			array_filter(
				$plan['noops'],
				function ( $r ) {
					return 'openrouter_key' === $r['kind'];
				}
			)
		);
		$creates = array_values(
			array_filter(
				$plan['creates'],
				function ( $r ) {
					return 'openrouter_key' === $r['kind'];
				}
			)
		);
		$this->assertCount( 1, $noops );
		$this->assertSame( 'production', $noops[0]['label'] );
		$this->assertCount( 1, $creates );
		$this->assertSame( 'staging', $creates[0]['label'] );
		$this->assertSame( 50.0, $creates[0]['limit_usd'] );
	}

	/**
	 * Test that Stripe list error surfaces in errors section.
	 *
	 * @return void
	 */
	public function test_stripe_list_error_surfaces_in_errors_section() {
		$stub   = new NVOOS_SaaS_Stub_Cloudflare_Client();
		$stripe = new NVOOS_SaaS_Stub_Stripe_Client();
		$stripe->products_error = new WP_Error( 'stripe_http_500', 'Boom' );
		$desired = array_merge(
			NVOOS_SaaS_Controller_Deployment_Config::defaults(),
			array(
				'stripe_products' => array(
					array(
						'id' => 'prod_x',
						'name' => 'X',
					),
				),
			)
		);
		$plan = ( new NVOOS_SaaS_Controller_Plan_Generator( $stub, $stripe, null ) )->generate( $desired );
		$this->assertSame( 1, $plan['summary']['errors'] );
		$this->assertSame( 'stripe_product', $plan['errors'][0]['kind'] );
	}
}

/**
 * Stub Stripe client returning fixed payloads — extends the real class so
 * the plan generator's `instanceof` check accepts it without a HTTP layer.
 */
class NVOOS_SaaS_Stub_Stripe_Client extends NVOOS_SaaS_Controller_Stripe_Client {

	/**
	 * Canned product list.
	 *
	 * @var array
	 */
	public $products = array();

	/**
	 * Canned price list.
	 *
	 * @var array
	 */
	public $prices = array();

	/**
	 * Canned products error.
	 *
	 * @var WP_Error|null
	 */
	public $products_error = null;

	/**
	 * Canned prices error.
	 *
	 * @var WP_Error|null
	 */
	public $prices_error = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		/* no super */ } // phpcs:ignore Generic.Classes.OpeningBraceSameLine

	/**
	 * List products (returns canned data or error).
	 *
	 * @param array $ids Product IDs to list.
	 * @return array|WP_Error
	 */
	public function list_products( array $ids ) {
		if ( null !== $this->products_error ) {
			return $this->products_error;
		}
		return $this->products;
	}

	/**
	 * List prices by lookup keys (returns canned data or error).
	 *
	 * @param array $lookup_keys Lookup keys to find.
	 * @return array|WP_Error
	 */
	public function list_prices_by_lookup_keys( array $lookup_keys ) {
		if ( null !== $this->prices_error ) {
			return $this->prices_error;
		}
		return $this->prices;
	}
}

/**
 * Stub OpenRouter client.
 */
class NVOOS_SaaS_Stub_OpenRouter_Client extends NVOOS_SaaS_Controller_OpenRouter_Client {

	/**
	 * Canned key list.
	 *
	 * @var array
	 */
	public $keys = array();

	/**
	 * Canned keys error.
	 *
	 * @var WP_Error|null
	 */
	public $keys_error = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		/* no super */ } // phpcs:ignore Generic.Classes.OpeningBraceSameLine

	/**
	 * List keys (returns canned data or error).
	 *
	 * @return array|WP_Error
	 */
	public function list_keys() {
		if ( null !== $this->keys_error ) {
			return $this->keys_error;
		}
		return $this->keys;
	}
}
