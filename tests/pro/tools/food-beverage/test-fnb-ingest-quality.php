<?php
/**
 * F&B Ingest Quality Tests — dedupe + out-of-band flags.
 *
 * Covers: near-duplicate detection (numeric tolerance, missing-key semantics),
 * negative-quantity and zero-price flags.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B ingest quality.
 */
class Test_Fnb_Ingest_Quality extends WP_UnitTestCase {

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

		foreach (
			array(
				'class-wp-mcp-ai-fnb-settings.php',
				'class-wp-mcp-ai-fnb-table-reader.php',
				'class-wp-mcp-ai-fnb-data-source.php',
				'class-wp-mcp-ai-fnb-ingest-quality.php',
			) as $file
		) {
			$path = $this->toolkit_dir . '/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Identical key columns within tolerance are a near-duplicate.
	 */
	public function test_near_duplicate_same_keys() {
		$existing = array(
			'date'    => '2026-10-01',
			'item_id' => 'IG-01',
			'type'    => 'food',
		);
		$incoming = array(
			'date'    => '2026-10-01',
			'item_id' => 'IG-01',
			'type'    => 'food',
		);

		$result = WP_MCP_AI_Fnb_Ingest_Quality::is_near_duplicate( '6.0', $existing, $incoming, array( 'date', 'item_id', 'type' ) );

		$this->assertTrue( $result );
	}

	/**
	 * A missing key on the existing row means incoming adds data → not a duplicate.
	 */
	public function test_not_duplicate_when_existing_missing_key() {
		$existing = array(
			'date'    => '',
			'item_id' => 'IG-01',
		);
		$incoming = array(
			'date'    => '2026-10-01',
			'item_id' => 'IG-01',
		);

		$result = WP_MCP_AI_Fnb_Ingest_Quality::is_near_duplicate( '6.0', $existing, $incoming, array( 'date', 'item_id' ) );

		$this->assertFalse( $result );
	}

	/**
	 * Numeric divergence beyond tolerance is not a duplicate.
	 */
	public function test_not_duplicate_when_value_differs() {
		$existing = array(
			'date'     => '2026-10-01',
			'qty_sold' => '5',
		);
		$incoming = array(
			'date'     => '2026-10-01',
			'qty_sold' => '7',
		);

		$result = WP_MCP_AI_Fnb_Ingest_Quality::is_near_duplicate( '6.0', $existing, $incoming, array( 'date', 'qty_sold' ) );

		$this->assertFalse( $result );
	}

	/**
	 * A negative quantity flags high severity.
	 */
	public function test_negative_quantity_flag() {
		$flags = WP_MCP_AI_Fnb_Ingest_Quality::assess_row(
			'6.0',
			array(
				'qty_sold'  => -3,
				'price_lkr' => 100,
			)
		);

		$this->assertNotEmpty( $flags );
		$this->assertSame( 'negative_qty_sold', $flags[0]['slug'] );
		$this->assertSame( 'high', $flags[0]['severity'] );
	}

	/**
	 * A zero price on a sold line flags medium severity.
	 */
	public function test_zero_price_flag() {
		$flags = WP_MCP_AI_Fnb_Ingest_Quality::assess_row(
			'6.0',
			array(
				'qty_sold'  => 5,
				'price_lkr' => 0,
			)
		);

		$this->assertNotEmpty( $flags );
		$this->assertSame( 'zero_price_lkr', $flags[0]['slug'] );
	}

	/**
	 * A clean row produces no flags.
	 */
	public function test_clean_row_no_flags() {
		$flags = WP_MCP_AI_Fnb_Ingest_Quality::assess_row(
			'6.0',
			array(
				'qty_sold'  => 5,
				'price_lkr' => 100,
			)
		);

		$this->assertEmpty( $flags );
	}
}
