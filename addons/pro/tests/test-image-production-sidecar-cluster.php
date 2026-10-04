<?php
/**
 * Tests for the Wave 1 image-production sidecar cluster (issue #6877).
 *
 * Covers the real-implementation rewrites of enhance_image_quality and
 * upscale_image_ai on the optimize_image_sharp dual path:
 * - enhancements enum → engine field mapping (incl. the 'auto' expansion),
 * - the honest wp_mcp_ai_sharp_unavailable error when neither the local
 *   Sharp runtime nor a Media Worker sidecar exists,
 * - the local Sharp subprocess path (short-circuited via the
 *   wp_mcp_ai_sharp_process_image filter),
 * - the sidecar path (test doubles overriding sidecar_upload),
 * - the upscale dimension cap and the honest no-backend failure,
 * - the W1d enhance re-route in optimize_image_sharp::optimize_via_sidecar.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Tests
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test doubles scoped to this suite.

require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-enhance-image-quality.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-upscale-image-ai.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-optimize-image-sharp.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-colorize-image.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/tools/image-production/class-wp-mcp-ai-tool-apply-artistic-style.php';

/**
 * Fake sidecar upload behavior shared by the tool test doubles.
 */
trait WP_MCP_AI_Sidecar_Cluster_Fake_Upload {

	/**
	 * Captured endpoint path.
	 *
	 * @var string
	 */
	public $captured_endpoint = '';

	/**
	 * Captured multipart fields.
	 *
	 * @var array
	 */
	public $captured_fields = array();

	/**
	 * Force the sidecar path.
	 *
	 * @return bool Always true.
	 */
	public function is_sidecar_upload_supported() {
		return true;
	}

	/**
	 * Fake multipart upload returning the source bytes as the worker would.
	 *
	 * @param string $endpoint  API path.
	 * @param string $file_path Local file path.
	 * @param array  $fields    Extra form fields.
	 * @param int    $timeout   Request timeout (unused).
	 * @return array Fake worker envelope.
	 */
	protected function sidecar_upload( $endpoint, $file_path, $fields = array(), $timeout = 330 ) {
		unset( $timeout );
		$this->captured_endpoint = $endpoint;
		$this->captured_fields   = $fields;
		$bytes                   = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test fixture read.

		return array(
			'success'         => true,
			'original_size'   => filesize( $file_path ),
			'optimized_size'  => strlen( $bytes ),
			'savings_percent' => '0%',
			'format'          => 'png',
			'width'           => 1,
			'height'          => 1,
			'b64'             => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Worker envelope contract fixture.
		);
	}
}

/**
 * Sidecar test double for the enhance tool.
 */
class WP_MCP_AI_Sidecar_Cluster_Test_Double_Enhance extends WP_MCP_AI_Tool_Enhance_Image_Quality {
	use WP_MCP_AI_Sidecar_Cluster_Fake_Upload;
}

/**
 * Sidecar test double for the upscale tool.
 */
class WP_MCP_AI_Sidecar_Cluster_Test_Double_Upscale extends WP_MCP_AI_Tool_Upscale_Image_AI {
	use WP_MCP_AI_Sidecar_Cluster_Fake_Upload;
}

/**
 * Sidecar test double for the optimize tool (W1d re-route probe).
 */
class WP_MCP_AI_Sidecar_Cluster_Test_Double_Optimize extends WP_MCP_AI_Tool_Optimize_Image_Sharp {
	use WP_MCP_AI_Sidecar_Cluster_Fake_Upload;
}

/**
 * Sidecar test double for the colorize tool.
 */
class WP_MCP_AI_Sidecar_Cluster_Test_Double_Colorize extends WP_MCP_AI_Tool_Colorize_Image {
	use WP_MCP_AI_Sidecar_Cluster_Fake_Upload;
}

/**
 * Sidecar test double for the artistic style tool.
 */
class WP_MCP_AI_Sidecar_Cluster_Test_Double_Style extends WP_MCP_AI_Tool_Apply_Artistic_Style {
	use WP_MCP_AI_Sidecar_Cluster_Fake_Upload;
}

/**
 * Image-production sidecar cluster test case.
 */
class Test_Image_Production_Sidecar_Cluster extends WP_UnitTestCase {

	/**
	 * 1x1 transparent PNG for image round-trip fixtures.
	 */
	const TINY_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

	/**
	 * Number of HTTP requests issued during the current test.
	 *
	 * @var int
	 */
	private $request_count = 0;

