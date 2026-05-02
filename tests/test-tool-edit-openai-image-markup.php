<?php
/**
 * Tests for the markup-aware variant of WP_MCP_AI_Tool_Edit_OpenAI_Image.
 *
 * Exercises only the markup gating logic — the actual OpenAI HTTP call
 * is not invoked. We verify:
 *  - needs_markup returns null when request_user_mask is false
 *  - needs_markup returns null when a mask_id is already present
 *  - needs_markup returns null when image_id is missing/invalid
 *  - needs_markup returns a Markup_Request when image is valid and the
 *    elicitation flag is set
 *  - consume_markup injects mask_attachment_id into mask_id and clears
 *    the elicitation flag before recursing
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-edit-openai-image.php';

/**
 * Test_Tool_Edit_OpenAI_Image_Markup test case.
 *
 * @group markup
 * @group tools
 */
class Test_Tool_Edit_OpenAI_Image_Markup extends WP_UnitTestCase {

	/**
	 * Subject under test.
	 *
	 * @var WP_MCP_AI_Tool_Edit_OpenAI_Image
	 */
	private $tool;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tool = new WP_MCP_AI_Tool_Edit_OpenAI_Image();
	}

	/**
	 * Helper — create a tiny PNG and register it as an attachment.
	 *
	 * @return int Attachment ID.
	 */
	private function factory_image_attachment() {
		$uploads  = wp_upload_dir();
		$filename = trailingslashit( $uploads['path'] ) . 'markup-test-' . wp_generate_password( 6, false ) . '.png';
		// 1x1 transparent PNG used purely as test fixture content.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$png_bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $filename, $png_bytes );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'markup-test',
				'post_status'    => 'inherit',
			),
			$filename
		);
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $filename ) );
		return $attachment_id;
	}

	/**
	 * Needs_markup returns null when request_user_mask is false.
	 */
	public function test_needs_markup_returns_null_when_flag_unset() {
		$attachment_id = $this->factory_image_attachment();
		$this->assertNull(
			$this->tool->needs_markup(
				array(
					'image_id' => $attachment_id,
					'prompt'   => 'test',
				),
				array()
			)
		);
	}

	/**
	 * Needs_markup returns null when caller already provided a mask_id.
	 */
	public function test_needs_markup_returns_null_when_mask_already_present() {
		$attachment_id = $this->factory_image_attachment();
		$this->assertNull(
			$this->tool->needs_markup(
				array(
					'image_id'          => $attachment_id,
					'prompt'            => 'test',
					'request_user_mask' => true,
					'mask_id'           => $attachment_id,
				),
				array()
			)
		);
	}

	/**
	 * Needs_markup returns null when image_id is missing entirely.
	 */
	public function test_needs_markup_returns_null_when_image_missing() {
		$this->assertNull(
			$this->tool->needs_markup(
				array(
					'request_user_mask' => true,
					'prompt'            => 'test',
				),
				array()
			)
		);
	}

	/**
	 * Needs_markup returns null when image_id does not refer to an image.
	 */
	public function test_needs_markup_returns_null_for_non_image_attachment() {
		// Non-image attachment ID (large random number unlikely to exist).
		$this->assertNull(
			$this->tool->needs_markup(
				array(
					'image_id'          => 999999,
					'prompt'            => 'test',
					'request_user_mask' => true,
				),
				array()
			)
		);
	}

	/**
	 * Needs_markup returns a populated WP_MCP_AI_Markup_Request when all
	 * preconditions are met.
	 */
	public function test_needs_markup_returns_request_when_eligible() {
		$attachment_id = $this->factory_image_attachment();
		$request       = $this->tool->needs_markup(
			array(
				'image_id'          => $attachment_id,
				'prompt'            => 'replace the sky',
				'request_user_mask' => true,
			),
			array( 'assistant_id' => 7 )
		);

		$this->assertInstanceOf( WP_MCP_AI_Markup_Request::class, $request );
		$this->assertSame( 'edit_openai_image', $request->get_tool_slug() );
		$this->assertSame( 'image', $request->get_target_type() );
		$this->assertSame( 'mask', $request->get_mode() );
		$target = $request->get_target();
		$this->assertSame( $attachment_id, (int) $target['attachment_id'] );
		$this->assertSame( 7, $request->get_assistant_id() );
		$this->assertStringContainsString( 'replace the sky', $request->get_instructions() );
	}

	/**
	 * Consume_markup injects the rasterized mask attachment ID into
	 * mask_id, clears the elicitation flag, and re-invokes execute().
	 */
	public function test_consume_markup_merges_mask_and_clears_flag() {
		$attachment_id = $this->factory_image_attachment();
		$mask_id       = $this->factory_image_attachment();

		// Build a fake markup result carrying the rasterized mask.
		$request = new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'edit_openai_image',
				'target_type' => 'image',
				'mode'        => 'mask',
				'target'      => array( 'attachment_id' => $attachment_id ),
			)
		);
		$result  = new WP_MCP_AI_Markup_Result(
			$request,
			array(
				'type'  => 'AnnotationCollection',
				'items' => array(),
			),
			array(),
			array( 'mask_attachment_id' => $mask_id )
		);

		// Stub execute() to capture the merged arguments instead of
		// hitting OpenAI. We use a partial mock keeping every other
		// method real.
		$tool = $this->getMockBuilder( WP_MCP_AI_Tool_Edit_OpenAI_Image::class )
			->onlyMethods( array( 'execute' ) )
			->getMock();
		$tool->expects( $this->once() )
			->method( 'execute' )
			->with(
				$this->callback(
					function ( $args ) use ( $attachment_id, $mask_id ) {
						return isset( $args['image_id'] ) && (int) $args['image_id'] === $attachment_id
						&& isset( $args['mask_id'] ) && (int) $args['mask_id'] === $mask_id
						&& empty( $args['request_user_mask'] );
					}
				),
				$this->anything()
			)
			->willReturn(
				array(
					'success' => true,
					'images'  => array(),
				)
			);

		$out = $tool->consume_markup(
			array(
				'image_id'          => $attachment_id,
				'prompt'            => 'replace sky',
				'request_user_mask' => true,
			),
			$result,
			array()
		);
		$this->assertIsArray( $out );
		$this->assertTrue( $out['success'] );
	}
}
