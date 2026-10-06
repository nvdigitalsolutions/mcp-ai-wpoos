<?php
/**
 * Fashion Batch REST controller tests (Pro).
 *
 * Covers route registration into the shared Media Studio namespace,
 * the permission matrix, create-job handling (including the ack path),
 * and review decisions.
 *
 * @package WP_MCP_AI
 */
class Test_Fashion_REST extends WP_UnitTestCase {

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__, 2 ) . '/media-studio/includes/ai/class-nvoos-media-studio-ai-service.php';
		require_once dirname( __DIR__ ) . '/includes/fashion/class-wp-mcp-ai-fashion-batch.php';
		require_once dirname( __DIR__ ) . '/includes/fashion/class-wp-mcp-ai-fashion-rest.php';

		WP_MCP_AI_Fashion_Batch::register_post_type();
		delete_option( NV_oOS_Media_Studio_AI_Service::OPTION_KEY );
		remove_all_filters( 'nvoos_media_studio_cost_estimate' );
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		delete_option( NV_oOS_Media_Studio_AI_Service::OPTION_KEY );
		remove_all_filters( 'nvoos_media_studio_cost_estimate' );
		parent::tearDown();
	}

	/**
	 * Create a GD image attachment fixture.
	 *
	 * @return int Attachment ID.
	 */
	private function create_image_attachment() {
		$img = imagecreatetruecolor( 30, 30 );
		imagefilledrectangle( $img, 0, 0, 30, 30, imagecolorallocate( $img, 250, 250, 250 ) );
		$dir  = wp_upload_dir();
		$name = 'nvoos-fr-test-' . uniqid() . '.png';
		$path = $dir['path'] . '/' . $name;
		imagepng( $img, $path );
		imagedestroy( $img );

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'REST Fixture',
				'post_status'    => 'inherit',
			),
			$path
		);
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );

		return $attachment_id;
	}

	/**
	 * Test the jobs routes are registered under the shared namespace.
	 */
	public function test_jobs_routes_registered() {
		add_action( 'rest_api_init', array( 'WP_MCP_AI_Fashion_REST', 'register_routes' ) );
		$GLOBALS['wp_rest_server'] = null;
		$server                    = rest_get_server();
		$routes                    = $server->get_routes( 'nvoos-media-studio/v1' );

		$this->assertArrayHasKey( '/nvoos-media-studio/v1/ai/jobs', $routes );
		$this->assertArrayHasKey( '/nvoos-media-studio/v1/ai/jobs/(?P<job_id>\\d+)', $routes );
		$this->assertArrayHasKey( '/nvoos-media-studio/v1/ai/jobs/(?P<job_id>\\d+)/review', $routes );
	}

	/**
	 * Test the write permission matrix.
	 */
	public function test_write_permission_matrix() {
		$strip_cap = static function ( $allcaps ) {
			unset( $allcaps['upload_files'], $allcaps['edit_posts'] );
			return $allcaps;
		};
		add_filter( 'user_has_cap', $strip_cap, 100 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertInstanceOf( 'WP_Error', WP_MCP_AI_Fashion_REST::write_permission() );
		remove_filter( 'user_has_cap', $strip_cap, 100 );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( WP_MCP_AI_Fashion_REST::write_permission() );
	}

	/**
	 * Test the id-list sanitizer drops junk entries.
	 */
	public function test_sanitize_id_list() {
		$ids = WP_MCP_AI_Fashion_REST::sanitize_id_list( array( 1, 'abc', -5, 2, 2, 0 ) );
		$this->assertSame( array( 1, 2 ), $ids );
	}

	/**
	 * Test create-job handler rejects empty source lists.
	 */
	public function test_create_job_handler_rejects_empty_sources() {
		$request = new WP_REST_Request( 'POST', '/nvoos-media-studio/v1/ai/jobs' );
		$request->set_param( 'attachment_ids', array() );
		$request->set_param( 'transform', 'background' );

		$result = WP_MCP_AI_Fashion_REST::create_job( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'nvoos_ms_job_no_sources', $result->get_error_code() );
	}

	/**
	 * Test create-job handler creates a job end-to-end.
	 */
	public function test_create_job_handler_creates_job() {
		add_filter(
			'nvoos_media_studio_cost_estimate',
			static function () {
				return array(
					'usd'   => 0.01,
					'known' => true,
					'tool'  => 'edit_gemini_image',
				);
			}
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$source  = $this->create_image_attachment();
		$request = new WP_REST_Request( 'POST', '/nvoos-media-studio/v1/ai/jobs' );
		$request->set_param( 'attachment_ids', array( $source ) );
		$request->set_param( 'transform', 'background' );
		$request->set_param( 'confirmed', true );

		$response = WP_MCP_AI_Fashion_REST::create_job( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'id', $data );
		$this->assertSame( 1, $data['total'] );
	}

	/**
	 * Test the acknowledgment path records the one-time ack.
	 */
	public function test_create_job_handler_records_ack() {
		add_filter(
			'nvoos_media_studio_cost_estimate',
			static function () {
				return array(
					'usd'   => 0.01,
					'known' => true,
					'tool'  => 'edit_gemini_image',
				);
			}
		);

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$this->assertFalse( NV_oOS_Media_Studio_AI_Service::has_acked( $user_id ) );

		$source  = $this->create_image_attachment();
		$request = new WP_REST_Request( 'POST', '/nvoos-media-studio/v1/ai/jobs' );
		$request->set_param( 'attachment_ids', array( $source ) );
		$request->set_param( 'transform', 'background' );
		$request->set_param( 'confirmed', true );
		$request->set_param( 'acknowledged', true );

		$response = WP_MCP_AI_Fashion_REST::create_job( $request );
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$this->assertTrue( NV_oOS_Media_Studio_AI_Service::has_acked( $user_id ) );
	}

	/**
	 * Test review handler rejects unknown jobs.
	 */
	public function test_review_handler_rejects_unknown_job() {
		$request = new WP_REST_Request( 'POST', '/nvoos-media-studio/v1/ai/jobs/1/review' );
		$request->set_param( 'job_id', 1 );
		$request->set_param( 'variant_key', 0 );
		$request->set_param( 'action', 'approve' );

		$result = WP_MCP_AI_Fashion_REST::review_job( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'nvoos_ms_job_not_found', $result->get_error_code() );
	}
}