	/**
	 * Captured arguments of the last HTTP request.
	 *
	 * @var array|null
	 */
	private $last_request_args = null;

	/**
	 * Set up the test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		wp_set_current_user( 1 );

		remove_all_filters( 'wp_mcp_ai_local_sharp_available' );
		remove_all_filters( 'wp_mcp_ai_sharp_process_image' );
		remove_all_filters( 'pre_http_request' );

		$this->request_count     = 0;
		$this->last_request_args = null;

		if ( class_exists( 'WP_MCP_AI_Credential_Resolver' ) ) {
			WP_MCP_AI_Credential_Resolver::clear_cache();
		}
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'reset_settings_cache' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}
		delete_option( 'wp_mcp_ai_settings' );

		// Default: no backends. Individual tests opt in via the filters or
		// the sidecar test doubles.
		add_filter( 'wp_mcp_ai_local_sharp_available', '__return_false' );
	}

	/**
	 * Reset filters between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_local_sharp_available' );
		remove_all_filters( 'wp_mcp_ai_sharp_process_image' );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Invoke a protected/private method on an object.
	 *
	 * @param object $instance Target object.
	 * @param string $method   Method name.
	 * @param array  $args     Method arguments.
	 * @return mixed Method return value.
	 */
	private function invoke_method( $instance, $method, array $args = array() ) {
		$reflection = new ReflectionMethod( get_class( $instance ), $method );
		return $reflection->invoke( $instance, ...$args );
	}

