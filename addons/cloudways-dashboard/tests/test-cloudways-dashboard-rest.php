<?php
/**
 * Tests for NV oOS Cloudways Dashboard REST endpoints.
 *
 * @package NV_oOS_CloudwaysDashboard
 * @since   0.1.0
 */

/**
 * Class Test_Cloudways_Dashboard_REST
 *
 * @group cloudways-dashboard
 * @group rest
 */
class Test_Cloudways_Dashboard_REST extends WP_UnitTestCase {

	/**
	 * REST namespace.
	 */
	const NS = 'nvoos-cloudways-dashboard/v1';

	/**
	 * Admin user ID for authenticated requests.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Subscriber user ID for permission tests.
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the addon classes are loaded.
		if ( ! class_exists( 'NV_oOS_CloudwaysDashboard_REST' ) ) {
			require_once dirname( __DIR__ ) . '/includes/rest/class-nvoos-cloudways-dashboard-rest.php';
		}

		// Register routes before each test so they're available.
		if ( ! has_action( 'rest_api_init', array( 'NV_oOS_CloudwaysDashboard_REST', 'register_routes' ) ) ) {
			add_action( 'rest_api_init', array( 'NV_oOS_CloudwaysDashboard_REST', 'register_routes' ) );
		}

		// Trigger route registration.
		do_action( 'rest_api_init' );

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	// ── Helpers ──────────────────────────────────────────────────────

	/**
	 * Create a REST request and dispatch it.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route relative to namespace.
	 * @param int    $user   User ID to set as current user (0 = unauthenticated).
	 * @return WP_REST_Response
	 */
	private function dispatch( $method, $route, $user = 0 ) {
		if ( $user ) {
			wp_set_current_user( $user );
		} else {
			wp_set_current_user( 0 );
		}

		$request = new WP_REST_Request( $method, '/' . self::NS . $route );
		if ( 'POST' === $method || 'PUT' === $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
		}

		return rest_do_request( $request );
	}

	/**
	 * Assert that a response has a given HTTP status.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @param int              $expected HTTP status code.
	 */
	private function assertStatus( $response, $expected ) {
		$this->assertSame( $expected, $response->get_status() );
	}

	/**
	 * Assert that a response contains a specific field.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @param string           $field    Field key.
	 */
	private function assertResponseHasField( $response, $field ) {
		$data = $response->get_data();
		$this->assertArrayHasKey( $field, $data, "Response missing field: {$field}" );
	}

	// ── Permission Tests ─────────────────────────────────────────────

	/**
	 * Test that unauthenticated health endpoint returns 401.
	 */
	public function test_unauthorized_health_returns_401() {
		$this->assertStatus( $this->dispatch( 'GET', '/health', 0 ), 401 );
	}

	/**
	 * Test that subscriber health endpoint returns 403.
	 */
	public function test_subscriber_health_returns_403() {
		$this->assertStatus( $this->dispatch( 'GET', '/health', $this->subscriber_id ), 403 );
	}

	/**
	 * Test that admin health endpoint returns 200.
	 */
	public function test_admin_health_returns_200() {
		$this->assertStatus( $this->dispatch( 'GET', '/health', $this->admin_id ), 200 );
	}

	/**
	 * Test that unauthenticated servers endpoint returns 401.
	 */
	public function test_unauthorized_servers_returns_401() {
		$this->assertStatus( $this->dispatch( 'GET', '/servers', 0 ), 401 );
	}

	/**
	 * Test that subscriber servers endpoint returns 403.
	 */
	public function test_subscriber_servers_returns_403() {
		$this->assertStatus( $this->dispatch( 'GET', '/servers', $this->subscriber_id ), 403 );
	}

	/**
	 * Test that unauthenticated toolkits endpoint returns 401.
	 */
	public function test_unauthorized_toolkits_returns_401() {
		$this->assertStatus( $this->dispatch( 'GET', '/toolkits', 0 ), 401 );
	}

	/**
	 * Test that subscriber toolkits endpoint returns 403.
	 */
	public function test_subscriber_toolkits_returns_403() {
		$this->assertStatus( $this->dispatch( 'GET', '/toolkits', $this->subscriber_id ), 403 );
	}

	/**
	 * Test that unauthenticated settings endpoint returns 401.
	 */
	public function test_unauthorized_settings_returns_401() {
		$this->assertStatus( $this->dispatch( 'GET', '/settings', 0 ), 401 );
	}

	/**
	 * Test that subscriber settings endpoint returns 403.
	 */
	public function test_subscriber_settings_returns_403() {
		$this->assertStatus( $this->dispatch( 'GET', '/settings', $this->subscriber_id ), 403 );
	}

	// ── Health Endpoint ──────────────────────────────────────────────

	/**
	 * Test that health response has expected shape.
	 */
	public function test_health_response_shape() {
		$resp = $this->dispatch( 'GET', '/health', $this->admin_id );
		$this->assertStatus( $resp, 200 );

		$data = $resp->get_data();
		$this->assertArrayHasKey( 'status', $data );
		$this->assertArrayHasKey( 'version', $data );
		$this->assertArrayHasKey( 'cloudways', $data );
		$this->assertContains( $data['cloudways'], array( 'unavailable', 'not_configured', 'connected' ) );
	}

	// ── Toolkits Endpoint ────────────────────────────────────────────

	/**
	 * Test that toolkits response has expected shape.
	 */
	public function test_toolkits_response_shape() {
		$resp = $this->dispatch( 'GET', '/toolkits', $this->admin_id );
		$this->assertStatus( $resp, 200 );

		$data = $resp->get_data();
		$this->assertArrayHasKey( 'toolkits', $data );
		$this->assertArrayHasKey( 'count', $data );
		$this->assertIsArray( $data['toolkits'] );
	}

	// ── Settings Endpoint ────────────────────────────────────────────

	/**
	 * Test that settings GET response has expected shape.
	 */
	public function test_settings_get_shape() {
		$resp = $this->dispatch( 'GET', '/settings', $this->admin_id );
		$this->assertStatus( $resp, 200 );

		$data = $resp->get_data();
		$this->assertArrayHasKey( 'configured', $data );
		$this->assertArrayHasKey( 'masked_email', $data );
	}

	/**
	 * Test that settings PUT with missing fields returns 400.
	 */
	public function test_settings_put_missing_fields_returns_400() {
		$request = new WP_REST_Request( 'PUT', '/' . self::NS . '/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array() ) );
		wp_set_current_user( $this->admin_id );

		$resp = rest_do_request( $request );
		$this->assertStatus( $resp, 400 );
	}

	// ── Summary Endpoint ─────────────────────────────────────────────

	/**
	 * Test that summary endpoint requires authentication.
	 */
	public function test_summary_auth_required() {
		$this->assertStatus( $this->dispatch( 'GET', '/summary', 0 ), 401 );
	}

	// ── Projects Endpoint ────────────────────────────────────────────

	/**
	 * Test that projects endpoint requires authentication.
	 */
	public function test_projects_auth_required() {
		$this->assertStatus( $this->dispatch( 'GET', '/projects', 0 ), 401 );
	}
}
