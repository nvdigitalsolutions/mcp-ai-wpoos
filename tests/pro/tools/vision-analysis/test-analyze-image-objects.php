<?php
/**
 * Test Vision Analysis Toolkit — Analyze Image Objects
 *
 * Validates the count normalizer math, the tool contract (schema, capability
 * gates, SSRF rejection), mocked-HTTP detection/VLM paths, the hybrid
 * fallback, and the GD bounding-box annotation (Phase 2).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.68
 */

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__, 4 ) . '/addons/pro/' );
}

/**
 * Test case for the Vision Analysis toolkit.
 *
 * @since 1.1.68
 */
class Test_Analyze_Image_Objects extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Analyze_Image_Objects
	 */
	private $tool;

	/**
	 * Admin user ID used as the current user.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Path to a generated test image.
	 *
	 * @var string
	 */
	private $test_image_path;

	/**
	 * SetUp.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__, 4 ) . '/addons/pro/' );
		}

		// Load the toolkit classes directly (in a full run they are loaded by
		// the pro module registry / tool map).
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/vision-analysis/class-wp-mcp-ai-vision-count-normalizer.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/vision-analysis/class-wp-mcp-ai-vision-vlm-client.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/vision-analysis/class-wp-mcp-ai-vision-annotator.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/vision-analysis/class-wp-mcp-ai-tool-analyze-image-objects.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-hf-vision-inference-service.php';

		// Enable the toolkit.
		$settings                                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_vision_analysis_toolkit'] = true;
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->tool = new WP_MCP_AI_Tool_Analyze_Image_Objects();
	}

	/**
	 * TearDown.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────
	// Normalizer math
	// ────────────────────────────────────────────────────────

	/**
	 * Grouping raw HF detections into a breakdown.
	 */
	public function test_group_detections_counts_and_sorts() {
		$detections = array(
			array(
				'label'      => 'person',
				'confidence' => 0.9,
				'box'        => array(
					'xmin' => 0.1,
					'ymin' => 0.1,
					'xmax' => 0.2,
					'ymax' => 0.3,
				),
			),
			array(
				'label'      => 'person',
				'confidence' => 0.7,
				'box'        => array(
					'xmin' => 0.3,
					'ymin' => 0.1,
					'xmax' => 0.4,
					'ymax' => 0.3,
				),
			),
			array(
				'label'      => 'cup',
				'confidence' => 0.8,
				'box'        => array(
					'x'      => 0.5,
					'y'      => 0.5,
					'width'  => 0.1,
					'height' => 0.1,
				),
			),
		);

		$breakdown = WP_MCP_AI_Vision_Count_Normalizer::group_detections( $detections, true );

		$this->assertCount( 2, $breakdown );
		$this->assertSame( 'person', $breakdown[0]['label'] );
		$this->assertSame( 2, $breakdown[0]['count'] );
		$this->assertEqualsWithDelta( 0.8, $breakdown[0]['avg_confidence'], 0.0001 );
		$this->assertCount( 2, $breakdown[0]['boxes'] );
		$this->assertSame( 1, $breakdown[1]['count'] );
		$this->assertSame( 3, WP_MCP_AI_Vision_Count_Normalizer::total_from_breakdown( $breakdown ) );

		// Box normalization: xmin/xmax → x/width.
		$this->assertSame( 0.1, $breakdown[0]['boxes'][0]['x'] );
		$this->assertEqualsWithDelta( 0.1, $breakdown[0]['boxes'][0]['width'], 0.0001 );
		$this->assertEqualsWithDelta( 0.2, $breakdown[0]['boxes'][0]['height'], 0.0001 );
	}

	/**
	 * Include_boxes=false strips boxes.
	 */
	public function test_group_detections_without_boxes() {
		$detections = array(
			array(
				'label'      => 'car',
				'confidence' => 0.6,
				'box'        => array(
					'x'      => 0,
					'y'      => 0,
					'width'  => 0.2,
					'height' => 0.2,
				),
			),
		);

		$breakdown = WP_MCP_AI_Vision_Count_Normalizer::group_detections( $detections, false );

		$this->assertArrayNotHasKey( 'boxes', $breakdown[0] );
	}

	/**
	 * Ollama detections may carry a per-row count.
	 */
	public function test_group_detections_respects_per_row_count() {
		$detections = array(
			array(
				'label'      => 'bottle',
				'confidence' => 0.55,
				'count'      => 4,
			),
		);

		$breakdown = WP_MCP_AI_Vision_Count_Normalizer::group_detections( $detections, true );

		$this->assertSame( 4, $breakdown[0]['count'] );
	}

	/**
	 * VLM JSON normalization coerces shapes defensively.
	 */
	public function test_normalize_vlm_counts() {
		$parsed = array(
			'counts' => array(
				array(
					'label'      => 'person',
					'count'      => 3,
					'confidence' => 0.9,
				),
				array(
					'label'      => 'car',
					'count'      => 2,
					'confidence' => 0.7,
				),
			),
		);

		$breakdown = WP_MCP_AI_Vision_Count_Normalizer::normalize_vlm_counts( $parsed );

		$this->assertCount( 2, $breakdown );
		$this->assertSame( 'person', $breakdown[0]['label'] );
		$this->assertSame( 3, $breakdown[0]['count'] );

		// Missing count → single instance.
		$single = WP_MCP_AI_Vision_Count_Normalizer::normalize_vlm_counts(
			array( 'items' => array( array( 'label' => 'cat' ) ) )
		);
		$this->assertSame( 1, $single[0]['count'] );
	}

	/**
	 * Tolerant JSON extraction from VLM text.
	 */
	public function test_extract_json_handles_fences_and_prose() {
		$fenced = "Sure! Here is the result:\n```json\n{\"counts\":[{\"label\":\"a\",\"count\":1}]}\n```";
		$this->assertSame( 'a', WP_MCP_AI_Vision_Count_Normalizer::extract_json( $fenced )['counts'][0]['label'] );

		$plain = '{"counts":[{"label":"b","count":2}]}';
		$this->assertSame( 'b', WP_MCP_AI_Vision_Count_Normalizer::extract_json( $plain )['counts'][0]['label'] );

		$this->assertNull( WP_MCP_AI_Vision_Count_Normalizer::extract_json( 'no json here' ) );
	}

	/**
	 * Label-alias merging sums counts and renames labels.
	 */
	public function test_merge_label_aliases() {
		$breakdown = array(
			array(
				'label'          => 'vehicle',
				'count'          => 2,
				'avg_confidence' => 0.8,
				'boxes'          => array(
					array(
						'x'      => 0,
						'y'      => 0,
						'width'  => 0.1,
						'height' => 0.1,
					),
				),
			),
			array(
				'label'          => 'car',
				'count'          => 3,
				'avg_confidence' => 0.9,
				'boxes'          => array(),
			),
		);

		$merged = WP_MCP_AI_Vision_Count_Normalizer::merge_label_aliases(
			$breakdown,
			array( 'vehicle' => 'car' )
		);

		$this->assertCount( 1, $merged );
		$this->assertSame( 'car', $merged[0]['label'] );
		$this->assertSame( 5, $merged[0]['count'] );
	}

	/**
	 * Message building.
	 */
	public function test_build_message() {
		$breakdown = array(
			array(
				'label' => 'person',
				'count' => 3,
			),
			array(
				'label' => 'cup',
				'count' => 1,
			),
		);

		$message = WP_MCP_AI_Vision_Count_Normalizer::build_message( $breakdown );
		$this->assertStringContainsString( '4', $message );
		$this->assertStringContainsString( 'person (3)', $message );
	}

	// ────────────────────────────────────────────────────────
	// Tool contract
	// ────────────────────────────────────────────────────────

	/**
	 * Tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'analyze_image_objects', $this->tool->get_slug() );
		$this->assertSame( 'upload_files', $this->tool->get_required_capability() );

		$flags = $this->tool->get_capability_flags();
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'requires-vision-model', $flags );

		$schema  = $this->tool->get_parameters_schema();
		$defined = $this->tool->get_definition();
		$this->assertArrayHasKey( 'mode', $schema['properties'] );
		$this->assertArrayHasKey( 'annotate', $schema['properties'] );
		$this->assertSame( array( 'vision', 'object-detection', 'counting', 'image-analysis' ), $defined['category'] );
	}

	/**
	 * Anonymous users are rejected.
	 */
	public function test_anonymous_rejected() {
		wp_set_current_user( 0 );

		$result = $this->tool->execute(
			array( 'url' => 'https://example.com/image.png' ),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_va_forbidden', $result->get_error_code() );
	}

	/**
	 * Missing image source is rejected.
	 */
	public function test_missing_source_rejected() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->admin_user_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_missing_source', $result->get_error_code() );
	}

	/**
	 * SSRF: loopback image URLs are blocked by the URL guard.
	 */
	public function test_ssrf_loopback_url_rejected() {
		if ( ! class_exists( 'WP_MCP_AI_Url_Guard' ) ) {
			$this->markTestSkipped( 'URL guard not loaded.' );
		}

		$result = $this->tool->execute(
			array( 'url' => 'http://127.0.0.1/secret.png' ),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
	}

	// ────────────────────────────────────────────────────────
	// Execution paths (mocked HTTP)
	// ────────────────────────────────────────────────────────

	/**
	 * Create a PNG test image and register it as an attachment.
	 *
	 * @return int Attachment ID.
	 */
	private function create_test_attachment() {
		$this->require_gd();

		$path = wp_tempnam( 'va-test-' ) . '.png';
		$img  = imagecreatetruecolor( 100, 80 );
		$bg   = imagecolorallocate( $img, 200, 200, 200 );
		imagefilledrectangle( $img, 0, 0, 100, 80, $bg );
		imagepng( $img, $path );
		imagedestroy( $img );

		$this->test_image_path = $path;

		return self::factory()->attachment->create_upload_object( $path );
	}

	/**
	 * Skip the test when GD is unavailable.
	 *
	 * Note: WP_Image_Editor_GD is lazy-loaded by wp_get_image_editor(), so
	 * only the raw GD function surface is checked here.
	 *
	 * @return void
	 */
	private function require_gd() {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) || ! function_exists( 'imagejpeg' ) ) {
			$this->markTestSkipped( 'GD image support is required.' );
		}
	}

	/**
	 * Detection mode returns a grouped count breakdown (mocked OWLv2).
	 */
	public function test_detection_mode_returns_breakdown() {
		$this->require_gd();
		$attachment_id = $this->create_test_attachment();

		$settings                        = get_option( 'wp_mcp_ai_settings', array() );
		$settings['huggingface_api_key'] = 'hf_test_key';
		// Deterministic endpoint: the service falls back to api-inference.huggingface.co
		// when no dedicated endpoint is configured.
		$settings['huggingface_endpoint_url'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'huggingface' ) ) {
					$body = wp_json_encode(
						array(
							array(
								'label' => 'person',
								'score' => 0.9,
								'box'   => array(
									'xmin' => 10,
									'ymin' => 10,
									'xmax' => 50,
									'ymax' => 70,
								),
							),
							array(
								'label' => 'person',
								'score' => 0.8,
								'box'   => array(
									'xmin' => 60,
									'ymin' => 10,
									'xmax' => 90,
									'ymax' => 70,
								),
							),
							array(
								'label' => 'cup',
								'score' => 0.7,
								'box'   => array(
									'xmin' => 20,
									'ymin' => 40,
									'xmax' => 40,
									'ymax' => 60,
								),
							),
						)
					);
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => $body,
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'attachment_id'  => $attachment_id,
				'mode'           => 'detection',
				'provider'       => 'huggingface',
				'min_confidence' => 0.5,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'detection', $result['mode'] );
		$this->assertSame( 'huggingface', $result['provider'] );
		$this->assertSame( 3, $result['total_items'] );
		$this->assertSame( 'person', $result['counts'][0]['label'] );
		$this->assertSame( 2, $result['counts'][0]['count'] );
		$this->assertSame( 'cup', $result['counts'][1]['label'] );
		$this->assertNotEmpty( $result['message'] );
		$this->assertSame( wp_get_attachment_url( $attachment_id ), $result['image_url'] );

		wp_delete_file( $this->test_image_path );
	}

	/**
	 * VLM mode returns a normalized breakdown (mocked OpenAI).
	 */
	public function test_vlm_mode_returns_breakdown() {
		$this->require_gd();
		$attachment_id = $this->create_test_attachment();

		$settings                    = get_option( 'wp_mcp_ai_settings', array() );
		$settings['openai_api_key']  = 'sk_test';
		$settings['va_vlm_provider'] = 'openai';
		update_option( 'wp_mcp_ai_settings', $settings );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'api.openai.com/v1/chat/completions' ) ) {
					$body = wp_json_encode(
						array(
							'choices' => array(
								array(
									'message' => array(
										'content' => '{"counts":[{"label":"person","count":3,"confidence":0.9},{"label":"cup","count":1,"confidence":0.8}],"total_items":4}',
									),
								),
							),
							'model'   => 'gpt-4o-mini',
						)
					);
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => $body,
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'mode'          => 'vlm',
				'provider'      => 'openai',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'vlm', $result['mode'] );
		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 4, $result['total_items'] );
		$this->assertSame( 3, $result['counts'][0]['count'] );

		wp_delete_file( $this->test_image_path );
	}

	/**
	 * VLM mode without any configured provider returns a clear error.
	 */
	public function test_vlm_mode_without_provider_errors() {
		$this->require_gd();
		$attachment_id = $this->create_test_attachment();

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		unset( $settings['openai_api_key'], $settings['anthropic_api_key'], $settings['gemini_api_key'] );
		update_option( 'wp_mcp_ai_settings', $settings );

		$result = $this->tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'mode'          => 'vlm',
				'provider'      => 'auto',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_va_no_vlm_provider', $result->get_error_code() );

		wp_delete_file( $this->test_image_path );
	}

	/**
	 * Hybrid mode falls back to the VLM when detection fails.
	 */
	public function test_hybrid_falls_back_to_vlm() {
		$this->require_gd();
		$attachment_id = $this->create_test_attachment();

		$settings                             = get_option( 'wp_mcp_ai_settings', array() );
		$settings['huggingface_api_key']      = 'hf_test_key';
		$settings['huggingface_endpoint_url'] = '';
		$settings['openai_api_key']           = 'sk_test';
		update_option( 'wp_mcp_ai_settings', $settings );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'huggingface' ) ) {
					return array(
						'response' => array( 'code' => 503 ),
						'body'     => wp_json_encode( array( 'error' => 'Service unavailable' ) ),
					);
				}
				if ( false !== strpos( $url, 'api.openai.com/v1/chat/completions' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'choices' => array(
									array( 'message' => array( 'content' => '{"counts":[{"label":"car","count":2,"confidence":0.9}]}' ) ),
								),
								'model'   => 'gpt-4o-mini',
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'mode'          => 'hybrid',
				'provider'      => 'auto',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'vlm', $result['mode'] );
		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 2, $result['total_items'] );

		wp_delete_file( $this->test_image_path );
	}

	// ────────────────────────────────────────────────────────
	// Phase 2 — annotation
	// ────────────────────────────────────────────────────────

	/**
	 * Annotate=true creates an annotated attachment.
	 */
	public function test_annotate_creates_attachment() {
		$this->require_gd();
		$attachment_id = $this->create_test_attachment();

		$settings                             = get_option( 'wp_mcp_ai_settings', array() );
		$settings['huggingface_api_key']      = 'hf_test_key';
		$settings['huggingface_endpoint_url'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'huggingface' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								array(
									'label' => 'person',
									'score' => 0.9,
									'box'   => array(
										'xmin' => 0.1,
										'ymin' => 0.1,
										'xmax' => 0.5,
										'ymax' => 0.7,
									),
								),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'mode'          => 'detection',
				'provider'      => 'huggingface',
				'annotate'      => true,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'annotated_image', $result );
		$this->assertNotEmpty( $result['annotated_image']['attachment_id'] );
		$this->assertNotEmpty( $result['annotated_image']['url'] );
		// PNG sources stay PNG to preserve transparency.
		$this->assertSame( 'image/png', $result['annotated_image']['mime_type'] );

		wp_delete_file( $this->test_image_path );
	}

	/**
	 * The annotator returns a real output file with boxes drawn.
	 */
	public function test_annotator_output_file() {
		$this->require_gd();

		$path = wp_tempnam( 'va-annotator-test-' ) . '.png';
		$img  = imagecreatetruecolor( 200, 150 );
		$bg   = imagecolorallocate( $img, 255, 255, 255 );
		imagefilledrectangle( $img, 0, 0, 200, 150, $bg );
		imagepng( $img, $path );
		imagedestroy( $img );

		$breakdown = array(
			array(
				'label' => 'person',
				'count' => 1,
				'boxes' => array(
					array(
						'x'      => 0.1,
						'y'      => 0.1,
						'width'  => 0.4,
						'height' => 0.6,
					),
				),
			),
		);

		$annotated = WP_MCP_AI_Vision_Annotator::annotate( $path, $breakdown );

		$this->assertNotWPError( $annotated );
		$this->assertFileExists( $annotated['path'] );
		$this->assertGreaterThan( 0, filesize( $annotated['path'] ) );

		wp_delete_file( $path );
		wp_delete_file( $annotated['path'] );
	}

	/**
	 * The annotator rejects breakdowns without boxes.
	 */
	public function test_annotate_without_boxes_errors() {
		$this->require_gd();
		$attachment_id = $this->create_test_attachment();

		// VLM mode produces no boxes → annotation must fail cleanly.
		$settings                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['openai_api_key'] = 'sk_test';
		update_option( 'wp_mcp_ai_settings', $settings );

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'api.openai.com/v1/chat/completions' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'choices' => array(
									array( 'message' => array( 'content' => '{"counts":[{"label":"person","count":1,"confidence":0.9}]}' ) ),
								),
							)
						),
					);
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'mode'          => 'vlm',
				'provider'      => 'openai',
				'annotate'      => true,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'annotation_error', $result );
		$this->assertArrayNotHasKey( 'annotated_image', $result );

		wp_delete_file( $this->test_image_path );
	}
}