	/**
	 * Create a PNG image attachment fixture.
	 *
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return int Attachment ID.
	 */
	private function create_image_attachment( $width = 1, $height = 1 ) {
		$bytes = ( 1 === $width && 1 === $height )
			? base64_decode( self::TINY_PNG_B64, true ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test fixture.
			: $this->render_png( $width, $height );

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$upload = wp_upload_bits( 'sidecar-cluster-' . $width . 'x' . $height . '.png', null, $bytes );
		$this->assertEmpty( $upload['error'] );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Sidecar Cluster Fixture',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	/**
	 * Render a solid PNG with GD.
	 *
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return string PNG bytes.
	 */
	private function render_png( $width, $height ) {
		$image = imagecreatetruecolor( $width, $height );
		imagesavealpha( $image, true );
		$transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
		imagefill( $image, 0, 0, $transparent );

		ob_start();
		imagepng( $image );
		$bytes = ob_get_clean();

		imagedestroy( $image );

		return $bytes;
	}

	/**
	 * Copy the tiny PNG fixture to a fresh .png temp path (fake engine output).
	 *
	 * @return string Temp file path.
	 */
	private function create_fake_output_file() {
		$path = wp_tempnam( 'sidecar-cluster-output-' ) . '.png';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing the test fixture image.
		file_put_contents( $path, base64_decode( self::TINY_PNG_B64, true ) );
		return $path;
	}

	/**
	 * Short-circuit the local Sharp subprocess with a fake result.
	 *
	 * @param string $output_path Fake processed output file path.
	 * @return void
	 */
	private function mock_local_sharp_result( $output_path ) {
		add_filter(
			'wp_mcp_ai_sharp_process_image',
			static function ( $result, $params ) use ( $output_path ) {
				unset( $result, $params );
				return array(
					'success'           => true,
					'output_path'       => $output_path,
					'original_size'     => 100,
					'optimized_size'    => 90,
					'reduction_percent' => 10,
					'dimensions'        => array(
						'width'  => 1,
						'height' => 1,
					),
				);
			},
			10,
			2
		);
	}

	/**
	 * 'auto' must expand to all four enhancement operations with strength-derived multipliers.
	 */
	public function test_enhance_maps_auto_to_all_operations() {
		$tool = new WP_MCP_AI_Tool_Enhance_Image_Quality();

		$fields = $this->invoke_method( $tool, 'map_enhancements_to_fields', array( array( 'auto' ), 0.5 ) );

		$this->assertSame( array( 'sharpen', 'saturation', 'contrast', 'denoise' ), array_keys( $fields ) );
		$this->assertSame( 0.5, $fields['sharpen'] );
		$this->assertSame( 1.25, $fields['saturation'] );
		$this->assertSame( 1.2, $fields['contrast'] );
		$this->assertTrue( $fields['denoise'] );
	}

	/**
	 * Zero strength with only 'sharpness' must yield an empty field map.
	 */
	public function test_enhance_maps_zero_strength_sharpness_to_empty() {
		$tool = new WP_MCP_AI_Tool_Enhance_Image_Quality();

		$fields = $this->invoke_method( $tool, 'map_enhancements_to_fields', array( array( 'sharpness' ), 0.0 ) );

		$this->assertSame( array(), $fields );
	}

	/**
	 * Without any backend the tool must return the honest unavailable error.
	 */
	public function test_enhance_errors_honestly_without_backends() {
		$attachment_id = $this->create_image_attachment();
		$tool          = new WP_MCP_AI_Tool_Enhance_Image_Quality();

		$result = $tool->execute( array( 'attachment_id' => $attachment_id ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_sharp_unavailable', $result->get_error_code() );
	}

	/**
	 * The local Sharp path must run end-to-end and echo the applied operations.
	 */
	public function test_enhance_local_sharp_end_to_end() {
		add_filter( 'wp_mcp_ai_local_sharp_available', '__return_true' );

		$output_path   = $this->create_fake_output_file();
		$attachment_id = $this->create_image_attachment();
		$this->mock_local_sharp_result( $output_path );

		$tool   = new WP_MCP_AI_Tool_Enhance_Image_Quality();
		$result = $tool->execute( array( 'attachment_id' => $attachment_id ), array() );

		$this->assertIsArray( $result );
		$this->assertSame( 'local_sharp', $result['engine'] );
		$this->assertSame( array( 'sharpen', 'saturation', 'contrast', 'denoise' ), $result['enhancements'] );
		$this->assertSame( 0.5, $result['strength'] );
		$this->assertSame( 100, $result['original_size'] );
		$this->assertSame( 90, $result['optimized_size'] );
		$this->assertTrue( wp_attachment_is_image( $result['attachment_id'] ) );
		$this->assertFalse( file_exists( $output_path ), 'The processed temp file is cleaned up after upload.' );
	}

	/**
	 * The sidecar path must run end-to-end with use_remote and echo the route.
	 */
	public function test_enhance_sidecar_end_to_end() {
		$attachment_id = $this->create_image_attachment();

		$tool   = new WP_MCP_AI_Sidecar_Cluster_Test_Double_Enhance();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'use_remote'    => true,
				'enhancements'  => array( 'denoise' ),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( '/api/image/enhance', $tool->captured_endpoint );
		$this->assertTrue( $tool->captured_fields['denoise'] );
		$this->assertSame( 'sidecar', $result['engine'] );
		$this->assertSame( array( 'denoise' ), $result['enhancements'] );
		$this->assertTrue( wp_attachment_is_image( $result['attachment_id'] ) );
	}

	/**
	 * The upscale tool must report lanczos3 honestly on the Sharp path.
	 */
	public function test_upscale_local_sharp_end_to_end() {
		add_filter( 'wp_mcp_ai_local_sharp_available', '__return_true' );

		$output_path   = $this->create_fake_output_file();
		$attachment_id = $this->create_image_attachment();
		$this->mock_local_sharp_result( $output_path );

		$tool   = new WP_MCP_AI_Tool_Upscale_Image_AI();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'scale_factor'  => 2,
				'model'         => 'anime',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'local_sharp', $result['engine'] );
		$this->assertSame( 'lanczos3', $result['upscale_method'] );
		$this->assertSame( 2, $result['scale_factor'] );
		$this->assertSame( 'anime', $result['requested_model'] );
		$this->assertFalse( $result['denoise_applied'] );
		$this->assertTrue( wp_attachment_is_image( $result['attachment_id'] ) );
	}

	/**
	 * The sidecar path must pass the factor through to /api/image/upscale.
	 */
	public function test_upscale_sidecar_end_to_end() {
		$attachment_id = $this->create_image_attachment();

		$tool   = new WP_MCP_AI_Sidecar_Cluster_Test_Double_Upscale();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'scale_factor'  => 4,
				'use_remote'    => true,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( '/api/image/upscale', $tool->captured_endpoint );
		$this->assertSame( 4, $tool->captured_fields['factor'] );
		$this->assertSame( 'sidecar', $result['engine'] );
		$this->assertSame( 'lanczos3', $result['upscale_method'] );
		$this->assertSame( 4, $result['scale_factor'] );
	}

	/**
	 * Without backends the upscale tool must fail honestly — WordPress image
	 * editors cannot upscale, so the degrade surfaces a clear error instead
	 * of a fake success.
	 */
	public function test_upscale_wp_editor_fallback_is_honest() {
		$attachment_id = $this->create_image_attachment();

		$tool   = new WP_MCP_AI_Tool_Upscale_Image_AI();
		$result = $tool->execute( array( 'attachment_id' => $attachment_id ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_sharp_unavailable', $result->get_error_code() );
		$this->assertStringContainsString( 'cannot upscale images', $result->get_error_message() );
	}

	/**
	 * Upscaling beyond the shared dimension cap must be rejected.
	 */
	public function test_upscale_rejects_dimension_cap() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is required for the dimension-cap fixture.' );
		}

		$attachment_id = $this->create_image_attachment( 1025, 8 );

		$tool   = new WP_MCP_AI_Tool_Upscale_Image_AI();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'scale_factor'  => 8,
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_dimension_cap', $result->get_error_code() );
	}

	/**
	 * Mock the HTTP layer to return a given JSON body with a given status.
	 *
	 * @param array|string $body   Body payload.
	 * @param int          $status HTTP status code.
	 * @return void
	 */
	private function mock_http( $body, $status = 200 ) {
		$test = $this;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $test, $body, $status ) {
				$test->request_count++;
				$test->last_request_args = array(
					'args' => $args,
					'url'  => $url,
				);

				return array(
					'headers'  => array(),
					'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => 200 === $status ? 'OK' : 'Error',
					),
				);
			},
			10,
			3
		);
	}

