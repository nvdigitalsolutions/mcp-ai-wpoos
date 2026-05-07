<?php
/**
 * REST contract tests.
 *
 * @package NV_oOS_Media_Studio
 */

class Test_Media_Studio_REST extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_MEDIA_STUDIO_VERSION' ) ) {
			define( 'NVOOS_MEDIA_STUDIO_VERSION', '0.1.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/rest/class-nvoos-media-studio-rest.php';
	}

	public function test_health_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = NV_oOS_Media_Studio_REST::admin_permission();
		$this->assertInstanceOf( 'WP_Error', $result );
	}
}
