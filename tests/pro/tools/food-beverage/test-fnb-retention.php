<?php
/**
 * F&B Retention Tests — native-storage prune.
 *
 * Covers: the keep-forever guard (retention_days = 0), the disabled guard, and
 * bounded batch deletion of posts older than the window.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B native-storage retention.
 */
class Test_Fnb_Retention extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		$base = $this->toolkit_dir . '/';
		foreach (
			array(
				'class-wp-mcp-ai-fnb-settings.php',
				'class-wp-mcp-ai-fnb-table-reader.php',
				'class-wp-mcp-ai-fnb-data-source.php',
				'trait-wp-mcp-ai-fnb-native-cpt-storage.php',
				'class-wp-mcp-ai-fnb-cpt-reader.php',
				'class-wp-mcp-ai-fnb-sale-cpt.php',
				'class-wp-mcp-ai-fnb-ingest-retention.php',
			) as $file
		) {
			$path = $base . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_fnb_settings' );
		wp_clear_scheduled_hook( WP_MCP_AI_Fnb_Ingest_Retention::CRON_HOOK );
		parent::tearDown();
	}

	/**
	 * Enable native storage with a given retention window.
	 *
	 * @param int $days Retention days.
	 * @return void
	 */
	private function enable_native( $days ) {
		update_option(
			'wp_mcp_ai_fnb_settings',
			array(
				'enable_fnb_native_storage' => true,
				'retention_days'            => $days,
			)
		);
	}

	/**
	 * A retention window of 0 keeps forever — the sweep deletes nothing.
	 */
	public function test_keep_forever_guard() {
		$this->enable_native( 0 );

		WP_MCP_AI_Fnb_Sale_CPT::register();
		$old = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Fnb_Sale_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '-2 years' ) ),
			)
		);

		$deleted = WP_MCP_AI_Fnb_Ingest_Retention::run_retention_sweep();

		$this->assertSame( 0, $deleted );
		$this->assertNotNull( get_post( $old ) );
	}

	/**
	 * When native storage is disabled, the sweep deletes nothing.
	 */
	public function test_disabled_guard() {
		delete_option( 'wp_mcp_ai_fnb_settings' ); // Native storage off.

		$deleted = WP_MCP_AI_Fnb_Ingest_Retention::run_retention_sweep();

		$this->assertSame( 0, $deleted );
	}

	/**
	 * Posts older than the window are pruned; recent ones are kept.
	 */
	public function test_sweep_prunes_only_old_posts() {
		$this->enable_native( 30 );

		WP_MCP_AI_Fnb_Sale_CPT::register();

		$old    = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Fnb_Sale_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) ),
			)
		);
		$recent = self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Fnb_Sale_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_date'   => gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ),
			)
		);

		$deleted = WP_MCP_AI_Fnb_Ingest_Retention::run_retention_sweep();

		$this->assertGreaterThanOrEqual( 1, $deleted );
		$this->assertNull( get_post( $old ) );
		$this->assertNotNull( get_post( $recent ) );
	}
}
