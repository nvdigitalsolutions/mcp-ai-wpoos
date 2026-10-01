<?php
/**
 * Test Roboflow Inference Service (RF-DETR)
 *
 * Validates the fail-closed credential rules per trust tier, SSRF/HTTPS
 * endpoint discipline, the /infer/{model_id} request shape, response
 * normalization (boxes/masks/keypoints), and the Apache/PML alias gate.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.90
 */

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__, 3 ) . '/addons/pro/' );
}

/**
 * Test case for the Roboflow Inference service.
 *
 * @since 1.1.90
 */
class Test_Roboflow_Inference_Service extends WP_UnitTestCase {

	/**
	 * Service instance under test.
	 *
	 * @var WP_MCP_AI_Roboflow_Inference_Service
	 */
	private $service;

	/**
	 * Base64 fixture standing in for image bytes.
	 *
	 * @var string
	 */
	private $image_base64;

	/**
	 * SetUp.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__, 3 ) . '/addons/pro/' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-roboflow-inference-service.php';

		$this->service      = new WP_MCP_AI_Roboflow_Inference_Service();
		$this->image_base64 = base64_encode( 'roboflow-fixture-image-bytes' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test fixture.

		// Neutral default settings.
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_api_url' => '',
				'va_roboflow_api_key' => '',
			)
		);
	}

	/**
	 * TearDown.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/**
	 * Serverless mode without an API key fails closed — no HTTP request fires.
	 */
	public function test_missing_key_short_circuits_without_request() {
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

		$result = $this->service->infer( $this->image_base64, 'rfdetr-small' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_roboflow_missing_api_key', $result->get_error_code() );
		$this->assertFalse( $spy_called, 'No HTTP request may fire without credentials.' );
	}

	/**
	 * A self-hosted loopback endpoint is usable without an API key.
	 */
	public function test_self_host_no_key_is_usable() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_api_url' => 'http://localhost:9001',
				'va_roboflow_api_key' => '',
			)
		);

		$captured = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				$captured['url']     = $url;
				$captured['headers'] = $args['headers'];
				$captured['body']    = $args['body'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'image'       => array(
								'width'  => 640,
								'height' => 480,
							),
							'predictions' => array(),
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->service->infer( $this->image_base64, 'rfdetr-small' );

		$this->assertNotWPError( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringStartsWith( 'http://localhost:9001/infer/rfdetr-small', $captured['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $captured['headers'] );
	}

	/**
	 * The request shape: base64 image payload, confidence, and the API key as
	 * the raw Authorization header on hosted tiers.
	 */
	public function test_request_shape_with_key_header() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_api_url' => 'https://serverless.roboflow.com',
				'va_roboflow_api_key' => 'rf_test_key',
			)
		);

		$captured = array();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured ) {
				$captured['url']     = $url;
				$captured['headers'] = $args['headers'];
				$captured['body']    = $args['body'];
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'image'       => array(
								'width'  => 640,
								'height' => 480,
							),
							'predictions' => array(),
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->service->infer( $this->image_base64, 'rfdetr-medium', 0.4, 0.6 );

		$this->assertNotWPError( $result );
		$this->assertStringStartsWith( 'https://serverless.roboflow.com/infer/rfdetr-medium', $captured['url'] );
		$this->assertSame( 'rf_test_key', $captured['headers']['Authorization'] );

		$body = json_decode( $captured['body'], true );
		$this->assertSame( 'base64', $body['image']['type'] );
		$this->assertSame( $this->image_base64, $body['image']['value'] );
		$this->assertSame( 0.4, $body['confidence'] );
		$this->assertSame( 0.6, $body['iou_threshold'] );
	}

	/**
	 * Plain-HTTP endpoints outside the local/private ranges are rejected.
	 */
	public function test_http_non_local_endpoint_rejected() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_api_url' => 'http://inference.example.com',
				'va_roboflow_api_key' => 'rf_test_key',
			)
		);

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

		$result = $this->service->infer( $this->image_base64, 'rfdetr-small' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_roboflow_insecure_endpoint', $result->get_error_code() );
		$this->assertFalse( $spy_called );
	}

	/**
	 * Private-network HTTP endpoints (self-hosted Inference server) are allowed.
	 */
	public function test_private_network_endpoint_allowed() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_api_url' => 'http://192.168.1.20:9001',
				'va_roboflow_api_key' => '',
			)
		);

		$this->assertTrue( $this->service->is_configured() );
	}

	/**
	 * Response normalization: pixel-space boxes become normalized coordinates,
	 * class names pass through, and masks/keypoints survive.
	 */
	public function test_response_normalization() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_api_url' => 'http://localhost:9001',
				'va_roboflow_api_key' => '',
			)
		);

		$predictions = array(
			array(
				'x'            => 320,
				'y'            => 240,
				'width'        => 200,
				'height'       => 100,
				'confidence'   => 0.93,
				'class'        => 'person',
				'class_id'     => 0,
				'detection_id' => 'det-1',
				'points'       => array(
					array(
						'x' => 0.34,
						'y' => 0.29,
					),
					array(
						'x' => 0.66,
						'y' => 0.71,
					),
				),
				'keypoints'    => array(
					array(
						'x'          => 320,
						'y'          => 100,
						'class'      => 'nose',
						'confidence' => 0.88,
					),
				),
			),
			array(
				'x'          => 100,
				'y'          => 200,
				'width'      => 80,
				'height'     => 80,
				'confidence' => 0.61,
				'class_id'   => 47,
			),
		);

		add_filter(
			'pre_http_request',
			function () use ( $predictions ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'time'        => 0.012,
							'image'       => array(
								'width'  => 640,
								'height' => 480,
							),
							'predictions' => $predictions,
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->service->infer( $this->image_base64, 'rfdetr-small' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'roboflow', $result['provider'] );
		$this->assertSame( 2, $result['total_count'] );

		$person = $result['detections'][0];
		$this->assertSame( 'person', $person['label'] );
		$this->assertSame( 0.93, $person['confidence'] );
		// Center x=320, width=200 → left=220; /640 → 0.3438.
		$this->assertEqualsWithDelta( 0.3438, $person['box']['x'], 0.0001 );
		$this->assertEqualsWithDelta( 0.3125, $person['box']['width'], 0.0001 );
		$this->assertCount( 2, $person['mask_points'] );
		$this->assertSame( 'nose', $person['keypoints'][0]['class'] );
		$this->assertEqualsWithDelta( 0.5, $person['keypoints'][0]['x'], 0.0001 );

		// COCO class_id fallback when the server omits the class name.
		$this->assertSame( 'apple', $result['detections'][1]['label'] );
		$this->assertSame( 12.0, $result['inference_ms'] );
	}

	/**
	 * PML-licensed aliases require the consent toggle.
	 */
	public function test_pml_alias_gated_behind_consent() {
		$denied = $this->service->validate_model_alias( 'rfdetr-xlarge' );
		$this->assertWPError( $denied );
		$this->assertSame( 'wp_mcp_ai_roboflow_pml_not_enabled', $denied->get_error_code() );

		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_allow_pml' => true,
			)
		);

		$this->assertSame( 'rfdetr-xlarge', $this->service->validate_model_alias( 'rfdetr-xlarge' ) );
	}

	/**
	 * Workspace/project/version references and Apache aliases validate; junk rejects.
	 */
	public function test_model_alias_validation() {
		$this->assertSame( 'rfdetr-small', $this->service->validate_model_alias( 'rfdetr-small' ) );
		$this->assertSame( 'rfdetr-seg-medium', $this->service->validate_model_alias( 'rfdetr-seg-medium' ) );
		$this->assertSame( 'myworkspace/myproject/3', $this->service->validate_model_alias( 'myworkspace/myproject/3' ) );

		$junk = $this->service->validate_model_alias( 'yolo11-x' );
		$this->assertWPError( $junk );
		$this->assertSame( 'wp_mcp_ai_roboflow_unknown_model', $junk->get_error_code() );
	}
}
