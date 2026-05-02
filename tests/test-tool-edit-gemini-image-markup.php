<?php
/**
 * Tests for the markup-aware variant of WP_MCP_AI_Tool_Edit_Gemini_Image.
 *
 * Exercises only the `needs_markup` and `consume_markup` lifecycle —
 * the actual Gemini API call is not invoked. We verify:
 *  - needs_markup returns null when request_user_region is false
 *  - needs_markup returns null when target_region is already supplied
 *  - needs_markup returns null when no source image can be resolved
 *  - needs_markup returns a populated request when eligible
 *  - consume_markup denormalizes [0,1] region_rect → pixel coords,
 *    augments the prompt with the explicit directive, persists
 *    target_region, and clears the elicitation flag
 *  - consume_markup leaves pixel-space coords unchanged
 *  - consume_markup with empty/missing region_rect still clears the flag
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-gemini-image.php';

/**
 * Test_Tool_Edit_Gemini_Image_Markup test case.
 *
 * @group markup
 * @group tools
 */
class Test_Tool_Edit_Gemini_Image_Markup extends WP_UnitTestCase {

	/**
	 * Subject under test.
	 *
	 * @var WP_MCP_AI_Tool_Edit_Gemini_Image
	 */
	private $tool;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tool = new WP_MCP_AI_Tool_Edit_Gemini_Image();
	}

	/**
	 * Helper — create a tiny PNG attachment with explicit dimensions.
	 *
	 * @param int $width  Width in pixels for the metadata.
	 * @param int $height Height in pixels for the metadata.
	 * @return int Attachment ID.
	 */
	private function factory_image_attachment( $width = 800, $height = 600 ) {
		$uploads  = wp_upload_dir();
		$filename = trailingslashit( $uploads['path'] ) . 'gemini-test-' . wp_generate_password( 6, false ) . '.png';
		// 1x1 transparent PNG used purely as test fixture content.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $filename, $png_bytes );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'gemini-test',
				'post_status'    => 'inherit',
			),
			$filename
		);
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
	 * Needs_markup returns null when request_user_region is unset.
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
	 * Needs_markup returns null when target_region is already supplied.
	 */
	public function test_needs_markup_returns_null_when_target_region_present() {
		$attachment_id = $this->factory_image_attachment();
		$this->assertNull(
			$this->tool->needs_markup(
				array(
					'attachment_id'       => $attachment_id,
					'request_user_region' => true,
					'target_region'       => array(
						'x'      => 0,
						'y'      => 0,
						'width'  => 10,
						'height' => 10,
					),
				),
				array()
			)
		);
	}

	/**
	 * Needs_markup returns null when no source image is resolvable.
	 */
	public function test_needs_markup_returns_null_when_no_source_image() {
		$this->assertNull(
			$this->tool->needs_markup(
				array( 'request_user_region' => true ),
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
				'attachment_id'       => $attachment_id,
				'request_user_region' => true,
				'prompt'              => 'change sky to sunset',
			),
			array( 'assistant_id' => 7 )
		);

		$this->assertInstanceOf( WP_MCP_AI_Markup_Request::class, $request );
		$this->assertSame( 'edit_gemini_image', $request->get_tool_slug() );
		$this->assertSame( 'image', $request->get_target_type() );
		$this->assertSame( 'region', $request->get_mode() );
		$target = $request->get_target();
		$this->assertSame( $attachment_id, (int) $target['attachment_id'] );
		$this->assertSame( 1024, (int) $target['width'] );
		$this->assertSame( 768, (int) $target['height'] );
		$this->assertSame( 7, $request->get_assistant_id() );
		$this->assertStringContainsString( 'change sky to sunset', $request->get_instructions() );
	}

	/**
	 * Consume_markup denormalizes [0,1] region_rect to pixels, augments
	 * the prompt with the explicit directive, persists target_region,
	 * and clears the elicitation flag.
	 */
	public function test_consume_markup_denormalizes_and_augments_prompt() {
		$attachment_id = $this->factory_image_attachment( 1024, 768 );
		$request       = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'edit_gemini_image',
				'target_type' => 'image',
				'mode'        => 'region',
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
				'region_rect' => array(
					'x'          => 0.25,
					'y'          => 0.5,
					'width'      => 0.5,
					'height'     => 0.25,
					'normalized' => true,
				),
			)
		);

		$tool          = $this->getMockBuilder( WP_MCP_AI_Tool_Edit_Gemini_Image::class )
			->onlyMethods( array( 'execute' ) )
			->getMock();
		$captured_args = null;
		$tool->expects( $this->once() )
			->method( 'execute' )
			->willReturnCallback(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return array( 'success' => true );
				}
			);

		$out = $tool->consume_markup(
			array(
				'attachment_id'       => $attachment_id,
				'request_user_region' => true,
				'prompt'              => 'change sky to sunset',
			),
			$result,
			array()
		);

		$this->assertIsArray( $out );
		$this->assertTrue( $out['success'] );
		$this->assertIsArray( $captured_args );
		// target_region populated with denormalized pixel coords.
		$this->assertSame( 256, (int) $captured_args['target_region']['x'] );
		$this->assertSame( 384, (int) $captured_args['target_region']['y'] );
		$this->assertSame( 512, (int) $captured_args['target_region']['width'] );
		$this->assertSame( 192, (int) $captured_args['target_region']['height'] );
		$this->assertSame( 1024, (int) $captured_args['target_region']['image_width'] );
		$this->assertSame( 768, (int) $captured_args['target_region']['image_height'] );
		// Prompt augmented with explicit directive but original prompt preserved.
		$this->assertStringContainsString( 'change sky to sunset', $captured_args['prompt'] );
		$this->assertStringContainsString( 'x=256', $captured_args['prompt'] );
		$this->assertStringContainsString( 'y=384', $captured_args['prompt'] );
		$this->assertStringContainsString( 'width=512', $captured_args['prompt'] );
		$this->assertStringContainsString( 'height=192', $captured_args['prompt'] );
		$this->assertStringContainsString( '1024x768', $captured_args['prompt'] );
		// Elicitation flag cleared so the recursive call doesn't re-trigger.
		$this->assertEmpty( $captured_args['request_user_region'] );
	}

	/**
	 * Consume_markup leaves pixel-space coords unchanged.
	 */
	public function test_consume_markup_passes_pixel_rect_unchanged() {
		$attachment_id = $this->factory_image_attachment( 1024, 768 );
		$request       = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'edit_gemini_image',
				'target_type' => 'image',
				'mode'        => 'region',
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
				'region_rect' => array(
					'x'          => 100,
					'y'          => 200,
					'width'      => 300,
					'height'     => 400,
					'normalized' => false,
				),
			)
		);

		$tool          = $this->getMockBuilder( WP_MCP_AI_Tool_Edit_Gemini_Image::class )
			->onlyMethods( array( 'execute' ) )
			->getMock();
		$captured_args = null;
		$tool->expects( $this->once() )
			->method( 'execute' )
			->willReturnCallback(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return array( 'success' => true );
				}
			);

		$tool->consume_markup(
			array(
				'attachment_id'       => $attachment_id,
				'request_user_region' => true,
				'prompt'              => '',
			),
			$result,
			array()
		);

		$this->assertSame( 100, (int) $captured_args['target_region']['x'] );
		$this->assertSame( 200, (int) $captured_args['target_region']['y'] );
		$this->assertSame( 300, (int) $captured_args['target_region']['width'] );
		$this->assertSame( 400, (int) $captured_args['target_region']['height'] );
		// With no original prompt, the prompt is the directive only.
		$this->assertStringContainsString( 'x=100', $captured_args['prompt'] );
	}

	/**
	 * Consume_markup clears the elicitation flag even when the
	 * rasterizer returned no usable rect.
	 */
	public function test_consume_markup_clears_flag_when_rect_missing() {
		$attachment_id = $this->factory_image_attachment( 1024, 768 );
		$request       = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'edit_gemini_image',
				'target_type' => 'image',
				'mode'        => 'region',
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
			array() // No region_rect at all.
		);

		$tool          = $this->getMockBuilder( WP_MCP_AI_Tool_Edit_Gemini_Image::class )
			->onlyMethods( array( 'execute' ) )
			->getMock();
		$captured_args = null;
		$tool->expects( $this->once() )
			->method( 'execute' )
			->willReturnCallback(
				function ( $args ) use ( &$captured_args ) {
					$captured_args = $args;
					return array( 'success' => true );
				}
			);

		$tool->consume_markup(
			array(
				'attachment_id'       => $attachment_id,
				'request_user_region' => true,
				'prompt'              => 'original prompt',
			),
			$result,
			array()
		);

		$this->assertEmpty( $captured_args['request_user_region'] );
		$this->assertSame( 'original prompt', $captured_args['prompt'] );
		$this->assertArrayNotHasKey( 'target_region', $captured_args );
	}
}
