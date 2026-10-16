<?php
/**
 * F&B Report Exporter Tests — CSV flattening + client-alias resolution.
 *
 * The CSV path is deterministic and needs no shell/network, so it is pinned
 * here. The PDF path gates behind the document-generation toolkit and is
 * covered only for graceful absence (no pdfkit bundle in the test bootstrap).
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B report exporter.
 */
class Test_Fnb_Report_Exporter extends WP_UnitTestCase {

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

		if ( ! defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			define( 'WP_MCP_AI_FNB_USE_FIXTURES', true );
		}

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';

		foreach (
			array(
				'class-wp-mcp-ai-fnb-settings.php',
				'class-wp-mcp-ai-fnb-tenant-profile.php',
				'class-wp-mcp-ai-fnb-table-reader.php',
				'class-wp-mcp-ai-fnb-data-source.php',
				'class-wp-mcp-ai-fnb-fixture-reader.php',
				'class-wp-mcp-ai-fnb-metrics.php',
				'class-wp-mcp-ai-fnb-report-builder.php',
				'class-wp-mcp-ai-fnb-csv-exporter.php',
				'class-wp-mcp-ai-fnb-report-exporter.php',
			) as $file
		) {
			$path = $this->toolkit_dir . '/' . $file;
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
		parent::tearDown();
	}

	/**
	 * Invoke a private method via reflection.
	 *
	 * @param object $obj    Instance.
	 * @param string $method Method.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	private function invoke_private( $obj, $method, array $args = array() ) {
		$ref = new ReflectionMethod( $obj, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $obj, $args );
	}

	/**
	 * Test: the client report aliases resolve onto canonical report IDs.
	 */
	public function test_client_aliases_resolve() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Report_Exporter' ) ) {
			$this->markTestSkipped( 'F&B report exporter not available' );
		}

		$this->assertSame( 'R-14', WP_MCP_AI_Fnb_Report_Exporter::resolve_report_id( 'client-r01' ) );
		$this->assertSame( 'R-05', WP_MCP_AI_Fnb_Report_Exporter::resolve_report_id( 'client-r02' ) );
		$this->assertSame( 'R-08', WP_MCP_AI_Fnb_Report_Exporter::resolve_report_id( 'client-r03' ) );
		// Canonical IDs pass through unchanged.
		$this->assertSame( 'R-01', WP_MCP_AI_Fnb_Report_Exporter::resolve_report_id( 'R-01' ) );
	}

	/**
	 * Test: R-14 (monthly sales report) builds with headline + revenue sections.
	 */
	public function test_monthly_sales_report_builds() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Report_Builder' ) ) {
			$this->markTestSkipped( 'F&B report builder not available' );
		}

		$report = WP_MCP_AI_Fnb_Report_Builder::build( 'R-14', array( 'month' => '2026-10' ) );
		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertSame( 'Monthly sales report', $report['title'] );
		$this->assertNotEmpty( $report['sections'] );
	}

	/**
	 * Test: CSV flattening yields a header + section rows with key/value split.
	 */
	public function test_csv_flattening() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Report_Exporter' ) ) {
			$this->markTestSkipped( 'F&B report exporter not available' );
		}

		$exporter = new WP_MCP_AI_Fnb_Report_Exporter();
		$report   = array(
			'title'    => 'Test',
			'sections' => array(
				array(
					'heading' => 'Headline',
					'lines'   => array( 'Revenue: 123.00', 'Covers: 45' ),
				),
			),
		);

		$rows = $this->invoke_private( $exporter, 'flatten', array( $report ) );

		$this->assertSame( array( 'section', 'key', 'value' ), $rows[0] );
		$this->assertSame( array( 'Headline', 'Revenue', '123.00' ), $rows[1] );
		$this->assertSame( array( 'Headline', 'Covers', '45' ), $rows[2] );
	}

	/**
	 * Test: PDF export either produces a stable envelope (success + file/url) or
	 * returns a graceful WP_Error — never a malformed result.
	 */
	public function test_pdf_export_stable_envelope() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Report_Exporter' ) ) {
			$this->markTestSkipped( 'F&B report exporter not available' );
		}

		$result = WP_MCP_AI_Fnb_Report_Exporter::to_pdf( 'R-14', array( 'month' => '2026-10' ) );

		if ( is_wp_error( $result ) ) {
			// Acceptable: the HTML→PDF engine is missing or a gate (shell tools /
			// capability) is unmet in this bootstrap. Either way it must surface as
			// a WP_Error, not a malformed success array.
			$this->assertNotEmpty( $result->get_error_code() );
			return;
		}

		// Success: the envelope must carry the stable keys.
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'R-14', $result['report_id'] );
		$this->assertSame( 'pdf', $result['mode'] );
		$this->assertTrue(
			isset( $result['attachment_id'] ) || isset( $result['url'] ) || isset( $result['file'] ),
			'PDF export must expose a location (attachment_id/url/file).'
		);
	}
}
