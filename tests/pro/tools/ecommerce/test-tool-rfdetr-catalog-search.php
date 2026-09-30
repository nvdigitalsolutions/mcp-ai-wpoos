<?php
/**
 * Test E-commerce Toolkit — RF-DETR Catalog Search
 *
 * Validates the rfdetr_catalog_search tool contract: unset-model error,
 * workspace/project/version path resolution, the per-model + dHash transient
 * cache (hit/miss), and the capability gate.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.90
 */

if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
	define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__, 4 ) . '/addons/pro/' );
}

/**
 * Test case for the RF-DETR catalog search tool.
 *
 * @since 1.1.90
 */
class Test_Tool_Rfdetr_Catalog_Search extends WP_UnitTestCase {

	/**
	 * Tool instance under test.
	 *
	 * @var WP_MCP_AI_Pro_Tool_Rfdetr_Catalog_Search
	 */
	private $tool;

	/**
	 * Admin user ID used as the current user.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * SetUp.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__, 4 ) . '/addons/pro/' );
		}

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/class-wp-mcp-ai-pro-tool-rfdetr-catalog-search.php';

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );

		$this->tool = new WP_MCP_AI_Pro_Tool_Rfdetr_Catalog_Search();
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

		$path = wp_tempnam( 'rfdetr-catalog-test-' ) . '.png';
		$img  = imagecreatetruecolor( 60, 40 );
		$bg   = imagecolorallocate( $img, 180, 120, 60 );
		imagefilledrectangle( $img, 0, 0, 60, 40, $bg );
		imagepng( $img, $path );
		imagedestroy( $img );

		return self::factory()->attachment->create_upload_object( $path );
	}

	/**
	 * An unset catalog model fails with a setup error before any HTTP.
	 */
	public function test_unset_catalog_model_errors() {
		$attachment_id = $this->create_test_attachment();

		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_api_url'       => 'http://localhost:9001',
				'va_roboflow_catalog_model' => '',
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

		$result = $this->tool->execute(
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_rfdetr_catalog_model_unset', $result->get_error_code() );
		$this->assertFalse( $spy_called, 'No HTTP request may fire without a catalog model.' );
	}

	/**
	 * A workspace/project/version catalog model resolves and returns the
	 * checkpoint class names.
	 */
	public function test_catalog_search_with_workspace_model() {
		$attachment_id = $this->create_test_attachment();

		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_api_url'       => 'http://localhost:9001',
				'va_roboflow_catalog_model' => 'myworkspace/myproject/3',
			)
		);

		$captured_url = '';

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$captured_url ) {
				$captured_url = $url;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'image'       => array(
								'width'  => 60,
								'height' => 40,
							),
							'predictions' => array(
								array(
									'x'          => 30,
									'y'          => 20,
									'width'      => 20,
									'height'     => 15,
									'confidence' => 0.85,
									'class'      => 'brand-bottle-xl',
									'class_id'   => 2,
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array( 'attachment_id' => $attachment_id ),
			array( 'user_id' => $this->admin_user_id )
		);

		$this->assertNotWPError( $result, is_wp_error( $result ) ? wp_json_encode( $result->get_error_data() ) : '' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'myworkspace/myproject/3', $result['model'] );
		$this->assertSame( 'brand-bottle-xl', $result['detections'][0]['label'] );
		$this->assertSame( 1, $result['total_count'] );
		$this->assertFalse( $result['cached'] );
		$this->assertStringContainsString( '/infer/myworkspace/myproject/3', $captured_url );
	}

	/**
	 * Identical image + model pairs hit the 5-minute transient cache.
	 */
	public function test_cache_hit_on_repeat() {
		$attachment_id = $this->create_test_attachment();

		update_option(
			'wp_mcp_ai_settings',
			array(
				'va_roboflow_api_url'       => 'http://localhost:9001',
				'va_roboflow_catalog_model' => 'rfdetr-small',
			)
		);

		$request_count = 0;

		add_filter(
			'pre_http_request',
			function () use ( &$request_count ) {
				$request_count++;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'image'       => array(
								'width'  => 60,
								'height' => 40,
							),
							'predictions' => array(
								array(
									'x'          => 30,
									'y'          => 20,
									'width'      => 10,
									'height'     => 10,
									'confidence' => 0.9,
									'class'      => 'bottle',
									'class_id'   => 39,
								),
							),
						)
					),
				);
			},
			10,
			3
		);

		$args = array(
			'attachment_id' => $attachment_id,
		);
		$ctx  = array( 'user_id' => $this->admin_user_id );

		$first = $this->tool->execute( $args, $ctx );
		$this->assertNotWPError( $first );
		$this->assertFalse( $first['cached'] );

		$second = $this->tool->execute( $args, $ctx );
		$this->assertNotWPError( $second );
		$this->assertTrue( $second['cached'] );
		$this->assertSame( 1, $request_count, 'The second identical call must be served from cache.' );
	}

	/**
	 * A subscriber cannot execute the tool.
	 */
	public function test_capability_gate() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_rfdetr_catalog_forbidden', $result->get_error_code() );
	}
}
