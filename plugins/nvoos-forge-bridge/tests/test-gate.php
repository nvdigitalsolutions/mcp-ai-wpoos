<?php
/**
 * Gate-state route tests (Proposal 152 P4) — detection, normalization, wire
 * shape, and the filter seam every other gate plugin uses.
 *
 * @package NvoosForgeBridge
 */

use NvoosForgeBridge\Rest\Gate;

/**
 * Gate suite.
 */
class Test_Forge_Bridge_Gate extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Load the plugin stubs + reset the gate options.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		require_once __DIR__ . '/stubs/gate-plugins.php';

		delete_option( 'elementor_maintenance_mode_mode' );
		delete_option( 'elementor_maintenance_mode_template_id' );
		delete_option( 'wpmm_settings' );

		global $wp_rest_server;
		$wp_rest_server = null;
		$this->server   = rest_get_server();
	}

	/**
	 * Teardown.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		delete_option( 'elementor_maintenance_mode_mode' );
		delete_option( 'elementor_maintenance_mode_template_id' );
		delete_option( 'wpmm_settings' );

		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tearDown();
	}

	/**
	 * The route registers under nvoos-forge/v1.
	 */
	public function test_route_registered(): void {
		$routes = $this->server->get_routes( 'nvoos-forge/v1' );
		$this->assertContains( '/nvoos-forge/v1/gate', array_keys( $routes ) );
	}

	/**
	 * Nothing configured = off, and the payload carries no extra fields.
	 */
	public function test_reports_off_by_default(): void {
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/nvoos-forge/v1/gate' ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertFalse( $data['maintenance'] );
		$this->assertSame( 'off', $data['mode'] );
		$this->assertNull( $data['plugin'] );
		$this->assertArrayHasKey( 'checkedAt', $data );
		// Never a URL, a template id, or a role list.
		$this->assertSame( array( 'maintenance', 'mode', 'plugin', 'checkedAt' ), array_keys( $data ) );
	}

	/**
	 * Elementor needs BOTH a mode and a template (its own enablement rule).
	 */
	public function test_elementor_needs_a_mode_and_a_template(): void {
		update_option( 'elementor_maintenance_mode_mode', 'coming_soon' );
		$this->assertFalse( Gate::detect()['maintenance'], 'a mode without a template does not gate the site' );

		update_option( 'elementor_maintenance_mode_template_id', 12 );
		$state = Gate::detect();
		$this->assertTrue( $state['maintenance'] );
		$this->assertSame( 'coming_soon', $state['mode'] );
		$this->assertSame( 'elementor', $state['plugin'] );
	}

	/**
	 * Maintenance mode (not coming soon) reports the 503 flavour.
	 */
	public function test_elementor_maintenance_mode(): void {
		update_option( 'elementor_maintenance_mode_mode', 'maintenance' );
		update_option( 'elementor_maintenance_mode_template_id', 12 );

		$state = Gate::detect();
		$this->assertSame( 'maintenance', $state['mode'] );
		$this->assertSame( 'elementor', $state['plugin'] );
	}

	/**
	 * WP Maintenance Mode's own status option.
	 */
	public function test_wp_maintenance_mode_status(): void {
		update_option( 'wpmm_settings', array( 'general' => array( 'status' => 1 ) ) );

		$state = Gate::detect();
		$this->assertTrue( $state['maintenance'] );
		$this->assertSame( 'maintenance', $state['mode'] );
		$this->assertSame( 'wp-maintenance-mode', $state['plugin'] );
	}

	/**
	 * A status of 0 is off.
	 */
	public function test_wp_maintenance_mode_disabled(): void {
		update_option( 'wpmm_settings', array( 'general' => array( 'status' => 0 ) ) );

		$this->assertFalse( Gate::detect()['maintenance'] );
	}

	/**
	 * Any other gate plugin declares itself through the filter.
	 */
	public function test_filter_declares_an_unsupported_plugin(): void {
		$filter = static function () {
			return array(
				'maintenance' => true,
				'mode'        => 'coming-soon',
				'plugin'      => 'custom-gate',
			);
		};
		add_filter( 'nvoos_forge_bridge_gate_state', $filter );

		$state = Gate::detect();
		remove_filter( 'nvoos_forge_bridge_gate_state', $filter );

		$this->assertTrue( $state['maintenance'] );
		// `coming-soon` is not a wire mode — it normalizes to maintenance.
		$this->assertSame( 'maintenance', $state['mode'] );
		$this->assertSame( 'custom-gate', $state['plugin'] );
	}

	/**
	 * A filter that returns garbage keeps the detected state.
	 */
	public function test_filter_garbage_keeps_the_detected_state(): void {
		update_option( 'wpmm_settings', array( 'general' => array( 'status' => 1 ) ) );
		$filter = static function () {
			return 'not-an-array';
		};
		add_filter( 'nvoos_forge_bridge_gate_state', $filter );

		$state = Gate::detect();
		remove_filter( 'nvoos_forge_bridge_gate_state', $filter );

		$this->assertTrue( $state['maintenance'] );
		$this->assertSame( 'wp-maintenance-mode', $state['plugin'] );
	}

	/**
	 * maintenance=false always reports mode=off, whatever the filter said.
	 */
	public function test_false_maintenance_forces_off(): void {
		$filter = static function () {
			return array(
				'maintenance' => false,
				'mode'        => 'coming_soon',
				'plugin'      => 'elementor',
			);
		};
		add_filter( 'nvoos_forge_bridge_gate_state', $filter );

		$state = Gate::detect();
		remove_filter( 'nvoos_forge_bridge_gate_state', $filter );

		$this->assertFalse( $state['maintenance'] );
		$this->assertSame( 'off', $state['mode'] );
	}
}
