<?php
/**
 * REST contract tests.
 *
 * @package NV_oOS_Media_Studio
 */
class Test_Media_Studio_REST extends WP_UnitTestCase {
	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ) {
			define( 'NVOOS_MEDIA_STUDIO_VERSION', '0.2.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/rest/class-nvoos-media-studio-rest.php';
		if ( ! class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			require_once dirname( __DIR__ ) . '/includes/ai/class-nvoos-media-studio-ai-service.php';
		}
	}

	/**
	 * Test that health endpoint requires manage_options capability.
	 */
	public function test_health_requires_manage_options() {
		// The test bootstrap grants manage_options globally; strip it at higher
		// priority so the subscriber correctly lacks the capability.
		$strip_cap = static function ( $allcaps ) {
			unset( $allcaps['manage_options'] );
			return $allcaps;
		};
		add_filter( 'user_has_cap', $strip_cap, 100 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = NV_oOS_Media_Studio_REST::admin_permission();
		remove_filter( 'user_has_cap', $strip_cap, 100 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test that admin permission check allows administrator role.
	 */
	public function test_admin_permission_allows_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = NV_oOS_Media_Studio_REST::admin_permission();
		$this->assertTrue( $result );
	}

	/**
	 * Test that health endpoint returns ok status.
	 */
	public function test_health_endpoint_returns_ok_status() {
		$response = NV_oOS_Media_Studio_REST::health();
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertSame( 'ok', $data['status'] );
		$this->assertTrue( isset( $data['version'] ) );
	}

	/**
	 * Test that edit_posts permission denies subscribers.
	 */
	public function test_edit_posts_permission_denies_subscriber() {
		$strip_cap = static function ( $allcaps ) {
			unset( $allcaps['edit_posts'] );
			return $allcaps;
		};
		add_filter( 'user_has_cap', $strip_cap, 100 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = NV_oOS_Media_Studio_REST::edit_posts_permission();
		remove_filter( 'user_has_cap', $strip_cap, 100 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test that upload_permission requires upload_files AND edit_posts.
	 */
	public function test_upload_permission_denies_editor_without_upload_files() {
		$strip_cap = static function ( $allcaps ) {
			unset( $allcaps['upload_files'] );
			return $allcaps;
		};
		add_filter( 'user_has_cap', $strip_cap, 100 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$result = NV_oOS_Media_Studio_REST::upload_permission();
		remove_filter( 'user_has_cap', $strip_cap, 100 );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Test that upload_permission allows administrators.
	 */
	public function test_upload_permission_allows_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( NV_oOS_Media_Studio_REST::upload_permission() );
	}

	/**
	 * Test capabilities endpoint returns the capability map.
	 */
	public function test_ai_capabilities_endpoint() {
		$response = NV_oOS_Media_Studio_REST::ai_capabilities();
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'transforms', $data );
		$this->assertArrayHasKey( 'settings', $data );
	}

	/**
	 * Test presets endpoint returns the base preset list.
	 */
	public function test_ai_presets_endpoint() {
		$response = NV_oOS_Media_Studio_REST::ai_presets();
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );
	}

	/**
	 * Test generate endpoint rejects unknown transforms.
	 */
	public function test_ai_generate_rejects_unknown_transform() {
		$request = new WP_REST_Request( 'POST', '/nvoos-media-studio/v1/ai/generate' );
		$request->set_param( 'attachment_id', 1 );
		$request->set_param( 'transform', 'nope' );

		$result = NV_oOS_Media_Studio_REST::ai_generate( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'nvoos_ms_invalid_transform', $result->get_error_code() );
	}

	/**
	 * Test import endpoint rejects missing attachments.
	 */
	public function test_ai_import_rejects_missing_attachment() {
		$request = new WP_REST_Request( 'POST', '/nvoos-media-studio/v1/ai/import' );
		$request->set_param( 'attachment_id', 999999 );

		$result = NV_oOS_Media_Studio_REST::ai_import( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'nvoos_ms_attachment_not_found', $result->get_error_code() );
	}

	/**
	 * Test export endpoint rejects invalid payloads.
	 */
	public function test_ai_export_rejects_invalid_payload() {
		$request = new WP_REST_Request( 'POST', '/nvoos-media-studio/v1/ai/export' );
		$request->set_param( 'data_url', 'data:text/html;base64,PGI+aGk8L2I+' );

		$result = NV_oOS_Media_Studio_REST::ai_export( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'nvoos_ms_invalid_payload', $result->get_error_code() );
	}

	/**
	 * Test pipeline endpoint rejects unknown profiles.
	 */
	public function test_ai_pipeline_rejects_invalid_profile() {
		if ( ! class_exists( 'NV_oOS_Media_Studio_Output_Pipeline' ) ) {
			require_once dirname( __DIR__ ) . '/includes/ai/class-nvoos-media-studio-output-pipeline.php';
		}
		$request = new WP_REST_Request( 'POST', '/nvoos-media-studio/v1/ai/pipeline' );
		$request->set_param( 'attachment_id', 1 );
		$request->set_param( 'profile', 'bogus' );

		$result = NV_oOS_Media_Studio_REST::ai_pipeline( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'nvoos_ms_invalid_profile', $result->get_error_code() );
	}

	/**
	 * Test data URL sanitizer drops non-image schemes.
	 */
	public function test_sanitize_data_url_drops_non_image() {
		$this->assertSame( '', NV_oOS_Media_Studio_REST::sanitize_data_url( 'data:text/html;base64,abc' ) );
		$this->assertSame(
			'data:image/png;base64,abc',
			NV_oOS_Media_Studio_REST::sanitize_data_url( 'data:image/png;base64,abc' )
		);
	}

	/**
	 * Test AI routes are registered under the namespace.
	 */
	public function test_ai_routes_registered() {
		add_action( 'rest_api_init', array( 'NV_oOS_Media_Studio_REST', 'register_routes' ) );
		$GLOBALS['wp_rest_server'] = null;
		$server                    = rest_get_server();
		$routes                    = $server->get_routes( 'nvoos-media-studio/v1' );
		remove_all_actions( 'rest_api_init' );

		foreach ( array( '/nvoos-media-studio/v1/ai/capabilities', '/nvoos-media-studio/v1/ai/presets', '/nvoos-media-studio/v1/ai/models', '/nvoos-media-studio/v1/ai/generate', '/nvoos-media-studio/v1/ai/import', '/nvoos-media-studio/v1/ai/export', '/nvoos-media-studio/v1/ai/pipeline' ) as $path ) {
			$this->assertArrayHasKey( $path, $routes );
			$this->assertIsArray( $routes[ $path ] );
		}
	}
}
