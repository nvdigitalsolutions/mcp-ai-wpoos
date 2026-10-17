<?php
/**
 * OCR service sidecar PDF wiring tests (Proposal 079, Appendix B).
 *
 * Covers the sidecar-first scanned-PDF chain in
 * WP_MCP_AI_OCR_Service::extract_text_from_pdf(): the worker ingest route
 * answers when the provider is auto/tesseract, a pinned vision provider
 * skips the sidecar, and every sidecar failure falls through to the local
 * conversion pipeline. Zero HTTP - the sidecar methods are faked on a
 * subclass.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case: OCR service sidecar PDF chain.
 */
class Test_OCR_Service_Sidecar_Pdf extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$service_path = dirname( __DIR__, 4 ) . '/addons/pro/includes/services/class-wp-mcp-ai-ocr-service.php';
		if ( file_exists( $service_path ) ) {
			require_once $service_path;
		}
	}

	/**
	 * Build a fake sidecar-backed OCR service.
	 *
	 * @param mixed $envelope Response returned by the fake upload.
	 * @return WP_MCP_AI_OCR_Service Service with sidecar faked.
	 */
	private function make_service( $envelope ) {
		return new class( $envelope ) extends WP_MCP_AI_OCR_Service {
			/**
			 * Number of sidecar upload calls.
			 *
			 * @var int
			 */
			public $upload_calls = 0;

			/**
			 * Last upload endpoint.
			 *
			 * @var string
			 */
			public $upload_endpoint = '';

			/**
			 * Upload envelope.
			 *
			 * @var mixed
			 */
			private $envelope;

			/**
			 * Constructor.
			 *
			 * @param mixed $envelope Upload envelope.
			 */
			public function __construct( $envelope ) {
				$this->envelope = $envelope;
			}

			/**
			 * Force the sidecar path.
			 *
			 * @return bool Always true.
			 */
			public function is_sidecar_upload_supported() {
				return true;
			}

			/**
			 * Fake upload returning the envelope.
			 *
			 * @param string $endpoint  API path.
			 * @param string $file_path Local file path.
			 * @param array  $fields    Extra form fields.
			 * @param int    $timeout   Request timeout (unused).
			 * @return array|WP_Error Envelope.
			 */
			protected function sidecar_upload( $endpoint, $file_path, $fields = array(), $timeout = 330 ) {
				unset( $file_path, $fields, $timeout );
				++$this->upload_calls;
				$this->upload_endpoint = $endpoint;

				return $this->envelope;
			}

			/**
			 * Never reached when the sidecar answers - fail loudly if it is.
			 *
			 * @param string $pdf_path PDF path.
			 * @param array  $options  Options.
			 * @return WP_Error Always.
			 */
			protected function convert_pdf_to_images( $pdf_path, $options = array() ) {
				unset( $pdf_path, $options );

				return new WP_Error( 'unexpected_local_convert', 'Local conversion should not run when the sidecar answers.' );
			}
		};
	}

	/**
	 * A worker pdf envelope short-circuits the local conversion entirely.
	 */
	public function test_pdf_ocr_routes_to_sidecar_when_auto() {
		if ( ! class_exists( 'WP_MCP_AI_OCR_Service' ) ) {
			$this->markTestSkipped( 'OCR service unavailable (standalone Pro matrix).' );
		}

		$tmp = tempnam( sys_get_temp_dir(), 'ocr_pdf_' ) . '.pdf';
		file_put_contents( $tmp, '%PDF-1.4 fake' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$service = $this->make_service(
			array(
				'success' => true,
				'format'  => 'pdf',
				'text'    => 'page one text',
				'meta'    => array(
					'ocr'   => true,
					'pages' => 1,
				),
			)
		);

		$result = $service->extract_text_from_pdf( $tmp, array( 'provider' => 'auto' ) );

		$this->assertSame( 'page one text', $result );
		$this->assertSame( 1, $service->upload_calls );
		$this->assertSame( '/api/ingest/parse', $service->upload_endpoint );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * A pinned vision provider skips the sidecar (the worker chain is tesseract).
	 */
	public function test_pdf_ocr_skips_sidecar_for_vision_provider() {
		if ( ! class_exists( 'WP_MCP_AI_OCR_Service' ) ) {
			$this->markTestSkipped( 'OCR service unavailable (standalone Pro matrix).' );
		}

		$tmp = tempnam( sys_get_temp_dir(), 'ocr_pdf_' ) . '.pdf';
		file_put_contents( $tmp, '%PDF-1.4 fake' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$service = $this->make_service(
			array(
				'success' => true,
				'format'  => 'pdf',
				'text'    => 'page one text',
			)
		);

		$result = $service->extract_text_from_pdf( $tmp, array( 'provider' => 'gemini' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'unexpected_local_convert', $result->get_error_code() );
		$this->assertSame( 0, $service->upload_calls );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}

	/**
	 * A sidecar failure falls through to the local conversion pipeline.
	 */
	public function test_pdf_ocr_falls_back_on_sidecar_error() {
		if ( ! class_exists( 'WP_MCP_AI_OCR_Service' ) ) {
			$this->markTestSkipped( 'OCR service unavailable (standalone Pro matrix).' );
		}

		$tmp = tempnam( sys_get_temp_dir(), 'ocr_pdf_' ) . '.pdf';
		file_put_contents( $tmp, '%PDF-1.4 fake' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$service = $this->make_service( new WP_Error( 'wp_mcp_ai_sidecar_error', 'boom' ) );

		$result = $service->extract_text_from_pdf( $tmp, array( 'provider' => 'auto' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'unexpected_local_convert', $result->get_error_code() );
		$this->assertSame( 1, $service->upload_calls );

		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}
