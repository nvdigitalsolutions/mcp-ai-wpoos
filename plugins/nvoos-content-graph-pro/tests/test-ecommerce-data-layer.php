<?php
/**
 * Characterization tests for the Wave F2 e-commerce data layer — the
 * ported `WP_MCP_AI_Sync_Log_Manager`, the e-commerce helpers +
 * optimization classes, the three shared traits, and the slimmed init.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   classes (classmap-autoloaded); the byte-identical surface is
 *   asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/` + `src/tools/ecommerce/` are asserted in full.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * E-commerce data layer tests.
 */
class Test_Ecommerce_Data_Layer extends WP_UnitTestCase {

	/**
	 * Snapshot of the shared settings option before mutation.
	 *
	 * @var mixed
	 */
	private $settings_snapshot = null;

	/**
	 * Snapshot the shared settings option.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->settings_snapshot = get_option( 'wp_mcp_ai_settings', null );
	}

	/**
	 * Restore the shared settings option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		if ( null === $this->settings_snapshot ) {
			delete_option( 'wp_mcp_ai_settings' );
		} else {
			update_option( 'wp_mcp_ai_settings', $this->settings_snapshot );
		}
		parent::tearDown();
	}

	/**
	 * The six ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Sync_Log_Manager'            => 'class-wp-mcp-ai-sync-log-manager.php',
			'WP_MCP_AI_Ecommerce_Optimization'      => 'tools/ecommerce/class-wp-mcp-ai-ecommerce-optimization.php',
			'WP_MCP_AI_Woo_Price_Qty_Updater'       => 'tools/ecommerce/trait-wp-mcp-ai-woo-price-qty-updater.php',
			'WP_MCP_AI_Shopify_Connection_Resolver' => 'tools/ecommerce/trait-wp-mcp-ai-shopify-connection-resolver.php',
			'WP_MCP_AI_Shopify_Smart_Search'        => 'tools/ecommerce/trait-wp-mcp-ai-shopify-smart-search.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The sync-log manager constants must be byte-identical.
	 */
	public function test_sync_log_constants(): void {
		$this->assertSame( 50, WP_MCP_AI_Sync_Log_Manager::MAX_RUNS );
		$this->assertSame( 200, WP_MCP_AI_Sync_Log_Manager::MAX_ITEMS_PER_RUN );
		$this->assertSame( 30, WP_MCP_AI_Sync_Log_Manager::DEFAULT_RETENTION_DAYS );
		$this->assertSame( 'wp_mcp_ai_sync_log_prune', WP_MCP_AI_Sync_Log_Manager::PRUNE_HOOK );
		$this->assertSame( 'sync_log_maintenance', WP_MCP_AI_Sync_Log_Manager::PRUNE_GROUP );
	}

	/**
	 * The sync-log run lifecycle must round-trip through the option store.
	 */
	public function test_sync_log_run_round_trip(): void {
		WP_MCP_AI_Sync_Log_Manager::init();

		$run_id = WP_MCP_AI_Sync_Log_Manager::start_run( 'ecommerce', null, true );
		$this->assertIsString( $run_id );

		WP_MCP_AI_Sync_Log_Manager::log_item( 'ecommerce', $run_id, 'upsert', 'product:1', array( 'dry' => true ) );
		WP_MCP_AI_Sync_Log_Manager::end_run(
			'ecommerce',
			$run_id,
			array(
				'status'        => 'completed',
				'items_total'   => 1,
				'items_skipped' => 1,
			)
		);

		$run = WP_MCP_AI_Sync_Log_Manager::get_run( 'ecommerce', $run_id );
		$this->assertNotNull( $run );
		$this->assertSame( 1, $run['items_total'] );
		$this->assertSame( 'completed', $run['status'] );

		$runs = WP_MCP_AI_Sync_Log_Manager::get_runs( 'ecommerce', 5 );
		$this->assertNotEmpty( $runs );
	}

	/**
	 * The enablement helper must track the enable_ecommerce_toolkit option.
	 */
	public function test_enablement_helper(): void {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/class-wp-mcp-ai-ecommerce-helpers.php';

		delete_option( 'wp_mcp_ai_settings' );
		$this->assertFalse( wp_mcp_ai_is_ecommerce_toolkit_enabled() );

		$settings = array( 'enable_ecommerce_toolkit' => 1 );
		update_option( 'wp_mcp_ai_settings', $settings );
		$this->assertTrue( wp_mcp_ai_is_ecommerce_toolkit_enabled() );
	}

	/**
	 * Standalone only: the init must load the data layer without the
	 * admin-gated page requires (CLI env) and stay idempotent.
	 */
	public function test_init_loads_data_layer_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin loads the e-commerce init.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/init.php';

		$this->assertTrue( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Sync_Log_Manager' ) );
		$this->assertTrue( class_exists( 'WP_MCP_AI_Ecommerce_Optimization' ) );
		$this->assertNotFalse( has_action( 'admin_enqueue_scripts', 'wp_mcp_ai_enqueue_ecommerce_toolkit_admin_styles' ) );
	}
}
