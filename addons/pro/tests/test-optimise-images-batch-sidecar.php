<?php
/**
 * Optimise Images Batch - Media Worker sidecar path tests
 * (Proposal 079, Appendix B).
 *
 * Covers the execute_sidecar_batch() path: one /api/image/batch call
 * replaces the per-image GD loop, original-format re-encodes overwrite the
 * source file, WebP conversions register a linked sibling attachment, and
 * dry-run previews never touch the sidecar. Zero HTTP - the sidecar methods
 * are faked via a trait on an anonymous tool subclass.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test doubles scoped to this suite.

/**
 * Fake sidecar batch upload for the optimise-images-batch tool.
 */
trait WP_MCP_AI_Optimise_Batch_Sidecar_Fake {

	/**
	 * Number of batch upload calls.
	 *
	 * @var int
	 */
	public $batch_upload_calls = 0;

	/**
	 * Last upload endpoint.
	 *
	 * @var string
	 */
	public $batch_endpoint = '';

	/**
	 * Last upload fields.
	 *
	 * @var array
	 */
	public $batch_fields = array();

	/**
	 * Per-file result fields merged into the fake envelope.
	 *
	 * @var array
	 */
	public $fake_result = array();

	/**
	 * Force the sidecar path.
	 *
	 * @return bool Always true.
	 */
	public function is_sidecar_upload_supported() {
		return true;
	}

	/**
	 * Fake multi-upload returning one result per file.
	 *
	 * @param string $endpoint   API path.
	 * @param array  $file_paths Local file paths.
	 * @param array  $fields     Extra form fields.
	 * @param int    $timeout    Request timeout (unused).
	 * @return array Fake worker envelope.
	 */
	protected function sidecar_upload_multi( $endpoint, $file_paths, $fields = array(), $timeout = 330 ) {
		unset( $timeout );
		++$this->batch_upload_calls;
		$this->batch_endpoint = $endpoint;
		$this->batch_fields   = $fields;

		$results = array();
		foreach ( $file_paths as $file_path ) {
			$results[] = array_merge(
				array( 'filename' => basename( $file_path ) ),
				$this->fake_result
			);
		}

		return array(
			'success'   => true,
			'processed' => count( $results ),
			'results'   => $results,
			'errors'    => array(),
		);
	}
}

/**
 * Test case: optimise_images_batch sidecar path.
 */
class Test_Optimise_Images_Batch_Sidecar extends WP_UnitTestCase {

	/**
	 * 8x8 PNG fixture (base64).
	 */
	const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAAEUlEQVQImWM4Y2yMFTEMLQkACspMgcuiywsAAAAASUVORK5CYII=';

	/**
	 * 8x8 WebP fixture (base64).
	 */
	const WEBP_B64 = 'UklGRjoAAABXRUJQVlA4IC4AAACQAQCdASoIAAgAAUAmJaACdLoAA5gA/u7/n/wF090Yt/+4OP+wcf9g4/dMAAAA';

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Optimise_Images_Batch
	 */
	private $tool;

	/**
	 * Attachment IDs created by the tests.
	 *
	 * @var array
	 */
	private $attachment_ids = array();

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$tool_file = dirname( __DIR__ ) . '/includes/tools/image-production/class-wp-mcp-ai-tool-optimise-images-batch.php';
		if ( file_exists( $tool_file ) ) {
			require_once $tool_file;
		}

