<?php
/**
 * Test capability + allowlist gates on the bridge endpoints.
 *
 * @package NV_oOS_Skote
 */

/**
 * @group nvoos-skote
 */
class NVOOS_Skote_REST_Bridge_Permissions_Test extends WP_UnitTestCase {

	public static function set_up_before_class() {
		parent::set_up_before_class();
		$plugin = dirname( __DIR__ ) . '/nvoos-skote.php';
		if ( ! class_exists( 'NV_oOS_Skote' ) && file_exists( $plugin ) ) {
			require_once $plugin;
		}
	}

	public function set_up() {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	public function test_bridge_users_requires_list_users_cap() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$request  = new WP_REST_Request( 'GET', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/bridge/wp/users' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( rest_authorization_required_code(), $response->get_status() );
	}

	public function test_bridge_cpt_blocks_post_types_not_in_allowlist() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		// Ensure the option is empty.
		delete_option( NV_oOS_Skote::OPTION_ALLOWED_CPTS );

		$request  = new WP_REST_Request( 'GET', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/bridge/cpt/post' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( 'nvoos_skote_cpt_not_allowed', $response->get_data()['code'] );
	}

	public function test_bridge_cpt_passes_when_post_type_allowed() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		update_option( NV_oOS_Skote::OPTION_ALLOWED_CPTS, array( 'post' ) );

		$request  = new WP_REST_Request( 'GET', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/bridge/cpt/post' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['success'] );

		delete_option( NV_oOS_Skote::OPTION_ALLOWED_CPTS );
	}

	public function test_bridge_wc_returns_404_when_woocommerce_inactive() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		if ( NV_oOS_Skote::is_woocommerce_active() ) {
			$this->markTestSkipped( 'WooCommerce is active in this test environment.' );
			return;
		}

		$request  = new WP_REST_Request( 'GET', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/bridge/wc/products' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 404, $response->get_status() );
	}
}
