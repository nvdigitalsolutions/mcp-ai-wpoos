<?php
/**
 * F&B Universal Ingestor Tests — schema-free folder role assignment.
 *
 * The Drive-facing scan is exercised through reflection (no network in the
 * test bootstrap); the header-scoring and role-assignment logic is the part
 * worth pinning here.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B universal reader role assignment.
 */
class Test_Fnb_Universal_Reader extends WP_UnitTestCase {

	/**
	 * Path to the toolkit services.
	 *
	 * @var string
	 */
	private $toolkit_dir;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-settings.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Tenant_Profile' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-tenant-profile.php';
		}
		if ( ! interface_exists( 'WP_MCP_AI_Fnb_Table_Reader' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-table-reader.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-data-source.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
			require_once $this->toolkit_dir . '/class-wp-mcp-ai-fnb-universal-reader.php';
		}
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_fnb_settings' );
		parent::tearDown();
	}

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param object $obj    Instance.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke_private( $obj, $method, array $args = array() ) {
		$ref = new ReflectionMethod( $obj, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $obj, $args );
	}

	/**
	 * Test: a recognizable "daily sales" header maps to table 6.0.
	 */
	public function test_assign_role_recognizes_daily_sales() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
			$this->markTestSkipped( 'F&B universal reader not available' );
		}

		$reader = new WP_MCP_AI_Fnb_Universal_Reader();

		$file    = array( 'id' => 'x', 'name' => 'final_sales.csv', 'mimeType' => 'text/csv' );
		$columns = array( 'date', 'month', 'item_id', 'item', 'type', 'qty_sold', 'price', 'revenue' );
		$assigned = $this->invoke_private( $reader, 'assign_role', array( $file, $columns ) );

		$this->assertSame( '6.0', $assigned['table_id'] );
		$this->assertGreaterThan( 0.5, $assigned['confidence'] );
	}

	/**
	 * Test: a legacy `_lkr` header still resolves (aliases) to table 6.0.
	 */
	public function test_assign_role_recognizes_legacy_lkr_headers() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
			$this->markTestSkipped( 'F&B universal reader not available' );
		}

		$reader   = new WP_MCP_AI_Fnb_Universal_Reader();
		$file     = array( 'id' => 'x', 'name' => 'sales_may.csv', 'mimeType' => 'text/csv' );
		$columns  = array( 'date', 'month', 'item_id', 'type', 'qty_sold', 'price_lkr', 'revenue_lkr' );
		$assigned = $this->invoke_private( $reader, 'assign_role', array( $file, $columns ) );

		$this->assertSame( '6.0', $assigned['table_id'] );
	}

	/**
	 * Test: a name-only hint maps a bare file to its role at low confidence.
	 */
	public function test_assign_role_falls_back_to_name_hint() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
			$this->markTestSkipped( 'F&B universal reader not available' );
		}

		$reader   = new WP_MCP_AI_Fnb_Universal_Reader();
		$file     = array( 'id' => 'x', 'name' => 'menu.csv', 'mimeType' => 'text/csv' );
		$columns  = array( 'col_a', 'col_b' );
		$assigned = $this->invoke_private( $reader, 'assign_role', array( $file, $columns ) );

		$this->assertSame( '1.0', $assigned['table_id'] );
		$this->assertSame( 0.2, $assigned['confidence'] );
	}

	/**
	 * Test: the curated HEADER_ALIASES dictionary resolves real-world variants.
	 */
	public function test_header_alias_dictionary_resolves_variants() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Data_Source' ) ) {
			$this->markTestSkipped( 'F&B data source not available' );
		}

		$map = array(
			'inv no'          => 'invoice',
			'invoice number'  => 'invoice',
			'quantity sold'   => 'qty_sold',
			'units sold'      => 'qty_sold',
			'sales'           => 'revenue',
			'gross'           => 'revenue',
			'net'             => 'revenue',
			'unit price'      => 'price',
			'expense amount'  => 'amount',
			'entry id'        => 'entry_id',
			'sku'             => 'item_id',
		);

		foreach ( $map as $header => $expected ) {
			$this->assertSame( $expected, WP_MCP_AI_Fnb_Data_Source::resolve_header_alias( $header ), "Header '$header' should resolve to '$expected'" );
		}
	}

	/**
	 * Test: the universal reader maps messy invoice headers via the dictionary.
	 */
	public function test_assign_role_uses_alias_dictionary() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
			$this->markTestSkipped( 'F&B universal reader not available' );
		}

		$reader   = new WP_MCP_AI_Fnb_Universal_Reader();
		$file     = array( 'id' => 'x', 'name' => 'supplier_invoices.csv', 'mimeType' => 'text/csv' );
		// Real-world invoice-adjacent headers that map onto the expense-ledger
		// canonical keys via the dictionary.
		$columns  = array( 'entry id', 'vendor', 'inv no', 'expense line', 'group', 'for month', 'invoice date', 'expense amount', 'due date', 'paid date', 'status' );
		$assigned = $this->invoke_private( $reader, 'assign_role', array( $file, $columns ) );

		$this->assertSame( '14.0', $assigned['table_id'] );
	}

	/**
	 * Test: an operator-authored table_map hint outranks header scoring.
	 */
	public function test_assign_role_honors_operator_table_map() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
			$this->markTestSkipped( 'F&B universal reader not available' );
		}

		update_option(
			'wp_mcp_ai_fnb_settings',
			array(
				'table_map' => array(
					'1.0' => array( 'file' => 'Our Menu October' ),
				),
			)
		);

		$reader = new WP_MCP_AI_Fnb_Universal_Reader();

		// Sales-shaped headers, but the operator pinned this file to 1.0 — the
		// explicit hint must win with full confidence.
		$file     = array(
			'id'       => 'x',
			'name'     => 'Our_Menu_October.csv',
			'mimeType' => 'text/csv',
		);
		$columns  = array( 'date', 'month', 'item_id', 'item', 'type', 'qty_sold', 'price', 'revenue' );
		$assigned = $this->invoke_private( $reader, 'assign_role', array( $file, $columns ) );

		$this->assertSame( '1.0', $assigned['table_id'] );
		$this->assertSame( 1.0, $assigned['confidence'] );
	}

	/**
	 * Test: a table_map hint for a different file leaves auto-mapping intact.
	 */
	public function test_assign_role_ignores_unrelated_table_map_entries() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Universal_Reader' ) ) {
			$this->markTestSkipped( 'F&B universal reader not available' );
		}

		update_option(
			'wp_mcp_ai_fnb_settings',
			array(
				'table_map' => array(
					'1.0' => array( 'file' => 'Menu_October' ),
				),
			)
		);

		$reader   = new WP_MCP_AI_Fnb_Universal_Reader();
		$file     = array(
			'id'       => 'x',
			'name'     => 'final_sales.csv',
			'mimeType' => 'text/csv',
		);
		$columns  = array( 'date', 'month', 'item_id', 'item', 'type', 'qty_sold', 'price', 'revenue' );
		$assigned = $this->invoke_private( $reader, 'assign_role', array( $file, $columns ) );

		$this->assertSame( '6.0', $assigned['table_id'] );
	}
}
