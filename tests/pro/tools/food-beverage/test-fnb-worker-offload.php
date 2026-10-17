<?php
/**
 * F&B Media Worker Offload Tests (Proposal 079).
 *
 * Covers the loader's sidecar seam: worker matrices are normalised into the
 * loader contract, worker failures fall back to the local parsers, PDF/photo
 * envelopes convert through the delimited path, and the real client refuses
 * to offload when no sidecar is configured or the setting is off. All worker
 * traffic is faked via the wp_mcp_ai_fnb_worker_client filter - zero HTTP.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: F&B worker offload seam.
 */
class Test_Fnb_Worker_Offload extends WP_UnitTestCase {

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

		$stub = dirname( __DIR__, 3 ) . '/helpers/class-fnb-worker-client-fake.php';
		if ( file_exists( $stub ) ) {
			require_once $stub;
		}

		$this->toolkit_dir = dirname( __DIR__, 4 ) . '/addons/pro/includes/tools/food-beverage';
		foreach (
			array(
				'class-wp-mcp-ai-fnb-loader.php',
				'class-wp-mcp-ai-fnb-worker-client.php',
				'class-wp-mcp-ai-fnb-settings.php',
			) as $file
		) {
			$path = $this->toolkit_dir . '/' . $file;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * Clean up filter and option state between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_fnb_worker_client' );
		remove_all_filters( 'wp_mcp_ai_fnb_worker_enabled' );
		remove_all_filters( 'wp_mcp_ai_fnb_ocr_confidence_floor' );
		remove_all_filters( 'wp_mcp_ai_fnb_ocr_vision_fallback' );
		remove_all_filters( 'wp_mcp_ai_fnb_vision_ocr_result' );
		delete_option( 'wp_mcp_ai_fnb_settings' );
		delete_option( 'wp_mcp_ai_media_worker_url' );

		parent::tearDown();
	}

	/**
	 * Inject a fake worker client returning the given payload.
	 *
	 * @param mixed $payload Payload returned by parse_file().
	 * @return Fnb_Worker_Client_Fake The injected fake (for call-state asserts).
	 */
	private function with_fake_worker( $payload ) {
		$fake = new Fnb_Worker_Client_Fake();
		add_filter(
			'wp_mcp_ai_fnb_worker_client',
			function () use ( $fake, $payload ) {
				$fake->payload = $payload;

				return $fake;
			}
		);

		return $fake;
	}

