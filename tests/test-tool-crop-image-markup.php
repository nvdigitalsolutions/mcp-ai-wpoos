<?php
/**
 * Tests for the markup-aware variant of WP_MCP_AI_Tool_Crop_Image.
 *
 * Exercises only the markup gating logic — the actual crop pipeline is
 * not invoked. We verify:
 *  - needs_markup returns null when request_user_crop is false
 *  - needs_markup returns null when manual x/y/width/height present
 *  - needs_markup returns null when aspect_ratio is present
 *  - needs_markup returns null when no source image can be resolved
 *  - needs_markup returns a Markup_Request when eligible
 *  - consume_markup denormalizes a [0,1]-space crop_rect to pixels
 *  - consume_markup passes through pixel-space crop_rect unchanged
 *  - consume_markup clears the elicitation flag before recursing
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-crop-image.php';

/**
 * Test_Tool_Crop_Image_Markup test case.
 *
 * @group markup
 * @group tools
 */
class Test_Tool_Crop_Image_Markup extends WP_UnitTestCase {

	/**
	 * Subject under test.
	 *
	 * @var WP_MCP_AI_Tool_Crop_Image
	 */
	private $tool;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tool = new WP_MCP_AI_Tool_Crop_Image();
	}

	/**
	 * Helper — create a tiny PNG and register it as an attachment.
	 *
	 * @param int $width  Width in pixels for the metadata.
	 * @param int $height Height in pixels for the metadata.
	 * @return int Attachment ID.
	 */
	private function factory_image_attachment( $width = 800, $height = 600 ) {
		$uploads  = wp_upload_dir();
		$filename = trailingslashit( $uploads['path'] ) . 'crop-test-' . wp_generate_password( 6, false ) . '.png';
		// 1x1 transparent PNG used purely as test fixture content.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $filename, $png_bytes );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'crop-test',
				'post_status'    => 'inherit',
			),
			$filename
		);
		// Override metadata with the requested dimensions for predictable tests.
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => (int) $width,
				'height' => (int) $height,
				'file'   => $filename,
			)
		);
		return $attachment_id;
	}

	/**
	 * Needs_markup returns null when request_user_crop is false.
	 */
	public function test_needs_markup_returns_null_when_flag_unset() {
		$attachment_id = $this->factory_image_attachment();
		$this->assertNull(
			$this->tool->needs_markup(
				array( 'attachment_id' => $attachment_id ),
				array()
			)
		);
	}

	/**
	 * Needs_markup returns null when manual pixel coords are supplied.
	 */
	public function test_needs_markup_returns_null_when_manual_coords_present() {
		$attachment_id = $this->factory_image_attachment();
		$this->assertNull(
			$this->tool->needs_markup(
				array(
					'attachment_id'     => $attachment_id,
					'request_user_crop' => true,
					'x'                 => 10,
					'y'                 => 20,
					'width'             => 100,
					'height'            => 100,
				),
				array()
			)
		);
	}

	/**
	 * Needs_markup returns null when an aspect_ratio is supplied.
	 */
	public function test_needs_markup_returns_null_when_aspect_ratio_present() {
		$attachment_id = $this->factory_image_attachment();
		$this->assertNull(
			$this->tool->needs_markup(
				array(
					'attachment_id'     => $attachment_id,
					'request_user_crop' => true,
					'aspect_ratio'      => '16:9',
				),
				array()
			)
		);
	}

	/**
	 * Needs_markup returns null when the image cannot be resolved.
	 */
	public function test_needs_markup_returns_null_when_no_source_image() {
		$this->assertNull(
			$this->tool->needs_markup(
				array( 'request_user_crop' => true ),
				array()
			)
		);
	}

	/**
	 * Needs_markup returns a populated WP_MCP_AI_Markup_Request when
	 * all preconditions are met.
	 */
	public function test_needs_markup_returns_request_when_eligible() {
		$attachment_id = $this->factory_image_attachment( 1024, 768 );
		$request       = $this->tool->needs_markup(
			array(
				'attachment_id'     => $attachment_id,
				'request_user_crop' => true,
			),
			array( 'assistant_id' => 42 )
		);

		$this->assertInstanceOf( WP_MCP_AI_Markup_Request::class, $request );
		$this->assertSame( 'crop_image', $request->get_tool_slug() );
		$this->assertSame( 'image', $request->get_target_type() );
		$this->assertSame( 'crop', $request->get_mode() );
		$target = $request->get_target();
		$this->assertSame( $attachment_id, (int) $target['attachment_id'] );
		$this->assertSame( 1024, (int) $target['width'] );
		$this->assertSame( 768, (int) $target['height'] );
		$this->assertSame( 42, $request->get_assistant_id() );
	}

	/**
	 * Consume_markup denormalizes a [0,1] crop_rect to pixel coords
	 * using the request target dimensions.
	 */
	public function test_consume_markup_denormalizes_normalized_rect() {
		$attachment_id = $this->factory_image_attachment( 1024, 768 );
		$request       = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'crop_image',
				'target_type' => 'image',
				'mode'        => 'crop',
				'target'      => array(
					'attachment_id' => $attachment_id,
					'width'         => 1024,
					'height'        => 768,
				),
			)
		);
		$result        = new WP_MCP_AI_Markup_Result(
			$request,
			array(
				'type'  => 'AnnotationCollection',
				'items' => array(),
			),
			array(),
			array(
				'crop_rect' => array(
					'x'          => 0.25,
					'y'          => 0.5,
					'width'      => 0.5,
					'height'     => 0.25,
					'normalized' => true,
				),
			)
		);

		$tool = $this->getMockBuilder( WP_MCP_AI_Tool_Crop_Image::class )
			->onlyMethods( array( 'execute' ) )
			->getMock();
		$tool->expects( $this->once() )
			->method( 'execute' )
			->with(
				$this->callback(
					static function ( $args ) {
						return 256 === (int) $args['x']
							&& 384 === (int) $args['y']
							&& 512 === (int) $args['width']
							&& 192 === (int) $args['height']
							&& empty( $args['request_user_crop'] );
					}
				),
				$this->anything()
			)
			->willReturn( array( 'success' => true ) );

		$out = $tool->consume_markup(
			array(
				'attachment_id'     => $attachment_id,
				'request_user_crop' => true,
			),
			$result,
			array()
		);
		$this->assertIsArray( $out );
		$this->assertTrue( $out['success'] );
	}

	/**
	 * Consume_markup passes through pixel-space crop_rect unchanged.
	 */
	public function test_consume_markup_uses_pixel_rect_unchanged() {
		$attachment_id = $this->factory_image_attachment( 1024, 768 );
		$request       = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'crop_image',
				'target_type' => 'image',
				'mode'        => 'crop',
				'target'      => array(
					'attachment_id' => $attachment_id,
					'width'         => 1024,
					'height'        => 768,
				),
			)
		);
		$result        = new WP_MCP_AI_Markup_Result(
			$request,
			array(
				'type'  => 'AnnotationCollection',
				'items' => array(),
			),
			array(),
			array(
				'crop_rect' => array(
					'x'          => 100,
					'y'          => 200,
					'width'      => 300,
					'height'     => 400,
					'normalized' => false,
				),
			)
		);

		$tool = $this->getMockBuilder( WP_MCP_AI_Tool_Crop_Image::class )
			->onlyMethods( array( 'execute' ) )
			->getMock();
		$tool->expects( $this->once() )
			->method( 'execute' )
			->with(
				$this->callback(
					static function ( $args ) {
						return 100 === (int) $args['x']
							&& 200 === (int) $args['y']
							&& 300 === (int) $args['width']
							&& 400 === (int) $args['height']
							&& empty( $args['request_user_crop'] );
					}
				),
				$this->anything()
			)
			->willReturn( array( 'success' => true ) );

		$out = $tool->consume_markup(
			array(
				'attachment_id'     => $attachment_id,
				'request_user_crop' => true,
			),
			$result,
			array()
		);
		$this->assertIsArray( $out );
		$this->assertTrue( $out['success'] );
	}
}
