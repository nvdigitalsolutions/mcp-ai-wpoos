<?php
/**
 * Tests for the Schedule Anything toolkit REST surface.
 *
 * Covers the toolkit list + toggle endpoints: permission gating,
 * slug validation, server-side tier entitlements, persistence, and
 * (on multisite) cross-tenant isolation.
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Class Test_SA_Toolkit_REST.
 */
class Test_SA_Toolkit_REST extends WP_UnitTestCase {

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	protected $admin_id = 0;

	/**
	 * Load the Schedule Anything surface + the platform toolkit manager.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			self::markTestSkipped( 'Pro Schedule Manager not available.' );
		}

		$base = dirname( __DIR__, 2 );

		require_once $base . '/addons/pro/includes/schedule-anything/class-wp-mcp-ai-pro-sa-toolkit-adapter.php';
		require_once $base . '/addons/pro/includes/rest/class-wp-mcp-ai-pro-sa-rest-controller.php';

		// Load the platform plugin's toolkit manager so the adapter's
		// platform-active paths (tier entitlements, slug map) are exercised.
		if ( ! class_exists( 'SA_Toolkit_Manager' ) ) {
			require_once $base . '/addons/schedule-anything-platform/includes/class-sa-toolkit-manager.php';
		}

		WP_MCP_AI_SA_REST_Controller::init();
	}

	/**
	 * Reset state + register routes between tests.
	 */
	protected function setUp(): void {
		parent::setUp();

		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'sa_tenant_meta' );
		delete_option( WP_MCP_AI_Pro_Schedule_Manager::SCHEDULES_OPTION );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		do_action( 'rest_api_init' );
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @param array  $body   JSON body.
	 * @return WP_REST_Response
	 */
	protected function dispatch( $method, $route, array $body = array() ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Unauthenticated list is forbidden.
	 */
	public function test_list_requires_permission() {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/toolkits' );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Authenticated list returns the canonical toolkit shape.
	 */
	public function test_list_returns_toolkits() {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'GET', '/mcp-ai-pro/v1/toolkits' );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertNotEmpty( $data['toolkits'] );

		$first = $data['toolkits'][0];
		$this->assertArrayHasKey( 'slug', $first );
		$this->assertArrayHasKey( 'flag_key', $first );
		$this->assertArrayHasKey( 'enabled', $first );
	}

	/**
	 * Unauthenticated toggle is forbidden.
	 */
	public function test_toggle_requires_permission() {
		wp_set_current_user( 0 );

		$response = $this->dispatch( 'POST', '/mcp-ai-pro/v1/toolkits/crm/toggle', array( 'enabled' => true ) );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Unknown toolkit slug returns 404.
	 */
	public function test_toggle_unknown_slug_404() {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/mcp-ai-pro/v1/toolkits/not-a-toolkit/toggle', array( 'enabled' => true ) );

		$this->assertEquals( 404, $response->get_status() );
	}

	/**
	 * Toggling a starter-entitled toolkit persists to wp_mcp_ai_settings.
	 */
	public function test_toggle_persists_setting() {
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/mcp-ai-pro/v1/toolkits/crm/toggle', array( 'enabled' => true ) );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertTrue( $data['enabled'] );

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertArrayHasKey( 'enable_crm_toolkit', $settings );
		$this->assertTrue( $settings['enable_crm_toolkit'] );
	}

	/**
	 * A toolkit outside the starter tier is rejected server-side (403).
	 */
	public function test_toggle_not_entitled_403() {
		update_option( 'sa_tenant_meta', array( 'tier' => 'starter' ) );
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/mcp-ai-pro/v1/toolkits/ecommerce/toggle', array( 'enabled' => true ) );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * Enterprise tier entitles the full catalogue.
	 */
	public function test_toggle_enterprise_entitled() {
		update_option( 'sa_tenant_meta', array( 'tier' => 'enterprise' ) );
		wp_set_current_user( $this->admin_id );

		$response = $this->dispatch( 'POST', '/mcp-ai-pro/v1/toolkits/ecommerce/toggle', array( 'enabled' => true ) );

		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Toolkit state is per-site (negative isolation path; multisite only).
	 */
	public function test_cross_tenant_isolation() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Requires multisite.' );
		}

		$blog_id = self::factory()->blog->create();
		wp_set_current_user( $this->admin_id );

		// Toggle ON in the main site.
		$this->dispatch( 'POST', '/mcp-ai-pro/v1/toolkits/crm/toggle', array( 'enabled' => true ) );

		switch_to_blog( $blog_id );
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$this->assertArrayNotHasKey( 'enable_crm_toolkit', $settings );
		restore_current_blog();
	}
}
