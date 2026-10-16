<?php
/**
 * F&B Multi-format Loader Tests — CSV/TSV/JSON/JSONL → matrix.
 *
 * Covers: delimiter detection, delimited parsing, JSON array-of-objects,
 * JSONL, BOM handling, and format reporting.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B multi-format loader.
 */
class Test_Fnb_Loader extends WP_UnitTestCase {

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
		$path              = $this->toolkit_dir . '/class-wp-mcp-ai-fnb-loader.php';
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}

	/**
	 * A comma-delimited CSV parses to the expected header + one row.
	 */
	public function test_csv_parses_header_and_rows() {
		$raw = "Date,Item ID,Qty Sold\n2026-10-01,IG-01,5\n";

		$result = WP_MCP_AI_Fnb_Loader::load( $raw, 'csv' );

		$this->assertSame( array( 'Date', 'Item ID', 'Qty Sold' ), $result['columns'] );
		$this->assertCount( 1, $result['rows'] );
		$this->assertSame( '2026-10-01', $result['rows'][0][0] );
		$this->assertSame( 'csv', $result['format'] );
	}

	/**
	 * A tab-delimited input correctly auto-detects TSV.
	 */
	public function test_tsv_auto_detected() {
		$raw = "Date\tItem ID\tQty Sold\n2026-10-01\tIG-01\t5";

		$result = WP_MCP_AI_Fnb_Loader::load( $raw );

		$this->assertSame( 'tsv', $result['format'] );
		$this->assertSame( array( 'Date', 'Item ID', 'Qty Sold' ), $result['columns'] );
	}

	/**
	 * JSON array-of-objects infers headers from keys and preserves order.
	 */
	public function test_json_array_of_objects() {
		$raw = '[{"date":"2026-10-01","item_id":"IG-01","qty":5}, {"date":"2026-10-02","item_id":"IG-02","qty":3}]';

		$result = WP_MCP_AI_Fnb_Loader::load( $raw, 'json' );

		$this->assertSame( array( 'date', 'item_id', 'qty' ), $result['columns'] );
		$this->assertCount( 2, $result['rows'] );
		$this->assertSame( '5', $result['rows'][0][2] );
	}

	/**
	 * JSONL parses one object per line.
	 */
	public function test_jsonl() {
		$raw = "{\"date\":\"2026-10-01\",\"item_id\":\"IG-01\"}\n{\"date\":\"2026-10-02\",\"item_id\":\"IG-02\"}";

		$result = WP_MCP_AI_Fnb_Loader::load( $raw, 'jsonl' );

		$this->assertSame( array( 'date', 'item_id' ), $result['columns'] );
		$this->assertCount( 2, $result['rows'] );
	}

	/**
	 * A UTF-8 BOM is stripped without corrupting the header.
	 */
	public function test_utf8_bom_stripped() {
		$raw = "\xEF\xBB\xBFDate,Item ID\n2026-10-01,IG-01";

		$result = WP_MCP_AI_Fnb_Loader::load( $raw, 'csv' );

		$this->assertSame( 'Date', $result['columns'][0] );
	}

	/**
	 * A single JSON object is wrapped into a one-record matrix.
	 */
	public function test_single_json_object() {
		$raw = '{"date":"2026-10-01","item_id":"IG-01"}';

		$result = WP_MCP_AI_Fnb_Loader::load( $raw, 'json' );

		$this->assertSame( array( 'date', 'item_id' ), $result['columns'] );
		$this->assertCount( 1, $result['rows'] );
	}

	/**
	 * A PDF file without the parser library yields an actionable error.
	 */
	public function test_pdf_without_parser_errors() {
		if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
			$this->markTestSkipped( 'smalot/pdfparser is installed — covered by the integration path.' );
		}

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_pdf_' ) . '.pdf';
		file_put_contents( $tmp, '%PDF-1.4 fake' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_fnb_pdf_parser_missing', $result->get_error_code() );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * A photo/image extension yields a clear "needs OCR" error.
	 */
	public function test_photo_needs_ocr_error() {
		$tmp = tempnam( sys_get_temp_dir(), 'fnb_photo_' ) . '.png';
		file_put_contents( $tmp, 'not-a-real-png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_fnb_photo_needs_ocr', $result->get_error_code() );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * A spreadsheet file without PhpSpreadsheet loaded yields an actionable error.
	 */
	public function test_xlsx_without_phpspreadsheet_errors() {
		if ( class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			$this->markTestSkipped( 'PhpSpreadsheet is loaded — covered by the integration path.' );
		}

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_xlsx_' ) . '.xlsx';
		file_put_contents( $tmp, 'not-a-real-xlsx' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_fnb_phpspreadsheet_missing', $result->get_error_code() );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * An ODS extension routes to the spreadsheet loader (not raw read).
	 */
	public function test_ods_routes_to_spreadsheet_loader() {
		$tmp = tempnam( sys_get_temp_dir(), 'fnb_ods_' ) . '.ods';
		file_put_contents( $tmp, 'not-a-real-ods' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		// Verify routing: either a clean matrix or a spreadsheet error — never a
		// raw delimited parse (which would indicate the .ods branch was missed).
		if ( is_wp_error( $result ) ) {
			$this->assertContains( $result->get_error_code(), array( 'wp_mcp_ai_fnb_phpspreadsheet_missing', 'wp_mcp_ai_fnb_xlsx_extract_failed' ) );
		} else {
			$this->assertArrayHasKey( 'columns', $result );
			$this->assertArrayHasKey( 'rows', $result );
			$this->assertSame( 'xlsx', $result['format'] );
		}

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}
