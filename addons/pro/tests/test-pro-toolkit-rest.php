<?php
/**
 * Tests for the manifest-driven Pro toolkit REST surface.
 *
 * Mirrors the Laravel contract suite
 * (`docker/laravel/app-skeleton/tests/Feature/ToolkitContractTest.php`) so the
 * two runtimes cannot drift: same catalogue shape, same lifecycle, same
 * dashboard semantics, same duplicate scan, same import accounting.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
}

/**
 * Toolkit REST contract tests.
 */
class Test_Pro_Toolkit_Rest extends WP_UnitTestCase {

	/**
	 * Whether the shared classes were loaded.
	 *
	 * @var bool
	 */
	private static $loaded = false;

	/**
	 * Administrator user id.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Install the records table once, outside the per-test transaction.
	 *
	 * DDL commits the surrounding transaction, so it must not run in setUp().
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! self::$loaded ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/toolkits/class-wp-mcp-ai-pro-toolkit-manifests.php';
			require_once WP_MCP_AI_PRO_PATH . 'includes/toolkits/class-wp-mcp-ai-pro-toolkit-records.php';
			require_once WP_MCP_AI_PRO_PATH . 'includes/rest/class-wp-mcp-ai-pro-toolkit-rest-controller.php';

			self::$loaded = true;
		}

		if ( class_exists( 'WP_MCP_AI_Pro_Toolkit_Manifests' ) ) {
			WP_MCP_AI_Pro_Toolkit_Manifests::install();
		}
	}

	/**
	 * Reset records + settings for each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'Nvoos\\Core\\Domain\\ValueObject\\ToolkitManifest' ) ) {
			$this->markTestSkipped( 'The nvoos/core manifest validator is not loaded.' );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Test fixture reset on a custom Pro table.
		$wpdb->query( 'DELETE FROM ' . WP_MCP_AI_Pro_Toolkit_Manifests::table() );

		delete_option( WP_MCP_AI_Pro_Toolkit_Manifests::OPTION_SETTINGS );

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Issue a request against the toolkit namespace.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route after the namespace.
	 * @param array<string, mixed> $params Params.
	 * @return WP_REST_Response
	 */
	private function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		do_action( 'rest_api_init' );

		$request = new WP_REST_Request( $method, '/mcp-ai-pro/v1' . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_do_request( $request );
	}

	/**
	 * Create a product as the administrator.
	 *
	 * @param array<string, mixed> $data Payload.
	 * @return WP_REST_Response
	 */
	private function create_product( array $data ): WP_REST_Response {
		return $this->request(
			'POST',
			'/toolkit-manifests/commerce/entities/product',
			array( 'data' => $data )
		);
	}

	// ------------------------------------------------------------ permissions

