<?php
/**
 * Test Pro-bridge + workflows controller behaviour when Pro is absent vs
 * present.
 *
 * @package NV_oOS_Skote
 */

/**
 * @group nvoos-skote
 */
class NVOOS_Skote_Pro_Bridge_Test extends WP_UnitTestCase {

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

	public function test_is_pro_active_reflects_pro_init_function() {
		$expected = function_exists( 'wp_mcp_ai_pro_init' );
		$this->assertSame( $expected, NV_oOS_Skote::is_pro_active() );
	}

	public function test_workflows_endpoint_returns_501_when_pro_inactive() {
		if ( NV_oOS_Skote::is_pro_active() ) {
			$this->markTestSkipped( 'Pro is active in this test environment.' );
			return;
		}

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request  = new WP_REST_Request( 'GET', '/' . NVOOS_SKOTE_REST_NAMESPACE . '/workflows' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 501, $response->get_status() );
		$this->assertSame( 'nvoos_skote_pro_required', $response->get_data()['code'] );
	}

	public function test_pro_bridge_payload_only_includes_pro_keys_when_pro_active() {
		$payload = NVOOS_Skote_Pro_Bridge::add_pro_payload(
			array( 'restUrl' => 'https://example.test/wp-json/nvoos-skote/v1/' ),
			array( 'surface' => 'admin' )
		);
		if ( NV_oOS_Skote::is_pro_active() ) {
			$this->assertArrayHasKey( 'pro', $payload );
		} else {
			$this->assertArrayNotHasKey( 'pro', $payload );
		}
	}

	public function test_jetengine_table_prefix_uses_underscores() {
		// Canonical prefix per JetEngine source. Hyphens were a previous
		// memory's mistake.
		$table = NVOOS_Skote_JetEngine_Bridge::get_cct_table_name( 'tasks' );
		$this->assertStringContainsString( 'jet_cct_tasks', $table );
		$this->assertStringNotContainsString( 'jet-cct-', $table );
	}
}
