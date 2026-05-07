<?php
/**
 * Toolkit Shell — REST contract tests.
 *
 * @package NV_oOS_Toolkit_Shell
 */

/**
 * Tests for NV_oOS_Toolkit_Shell_REST.
 */
class Test_Toolkit_Shell_REST extends WP_UnitTestCase {

	/**
	 * Bootstrap addon constants.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_VERSION' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_VERSION', '0.2.0' );
		}
		if ( ! defined( 'NVOOS_TOOLKIT_SHELL_PATH' ) ) {
			define( 'NVOOS_TOOLKIT_SHELL_PATH', dirname( __DIR__ ) . '/' );
		}
		require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/class-nvoos-toolkit-shell-manifest-registry.php';
		require_once NVOOS_TOOLKIT_SHELL_PATH . 'includes/rest/class-nvoos-toolkit-shell-rest.php';
	}

	/**
	 * Anonymous users are denied from reader endpoints.
	 *
	 * @return void
	 */
	public function test_reader_permission_denies_anonymous() {
		wp_set_current_user( 0 );
		$result = NV_oOS_Toolkit_Shell_REST::reader_permission();
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * Subscribers (with `read`) are allowed to list manifests.
	 *
	 * @return void
	 */
	public function test_reader_permission_allows_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( NV_oOS_Toolkit_Shell_REST::reader_permission() );
	}

	/**
	 * Subscribers cannot hit the admin-only health endpoint.
	 *
	 * @return void
	 */
	public function test_admin_permission_denies_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = NV_oOS_Toolkit_Shell_REST::admin_permission();
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Admins can hit the admin-only endpoints.
	 *
	 * @return void
	 */
	public function test_admin_permission_allows_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( NV_oOS_Toolkit_Shell_REST::admin_permission() );
	}

	/**
	 * Health endpoint returns ok + version + manifest_count.
	 *
	 * @return void
	 */
	public function test_health_endpoint_returns_status() {
		$response = NV_oOS_Toolkit_Shell_REST::health();
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertSame( 'ok', $data['status'] );
		$this->assertArrayHasKey( 'version', $data );
		$this->assertArrayHasKey( 'manifest_count', $data );
	}

	/**
	 * `list_manifests` returns a summary array.
	 *
	 * @return void
	 */
	public function test_list_manifests_returns_summary() {
		NV_oOS_Toolkit_Shell_Manifest_Registry::reset_cache();
		$response = NV_oOS_Toolkit_Shell_REST::list_manifests();
		$this->assertInstanceOf( 'WP_REST_Response', $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'manifests', $data );
		$this->assertIsArray( $data['manifests'] );
	}

	/**
	 * `get_manifest` for a missing slug returns 404.
	 *
	 * @return void
	 */
	public function test_get_manifest_returns_404_for_missing() {
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'toolkit', 'definitely-not-a-toolkit' );
		$result = NV_oOS_Toolkit_Shell_REST::get_manifest( $request );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}
}
