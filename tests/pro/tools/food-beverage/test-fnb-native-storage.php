<?php
/**
 * F&B Native Storage Tests — Phase 9 CPT layer.
 *
 * Covers: CPT registration gate, idempotent upserts, before/after log hooks,
 * the CPT reader adapter, native dispatch in the data source, and the
 * fnb_import_table import bridge (capability gate + idempotency).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B native storage.
 */
class Test_Fnb_Native_Storage extends WP_UnitTestCase {

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

		// Force the CSV fixture adapter (no network) for the primary backend.
		if ( ! defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );
		}

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		$this->require_services();

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_fnb_settings' );
		parent::tearDown();
	}

	/**
	 * Require the toolkit services and CPT classes.
	 *
	 * @return void
	 */
	private function require_services() {
		$base = $this->toolkit_dir . '/';
		foreach (
			array(
				'class-wp-mcp-ai-fnb-settings.php',
				'class-wp-mcp-ai-fnb-table-reader.php',
				'class-wp-mcp-ai-fnb-data-source.php',
				'class-wp-mcp-ai-fnb-fixture-reader.php',
				'trait-wp-mcp-ai-fnb-native-cpt-storage.php',
				'class-wp-mcp-ai-fnb-cpt-reader.php',
				'class-wp-mcp-ai-fnb-sale-cpt.php',
				'class-wp-mcp-ai-fnb-purchase-cpt.php',
				'class-wp-mcp-ai-fnb-stock-count-cpt.php',
				'class-wp-mcp-ai-fnb-waste-cpt.php',
				'class-wp-mcp-ai-fnb-booking-cpt.php',
			) as $file
		) {
			if ( file_exists( $base . $file ) ) {
				require_once $base . $file;
			}
		}
	}

	/**
	 * Toggle native storage on.
	 *
	 * @return void
	 */
	private function enable_native_storage() {
		update_option(
			'wp_mcp_ai_fnb_settings',
			array( 'enable_fnb_native_storage' => true )
		);
	}

	/**
	 * Test: CPT registration is gated on the native-storage toggle.
	 */
	public function test_cpt_registration_gated_on_toggle() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Sale_CPT' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}

		// Toggle off: registration is a no-op.
		unregister_post_type( 'mcp_ai_fnb_sale' );
		update_option( 'wp_mcp_ai_fnb_settings', array( 'enable_fnb_native_storage' => false ) );
		WP_MCP_AI_Fnb_Sale_CPT::register();
		$this->assertFalse( post_type_exists( 'mcp_ai_fnb_sale' ), 'CPT must not register while native storage is off.' );

		// Toggle on: CPT registers with the toolkit's admin-surface args.
		$this->enable_native_storage();
		WP_MCP_AI_Fnb_Sale_CPT::register();

		$this->assertTrue( post_type_exists( 'mcp_ai_fnb_sale' ) );
		$object = get_post_type_object( 'mcp_ai_fnb_sale' );
		$this->assertTrue( $object->show_ui, 'Native CPTs are editable in wp-admin.' );
		$this->assertFalse( $object->show_in_menu, 'Native CPTs attach to the F&B Management menu, not their own.' );
		$this->assertTrue( $object->map_meta_cap );
	}

	/**
	 * Test: upserts are idempotent and fire the before/after log hooks.
	 */
	public function test_upsert_is_idempotent_and_hooks_fire() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Sale_CPT' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}

		$this->enable_native_storage();
		WP_MCP_AI_Fnb_Sale_CPT::register();

		$before_calls = array();
		$after_calls  = array();
		add_action(
			'wp_mcp_ai_fnb_before_sale_log',
			function ( $row, $action, $existing_id ) use ( &$before_calls ) {
				$before_calls[] = array( $row, $action, $existing_id );
			},
			10,
			3
		);
		add_action(
			'wp_mcp_ai_fnb_after_sale_log',
			function ( $post_id, $row, $action ) use ( &$after_calls ) {
				$after_calls[] = array( $post_id, $row, $action );
			},
			10,
			3
		);

		$row = array(
			'date'        => '2026-10-01 00:00:00',
			'month'       => '2026-10',
			'item_id'     => 'B-01',
			'item'        => 'Gold standard',
			'type'        => 'Beverage',
			'qty_sold'    => '2',
			'price_lkr'   => '2700',
			'revenue_lkr' => '5400',
		);

		$first = WP_MCP_AI_Fnb_Sale_CPT::upsert( $row );
		$this->assertSame( 'insert', $first['action'] );
		$this->assertSame( 'B-01', get_post_meta( $first['post_id'], '_fnb_item_id', true ) );

		// Same key with a changed value updates in place — no duplicate row.
		$row['revenue_lkr'] = '5500';
		$second             = WP_MCP_AI_Fnb_Sale_CPT::upsert( $row );
		$this->assertSame( 'update', $second['action'] );
		$this->assertSame( $first['post_id'], $second['post_id'] );

		$count = wp_count_posts( 'mcp_ai_fnb_sale' );
		$this->assertSame( 1, (int) $count->publish );

		$this->assertCount( 2, $before_calls );
		$this->assertCount( 2, $after_calls );
		$this->assertSame( 'insert', $before_calls[0][1] );
		$this->assertSame( 'update', $before_calls[1][1] );
		$this->assertNull( $before_calls[0][2] );
		$this->assertSame( $first['post_id'], $before_calls[1][2] );
	}

	/**
	 * Test: the CPT reader returns canonical columns + aligned rows.
	 */
	public function test_cpt_reader_returns_canonical_rows() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Cpt_Reader' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}

		$this->assertTrue( WP_MCP_AI_Fnb_Cpt_Reader::is_native_table( '9.0' ) );
		$this->assertSame( 'mcp_ai_fnb_stock', WP_MCP_AI_Fnb_Cpt_Reader::post_type_for( '9.0' ) );
		$this->assertFalse( WP_MCP_AI_Fnb_Cpt_Reader::is_native_table( '1.0' ) );

		$this->enable_native_storage();
		$registered = WP_MCP_AI_Fnb_Stock_Count_CPT::register();
		$this->assertTrue( $registered, 'Stock-count CPT must register (post-type keys are capped at 20 chars).' );

		WP_MCP_AI_Fnb_Stock_Count_CPT::upsert(
			array(
				'count_date'    => '2026-09-30 00:00:00',
				'count_type'    => 'Full',
				'ingredient_id' => 'IG-01',
				'opening'       => '10',
				'closing'       => '4',
			)
		);
		WP_MCP_AI_Fnb_Stock_Count_CPT::upsert(
			array(
				'count_date'    => '2026-09-30 00:00:00',
				'count_type'    => 'Full',
				'ingredient_id' => 'IG-02',
				'opening'       => '20',
				'closing'       => '6',
			)
		);

		$reader = new WP_MCP_AI_Fnb_Cpt_Reader();
		$table  = $reader->read_table( '9.0' );

		$this->assertNotInstanceOf( 'WP_Error', $table );
		$this->assertSame( 'count_date', $table['columns'][0] );
		$this->assertContains( 'closing_value_lkr', $table['columns'], 'Reader exposes the full canonical column list.' );
		$this->assertCount( 2, $table['rows'] );

		$index_ingredient = array_search( 'ingredient_id', $table['columns'], true );
		$values           = wp_list_pluck( $table['rows'], $index_ingredient );
		$this->assertEqualSets( array( 'IG-01', 'IG-02' ), $values );
	}

	/**
	 * Test: the data source resolves native tables from CPTs and keeps the
	 * primary backend for reference tables.
	 */
	public function test_data_source_native_dispatch() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}

		$this->enable_native_storage();
		WP_MCP_AI_Fnb_Sale_CPT::register();
		WP_MCP_AI_Fnb_Sale_CPT::upsert(
			array(
				'date'        => '2026-10-01 00:00:00',
				'item_id'     => 'B-01',
				'type'        => 'Beverage',
				'revenue_lkr' => '5400',
			)
		);

		// Native dispatch: transactional table resolves from the CPT.
		$native = WP_MCP_AI_Fnb_Data_Source::get_table( '6.0' );
		$this->assertSame( 'fnb_native_cpt', $native['source'] );
		$this->assertSame( 1, $native['count'] );
		$this->assertContains( 'item_id', $native['columns'] );

		// Reference tables keep the primary (fixture) backend.
		$reference = WP_MCP_AI_Fnb_Data_Source::get_table( '1.0' );
		$this->assertNotInstanceOf( 'WP_Error', $reference );
		$this->assertSame( 'fnb_data_source', $reference['source'] );

		// Forced primary backend reads the source rows, not the CPT.
		$forced = WP_MCP_AI_Fnb_Data_Source::get_table( '6.0', false );
		$this->assertSame( 'fnb_data_source', $forced['source'] );
		$this->assertGreaterThan( 1, $forced['count'], 'Source CSV has many rows.' );
	}

	/**
	 * Test: import bridge is ACT-tier (manage_options) and idempotent.
	 */
	public function test_import_tool_gate_and_idempotency() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Fnb_Import_Table' ) ) {
			$this->markTestSkipped( 'F&B toolkit not available' );
		}

		$tool_file = $this->toolkit_dir . '/class-wp-mcp-ai-tool-fnb-import-table.php';
		if ( file_exists( $tool_file ) ) {
			require_once $tool_file;
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Fnb_Import_Table' ) ) {
			$this->markTestSkipped( 'F&B import tool not available' );
		}

		$this->enable_native_storage();
		WP_MCP_AI_Fnb_Sale_CPT::register();

		$tool = new WP_MCP_AI_Tool_Fnb_Import_Table();
		$this->assertSame( 'manage_options', $tool->get_required_capability(), 'ACT-tier gate: only site managers import.' );
		$this->assertContains( 'database-write', $tool->get_capability_flags() );

		// A subscriber cannot import.
		$subscriber = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$denied = $tool->execute( array( 'table_id' => '6.0' ), array() );
		$this->assertInstanceOf( 'WP_Error', $denied );

		// A reference table cannot be imported.
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$bad_table = $tool->execute( array( 'table_id' => '1.0' ), array() );
		$this->assertInstanceOf( 'WP_Error', $bad_table );

		// Dry run previews without writing.
		$dry = $tool->execute(
			array(
				'table_id' => '6.0',
				'dry_run'  => true,
				'limit'    => 3,
			),
			array()
		);
		$this->assertTrue( $dry['success'] );
		$this->assertTrue( $dry['dry_run'] );
		$this->assertCount( 3, $dry['preview'] );
		$this->assertSame( 0, (int) wp_count_posts( 'mcp_ai_fnb_sale' )->publish );

		// First import inserts; re-import updates the same rows.
		$first = $tool->execute(
			array(
				'table_id' => '6.0',
				'limit'    => 3,
			),
			array()
		);
		$this->assertTrue( $first['success'] );
		$this->assertSame( 3, $first['summary']['inserted'] );
		$this->assertSame( 0, $first['summary']['updated'] );

		$second = $tool->execute(
			array(
				'table_id' => '6.0',
				'limit'    => 3,
			),
			array()
		);
		$this->assertTrue( $second['success'] );
		$this->assertSame( 0, $second['summary']['inserted'] );
		$this->assertSame( 3, $second['summary']['updated'] );

		$this->assertSame( 3, (int) wp_count_posts( 'mcp_ai_fnb_sale' )->publish, 'Re-import must not duplicate rows.' );
	}

	/**
	 * Test: the import tool refuses to run while native storage is disabled.
	 */
	public function test_import_tool_blocked_when_native_disabled() {
		$tool_file = $this->toolkit_dir . '/class-wp-mcp-ai-tool-fnb-import-table.php';
		if ( ! file_exists( $tool_file ) ) {
			$this->markTestSkipped( 'F&B import tool not available' );
		}
		require_once $tool_file;
		if ( ! class_exists( 'WP_MCP_AI_Tool_Fnb_Import_Table' ) ) {
			$this->markTestSkipped( 'F&B import tool not available' );
		}

		update_option( 'wp_mcp_ai_fnb_settings', array( 'enable_fnb_native_storage' => false ) );

		$tool   = new WP_MCP_AI_Tool_Fnb_Import_Table();
		$result = $tool->execute(
			array(
				'table_id' => '6.0',
				'limit'    => 1,
			),
			array()
		);
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test: JetEngine meta schemas cover the five native CPTs.
	 */
	public function test_jetengine_meta_schemas_registered() {
		$schema_file = dirname( __DIR__, 4 ) . '/addons/pro/includes/class-wp-mcp-ai-pro-cpt-meta-schema.php';
		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' ) && file_exists( $schema_file ) ) {
			require_once $schema_file;
		}
		if ( ! class_exists( 'WP_MCP_AI_Pro_CPT_Meta_Schema' ) ) {
			$this->markTestSkipped( 'Pro CPT meta schema registry not available' );
		}

		$probes = array(
			'mcp_ai_fnb_sale'     => '_fnb_revenue_lkr',
			'mcp_ai_fnb_purchase' => '_fnb_line_total_lkr',
			'mcp_ai_fnb_stock'    => '_fnb_closing_value_lkr',
			'mcp_ai_fnb_waste'    => '_fnb_value_lkr',
			'mcp_ai_fnb_booking'  => '_fnb_booking_id',
		);

		foreach ( $probes as $post_type => $probe_field ) {
			$schema = WP_MCP_AI_Pro_CPT_Meta_Schema::get( $post_type );
			$this->assertArrayHasKey( $probe_field, $schema, $post_type . ' must expose its canonical fields to JetEngine' );
		}
	}
}
