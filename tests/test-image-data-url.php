<?php
/**
 * Image Data URL helper tests.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-image-data-url.php';

/**
 * Tests for the WP_MCP_AI_Image_Data_Url helper.
 */
class WP_MCP_AI_Image_Data_Url_Test extends WP_UnitTestCase {
	/**
	 * 1x1 transparent PNG fixture.
	 *
	 * @var string
	 */
	const PNG_FIXTURE_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

	/**
	 * Reset the settings cache before each test so option writes are observed.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
	}

	/**
	 * Clean up filters and settings between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_mcp_ai_image_inline_max_bytes' );
		remove_all_filters( 'wp_mcp_ai_image_inline_mime_types' );
		remove_all_filters( 'wp_mcp_ai_image_inline_optimize' );
		remove_all_filters( 'wp_mcp_ai_image_inline_optimize_min_bytes' );
		remove_all_filters( 'wp_mcp_ai_image_inline_max_dimension' );
		remove_all_filters( 'wp_mcp_ai_image_inline_jpeg_quality' );
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		parent::tearDown();
	}

	/**
	 * Local attachments are read straight off disk — no HTTP involved.
	 */
	public function test_from_segment_reads_local_attachment_file() {
		list( $attachment_id ) = $this->create_image_attachment( 'inline.png' );

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment( array( 'attachment_id' => $attachment_id ) );

		$this->assertSame( $this->expected_data_url(), $data_url );
	}