	/**
	 * Gemini image-edit HTTP response payload returning the tiny PNG fixture.
	 *
	 * @return array Response payload.
	 */
	private function gemini_edit_payload() {
		return array(
			'candidates' => array(
				array(
					'content' => array(
						'parts' => array(
							array(
								'inlineData' => array(
									'data'     => self::TINY_PNG_B64,
									'mimeType' => 'image/png',
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The colorize prompt map must cover all three color modes.
	 */
	public function test_colorize_prompt_map_covers_all_modes() {
		$tool    = new WP_MCP_AI_Tool_Colorize_Image();
		$prompts = $this->invoke_method( $tool, 'get_colorize_prompts' );

		$this->assertSame( array( 'auto', 'vibrant', 'subtle' ), array_keys( $prompts ) );
		foreach ( $prompts as $prompt ) {
			$this->assertStringContainsString( 'color', strtolower( $prompt ) );
		}
	}

	/**
	 * Without a sidecar or provider keys the tool must error honestly.
	 */
	public function test_colorize_errors_honestly_without_backends() {
		$attachment_id = $this->create_image_attachment();
		$tool          = new WP_MCP_AI_Tool_Colorize_Image();

		$result = $tool->execute( array( 'attachment_id' => $attachment_id ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_no_provider', $result->get_error_code() );
	}

	/**
	 * The Gemini path must run end-to-end and stamp honest metadata.
	 */
	public function test_colorize_gemini_end_to_end() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'gemini_api_key' => 'gsk-test' )
		);
		WP_MCP_AI_Credential_Resolver::clear_cache();
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$this->mock_http( $this->gemini_edit_payload() );

		$attachment_id = $this->create_image_attachment();
		$tool          = new WP_MCP_AI_Tool_Colorize_Image();

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'color_mode'    => 'vibrant',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'gemini', $result['engine'] );
		$this->assertSame( 'vibrant', $result['color_mode'] );
		$this->assertStringContainsString( 'vibrant', $result['prompt'] );
		$this->assertTrue( wp_attachment_is_image( $result['attachment_id'] ) );
		$this->assertSame( '1', get_post_meta( $result['attachment_id'], '_wp_mcp_ai_colorized', true ) );
		$this->assertSame( 'vibrant', get_post_meta( $result['attachment_id'], '_wp_mcp_ai_color_mode', true ) );
	}

	/**
	 * The sidecar path must pass the operation and color mode through.
	 */
	public function test_colorize_sidecar_end_to_end() {
		$attachment_id = $this->create_image_attachment();

		$tool   = new WP_MCP_AI_Sidecar_Cluster_Test_Double_Colorize();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'use_remote'    => true,
				'color_mode'    => 'subtle',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( '/api/image/edit', $tool->captured_endpoint );
		$this->assertSame( 'colorize', $tool->captured_fields['operation'] );
		$this->assertSame( 'subtle', $tool->captured_fields['color_mode'] );
		$this->assertSame( 'sidecar', $result['engine'] );
		$this->assertSame( '1', get_post_meta( $result['attachment_id'], '_wp_mcp_ai_colorized', true ) );
	}

	/**
	 * Unknown style presets must be rejected with an honest error.
	 */
	public function test_style_rejects_unknown_preset() {
		$attachment_id = $this->create_image_attachment();
		$tool          = new WP_MCP_AI_Tool_Apply_Artistic_Style();

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'style'         => 'impressionism',
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_arguments', $result->get_error_code() );
	}

	/**
	 * The strength modifier must follow the deterministic thresholds.
	 */
	public function test_style_strength_modifier_thresholds() {
		$tool = new WP_MCP_AI_Tool_Apply_Artistic_Style();

		$strong = $this->invoke_method( $tool, 'get_style_strength_modifier', array( 0.9 ) );
		$subtle = $this->invoke_method( $tool, 'get_style_strength_modifier', array( 0.2 ) );
		$middle = $this->invoke_method( $tool, 'get_style_strength_modifier', array( 0.5 ) );

		$this->assertStringContainsString( 'strongly', $strong );
		$this->assertStringContainsString( 'subtly', $subtle );
		$this->assertSame( '', $middle );
	}

	/**
	 * The Gemini path must run end-to-end and stamp honest style metadata.
	 */
	public function test_style_gemini_end_to_end() {
		update_option(
			'wp_mcp_ai_settings',
			array( 'gemini_api_key' => 'gsk-test' )
		);
		WP_MCP_AI_Credential_Resolver::clear_cache();
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$this->mock_http( $this->gemini_edit_payload() );

		$attachment_id = $this->create_image_attachment();
		$tool          = new WP_MCP_AI_Tool_Apply_Artistic_Style();

		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'style'         => 'watercolor',
				'strength'      => 0.9,
				'style_image'   => array( 'attachment_id' => $attachment_id ),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'gemini', $result['engine'] );
		$this->assertSame( 'watercolor', $result['style'] );
		$this->assertSame( 0.9, $result['strength'] );
		$this->assertStringContainsString( 'strongly', $result['prompt'] );
		$this->assertFalse( $result['style_image_applied'] );
		$this->assertTrue( wp_attachment_is_image( $result['attachment_id'] ) );
		$this->assertSame( 'watercolor', get_post_meta( $result['attachment_id'], '_wp_mcp_ai_artistic_style', true ) );
	}

	/**
	 * The sidecar path must pass the operation and style slug through.
	 */
	public function test_style_sidecar_end_to_end() {
		$attachment_id = $this->create_image_attachment();

		$tool   = new WP_MCP_AI_Sidecar_Cluster_Test_Double_Style();
		$result = $tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'use_remote'    => true,
				'style'         => 'pop_art',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertSame( '/api/image/edit', $tool->captured_endpoint );
		$this->assertSame( 'style_transfer', $tool->captured_fields['operation'] );
		$this->assertSame( 'pop_art', $tool->captured_fields['style'] );
		$this->assertSame( 'sidecar', $result['engine'] );
		$this->assertSame( 'pop_art', get_post_meta( $result['attachment_id'], '_wp_mcp_ai_artistic_style', true ) );
	}

	/**
	 * W1d: optimize_image_sharp must re-route enhance to /api/image/enhance.
	 */
	public function test_optimize_image_sharp_enhance_reroutes_to_enhance_route() {
		$attachment_id = $this->create_image_attachment();
		$source_path   = get_attached_file( $attachment_id );

		$tool   = new WP_MCP_AI_Sidecar_Cluster_Test_Double_Optimize();
		$result = $this->invoke_method(
			$tool,
			'optimize_via_sidecar',
			array(
				array(
					'source'    => $source_path,
					'operation' => 'enhance',
					'sharpen'   => true,
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( '/api/image/enhance', $tool->captured_endpoint );
		$this->assertTrue( $tool->captured_fields['sharpen'] );

		// Blur stays unsupported on the worker and must error honestly.
		$blur_result = $this->invoke_method(
			$tool,
			'optimize_via_sidecar',
			array(
				array(
					'source'    => $source_path,
					'operation' => 'enhance',
					'blur'      => 2.0,
				),
			)
		);

		$this->assertIsArray( $blur_result );
		$this->assertArrayHasKey( 'error', $blur_result );
		$this->assertStringContainsString( 'blur', $blur_result['error'] );
	}
}
