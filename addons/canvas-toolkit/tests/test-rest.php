<?php
/**
 * REST contract tests.
 *
 * @package NV_oOS_Canvas_Toolkit
 */
class Test_Canvas_Toolkit_REST extends WP_UnitTestCase {
	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_CANVAS_TOOLKIT_VERSION' ) ) {
			define( 'NVOOS_CANVAS_TOOLKIT_VERSION', '0.1.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/rest/class-nvoos-canvas-toolkit-rest.php';
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
		$result = NV_oOS_Canvas_Toolkit_REST::admin_permission();
		remove_filter( 'user_has_cap', $strip_cap, 100 );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Test that admin permission check allows administrator role.
	 */
	public function test_admin_permission_allows_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = NV_oOS_Canvas_Toolkit_REST::admin_permission();
		$this->assertTrue( $result );
	}

	/**
	 * Test that health endpoint returns ok status.
	 */
	public function test_health_endpoint_returns_ok_status() {
		$response = NV_oOS_Canvas_Toolkit_REST::health();
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertSame( 'ok', $data['status'] );
		$this->assertTrue( isset( $data['version'] ) );
	}
}
