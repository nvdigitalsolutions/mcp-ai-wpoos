<?php
/**
 * REST contract tests.
 *
 * @package NV_oOS_Chat_Spa
 */

class Test_Chat_Spa_REST extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_CHAT_SPA_VERSION' ) ) {
			define( 'NVOOS_CHAT_SPA_VERSION', '0.5.0' );
		}
		require_once dirname( __DIR__ ) . '/includes/rest/class-nvoos-chat-spa-rest.php';
	}

	public function test_health_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = NV_oOS_Chat_Spa_REST::admin_permission();
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_config_payload_includes_chat_endpoints() {
		$response = NV_oOS_Chat_Spa_REST::config();
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'endpoints', $data );
		$this->assertArrayHasKey( 'chatClient', $data['endpoints'] );
		$this->assertArrayHasKey( 'transcripts', $data['endpoints'] );
		$this->assertArrayHasKey( 'memory', $data['endpoints'] );
		$this->assertArrayHasKey( 'features', $data );
	}

	public function test_manifest_payload_describes_addon() {
		$response = NV_oOS_Chat_Spa_REST::manifest();
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertSame( 'chat-spa', $data['slug'] );
		$this->assertSame( 'chat', $data['surface'] );
	}
}