	/**
	 * A worker xlsx envelope normalises into the matrix contract.
	 */
	public function test_worker_xlsx_matrix_is_returned() {
		$this->with_fake_worker(
			array(
				'success' => true,
				'format'  => 'xlsx',
				'columns' => array( 'Date', 'Qty' ),
				'rows'    => array( array( '2026-10-01', '5' ) ),
				'meta'    => array( 'engine' => 'exceljs' ),
			)
		);

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_wx_' ) . '.xlsx';
		file_put_contents( $tmp, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'Date', 'Qty' ), $result['columns'] );
		$this->assertSame( 'xlsx', $result['format'] );
		$this->assertSame( 'exceljs', $result['meta']['engine'] );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * A worker failure falls through to the local parser unchanged.
	 *
	 * The local path output is environment-dependent: with PhpSpreadsheet
	 * loaded, IOFactory content-sniffs the fixture and may parse it as CSV
	 * (a one-cell matrix); without it, the loader returns its actionable
	 * missing-library error. Either way the worker's WP_Error must be
	 * swallowed and the seam exercised exactly once.
	 */
	public function test_worker_error_falls_back_to_local_parser() {
		$fake = $this->with_fake_worker( new WP_Error( 'wp_mcp_ai_sidecar_error', 'boom' ) );

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_wx_' ) . '.xlsx';
		file_put_contents( $tmp, 'not-a-real-xlsx' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		if ( is_wp_error( $result ) ) {
			$this->assertContains( $result->get_error_code(), array( 'wp_mcp_ai_fnb_phpspreadsheet_missing', 'wp_mcp_ai_fnb_xlsx_extract_failed' ) );
		} else {
			$this->assertArrayHasKey( 'columns', $result );
			$this->assertArrayHasKey( 'rows', $result );
		}
		$this->assertSame( 1, $fake->parse_calls, 'the worker was consulted once before the fallback' );
		$this->assertNotSame( '', $fake->last_path );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * A worker PDF envelope converts through the delimited path.
	 */
	public function test_worker_pdf_text_builds_matrix() {
		$this->with_fake_worker(
			array(
				'success' => true,
				'format'  => 'pdf',
				'text'    => "Date,Item\n2026-10-01,IG-01",
				'columns' => array(),
				'rows'    => array(),
			)
		);

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_wp_' ) . '.pdf';
		file_put_contents( $tmp, '%PDF-1.4 fake' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertIsArray( $result );
		$this->assertSame( 'pdf', $result['format'] );
		$this->assertSame( array( 'Date', 'Item' ), $result['columns'] );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * An empty worker PDF text layer keeps the scanned-PDF error.
	 */
	public function test_worker_empty_pdf_text_errors_scanned() {
		$this->with_fake_worker(
			array(
				'success' => true,
				'format'  => 'pdf',
				'text'    => '',
				'columns' => array(),
				'rows'    => array(),
			)
		);

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_wp_' ) . '.pdf';
		file_put_contents( $tmp, '%PDF-1.4 fake' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_fnb_pdf_scanned', $result->get_error_code() );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * A confident worker photo OCR builds a photo matrix.
	 */
	public function test_worker_photo_high_confidence_builds_matrix() {
		$this->with_fake_worker(
			array(
				'success'    => true,
				'format'     => 'photo',
				'text'       => "Date,Item\n2026-10-01,IG-01",
				'confidence' => 92,
				'columns'    => array(),
				'rows'       => array(),
			)
		);

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_wph_' ) . '.png';
		file_put_contents( $tmp, 'not-a-real-png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertIsArray( $result );
		$this->assertSame( 'photo', $result['format'] );
		$this->assertSame( array( 'Date', 'Item' ), $result['columns'] );
		$this->assertSame( 'sidecar-ocr', $result['meta']['engine'] );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * A low-confidence worker photo keeps the actionable OCR pointer.
	 */
	public function test_worker_photo_low_confidence_keeps_ocr_pointer() {
		$this->with_fake_worker(
			array(
				'success'    => true,
				'format'     => 'photo',
				'text'       => 'some noisy text',
				'confidence' => 40,
				'columns'    => array(),
				'rows'       => array(),
			)
		);

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_wpl_' ) . '.jpg';
		file_put_contents( $tmp, 'not-a-real-jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_fnb_photo_needs_ocr', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 'some noisy text', $data['ocr_text'] );
		$this->assertSame( 40.0, $data['confidence'] );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * The real client never offloads when no sidecar is configured.
	 */
	public function test_real_client_offloads_nothing_without_sidecar() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Worker_Client' ) ) {
			$this->markTestSkipped( 'Worker client unavailable (standalone Pro matrix).' );
		}

		update_option( 'wp_mcp_ai_media_worker_url', '' );
		$client = WP_MCP_AI_Fnb_Worker_Client::get_instance();

		$this->assertFalse( $client->should_offload( __DIR__ . '/fixture.csv' ) );
		$this->assertFalse( $client->should_offload( __DIR__ . '/fixture.xlsx' ) );
	}

	/**
	 * The real client respects the off setting before any sidecar check.
	 */
	public function test_real_client_respects_off_setting() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Worker_Client' ) || ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			$this->markTestSkipped( 'F&B toolkit unavailable (standalone Pro matrix).' );
		}

		update_option( 'wp_mcp_ai_fnb_settings', array( 'use_media_worker' => 'off' ) );
		$client = WP_MCP_AI_Fnb_Worker_Client::get_instance();

		$this->assertFalse( $client->should_offload( __DIR__ . '/fixture.xlsx' ) );
	}

	/**
	 * The vision fallback is opt-in: off by default, the actionable pointer
	 * is returned with no vision attempt.
	 */
	public function test_vision_fallback_off_by_default_keeps_pointer() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			$this->markTestSkipped( 'F&B toolkit unavailable (standalone Pro matrix).' );
		}

		update_option( 'wp_mcp_ai_fnb_settings', array() );
		add_filter(
			'wp_mcp_ai_fnb_vision_ocr_result',
			function () {
				return array(
					'columns' => array( 'Date' ),
					'rows'    => array(),
					'format'  => 'photo',
					'meta'    => array( 'engine' => 'vision-ocr' ),
				);
			}
		);

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_voff_' ) . '.png';
		file_put_contents( $tmp, 'not-a-real-png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_fnb_photo_needs_ocr', $result->get_error_code() );
		$this->assertNull( $result->get_error_data() );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * With the setting on, a preset vision matrix is returned for a photo.
	 */
	public function test_vision_fallback_returns_matrix_when_enabled() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			$this->markTestSkipped( 'F&B toolkit unavailable (standalone Pro matrix).' );
		}

		update_option( 'wp_mcp_ai_fnb_settings', array( 'ocr_vision_fallback' => true ) );
		add_filter(
			'wp_mcp_ai_fnb_vision_ocr_result',
			function () {
				return array(
					'columns' => array( 'Date', 'Item' ),
					'rows'    => array( array( '2026-10-01', 'IG-01' ) ),
					'format'  => 'photo',
					'meta'    => array( 'engine' => 'vision-ocr' ),
				);
			}
		);

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_von_' ) . '.png';
		file_put_contents( $tmp, 'not-a-real-png' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertIsArray( $result );
		$this->assertSame( 'photo', $result['format'] );
		$this->assertSame( 'vision-ocr', $result['meta']['engine'] );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * A failing vision rung keeps the pointer and reports the failure.
	 */
	public function test_vision_fallback_error_keeps_pointer_with_data() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Settings' ) ) {
			$this->markTestSkipped( 'F&B toolkit unavailable (standalone Pro matrix).' );
		}

		update_option( 'wp_mcp_ai_fnb_settings', array( 'ocr_vision_fallback' => true ) );
		add_filter(
			'wp_mcp_ai_fnb_vision_ocr_result',
			function () {
				return new WP_Error( 'wp_mcp_ai_missing_api_key', 'Gemini API key is not configured.' );
			}
		);

		$tmp = tempnam( sys_get_temp_dir(), 'fnb_verr_' ) . '.jpg';
		file_put_contents( $tmp, 'not-a-real-jpg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = WP_MCP_AI_Fnb_Loader::load_file( $tmp );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_fnb_photo_needs_ocr', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 'Gemini API key is not configured.', $data['vision_error'] );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}
