<?php
/**
 * Markup subsystem test.
 *
 * Markup validator tests.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for Test_Markup_Validator.
 *
 * @group markup
 */
class Test_Markup_Validator extends WP_UnitTestCase {

	/**
	 * Test fixture builder.
	 *
	 * @return WP_MCP_AI_Markup_Request
	 */
	private function make_request() {
		return new WP_MCP_AI_Markup_Request(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array(
					'url'    => 'https://example.com/x.png',
					'width'  => 512,
					'height' => 512,
				),
				'target_type' => WP_MCP_AI_Markup_Request::TARGET_TYPE_IMAGE,
				'mode'        => WP_MCP_AI_Markup_Request::MODE_MASK,
			)
		);
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_rejects_non_array_payload() {
		$validator = new WP_MCP_AI_Markup_Validator();
		$result    = $validator->validate( $this->make_request(), 'not-an-array' );
		$this->assertWPError( $result );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_rejects_wrong_type() {
		$validator = new WP_MCP_AI_Markup_Validator();
		$result    = $validator->validate( $this->make_request(), array( 'type' => 'NotAnnotation' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_markup_invalid_type', $result->get_error_code() );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_accepts_minimal_annotation() {
		$validator  = new WP_MCP_AI_Markup_Validator();
		$annotation = array(
			'@context' => WP_MCP_AI_Markup_Elicitation::ANNOTATION_CONTEXT,
			'type'     => 'Annotation',
			'body'     => array(
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
								'x' => 100,
								'y' => 100,
							),
						),
					),
				),
			),
			'target'   => array(
				'source'   => 'https://example.com/x.png',
				'selector' => array(
					'type'   => 'RectSelector',
					'x'      => 10,
					'y'      => 10,
					'width'  => 90,
					'height' => 90,
				),
			),
		);
		$cleaned    = $validator->validate( $this->make_request(), $annotation );
		$this->assertIsArray( $cleaned );
		$this->assertSame( 'Annotation', $cleaned['type'] );
		$this->assertSame( 'rect', $cleaned['body'][0]['shape']['kind'] );
		$this->assertSame( 'RectSelector', $cleaned['target']['selector']['type'] );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_rejects_too_many_shapes() {
		$validator = new WP_MCP_AI_Markup_Validator();
		$body      = array();
		for ( $i = 0; $i < WP_MCP_AI_Markup_Validator::MAX_SHAPES + 1; $i++ ) {
			$body[] = array(
				'type'  => 'Shape',
				'shape' => array(
					'kind'   => 'rect',
					'points' => array(
						array(
							'x' => 0,
							'y' => 0,
						),
						array(
							'x' => 1,
							'y' => 1,
						),
					),
				),
			);
		}
		$result = $validator->validate(
			$this->make_request(),
			array(
				'type'   => 'Annotation',
				'body'   => $body,
				'target' => array( 'source' => 'https://example.com/x.png' ),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_markup_too_many_shapes', $result->get_error_code() );
	}

	/**
	 * Test case.
	 *
	 * @return void
	 */
	public function test_rejects_oversized_svg_selector() {
		$validator = new WP_MCP_AI_Markup_Validator();
		$svg       = '<svg>' . str_repeat( 'a', WP_MCP_AI_Markup_Validator::MAX_SVG_BYTES + 100 ) . '</svg>';
		$result    = $validator->validate(
			$this->make_request(),
			array(
				'type'   => 'Annotation',
				'body'   => array(),
				'target' => array(
					'source'   => 'https://example.com/x.png',
					'selector' => array(
						'type'  => 'SvgSelector',
						'value' => $svg,
					),
				),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_markup_svg_too_large', $result->get_error_code() );
	}
}
