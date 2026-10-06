<?php
/**
 * Output Pipeline tests.
 *
 * Covers profile definitions, provenance naming, dimension validation,
 * downscale/upscale behavior with the resolution ceiling, format conversion
 * with JPEG fallback, alt-text generation, and the Amazon white-background
 * check on derived output.
 *
 * @package NV_oOS_Media_Studio
 */
class Test_Media_Studio_Output_Pipeline extends WP_UnitTestCase {

	/**
	 * Pipeline class name.
	 */
	const PIPELINE = 'NV_oOS_Media_Studio_Output_Pipeline';

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ) {
			define( 'NVOOS_MEDIA_STUDIO_VERSION', '0.4.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/ai/class-nvoos-media-studio-ai-service.php';
		require_once dirname( __DIR__ ) . '/includes/ai/class-nvoos-media-studio-output-pipeline.php';
		remove_all_filters( 'nvoos_media_studio_alt_text' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'nvoos_media_studio_alt_text' );
		parent::tearDown();
	}

	/**
	 * Create a GD image attachment fixture.
	 *
	 * @param array  $rgb   RGB fill color.
	 * @param int    $w     Width.
	 * @param int    $h     Height.
	 * @param string $name Base file name.
	 * @return int Attachment ID.
	 */
	private function create_image_attachment( $rgb = array( 255, 255, 255 ), $w = 100, $h = 100, $name = '' ) {
		$img = imagecreatetruecolor( $w, $h );
		imagefilledrectangle( $img, 0, 0, $w, $h, imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] ) );
		$dir  = wp_upload_dir();
		$name = '' === $name ? 'nvoos-op-test-' . uniqid() . '.png' : $name . '.png';
		$path = $dir['path'] . '/' . $name;
		imagepng( $img, $path );
		imagedestroy( $img );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Pipeline Fixture',
				'post_status'    => 'inherit',
			),
			$path
		);
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

		return $attachment_id;
	}

	/**
	 * Test the profiles map shape.
	 */
	public function test_profiles_shape() {
		$profiles = NV_oOS_Media_Studio_Output_Pipeline::get_profiles();
		$this->assertArrayHasKey( 'amazon', $profiles );
		$this->assertArrayHasKey( 'woocommerce', $profiles );
		$this->assertArrayHasKey( 'social', $profiles );
		$this->assertArrayHasKey( 'web', $profiles );
		$this->assertSame( 1600, $profiles['amazon']['min_side'] );
		$this->assertTrue( $profiles['amazon']['square'] );
		$this->assertTrue( $profiles['amazon']['white_bg'] );
		$this->assertSame( 'image/jpeg', $profiles['amazon']['format'] );
	}

	/**
	 * Test profile validation.
	 */
	public function test_is_valid_profile() {
		$this->assertTrue( NV_oOS_Media_Studio_Output_Pipeline::is_valid_profile( 'amazon' ) );
		$this->assertFalse( NV_oOS_Media_Studio_Output_Pipeline::is_valid_profile( 'nope' ) );
	}

	/**
	 * Test provenance naming: profile + variant suffix, IMG_ prefix scrubbed.
	 */
	public function test_build_file_name() {
		$attachment_id = $this->create_image_attachment( array( 255, 255, 255 ), 60, 60, 'fashion-packshot-abc123' );
		$this->assertSame( 'fashion-packshot-abc123-amazon-0', NV_oOS_Media_Studio_Output_Pipeline::build_file_name( $attachment_id, 'amazon', 0 ) );
		$this->assertSame( 'fashion-packshot-abc123-woocommerce-3', NV_oOS_Media_Studio_Output_Pipeline::build_file_name( $attachment_id, 'woocommerce', 3 ) );

		$img_id = $this->create_image_attachment( array( 255, 255, 255 ), 60, 60, 'IMG_1234' );
		$name   = NV_oOS_Media_Studio_Output_Pipeline::build_file_name( $img_id, 'amazon' );
		$this->assertStringStartsWith( 'media-studio-amazon-', $name );
	}

	/**
	 * Test dimension validation against profiles.
	 */
	public function test_validate_dimensions() {
		$attachment_id = $this->create_image_attachment( array( 255, 255, 255 ), 500, 700 );

		$amazon = NV_oOS_Media_Studio_Output_Pipeline::validate_dimensions( $attachment_id, 'amazon' );
		$this->assertFalse( $amazon['meets'] );
		$this->assertSame( 500, $amazon['min_side'] );
		$this->assertEqualsWithDelta( 3.2, $amazon['factor'], 0.01 );

		$woo = NV_oOS_Media_Studio_Output_Pipeline::validate_dimensions( $attachment_id, 'woocommerce' );
		$this->assertFalse( $woo['meets'] );
		$this->assertEqualsWithDelta( 1.6, $woo['factor'], 0.01 );

		$error = NV_oOS_Media_Studio_Output_Pipeline::validate_dimensions( $attachment_id, 'bogus' );
		$this->assertWPError( $error );
	}

	/**
	 * Test the Amazon profile downscales to a 1600 square JPEG with white check.
	 */
	public function test_process_amazon_downscale_square() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$source = $this->create_image_attachment( array( 255, 255, 255 ), 2000, 2000 );

		$result = NV_oOS_Media_Studio_Output_Pipeline::process(
			$source,
			'amazon',
			array( 'alt_text' => false ),
			0
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'amazon', $result['profile'] );
		$this->assertSame( 'image/jpeg', $result['mime_type'] );
		$this->assertFalse( $result['upscaled'] );

		$size = getimagesize( get_attached_file( $result['attachment_id'] ) );
		$this->assertSame( 1600, $size[0] );
		$this->assertSame( 1600, $size[1] );

		$this->assertSame( (string) $source, (string) get_post_meta( $result['attachment_id'], NV_oOS_Media_Studio_Output_Pipeline::META_DERIVED_FROM, true ) );
		$this->assertSame( 'amazon', get_post_meta( $result['attachment_id'], NV_oOS_Media_Studio_Output_Pipeline::META_PROFILE, true ) );
		$this->assertTrue( $result['white_background']['is_white'] );
	}

	/**
	 * Test bounded upscaling flags the output (WooCommerce 500 → 800).
	 */
	public function test_process_woocommerce_upscale_flagged() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$source = $this->create_image_attachment( array( 240, 240, 240 ), 500, 500 );

		$result = NV_oOS_Media_Studio_Output_Pipeline::process(
			$source,
			'woocommerce',
			array( 'alt_text' => false ),
			0
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['upscaled'] );
		$this->assertContains( $result['mime_type'], array( 'image/webp', 'image/jpeg' ) );

		$size = getimagesize( get_attached_file( $result['attachment_id'] ) );
		$this->assertGreaterThanOrEqual( 800, $size[0] );
		$this->assertGreaterThanOrEqual( 800, $size[1] );
		$this->assertSame( '1', get_post_meta( $result['attachment_id'], NV_oOS_Media_Studio_Output_Pipeline::META_UPSCALED, true ) );
	}

	/**
	 * Test resolutions beyond the upscale ceiling are refused.
	 */
	public function test_process_insufficient_resolution() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		$source = $this->create_image_attachment( array( 255, 255, 255 ), 300, 300 );

		$result = NV_oOS_Media_Studio_Output_Pipeline::process(
			$source,
			'amazon',
			array( 'alt_text' => false ),
			0
		);
		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_insufficient_resolution', $result->get_error_code() );
	}

	/**
	 * Test invalid profiles are rejected.
	 */
	public function test_process_rejects_invalid_profile() {
		$source = $this->create_image_attachment( array( 255, 255, 255 ), 100, 100 );
		$result = NV_oOS_Media_Studio_Output_Pipeline::process( $source, 'bogus', array(), 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_ms_invalid_profile', $result->get_error_code() );
	}

	/**
	 * Test alt text generation writes the attachment alt meta.
	 */
	public function test_process_writes_alt_text() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available.' );
		}
		add_filter(
			'nvoos_media_studio_alt_text',
			static function () {
				return 'Canned alt description';
			}
		);

		$source = $this->create_image_attachment( array( 255, 255, 255 ), 2000, 2000 );
		$result = NV_oOS_Media_Studio_Output_Pipeline::process(
			$source,
			'web',
			array( 'alt_text' => true ),
			0
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Canned alt description', $result['alt_text'] );
		$this->assertSame( 'Canned alt description', get_post_meta( $result['attachment_id'], '_wp_attachment_image_alt', true ) );
	}
}
