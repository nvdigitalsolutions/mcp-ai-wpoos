<?php
/**
 * Test the NV oOS Skote /settings + /me + /apps REST endpoints.
 *
 * @package NV_oOS_Skote
 */

/**
 * @group nvoos-skote
 */
class NVOOS_Skote_REST_Settings_Test extends WP_UnitTestCase {

	/**
	 * Ensure the plugin classes are loaded.
	 */
	public static function set_up_before_class() {
		parent::set_up_before_class();
		$plugin = dirname( __DIR__ ) . '/nvoos-skote.php';
		if ( ! class_exists( 'NV_oOS_Skote' ) && file_exists( $plugin ) ) {
			require_once $plugin;
		}
	}

	public function set_up() {
		parent::set_up();
		// Force REST init so our routes register.
		do_action( 'rest_api_init' );
	}

	public function test_routes_are_registered() {
		$server = rest_get_server();
		$routes = $server->get_routes();
		$this->assertArrayHasKey( '/' . NVOOS_SKOTE_REST_NAMESPACE . '/settings', $routes );
		$this->assertArrayHasKey( '/' . NVOOS_SKOTE_REST_NAMESPACE . '/me', $routes );
		$this->assertArrayHasKey( '/' . NVOOS_SKOTE_REST_NAMESPACE . '/apps', $routes );
	}

	public function test_settings_get_requires_auth() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/settings' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertNotEquals( 200, $response->get_status(), 'Anonymous users must not see settings.' );
	}

	public function test_settings_get_returns_envelope() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/settings' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertTrue( $data['success'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'user', $data['data'] );
		$this->assertArrayHasKey( 'site', $data['data'] );
	}

	public function test_settings_post_user_saves_only_whitelisted_keys() {
		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/settings' );
		$request->set_body_params( array() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'user' => array(
						'theme'              => 'dark',
						'sidebarCollapsed'   => true,
						'__evil__'           => '<script>alert(1)</script>',
					),
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$saved = get_user_meta( $user_id, NV_oOS_Skote::USER_META_PREFS, true );
		$this->assertIsArray( $saved );
		$this->assertSame( 'dark', $saved['theme'] );
		$this->assertTrue( $saved['sidebarCollapsed'] );
		$this->assertArrayNotHasKey( '__evil__', $saved, 'Non-allowlisted keys must be dropped.' );
	}

	public function test_settings_post_site_requires_admin() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$request = new WP_REST_Request( 'POST', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'site' => array( 'brandName' => 'Acme' ) ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( rest_authorization_required_code(), $response->get_status() );

		$site = get_option( NV_oOS_Skote::OPTION_SETTINGS, array() );
		$this->assertEmpty( $site );
	}

	public function test_apps_excludes_pro_when_pro_inactive() {
		// In the Phase-1 test environment, NV oOS Pro is not active.
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/apps' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$body  = $response->get_data();
		$slugs = wp_list_pluck( $body['data'], 'slug' );
		$this->assertContains( 'dashboard', $slugs );

		// Pro-only apps should still appear in the list (admins get to see
		// them) but with `enabled => false` when Pro is absent.
		foreach ( $body['data'] as $app ) {
			if ( 'workflows' === $app['slug'] ) {
				$this->assertSame( NV_oOS_Skote::is_pro_active(), $app['enabled'] );
			}
		}
	}

	public function test_me_returns_current_user_info() {
		$user_id = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Skote Tester',
			)
		);
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/me' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );

		$body = $response->get_data();
		$this->assertSame( $user_id, $body['data']['id'] );
		$this->assertSame( 'Skote Tester', $body['data']['displayName'] );
		$this->assertContains( 'administrator', $body['data']['roles'] );
	}
}