	/**
	 * Remote URLs are downloaded by the WordPress server and inlined.
	 */
	public function test_from_segment_downloads_remote_url_server_side() {
		$stub = function ( $preempt, $args, $url ) {
			if ( 'https://example.com/media/pack-shot.png' === $url ) {
				return array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a static PNG fixture for an image test.
					'body'     => base64_decode( self::PNG_FIXTURE_BASE64 ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'headers'  => array( 'content-type' => 'image/png' ),
				);
			}

			return false;
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment(
			array( 'image_url' => array( 'url' => 'https://example.com/media/pack-shot.png' ) )
		);

		$this->assertSame( $this->expected_data_url(), $data_url );
	}

	/**
	 * A failed download returns an empty string so callers keep the URL fallback.
	 */
	public function test_from_segment_returns_empty_on_http_error() {
		$stub = function () {
			return array(
				'body'     => 'Not Found',
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
				'headers'  => array( 'content-type' => 'text/html' ),
			);
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment(
			array( 'url' => 'https://example.com/missing.png' )
		);

		$this->assertSame( '', $data_url );
	}

	/**
	 * Images whose advertised size exceeds the cap are not inlined.
	 */
	public function test_from_segment_returns_empty_when_oversized() {
		$stub = function () {
			return array(
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array( 'content-length' => '20971520' ),
			);
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment(
			array( 'url' => 'https://example.com/huge.png' )
		);

		$this->assertSame( '', $data_url );
	}

	/**
	 * Non-inlineable MIME types (PDF, SVG) keep their URL fallback.
	 */
	public function test_from_segment_returns_empty_for_non_image_mime() {
		$stub = function () {
			return array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a static PNG fixture for an image test.
				'body'     => base64_decode( self::PNG_FIXTURE_BASE64 ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array( 'content-type' => 'application/pdf' ),
			);
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment(
			array( 'url' => 'https://example.com/report.pdf' )
		);

		$this->assertSame( '', $data_url );
	}

	/**
	 * Segments that already carry a data URL pass through untouched.
	 */
	public function test_from_segment_passes_existing_data_url_through() {
		$data_url = WP_MCP_AI_Image_Data_Url::from_segment(
			array( 'image_url' => array( 'url' => 'data:image/png;base64,QUJD' ) )
		);

		$this->assertSame( 'data:image/png;base64,QUJD', $data_url );
	}

	/**
	 * The inline size cap is configurable through a filter.
	 */
	public function test_from_segment_respects_max_bytes_filter() {
		$stub = function () {
			return array(
				'body'     => str_repeat( 'a', 2048 ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'headers'  => array( 'content-type' => 'image/png' ),
			);
		};
		add_filter( 'pre_http_request', $stub, 10, 3 );
		add_filter(
			'wp_mcp_ai_image_inline_max_bytes',
			static function () {
				return 1024;
			}
		);

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment(
			array( 'url' => 'https://example.com/medium.png' )
		);

		$this->assertSame( '', $data_url );
	}

	/**
	 * Large opaque PNGs are re-encoded as JPEG for the inline payload.
	 */
	public function test_from_segment_optimizes_large_opaque_png_to_jpeg() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD image functions not available.' );
		}

		list( $attachment_id ) = $this->create_png_attachment( 'noise.png', $this->create_noise_png( 700, 700 ) );

		add_filter(
			'wp_mcp_ai_image_inline_optimize_min_bytes',
			static function () {
				return 1024;
			}
		);

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment( array( 'attachment_id' => $attachment_id ) );

		$this->assertStringStartsWith( 'data:image/jpeg;base64,', $data_url );
	}

	/**
	 * Oversized images are downscaled to the vision-tile dimension cap,
	 * even when their byte size is tiny (well-compressed large images).
	 */
	public function test_from_segment_downscales_oversized_images() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD image functions not available.' );
		}

		list( $attachment_id ) = $this->create_png_attachment( 'big.png', $this->create_solid_png( 3000, 3000 ) );
		$original_file         = file_get_contents( get_attached_file( $attachment_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads its own fixture.

		add_filter(
			'wp_mcp_ai_image_inline_optimize_min_bytes',
			static function () {
				return 1024;
			}
		);

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment( array( 'attachment_id' => $attachment_id ) );

		$this->assertStringStartsWith( 'data:image/', $data_url );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding the helper output for an image test.
		$bytes = base64_decode( substr( $data_url, strpos( $data_url, ',' ) + 1 ) );
		$size  = getimagesizefromstring( $bytes );
		$this->assertIsArray( $size );
		$this->assertLessThanOrEqual( 2048, $size[0] );
		$this->assertLessThanOrEqual( 2048, $size[1] );

		// Only the inline copy is scaled — the original upload stays untouched.
		$this->assertSame( $original_file, file_get_contents( get_attached_file( $attachment_id ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads its own fixture.
	}

	/**
	 * PNGs with an alpha channel keep their PNG encoding.
	 */
	public function test_from_segment_keeps_alpha_png_as_png() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD image functions not available.' );
		}

		list( $attachment_id ) = $this->create_png_attachment( 'alpha.png', $this->create_alpha_png( 800, 800 ) );

		add_filter(
			'wp_mcp_ai_image_inline_optimize_min_bytes',
			static function () {
				return 1024;
			}
		);

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment( array( 'attachment_id' => $attachment_id ) );

		$this->assertStringStartsWith( 'data:image/png;base64,', $data_url );
	}

	/**
	 * Disabling the optimize filter inlines the original bytes untouched.
	 */
	public function test_from_segment_optimize_filter_off_keeps_original_bytes() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD image functions not available.' );
		}

		list( $attachment_id ) = $this->create_png_attachment( 'big.png', $this->create_solid_png( 3000, 3000 ) );
		$attached_bytes        = file_get_contents( get_attached_file( $attachment_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads its own fixture.

		add_filter( 'wp_mcp_ai_image_inline_optimize', '__return_false' );

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment( array( 'attachment_id' => $attachment_id ) );

		// The inline copy matches the attachment bytes exactly (WordPress may
		// have scaled the original upload on ingest — compare against disk).
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding fixture bytes for an image test.
		$this->assertSame( 'data:image/png;base64,' . base64_encode( $attached_bytes ), $data_url );
	}

	/**
	 * The admin toggle (chat_inline_image_optimization) gates the optimizer.
	 */
	public function test_from_segment_respects_settings_toggle() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD image functions not available.' );
		}

		list( $attachment_id ) = $this->create_png_attachment( 'big.png', $this->create_solid_png( 3000, 3000 ) );
		$attached_bytes        = file_get_contents( get_attached_file( $attachment_id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test reads its own fixture.

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'chat_inline_image_optimization' => false )
		);
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		add_filter(
			'wp_mcp_ai_image_inline_optimize_min_bytes',
			static function () {
				return 1024;
			}
		);

		$data_url = WP_MCP_AI_Image_Data_Url::from_segment( array( 'attachment_id' => $attachment_id ) );

		// The setting is off, so the original bytes are inlined unchanged.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding fixture bytes for an image test.
		$this->assertSame( 'data:image/png;base64,' . base64_encode( $attached_bytes ), $data_url );
	}

	/**
	 * Segments without any resolvable source return an empty string.
	 */
	public function test_from_segment_returns_empty_without_source() {
		$this->assertSame( '', WP_MCP_AI_Image_Data_Url::from_segment( array( 'type' => 'input_image' ) ) );
	}

	/**
	 * The expected data URL for the shared PNG fixture.
	 *
	 * @return string
	 */
	protected function expected_data_url() {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a static PNG fixture for an image-upload test.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding a static PNG fixture for an image-upload test.
		return 'data:image/png;base64,' . base64_encode( base64_decode( self::PNG_FIXTURE_BASE64 ) );
	}

	/**
	 * Create a real PNG attachment from raw bytes and return the ID and path.
	 *
	 * @param string $filename File name.
	 * @param string $contents PNG bytes.
	 * @return array
	 */
	protected function create_png_attachment( $filename, $contents ) {
		$upload = wp_upload_bits( $filename, null, $contents );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		return array( $attachment_id, $upload['file'] );
	}

	/**
	 * Render a solid-color opaque PNG with GD.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string PNG bytes.
	 */
	protected function create_solid_png( $width, $height ) {
		$img   = imagecreatetruecolor( $width, $height );
		$color = imagecolorallocate( $img, 30, 120, 200 );
		imagefilledrectangle( $img, 0, 0, $width, $height, $color );
		$png = $this->capture_png( $img );
		imagedestroy( $img );

		return $png;
	}

	/**
	 * Render a transparent (alpha channel) PNG with GD.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string PNG bytes.
	 */
	protected function create_alpha_png( $width, $height ) {
		$img = imagecreatetruecolor( $width, $height );
		imagealphablending( $img, false );
		imagesavealpha( $img, true );
		$transparent = imagecolorallocatealpha( $img, 0, 0, 0, 127 );
		imagefilledrectangle( $img, 0, 0, $width, $height, $transparent );
		$png = $this->capture_png( $img );
		imagedestroy( $img );

		return $png;
	}

	/**
	 * Render a random-noise opaque PNG with GD (worst case for PNG encoding).
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return string PNG bytes.
	 */
	protected function create_noise_png( $width, $height ) {
		$img = imagecreatetruecolor( $width, $height );
		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				imagesetpixel( $img, $x, $y, imagecolorallocate( $img, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
			}
		}
		$png = $this->capture_png( $img );
		imagedestroy( $img );

		return $png;
	}

	/**
	 * Capture a GD image as PNG bytes.
	 *
	 * @param resource $img GD image resource.
	 * @return string PNG bytes.
	 */
	protected function capture_png( $img ) {
		ob_start();
		imagepng( $img );
		$png = (string) ob_get_clean();

		return $png;
	}

	/**
	 * Create a real image attachment and return the ID and path.
	 *
	 * @param string $filename File name.
	 * @return array
	 */
	protected function create_image_attachment( $filename ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a static PNG fixture for an image-upload test.
		$png = base64_decode( self::PNG_FIXTURE_BASE64 );
		$this->assertNotEmpty( $png );

		$upload = wp_upload_bits( $filename, null, $png );
		$this->assertFalse( $upload['error'] );

		$attachment_id = self::factory()->attachment->create_upload_object( $upload['file'] );

		return array( $attachment_id, $upload['file'] );
	}
}