		$this->tool = new class() extends WP_MCP_AI_Tool_Optimise_Images_Batch {
			use WP_MCP_AI_Optimise_Batch_Sidecar_Fake;
		};
	}

	/**
	 * Clean up attachments between tests.
	 */
	public function tearDown(): void {
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
		$this->attachment_ids = array();

		parent::tearDown();
	}

	/**
	 * Create a PNG attachment fixture.
	 *
	 * @return int Attachment ID.
	 */
	private function create_png_attachment() {
		$uploads  = wp_upload_dir();
		$filename = 'batch-sidecar-test-' . uniqid() . '.png';
		$target   = trailingslashit( $uploads['path'] ) . $filename;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing the test fixture image.
		file_put_contents( $target, base64_decode( self::PNG_B64, true ) );

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => 'batch-sidecar-test',
				'post_mime_type' => 'image/png',
				'post_status'    => 'inherit',
			),
			$target,
			0
		);

		$this->attachment_ids[] = $attachment_id;

		return $attachment_id;
	}

	/**
	 * Original-format re-encode overwrites the source file via one worker call.
	 */
	public function test_sidecar_batch_overwrites_original() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Optimise_Images_Batch' ) ) {
			$this->markTestSkipped( 'Image production toolkit unavailable.' );
		}

		$attachment_id = $this->create_png_attachment();
		$file_path     = get_attached_file( $attachment_id );
		$before_size   = filesize( $file_path );

		$this->tool->fake_result = array(
			'format' => 'png',
			'b64'    => self::PNG_B64,
		);

		$result = $this->tool->execute(
			array(
				'attachment_ids'  => array( $attachment_id ),
				'quality'         => 80,
				'convert_to_webp' => false,
				'dry_run'         => false,
			)
		);

		$this->assertSame( true, $result['success'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 1, $this->tool->batch_upload_calls );
		$this->assertSame( '/api/image/batch', $this->tool->batch_endpoint );
		$this->assertSame( 'original', $this->tool->batch_fields['format'] );
		$this->assertSame( 80, $this->tool->batch_fields['quality'] );

		clearstatcache( true, $file_path );
		$this->assertSame( $before_size, filesize( $file_path ), 'fixture bytes are the same length after the fake re-encode' );
		$this->assertNotSame( '', get_post_meta( $attachment_id, '_wp_mcp_ai_optimized', true ) );
	}

	/**
	 * WebP conversion writes the sibling file and links a new attachment.
	 */
	public function test_sidecar_batch_webp_conversion_links_sibling() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Optimise_Images_Batch' ) ) {
			$this->markTestSkipped( 'Image production toolkit unavailable.' );
		}

		$attachment_id = $this->create_png_attachment();
		$file_path     = get_attached_file( $attachment_id );

		$this->tool->fake_result = array(
			'format' => 'webp',
			'b64'    => self::WEBP_B64,
		);

		$result = $this->tool->execute(
			array(
				'attachment_ids'  => array( $attachment_id ),
				'quality'         => 82,
				'convert_to_webp' => true,
				'dry_run'         => false,
			)
		);

		$this->assertSame( true, $result['success'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 'webp', $this->tool->batch_fields['format'] );

		$webp_path = pathinfo( $file_path, PATHINFO_DIRNAME ) . '/' . pathinfo( $file_path, PATHINFO_FILENAME ) . '.webp';
		$this->assertFileExists( $webp_path );

		$webp_attachment_id = get_post_meta( $attachment_id, '_wp_mcp_ai_webp_version', true );
		$this->assertNotSame( '', $webp_attachment_id );
		$this->assertSame( (string) $attachment_id, (string) get_post_meta( $webp_attachment_id, '_wp_mcp_ai_original_id', true ) );
		$this->attachment_ids[] = $webp_attachment_id;
	}

	/**
	 * Dry-run previews never touch the sidecar.
	 */
	public function test_dry_run_skips_sidecar() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Optimise_Images_Batch' ) ) {
			$this->markTestSkipped( 'Image production toolkit unavailable.' );
		}

		$attachment_id = $this->create_png_attachment();

		$result = $this->tool->execute(
			array(
				'attachment_ids'  => array( $attachment_id ),
				'quality'         => 82,
				'convert_to_webp' => true,
				'dry_run'         => true,
			)
		);

		$this->assertSame( true, $result['success'] );
		$this->assertSame( 1, $result['processed'] );
		$this->assertSame( 'preview', $result['results'][0]['status'] );
		$this->assertSame( 0, $this->tool->batch_upload_calls );
	}
}
