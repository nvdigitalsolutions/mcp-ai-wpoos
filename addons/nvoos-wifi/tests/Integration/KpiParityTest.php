<?php
/**
 * Proposal 150, T-0.11 — the WordPress half of the dual-runtime parity test.
 *
 * Asserts that the WordPress REST controller's payload equals the committed
 * parity fixture. The Laravel twin
 * (`docker/laravel/app-skeleton/tests/Feature/WifiKpisTest.php`) asserts against
 * its own copy of the same bytes, so the portable service cannot drift without
 * failing both suites.
 *
 * Deliberately drives the controller directly rather than round-tripping the
 * REST server: route registration is covered separately and this keeps the
 * assertion about the *payload*, which is what portability actually depends on.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use NvoosWifi\Domain\Kpi_Schema;
use NvoosWifi\Interfaces\Interface_WiFi_Clock;
use NvoosWifi\Plugin;
use NvoosWifi\REST\Class_Kpi_Controller;

require_once dirname( __DIR__, 2 ) . '/nvoos-wifi.php';

/**
 * Clock frozen at the fixture instant.
 *
 * @since 0.1.0
 */
final class NvoosWiFi_Test_Frozen_Clock implements Interface_WiFi_Clock {

	/**
	 * Construct the clock.
	 *
	 * @param int $at Timestamp to return.
	 */
	public function __construct( private int $at ) {}

	/**
	 * {@inheritdoc}
	 */
	public function now(): int {
		return $this->at;
	}
}

/**
 * Parity and wiring tests for the WiFi REST surface.
 *
 * @since 0.1.0
 */
final class NvoosWifiKpiParityTest extends WP_UnitTestCase {

	/**
	 * The instant the fixture pins `generated_at` to.
	 */
	const FROZEN_AT = 1763424100;

	/**
	 * Window start used by the fixture.
	 */
	const WINDOW_FROM = 1763337600;

	/**
	 * Window end used by the fixture.
	 */
	const WINDOW_TO = 1763424000;

	/**
	 * Prepare a known option state and freeze the clock.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		Plugin::reset();
		add_filter( 'nvoos_wifi_clock', array( $this, 'freeze_clock' ) );

		update_option(
			'nvoos_wifi_venues',
			array(
				'venue-1' => array(
					'label'     => 'Kingston Waterfront',
					'timezone'  => 'America/Jamaica',
					'residency' => 'jm',
				),
			)
		);
	}

	/**
	 * Restore global state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'nvoos_wifi_clock', array( $this, 'freeze_clock' ) );
		delete_option( 'nvoos_wifi_venues' );
		Plugin::reset();

		parent::tear_down();
	}

	/**
	 * Filter callback freezing time.
	 *
	 * @return Interface_WiFi_Clock
	 */
	public function freeze_clock(): Interface_WiFi_Clock {
		return new NvoosWiFi_Test_Frozen_Clock( self::FROZEN_AT );
	}

	/**
	 * The controller payload matches the committed fixture.
	 *
	 * @return void
	 */
	public function test_controller_payload_matches_the_shared_parity_fixture(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = ( new Class_Kpi_Controller() )->get_kpis( $this->request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$raw     = file_get_contents( dirname( __DIR__ ) . '/fixtures/kpis-venue-1-window-1.json' );
		$fixture = json_decode( (string) $raw, true );

		$this->assertIsArray( $fixture, 'The parity fixture must be readable JSON.' );
		$this->assertSame(
			$this->canonicalise( $fixture ),
			$this->canonicalise( $response->get_data() ),
			'The WordPress KPI payload drifted from the shared parity fixture. Either the portable '
			. 'service changed (regenerate BOTH fixtures) or the controller added shaping it must not add.'
		);
	}

	/**
	 * Every entity count in the payload declares its method.
	 *
	 * @return void
	 */
	public function test_every_entity_count_declares_its_method(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$data    = ( new Class_Kpi_Controller() )->get_kpis( $this->request() )->get_data();
		$checked = 0;

		foreach ( $data['samples'] as $sample ) {
			if ( ! Kpi_Schema::requires_method( $sample['key'] ) ) {
				continue;
			}

			++$checked;
			$this->assertNotNull( $sample['method'], "KPI {$sample['key']} counts entities and must declare a method." );
		}

		$this->assertGreaterThan( 0, $checked, 'The fixture should contain at least one entity-count KPI.' );
	}

	/**
	 * The route is gated on a real capability.
	 *
	 * @return void
	 */
	public function test_read_permission_requires_manage_options(): void {
		$controller = new Class_Kpi_Controller();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( $controller->can_read() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $controller->can_read() );
	}

	/**
	 * An unregistered venue fails with a stable code.
	 *
	 * @return void
	 */
	public function test_unknown_venue_returns_a_wp_error(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = ( new Class_Kpi_Controller() )->get_kpis( $this->request( 'venue-missing' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'nvoos_wifi_venue_not_found', $result->get_error_code() );
	}

	/**
	 * The tool is present in the Base tool map.
	 *
	 * @return void
	 */
	public function test_health_check_tool_is_registered_through_the_base_filter(): void {
		$tools = apply_filters( 'wp_mcp_ai_default_tools', array() );

		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_WiFi_Health_Check', $tools );
		$this->assertIsString( $tools['WP_MCP_AI_Tool_WiFi_Health_Check'] );
		$this->assertFileExists( $tools['WP_MCP_AI_Tool_WiFi_Health_Check'] );
	}

	/**
	 * Build a request for the controller.
	 *
	 * @param string $venue Venue id.
	 * @param int    $from  Window start.
	 * @param int    $to    Window end.
	 * @return WP_REST_Request
	 */
	private function request(
		string $venue = 'venue-1',
		int $from = self::WINDOW_FROM,
		int $to = self::WINDOW_TO
	): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/nvoos-wifi/v1/venues/' . $venue . '/kpis' );
		$request->set_param( 'id', $venue );
		$request->set_param( 'from', $from );
		$request->set_param( 'to', $to );

		return $request;
	}

	/**
	 * Order-insensitive JSON comparison for the fixture assertion.
	 *
	 * @param mixed $value Value to canonicalise.
	 * @return string
	 */
	private function canonicalise( $value ): string {
		return (string) wp_json_encode( $this->sort_keys( $value ) );
	}

	/**
	 * Recursively sort associative array keys.
	 *
	 * @param mixed $value Value to sort.
	 * @return mixed
	 */
	private function sort_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );

		if ( ! $is_list ) {
			ksort( $value );
		}

		return array_map( array( $this, 'sort_keys' ), $value );
	}
}
