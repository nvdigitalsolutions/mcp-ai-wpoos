<?php
/**
 * REST contract tests.
 *
 * @package NV_oOS_Canvas_Toolkit
 */

class Test_Canvas_Toolkit_REST extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_CANVAS_TOOLKIT_VERSION' ) ) {
			define( 'NVOOS_CANVAS_TOOLKIT_VERSION', '0.1.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/rest/class-nvoos-canvas-toolkit-rest.php';
	}

	public function test_health_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = NV_oOS_Canvas_Toolkit_REST::admin_permission();
		$this->assertInstanceOf( 'WP_Error', $result );
	}
}
