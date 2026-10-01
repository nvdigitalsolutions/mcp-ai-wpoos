<?php
/**
 * XMP Writer tests.
 *
 * Covers packet construction + escaping, JPEG APP1 embedding, PNG iTXt
 * embedding, WebP RIFF "XMP " embedding (with VP8X insertion), decode
 * round-trips, and graceful rejection of unsupported formats.
 *
 * @package NV_oOS_Media_Studio
 */
class Test_Media_Studio_XMP_Writer extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ) {
			define( 'NVOOS_MEDIA_STUDIO_VERSION', '0.5.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/ai/class-nvoos-media-studio-xmp-writer.php';
	}

	/**
	 * Create an attachment from GD-rendered bytes.
	 *
	 * @param string $ext    File extension (png/jpg/webp/gif).
	 * @param string $mime   MIME type.
	 * @param string $render GD renderer callable.
	 * @return int|false Attachment ID or false when the renderer is unavailable.
	 */
	private function create_attachment( $ext, $mime, $render ) {
		$img = imagecreatetruecolor( 40, 40 );
		imagefilledrectangle( $img, 0, 0, 40, 40, imagecolorallocate( $img, 200, 200, 200 ) );
		ob_start();
		$ok = $render( $img );
		$bytes = ob_get_clean();
		imagedestroy( $img );
		if ( ! $ok || '' === $bytes ) {
			return false;
		}

		$dir  = wp_upload_dir();
		$path = $dir['path'] . '/nvoos-xmp-test-' . uniqid() . '.' . $ext;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture write.
		file_put_contents( $path, $bytes );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => 'XMP Fixture',
				'post_status'    => 'inherit',
			),
			$path
		);
		return $attachment_id;
	}

	/**
	 * Test the packet is well-formed XML and escapes hostile values.
	 */
	public function test_build_packet_escapes_and_parses() {
		$packet = NV_oOS_Media_Studio_XMP_Writer::build_packet(
			array(
				'DigitalSourceType' => NV_oOS_Media_Studio_XMP_Writer::DST_TRAINED_ALGORITHMIC,
				'AISystemUsed'      => 'gemini "quoted" <script> & more',
			)
		);

		$this->assertStringContainsString( 'xpacket begin', $packet );
		$this->assertStringContainsString( 'xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/"', $packet );
		$this->assertStringNotContainsString( '<script>', $packet );

		$xml = simplexml_load_string( $packet );
		$this->assertNotFalse( $xml );
	}

	/**
	 * Test JPEG embedding: XMP APP1 present, still decodable, idempotent.
	 */
	public function test_embed_jpeg_round_trip() {
		$attachment_id = $this->create_attachment(
			'jpg',
			'image/jpeg',
			static function ( $img ) {
				return imagejpeg( $img );
			}
		);
		$this->assertNotFalse( $attachment_id );

		$fields = array(
			'DigitalSourceType' => NV_oOS_Media_Studio_XMP_Writer::DST_TRAINED_ALGORITHMIC,
			'AISystemUsed'      => 'gemini',
		);

		$this->assertTrue( NV_oOS_Media_Studio_XMP_Writer::embed( $attachment_id, $fields ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads local fixture.
		$data = file_get_contents( get_attached_file( $attachment_id ) );
		$this->assertStringContainsString( 'http://ns.adobe.com/xap/1.0/', $data );
		$this->assertStringContainsString( 'AISystemUsed', $data );

		// Still decodable by GD.
		$this->assertNotFalse( imagecreatefromjpeg( get_attached_file( $attachment_id ) ) );

		// Idempotent: a second embed replaces, never duplicates. Count the
		// APP1 segment header (identifier + NUL); the packet itself also
		// contains the xap namespace URL, so the raw substring appears twice.
		$this->assertTrue( NV_oOS_Media_Studio_XMP_Writer::embed( $attachment_id, $fields ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads local fixture.
		$data2 = file_get_contents( get_attached_file( $attachment_id ) );
		$this->assertSame( 1, substr_count( $data2, 'http://ns.adobe.com/xap/1.0/' . "\0" ) );
	}

	/**
	 * Test PNG embedding via the iTXt chunk.
	 */
	public function test_embed_png_round_trip() {
		$attachment_id = $this->create_attachment(
			'png',
			'image/png',
			static function ( $img ) {
				return imagepng( $img );
			}
		);
		$this->assertNotFalse( $attachment_id );

		$this->assertTrue(
			NV_oOS_Media_Studio_XMP_Writer::embed(
				$attachment_id,
				array( 'AISystemUsed' => 'gemini' )
			)
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads local fixture.
		$data = file_get_contents( get_attached_file( $attachment_id ) );
		$this->assertStringContainsString( 'iTXt', $data );
		$this->assertStringContainsString( 'XML:com.adobe.xmp', $data );
		$this->assertStringContainsString( 'AISystemUsed', $data );

		$this->assertNotFalse( imagecreatefrompng( get_attached_file( $attachment_id ) ) );
	}

	/**
	 * Test WebP embedding inserts a VP8X chunk and stays decodable.
	 */
	public function test_embed_webp_round_trip() {
		if ( ! function_exists( 'imagewebp' ) || ! function_exists( 'imagecreatefromwebp' ) ) {
			$this->markTestSkipped( 'GD WebP support is not available.' );
		}

		$attachment_id = $this->create_attachment(
			'webp',
			'image/webp',
			static function ( $img ) {
				return imagewebp( $img, null, 90 );
			}
		);
		$this->assertNotFalse( $attachment_id );

		$this->assertTrue(
			NV_oOS_Media_Studio_XMP_Writer::embed(
				$attachment_id,
				array( 'AISystemUsed' => 'gemini' )
			)
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads local fixture.
		$data = file_get_contents( get_attached_file( $attachment_id ) );
		$this->assertStringContainsString( 'VP8X', $data );
		$this->assertStringContainsString( 'XMP ', $data );
		$this->assertStringContainsString( 'AISystemUsed', $data );

		$this->assertNotFalse( imagecreatefromwebp( get_attached_file( $attachment_id ) ) );
	}

	/**
	 * Test unsupported formats are rejected gracefully.
	 */
	public function test_embed_rejects_unsupported_mime() {
		if ( ! function_exists( 'imagegif' ) ) {
			$this->markTestSkipped( 'GD GIF support is not available.' );
		}
		$attachment_id = $this->create_attachment(
			'gif',
			'image/gif',
			static function ( $img ) {
				return imagegif( $img );
			}
		);
		$this->assertNotFalse( $attachment_id );

		$result = NV_oOS_Media_Studio_XMP_Writer::embed( $attachment_id, array( 'AISystemUsed' => 'gemini' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_xmp_unsupported', $result->get_error_code() );
	}

	/**
	 * Test supports_mime gate.
	 */
	public function test_supports_mime() {
		$this->assertTrue( NV_oOS_Media_Studio_XMP_Writer::supports_mime( 'image/jpeg' ) );
		$this->assertTrue( NV_oOS_Media_Studio_XMP_Writer::supports_mime( 'image/png' ) );
		$this->assertTrue( NV_oOS_Media_Studio_XMP_Writer::supports_mime( 'image/webp' ) );
		$this->assertFalse( NV_oOS_Media_Studio_XMP_Writer::supports_mime( 'image/gif' ) );
	}
}
