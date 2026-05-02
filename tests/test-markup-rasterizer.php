<?php
/**
 * Markup subsystem test.
 *
 * Markup rasterizer tests (mask PNG, position vector, crop rect).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Test_Markup_Rasterizer.
 *
 * @group markup
 */
class Test_Markup_Rasterizer extends WP_UnitTestCase {

	/**
	 * Test fixture builder.
	 *
	 * @param string $mode Markup mode.
	 * @return WP_MCP_AI_Markup_Request
	 */
	private function make_request( $mode = 'mask' ) {
		return new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array(
					'attachment_id' => 1,
					'width'         => 64,
					'height'        => 64,
				),
				'target_type' => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'        => $mode,
				'user_id'     => 0,
			)
		);
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_crop_rect_from_polygon() {
		$rasterizer = new WP_MCP_AI_Markup_Rasterizer();
		$annotation = array(
			'type' => 'Annotation',
			'body' => array(
				array(
					'type'  => 'Shape',
					'shape' => array(
						'kind'   => 'polygon',
						'points' => array(
							array(
								'x' => 10,
								'y' => 12,
							),
							array(
								'x' => 50,
								'y' => 12,
							),
							array(
								'x' => 50,
								'y' => 60,
							),
							array(
								'x' => 10,
								'y' => 60,
							),
						),
					),
				),
			),
		);
		$artifacts  = $rasterizer->rasterize( $this->make_request( WP_MCP_AI_Markup_Request::MODE_CROP ), $annotation );
		$this->assertArrayHasKey( 'crop_rect', $artifacts );
		$this->assertSame( 10.0, $artifacts['crop_rect']['x'] );
		$this->assertSame( 12.0, $artifacts['crop_rect']['y'] );
		$this->assertSame( 40.0, $artifacts['crop_rect']['width'] );
		$this->assertSame( 48.0, $artifacts['crop_rect']['height'] );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_position_vector_from_arrow() {
		$rasterizer = new WP_MCP_AI_Markup_Rasterizer();
		$annotation = array(
			'type' => 'Annotation',
			'body' => array(
				array(
					'type'  => 'Vector',
					'shape' => array(
						'kind'   => 'arrow',
						'points' => array(
							array(
								'x' => 0.1,
								'y' => 0.1,
							),
							array(
								'x' => 0.8,
								'y' => 0.7,
							),
						),
					),
				),
			),
		);
		$artifacts  = $rasterizer->rasterize( $this->make_request( WP_MCP_AI_Markup_Request::MODE_POSITION ), $annotation );
		$this->assertArrayHasKey( 'position_vector', $artifacts );
		$this->assertSame( 0.1, $artifacts['position_vector']['from']['x'] );
		$this->assertSame( 0.8, $artifacts['position_vector']['to']['x'] );
		$this->assertTrue( $artifacts['position_vector']['normalized'] );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_redaction_rects_collected() {
		$rasterizer = new WP_MCP_AI_Markup_Rasterizer();
		$annotation = array(
			'type' => 'Annotation',
			'body' => array(
				array(
					'type'  => 'Shape',
					'shape' => array(
						'kind'   => 'rect',
						'page'   => 2,
						'points' => array(
							array(
								'x' => 5,
								'y' => 6,
							),
							array(
								'x' => 15,
								'y' => 16,
							),
						),
					),
				),
			),
		);
		$artifacts  = $rasterizer->rasterize( $this->make_request( WP_MCP_AI_Markup_Request::MODE_REDACT ), $annotation );
		$this->assertArrayHasKey( 'redaction_rects', $artifacts );
		$this->assertCount( 1, $artifacts['redaction_rects'] );
		$this->assertSame( 2, $artifacts['redaction_rects'][0]['page'] );
		$this->assertSame( 10.0, $artifacts['redaction_rects'][0]['width'] );
	}

	/**
	 * Mask PNG produces zero-alpha inside the marked rect.
	 *
	 * @requires extension gd
	 * @return void
	 */
	public function test_mask_png_has_zero_alpha_inside_rect() {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is required.' );
		}
		$rasterizer = new WP_MCP_AI_Markup_Rasterizer();
		$annotation = array(
			'type' => 'Annotation',
			'body' => array(
				array(
					'type'  => 'Shape',
					'shape' => array(
						'kind'   => 'rect',
						'points' => array(
							array(
								'x' => 10,
								'y' => 10,
							),
							array(
								'x' => 30,
								'y' => 30,
							),
						),
					),
				),
			),
		);
		$artifacts  = $rasterizer->rasterize( $this->make_request( WP_MCP_AI_Markup_Request::MODE_MASK ), $annotation );
		$this->assertArrayHasKey( 'mask_attachment_id', $artifacts );
		$attach_id = (int) $artifacts['mask_attachment_id'];
		$path      = get_attached_file( $attach_id );
		$this->assertFileExists( $path );

		$image = imagecreatefrompng( $path );
		$this->assertNotFalse( $image );

		// Inside region — alpha should be 127 (transparent in GD).
		$inside_rgba  = imagecolorat( $image, 20, 20 );
		$inside_alpha = ( $inside_rgba >> 24 ) & 0x7F;
		// Outside region — alpha should be 0 (opaque).
		$outside_rgba  = imagecolorat( $image, 5, 5 );
		$outside_alpha = ( $outside_rgba >> 24 ) & 0x7F;

		$this->assertSame( 127, $inside_alpha, 'Inside the marked rect should be fully transparent.' );
		$this->assertSame( 0, $outside_alpha, 'Outside the marked rect should be fully opaque.' );

		imagedestroy( $image );
		wp_delete_attachment( $attach_id, true );
	}
}