	/**
	 * Every route is admin-only.
	 *
	 * @return void
	 */
	public function test_routes_require_manage_options(): void {
		wp_set_current_user( 0 );

		$anonymous = $this->request( 'GET', '/toolkit-manifests' );

		$this->assertContains( $anonymous->get_status(), array( 401, 403 ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertSame( 403, $this->request( 'GET', '/toolkit-manifests' )->get_status() );

		wp_set_current_user( $this->admin );

		$this->assertSame( 200, $this->request( 'GET', '/toolkit-manifests' )->get_status() );
	}

	// ------------------------------------------------------------- catalogue

	/**
	 * The pilot manifests are listed.
	 *
	 * @return void
	 */
	public function test_catalogue_lists_the_shipped_manifests(): void {
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', '/toolkit-manifests' )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( array(), $data['data']['invalid'] );

		$slugs = array_column( $data['data']['toolkits'], 'slug' );

		$this->assertContains( 'commerce', $slugs );
		$this->assertContains( 'documents', $slugs );
	}

	/**
	 * A manifest is served with its declared screens.
	 *
	 * @return void
	 */
	public function test_manifest_is_served(): void {
		wp_set_current_user( $this->admin );

		$data = $this->request( 'GET', '/toolkit-manifests/commerce' )->get_data();

		$this->assertSame( 'commerce', $data['data']['toolkit']['slug'] );
		$this->assertContains( 'entities', $data['data']['toolkit']['screens'] );
		$this->assertSame( 'sku', $data['data']['toolkit']['entities'][0]['id_field'] );
	}

	/**
	 * Unknown toolkits are a typed 404.
	 *
	 * @return void
	 */
	public function test_unknown_toolkit_is_404(): void {
		wp_set_current_user( $this->admin );

		$response = $this->request( 'GET', '/toolkit-manifests/nope' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'toolkit_not_found', $response->get_data()['error']['code'] );
	}

	// -------------------------------------------------------------- research

	/**
	 * Research validates its inputs before touching a provider.
	 *
	 * @return void
	 */
	public function test_research_requires_an_entity_and_query(): void {
		wp_set_current_user( $this->admin );

		$response = $this->request( 'POST', '/toolkit-manifests/commerce/research', array() );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['error']['code'] );
	}

	/**
	 * Research rejects an entity the manifest does not declare.
	 *
	 * @return void
	 */
	public function test_research_rejects_an_unknown_entity(): void {
		wp_set_current_user( $this->admin );

		$response = $this->request(
			'POST',
			'/toolkit-manifests/commerce/research',
			array(
				'entity' => 'spaceship',
				'query'  => 'anything',
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'entity_not_found', $response->get_data()['error']['code'] );
	}

	// -------------------------------------------------------------- settings

	/**
	 * Defaults are served and values round-trip.
	 *
	 * @return void
	 */
	public function test_settings_round_trip(): void {
		wp_set_current_user( $this->admin );

		$initial = $this->request( 'GET', '/toolkit-manifests/commerce/settings' )->get_data();

		// The shared console's envelope carries the slug (Laravel parity).
		$this->assertSame( 'commerce', $initial['data']['slug'] );
		$this->assertSame( 'USD', $initial['data']['values']['currency'] );
		$this->assertSame( 5, $initial['data']['values']['low_stock_threshold'] );

		$saved = $this->request(
			'POST',
			'/toolkit-manifests/commerce/settings',
			array( 'values' => array( 'low_stock_threshold' => 9 ) )
		)->get_data();

		$this->assertSame( 9, $saved['data']['values']['low_stock_threshold'] );
	}

	/**
	 * A bad setting value is rejected with a field message.
	 *
	 * @return void
	 */
	public function test_invalid_setting_is_rejected(): void {
		wp_set_current_user( $this->admin );

		$response = $this->request(
			'POST',
			'/toolkit-manifests/commerce/settings',
			array( 'values' => array( 'low_stock_threshold' => 'many' ) )
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['error']['code'] );
	}

	// -------------------------------------------------------------- entities

	/**
	 * Create, list, read, update, delete.
	 *
	 * @return void
	 */
	public function test_entity_lifecycle(): void {
		wp_set_current_user( $this->admin );

		$created = $this->create_product(
			array(
				'sku'    => 'WP-1',
				'name'   => 'Classic Tee',
				'price'  => 29.5,
				'stock'  => 4,
				'status' => 'active',
			)
		);

		$this->assertSame( 201, $created->get_status() );
		$this->assertSame( 'WP-1', $created->get_data()['data']['record']['id'] );

		$listed = $this->request( 'GET', '/toolkit-manifests/commerce/entities/product' )->get_data();

		// The shared console's page envelope carries the entity key (Laravel parity).
		$this->assertSame( 'product', $listed['data']['entity'] );
		$this->assertSame( 1, $listed['data']['total'] );

		$updated = $this->request(
			'PUT',
			'/toolkit-manifests/commerce/entities/product/WP-1',
			array( 'data' => array( 'name' => 'Updated' ) )
		)->get_data();

		$this->assertSame( 'Updated', $updated['data']['record']['data']['name'] );
		$this->assertSame( 29.5, $updated['data']['record']['data']['price'] );

		$deleted = $this->request( 'DELETE', '/toolkit-manifests/commerce/entities/product/WP-1' );

		$this->assertSame( 200, $deleted->get_status() );
		$this->assertTrue( $deleted->get_data()['data']['deleted'] );

		$this->assertSame( 404, $this->request( 'GET', '/toolkit-manifests/commerce/entities/product/WP-1' )->get_status() );
	}

	/**
	 * Unknown and immutable fields are refused.
	 *
	 * @return void
	 */
	public function test_payload_validation(): void {
		wp_set_current_user( $this->admin );

		$unknown = $this->create_product(
			array(
				'sku'    => 'WP-2',
				'name'   => 'Tee',
				'colour' => 'red',
			)
		);

		$this->assertSame( 422, $unknown->get_status() );
		$this->assertSame( 'Unknown field.', $unknown->get_data()['error']['fields']['colour'] );

		$this->create_product(
			array(
				'sku'  => 'WP-3',
				'name' => 'Tee',
			)
		);

		$immutable = $this->request(
			'PUT',
			'/toolkit-manifests/commerce/entities/product/WP-3',
			array( 'data' => array( 'sku' => 'WP-4' ) )
		);

		$this->assertSame( 422, $immutable->get_status() );
		$this->assertSame( 'id_immutable', $immutable->get_data()['error']['code'] );
	}

	// ------------------------------------------------------------- dashboard

	/**
	 * Dashboard cards compute, including the setting-bound low-stock card.
	 *
	 * @return void
	 */
	public function test_dashboard_binds_a_filter_to_a_setting(): void {
		wp_set_current_user( $this->admin );

		$this->create_product(
			array(
				'sku'    => 'LOW-1',
				'name'   => 'Low',
				'stock'  => 2,
				'status' => 'active',
			)
		);
		$this->create_product(
			array(
				'sku'    => 'HIGH-1',
				'name'   => 'High',
				'stock'  => 50,
				'status' => 'active',
			)
		);

		$cards = $this->request( 'GET', '/toolkit-manifests/commerce/dashboard' )->get_data()['data']['cards'];

		$by_key = array_column( $cards, 'value', 'key' );

		$this->assertSame( 2, $by_key['total_products'] );
		$this->assertSame( 52, $by_key['stock_value'] );
		$this->assertSame( 1, $by_key['low_stock'] );

		$this->request(
			'POST',
			'/toolkit-manifests/commerce/settings',
			array( 'values' => array( 'low_stock_threshold' => 1 ) )
		);

		$after = array_column(
			$this->request( 'GET', '/toolkit-manifests/commerce/dashboard' )->get_data()['data']['cards'],
			'value',
			'key'
		);

		$this->assertSame( 0, $after['low_stock'] );
	}

	// ------------------------------------------------------------ duplicates

	/**
	 * The scan groups the whole entity by one column.
	 *
	 * @return void
	 */
	public function test_duplicates_group_the_whole_entity(): void {
		wp_set_current_user( $this->admin );

		foreach ( array( array( 'D-1', 'Tee' ), array( 'D-2', 'Tee' ), array( 'D-3', 'Cap' ) ) as $row ) {
			$this->create_product(
				array(
					'sku'  => $row[0],
					'name' => $row[1],
				)
			);
		}

		$data = $this->request(
			'GET',
			'/toolkit-manifests/commerce/entities/product/duplicates',
			array( 'column' => 'name' )
		)->get_data()['data'];

		$this->assertSame( 3, $data['scanned'] );
		$this->assertCount( 1, $data['groups'] );
		$this->assertSame( 'Tee', $data['groups'][0]['value'] );
		$this->assertSame( 2, $data['groups'][0]['count'] );
	}

	/**
	 * Only text/enum columns can be grouped.
	 *
	 * @return void
	 */
	public function test_duplicates_reject_a_non_groupable_column(): void {
		wp_set_current_user( $this->admin );

		$response = $this->request(
			'GET',
			'/toolkit-manifests/commerce/entities/product/duplicates',
			array( 'column' => 'stock' )
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'validation_failed', $response->get_data()['error']['code'] );
	}

	// ---------------------------------------------------------------- import

	/**
	 * Preview writes nothing; commit creates the valid rows only.
	 *
	 * @return void
	 */
	public function test_import_previews_then_creates(): void {
		wp_set_current_user( $this->admin );

		$this->create_product(
			array(
				'sku'  => 'E-1',
				'name' => 'Existing',
			)
		);

		$rows = array(
			array(
				'sku'  => 'E-1',
				'name' => 'Existing',
			),
			array(
				'sku'  => 'E-2',
				'name' => 'New',
			),
			array( 'name' => 'No sku' ),
		);

		$preview = $this->request(
			'POST',
			'/toolkit-manifests/commerce/entities/product/import',
			array(
				'rows'    => $rows,
				'preview' => true,
			)
		)->get_data()['data'];

		$this->assertTrue( $preview['preview'] );
		$this->assertSame( 1, $preview['valid'] );
		$this->assertSame( 1, $preview['duplicates'] );
		$this->assertCount( 1, $preview['failed'] );

		$after_preview = $this->request( 'GET', '/toolkit-manifests/commerce/entities/product' )->get_data()['data'];

		$this->assertSame( 1, $after_preview['total'] );

		$committed = $this->request(
			'POST',
			'/toolkit-manifests/commerce/entities/product/import',
			array( 'rows' => $rows )
		)->get_data()['data'];

		$this->assertSame( 1, $committed['created'] );
		$this->assertSame( 1, $committed['duplicates'] );

		$after_commit = $this->request( 'GET', '/toolkit-manifests/commerce/entities/product' )->get_data()['data'];

		$this->assertSame( 2, $after_commit['total'] );
	}

	/**
	 * Existing keys are reported instead of skipped when asked.
	 *
	 * @return void
	 */
	public function test_import_reports_duplicates_as_failures_when_asked(): void {
		wp_set_current_user( $this->admin );

		$this->create_product(
			array(
				'sku'  => 'F-1',
				'name' => 'Existing',
			)
		);

		$data = $this->request(
			'POST',
			'/toolkit-manifests/commerce/entities/product/import',
			array(
				'rows'          => array(
					array(
						'sku'  => 'F-1',
						'name' => 'Existing',
					),
				),
				'skip_existing' => false,
			)
		)->get_data()['data'];

		$this->assertSame( 0, $data['created'] );
		$this->assertSame( 0, $data['duplicates'] );
		$this->assertArrayHasKey( 'sku', $data['failed'][0]['fields'] );
	}

	/**
	 * The batch size is capped.
	 *
	 * @return void
	 */
	public function test_import_caps_the_batch_size(): void {
		wp_set_current_user( $this->admin );

		$response = $this->request(
			'POST',
			'/toolkit-manifests/commerce/entities/product/import',
			array(
				'rows' => array_fill(
					0,
					501,
					array(
						'sku'  => 'X',
						'name' => 'X',
					)
				),
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'too_many_rows', $response->get_data()['error']['code'] );
	}
}
