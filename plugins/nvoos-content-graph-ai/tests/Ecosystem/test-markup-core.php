<?php
/**
 * Markup subsystem core port tests (Wave E6, sub-cluster 2).
 *
 * Characterization suite for the ported `NvoosContentGraphAi\Engine\Markup`
 * core: the request value object (round-trip, validation, TTL clamp,
 * target sanitization), the elicitation envelopes (widget payload,
 * MCP envelope with URL mode, motivation mapping), the transient store
 * (save/get/consume/cap/expiry/cleanup), the W3C annotation validator
 * (envelope, body shapes, selectors, capability gate), and the
 * rasterizer (crop rect, position vector, redaction rects, GD mask).
 * Runs in both matrices.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Engine\Markup\MarkupElicitation;
use NvoosContentGraphAi\Engine\Markup\MarkupRasterizer;
use NvoosContentGraphAi\Engine\Markup\MarkupRequest;
use NvoosContentGraphAi\Engine\Markup\MarkupStore;
use NvoosContentGraphAi\Engine\Markup\MarkupValidator;

/**
 * @group markup
 */
class Test_Markup_Core extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		\delete_option( MarkupStore::INDEX_OPTION );
		\delete_option( 'wp_mcp_ai_settings' );
	}

	public function tearDown(): void {
		\delete_option( MarkupStore::INDEX_OPTION );
		\delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Build a minimal image-mask request.
	 *
	 * @param string $mode Mode.
	 * @return MarkupRequest
	 */
	private function make_request( string $mode = 'mask' ): MarkupRequest {
		return new MarkupRequest(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array(
					'attachment_id' => 1,
					'width'         => 64,
					'height'        => 64,
				),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => $mode,
				'user_id'     => 0,
			)
		);
	}

	// ─── Request value object ─────────────────────────────────────

	public function test_request_round_trip(): void {
		$request = new MarkupRequest(
			array(
				'tool_slug'    => 'image_inpainting',
				'target'       => array( 'attachment_id' => 42 ),
				'target_type'  => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'         => MarkupRequest::MODE_MASK,
				'instructions' => 'Mark the logo.',
				'assistant_id' => 5,
				'user_id'      => 7,
			)
		);

		$data = $request->to_array();
		$this->assertSame( 'image_inpainting', $data['tool_slug'] );
		$this->assertSame( 42, $data['target']['attachment_id'] );
		$this->assertSame( 5, $data['assistant_id'] );
		$this->assertSame( 7, $data['user_id'] );

		$rebuilt = MarkupRequest::from_array( $data );
		$this->assertInstanceOf( MarkupRequest::class, $rebuilt );
		$this->assertSame( $request->get_request_id(), $rebuilt->get_request_id() );
		$this->assertSame( $request->get_expires_at(), $rebuilt->get_expires_at() );
		$this->assertSame( 'Mark the logo.', $rebuilt->get_instructions() );
	}

	public function test_invalid_mode_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		new MarkupRequest(
			array(
				'tool_slug'   => 'x',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => 'not_a_mode',
			)
		);
	}

	public function test_invalid_target_type_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		new MarkupRequest(
			array(
				'tool_slug'   => 'x',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => 'video',
				'mode'        => MarkupRequest::MODE_MASK,
			)
		);
	}

	public function test_request_id_generation_shape(): void {
		$this->assertStringStartsWith( 'mr_', MarkupRequest::generate_id() );
	}

	public function test_ttl_clamped_and_expiry_probe(): void {
		$request = new MarkupRequest(
			array(
				'tool_slug'   => 'x',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => MarkupRequest::MODE_MASK,
				'ttl'         => 1,
			)
		);
		$this->assertFalse( $request->is_expired() );

		$expired = new MarkupRequest(
			array(
				'tool_slug'   => 'x',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => MarkupRequest::MODE_MASK,
				'expires_at'  => \time() - 10,
			)
		);
		$this->assertTrue( $expired->is_expired() );
	}

	// ─── Elicitation envelopes ────────────────────────────────────

	public function test_widget_payload_shape(): void {
		$request = $this->make_request( MarkupRequest::MODE_MASK );
		$payload = MarkupElicitation::to_widget_payload( $request );

		$this->assertSame( 'markup_elicitation', $payload['type'] );
		$this->assertSame( $request->get_request_id(), $payload['request_id'] );
		$this->assertSame( 'image_inpainting', $payload['tool'] );
		$this->assertSame( 'mask', $payload['mode'] );
		$this->assertSame( 1, $payload['target']['attachment_id'] );
		$this->assertStringContainsString( '/mcp-ai/v1/markup/', $payload['submit_url'] );
		$this->assertArrayHasKey( 'fallback_url', $payload );
	}

	public function test_mcp_envelope_includes_url_mode(): void {
		$request     = $this->make_request( MarkupRequest::MODE_MASK );
		$elicitation = MarkupElicitation::to_mcp_elicitation( $request );

		$this->assertSame( 'elicitation/create', $elicitation['method'] );
		$this->assertArrayHasKey( 'markup', $elicitation['params']['requestedSchema']['properties'] );
		$this->assertContains( 'markup', $elicitation['params']['requestedSchema']['required'] );
		$this->assertArrayHasKey( 'url', $elicitation['params']['urlMode'] );
		$this->assertSame( $request->get_request_id(), $elicitation['params']['_nvoos']['request_id'] );
	}

	public function test_motivation_mapping(): void {
		$this->assertSame( 'moderating', MarkupElicitation::motivation_for_mode( MarkupRequest::MODE_REDACT ) );
		$this->assertSame( 'commenting', MarkupElicitation::motivation_for_mode( MarkupRequest::MODE_ANNOTATE ) );
		$this->assertSame( 'highlighting', MarkupElicitation::motivation_for_mode( MarkupRequest::MODE_TEXT_RANGE ) );
		$this->assertSame( 'linking', MarkupElicitation::motivation_for_mode( MarkupRequest::MODE_POSITION ) );
		$this->assertSame( 'identifying', MarkupElicitation::motivation_for_mode( MarkupRequest::MODE_CROP ) );
		$this->assertSame( 'editing', MarkupElicitation::motivation_for_mode( MarkupRequest::MODE_MASK ) );
		$this->assertSame( 'editing', MarkupElicitation::motivation_for_mode( MarkupRequest::MODE_REGION ) );
	}

	public function test_build_annotation_attachment_source(): void {
		$request    = $this->make_request( MarkupRequest::MODE_MASK );
		$annotation = MarkupElicitation::build_annotation( $request );

		$this->assertSame( 'Annotation', $annotation['type'] );
		$this->assertSame( 'wp-attachment://1', $annotation['target']['source'] );
		$this->assertSame( MarkupElicitation::ANNOTATION_CONTEXT, $annotation['@context'] );
	}

	// ─── Store ─────────────────────────────────────────────────────

	public function test_save_and_get(): void {
		$store   = new MarkupStore();
		$request = $this->make_request( MarkupRequest::MODE_MASK );

		$this->assertTrue( $store->save( $request ) );
		$fetched = $store->get( $request->get_request_id() );
		$this->assertInstanceOf( MarkupRequest::class, $fetched );
		$this->assertSame( $request->get_request_id(), $fetched->get_request_id() );
	}

	public function test_consume_deletes_on_read(): void {
		$store   = new MarkupStore();
		$request = $this->make_request( MarkupRequest::MODE_MASK );
		$store->save( $request );

		$consumed = $store->consume( $request->get_request_id() );
		$this->assertInstanceOf( MarkupRequest::class, $consumed );
		$this->assertNull( $store->get( $request->get_request_id() ) );
	}

	public function test_per_assistant_cap(): void {
		$store = new MarkupStore();
		for ( $i = 0; $i < MarkupStore::MAX_PER_ASSISTANT; $i++ ) {
			$request = new MarkupRequest(
				array(
					'tool_slug'    => 't' . $i,
					'target'       => array( 'url' => 'https://example.com/' . $i . '.png' ),
					'target_type'  => MarkupRequest::TARGET_TYPE_IMAGE,
					'mode'         => MarkupRequest::MODE_MASK,
					'assistant_id' => 9,
				)
			);
			$this->assertTrue( $store->save( $request ) );
		}

		$overflow = new MarkupRequest(
			array(
				'tool_slug'    => 'overflow',
				'target'       => array( 'url' => 'https://example.com/overflow.png' ),
				'target_type'  => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'         => MarkupRequest::MODE_MASK,
				'assistant_id' => 9,
			)
		);
		$result   = $store->save( $overflow );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_markup_too_many_requests', $result->get_error_code() );
	}

	public function test_expired_entries_returned_as_null(): void {
		$store   = new MarkupStore();
		$request = new MarkupRequest(
			array(
				'tool_slug'   => 'x',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => MarkupRequest::MODE_MASK,
				'expires_at'  => \time() - 5,
			)
		);
		$store->save( $request );

		$this->assertNull( $store->get( $request->get_request_id() ) );
	}

	public function test_cleanup_removes_expired_index_entries(): void {
		$store   = new MarkupStore();
		$request = new MarkupRequest(
			array(
				'tool_slug'   => 'x',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => MarkupRequest::MODE_MASK,
				'expires_at'  => \time() - 5,
			)
		);
		$store->save( $request );

		$this->assertGreaterThanOrEqual( 1, $store->cleanup_expired() );

		$index = \get_option( MarkupStore::INDEX_OPTION, array() );
		$this->assertEmpty( $index );
	}

	// ─── Validator ────────────────────────────────────────────────

	public function test_rejects_non_array_payload(): void {
		$validator = new MarkupValidator();
		$result    = $validator->validate( $this->make_request( MarkupRequest::MODE_MASK ), 'not-array' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_markup_invalid_payload', $result->get_error_code() );
	}

	public function test_rejects_wrong_type(): void {
		$validator = new MarkupValidator();
		$result    = $validator->validate(
			$this->make_request( MarkupRequest::MODE_MASK ),
			array(
				'type'   => 'NotAnnotation',
				'body'   => array(),
				'target' => array( 'source' => 'https://example.com/x.png' ),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_markup_invalid_type', $result->get_error_code() );
	}

	public function test_accepts_minimal_annotation(): void {
		$validator = new MarkupValidator();
		$request   = new MarkupRequest(
			array(
				'tool_slug'   => 'image_inpainting',
				'target'      => array( 'url' => 'https://example.com/x.png' ),
				'target_type' => MarkupRequest::TARGET_TYPE_IMAGE,
				'mode'        => MarkupRequest::MODE_MASK,
				'user_id'     => 0,
			)
		);
		$cleaned   = $validator->validate(
			$request,
			array(
				'type'   => 'Annotation',
				'body'   => array(),
				'target' => array( 'source' => 'https://example.com/x.png' ),
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $cleaned );
		$this->assertSame( 'Annotation', $cleaned['type'] );
		$this->assertSame( 'editing', $cleaned['motivation'] );
	}

	public function test_rejects_too_many_shapes(): void {
		$validator = new MarkupValidator();
		$body      = array();
		for ( $i = 0; $i <= MarkupValidator::MAX_SHAPES; $i++ ) {
			$body[] = array(
				'type'  => 'TextualBody',
				'value' => 'x',
			);
		}
		$result = $validator->validate(
			$this->make_request( MarkupRequest::MODE_MASK ),
			array(
				'type'   => 'Annotation',
				'body'   => $body,
				'target' => array( 'source' => 'https://example.com/x.png' ),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_markup_too_many_shapes', $result->get_error_code() );
	}

	public function test_rejects_oversized_svg_selector(): void {
		$validator = new MarkupValidator();
		$svg       = \str_repeat( 'a', MarkupValidator::MAX_SVG_BYTES + 1 );
		$result    = $validator->validate(
			$this->make_request( MarkupRequest::MODE_MASK ),
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

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_markup_svg_too_large', $result->get_error_code() );
	}

	public function test_rejects_unknown_selector(): void {
		$validator = new MarkupValidator();
		$result    = $validator->validate(
			$this->make_request( MarkupRequest::MODE_MASK ),
			array(
				'type'   => 'Annotation',
				'body'   => array(),
				'target' => array(
					'source'   => 'https://example.com/x.png',
					'selector' => array(
						'type' => 'ExploitSelector',
					),
				),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_markup_unknown_selector', $result->get_error_code() );
	}

	public function test_rejects_invalid_shape_kind(): void {
		$validator = new MarkupValidator();
		$result    = $validator->validate(
			$this->make_request( MarkupRequest::MODE_MASK ),
			array(
				'type'   => 'Annotation',
				'body'   => array(
					array(
						'type'  => 'Shape',
						'shape' => array(
							'kind'   => 'exploit',
							'points' => array(),
						),
					),
				),
				'target' => array( 'source' => 'https://example.com/x.png' ),
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_markup_invalid_shape', $result->get_error_code() );
	}

	// ─── Rasterizer ───────────────────────────────────────────────

	public function test_crop_rect_from_polygon(): void {
		$rasterizer = new MarkupRasterizer();
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
		$artifacts  = $rasterizer->rasterize( $this->make_request( MarkupRequest::MODE_CROP ), $annotation );
		$this->assertArrayHasKey( 'crop_rect', $artifacts );
		$this->assertSame( 10.0, $artifacts['crop_rect']['x'] );
		$this->assertSame( 12.0, $artifacts['crop_rect']['y'] );
		$this->assertSame( 40.0, $artifacts['crop_rect']['width'] );
		$this->assertSame( 48.0, $artifacts['crop_rect']['height'] );
	}

	public function test_position_vector_from_arrow(): void {
		$rasterizer = new MarkupRasterizer();
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
		$artifacts  = $rasterizer->rasterize( $this->make_request( MarkupRequest::MODE_POSITION ), $annotation );
		$this->assertArrayHasKey( 'position_vector', $artifacts );
		$this->assertSame( 0.1, $artifacts['position_vector']['from']['x'] );
		$this->assertSame( 0.8, $artifacts['position_vector']['to']['x'] );
		$this->assertTrue( $artifacts['position_vector']['normalized'] );
	}

	public function test_redaction_rects_collected(): void {
		$rasterizer = new MarkupRasterizer();
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
		$artifacts  = $rasterizer->rasterize( $this->make_request( MarkupRequest::MODE_REDACT ), $annotation );
		$this->assertArrayHasKey( 'redaction_rects', $artifacts );
		$this->assertCount( 1, $artifacts['redaction_rects'] );
		$this->assertSame( 2, $artifacts['redaction_rects'][0]['page'] );
		$this->assertSame( 10.0, $artifacts['redaction_rects'][0]['width'] );
	}

	/**
	 * Mask PNG produces zero-alpha inside the marked rect.
	 *
	 * @requires extension gd
	 */
	public function test_mask_png_has_zero_alpha_inside_rect(): void {
		if ( ! \function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is required.' );
		}

		$uploads = \wp_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			\wp_mkdir_p( $uploads['basedir'] );
		}

		$rasterizer = new MarkupRasterizer();
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
		$artifacts  = $rasterizer->rasterize( $this->make_request( MarkupRequest::MODE_MASK ), $annotation );
		$this->assertArrayHasKey( 'mask_attachment_id', $artifacts );
		$attach_id = (int) $artifacts['mask_attachment_id'];
		$path      = \get_attached_file( $attach_id );
		$this->assertFileExists( $path );

		$image = \imagecreatefrompng( $path );
		$this->assertNotFalse( $image );

		// Inside region — alpha should be 127 (transparent in GD).
		$inside_rgba  = \imagecolorat( $image, 20, 20 );
		$inside_alpha = ( $inside_rgba >> 24 ) & 0x7F;
		// Outside region — alpha should be 0 (opaque).
		$outside_rgba  = \imagecolorat( $image, 5, 5 );
		$outside_alpha = ( $outside_rgba >> 24 ) & 0x7F;

		$this->assertSame( 127, $inside_alpha, 'Inside the marked rect should be fully transparent.' );
		$this->assertSame( 0, $outside_alpha, 'Outside the marked rect should be fully opaque.' );

		\imagedestroy( $image );
		\wp_delete_attachment( $attach_id, true );
	}
}
