<?php
/**
 * Test Vision Analysis Toolkit — RF-DETR Detect
 *
 * Validates the rfdetr_detect tool contract: per-task response mapping
 * (boxes / mask polygons / keypoints), model resolution, max_results
 * truncation, the fail-closed credential gate, and the capability gate.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.90
 */

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__, 4 ) . '/addons/pro/' );
}

/**
 * Test case for the RF-DETR detection tool.
 *
 * @since 1.1.90
 */
class Test_Tool_Rfdetr_Detect extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Tool_Rfdetr_Detect
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

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/vision-analysis/class-wp-mcp-ai-tool-rfdetr-detect.php';

		// Enable the toolkit so the settings accessor resolves defaults.
		$settings                                   = get_option( 'wp_mcp_ai_settings', array() );
		$settings['enable_vision_analysis_toolkit'] = true;
		$settings['va_roboflow_api_url']            = 'http://localhost:9001';
		$settings['va_roboflow_api_key']            = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->tool = new WP_MCP_AI_Tool_Rfdetr_Detect();
	}

	/**
	 * TearDown.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Skip the test when GD is unavailable.
	 *
	 * @return void
	 */
	private function require_gd() {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagepng' ) ) {
			$this->markTestSkipped( 'GD image support is required.' );
		}
	}

	/**
	 * Create a PNG test image and register it as an attachment.
	 *
	 * @return int Attachment ID.
	 */
	private function create_test_attachment() {
		$this->require_gd();

		$path = wp_tempnam( 'rfdetr-test-' ) . '.png';
		$img  = imagecreatetruecolor( 100, 80 );
		$bg   = imagecolorallocate( $img, 200, 200, 200 );
		imagefilledrectangle( $img, 0, 0, 100, 80, $bg );
		imagepng( $img, $path );
		imagedestroy( $img );

		$this->test_image_path = $path;

		return self::factory()->attachment->create_upload_object( $path );
	}

	/**
	 * Detection task returns ranked detections with normalized boxes.
	 */
	public function test_detect_task_returns_boxes() {
		$attachment_id = $this->create_test_attachment();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'localhost:9001/infer/' ) ) {
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode(
							array(
								'image'       => array(
									'width'  => 100,
									'height' => 80,
								),
								'predictions' => array(
									array(
										'x'          => 50,
										'y'          => 40,
										'width'      => 40,
										'height'     => 30,
										'confidence' => 0.9,
										'class'      => 'dog',
										'class_id'   => 16,
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
				'task'          => 'detect',
				'confidence'    => 0.5,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'detect', $result['task'] );
		$this->assertSame( 'roboflow', $result['provider'] );
		$this->assertSame( 1, $result['total_count'] );
		$this->assertSame( 'dog', $result['detections'][0]['label'] );
		$this->assertEqualsWithDelta( 0.3, $result['detections'][0]['box']['x'], 0.0001 );
		$this->assertEqualsWithDelta( 0.4, $result['detections'][0]['box']['width'], 0.0001 );

		wp_delete_file( $this->test_image_path );
	}

	/**
	 * Segment task passes mask polygons through; keypoints task maps the
	 * 17-COCO keypoint shape.
	 */
	public function test_segment_and_keypoint_tasks() {
		$attachment_id = $this->create_test_attachment();

		$responses = array(
			'infer/rfdetr-seg-small'        => array(
				'image'       => array(
					'width'  => 100,
					'height' => 80,
				),
				'predictions' => array(
					array(
						'x'          => 50,
						'y'          => 40,
						'width'      => 40,
						'height'     => 30,
						'confidence' => 0.8,
						'class'      => 'dog',
						'points'     => array(
							array(
								'x' => 0.3,
								'y' => 0.3,
							),
							array(
								'x' => 0.7,
								'y' => 0.7,
							),
						),
					),
				),
			),
			'infer/rfdetr-keypoint-preview' => array(
				'image'       => array(
					'width'  => 100,
					'height' => 80,
				),
				'predictions' => array(
					array(
						'x'          => 50,
						'y'          => 40,
						'width'      => 40,
						'height'     => 60,
						'confidence' => 0.9,
						'class'      => 'person',
						'keypoints'  => array(
							array(
								'x'          => 50,
								'y'          => 10,
								'class'      => 'nose',
								'confidence' => 0.95,
							),
						),
					),
				),
			),
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $responses ) {
				foreach ( $responses as $fragment => $body ) {
					if ( false !== strpos( $url, $fragment ) ) {
						return array(
							'response' => array( 'code' => 200 ),
							'body'     => wp_json_encode( $body ),
						);
					}
				}
				return $pre;
			},
			10,
			3
		);

		$seg_result = $this->tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'task'          => 'segment',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $seg_result );
		$this->assertSame( 'rfdetr-seg-small', $seg_result['model'] );
		$this->assertCount( 2, $seg_result['detections'][0]['mask_points'] );
		$this->assertEqualsWithDelta( 0.3, $seg_result['detections'][0]['mask_points'][0]['x'], 0.0001 );

		$kp_result = $this->tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'task'          => 'keypoints',
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $kp_result );
		$this->assertSame( 'rfdetr-keypoint-preview', $kp_result['model'] );
		$this->assertSame( 'nose', $kp_result['detections'][0]['keypoints'][0]['class'] );
		$this->assertEqualsWithDelta( 0.5, $kp_result['detections'][0]['keypoints'][0]['x'], 0.0001 );

		wp_delete_file( $this->test_image_path );
	}

	/**
	 * Max_results truncates the detection list.
	 */
	public function test_max_results_truncates() {
		$attachment_id = $this->create_test_attachment();

		$predictions = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$predictions[] = array(
				'x'          => 10 + ( $i * 10 ),
				'y'          => 10,
				'width'      => 5,
				'height'     => 5,
				'confidence' => 0.9,
				'class'      => 'cup',
				'class_id'   => 41,
			);
		}

		add_filter(
			'pre_http_request',
			function () use ( $predictions ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'image'       => array(
								'width'  => 100,
								'height' => 80,
							),
							'predictions' => $predictions,
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'attachment_id' => $attachment_id,
				'task'          => 'detect',
				'max_results'   => 2,
			),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['total_count'] );

		wp_delete_file( $this->test_image_path );
	}

	/**
	 * A subscriber cannot execute the tool.
	 */
	public function test_capability_gate() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = $this->tool->execute(
			array( 'task' => 'detect' ),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_rfdetr_forbidden', $result->get_error_code() );
	}

	/**
	 * Serverless tier without an API key fails closed (no request, clear error).
	 */
	public function test_missing_key_fails_closed() {
		$attachment_id = $this->create_test_attachment();

		$settings                        = get_option( 'wp_mcp_ai_settings', array() );
		$settings['va_roboflow_api_url'] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		$spy_called = false;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$spy_called ) {
				$spy_called = true;
				return $pre;
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_roboflow_missing_api_key', $result->get_error_code() );
		$this->assertFalse( $spy_called, 'No HTTP request may fire without credentials.' );

		wp_delete_file( $this->test_image_path );
	}
}
