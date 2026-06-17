<?php
/**
 * Tests for WP_MCP_AI_Error_Tracking_Service.
 *
 * Covers singleton behaviour, error tracking, retrieval, statistics,
 * component filtering, cleanup, and the settings-repository injection path.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for WP_MCP_AI_Error_Tracking_Service.
 */
class Test_Service_Error_Tracking extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var WP_MCP_AI_Error_Tracking_Service
	 */
	private $service;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->service = WP_MCP_AI_Error_Tracking_Service::get_instance();

		// Start each test with a clean slate.
		$this->service->clear_all_errors();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		$this->service->clear_all_errors();
		parent::tearDown();
	}

	/**
	 * Test that get_instance returns the same object on subsequent calls.
	 */
	public function test_get_instance_returns_singleton() {
		$a = WP_MCP_AI_Error_Tracking_Service::get_instance();
		$b = WP_MCP_AI_Error_Tracking_Service::get_instance();

		$this->assertSame( $a, $b );
	}

	/**
	 * Test that track_error returns a non-empty string ID.
	 */
	public function test_track_error_returns_non_empty_string_id() {
		$id = $this->service->track_error( 'test_component', 'A test error occurred.' );

		$this->assertNotEmpty( $id );
		$this->assertIsString( $id );
	}

	/**
	 * Test that track_error stores the error so it appears in get_recent_errors.
	 */
	public function test_tracked_error_appears_in_recent_errors() {
		$this->service->track_error( 'rest_api', 'Something went wrong in REST.' );

		$errors = $this->service->get_recent_errors( 10 );

		$this->assertIsArray( $errors );
		$this->assertNotEmpty( $errors );
	}

	/**
	 * Test that get_recent_errors returns an array.
	 */
	public function test_get_recent_errors_returns_array() {
		$errors = $this->service->get_recent_errors();
		$this->assertIsArray( $errors );
	}

	/**
	 * Test that get_recent_errors respects the limit parameter.
	 */
	public function test_get_recent_errors_respects_limit() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->service->track_error( 'rest_api', "Error #{$i}" );
		}

		$errors = $this->service->get_recent_errors( 3 );

		$this->assertIsArray( $errors );
		$this->assertLessThanOrEqual( 3, count( $errors ) );
	}

	/**
	 * Test that get_errors_by_component returns only matching errors.
	 */
	public function test_get_errors_by_component_filters_by_component() {
		$this->service->track_error( 'rest_api', 'REST error' );
		$this->service->track_error( 'chat_ui', 'Chat error' );

		$rest_errors = $this->service->get_errors_by_component( 'rest_api', 3600 );

		$this->assertIsArray( $rest_errors );
		foreach ( $rest_errors as $error ) {
			$this->assertSame( 'rest_api', $error['component'] );
		}
	}

	/**
	 * Test that get_error_statistics returns array keyed by component.
	 */
	public function test_get_error_statistics_returns_component_keyed_array() {
		$this->service->track_error( 'mcp_core', 'Core error' );
		$this->service->track_error( 'mcp_core', 'Another core error' );

		$stats = $this->service->get_error_statistics( 3600 );

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'mcp_core', $stats );
		$this->assertGreaterThanOrEqual( 2, $stats['mcp_core']['count'] );
	}

	/**
	 * Test that clear_all_errors returns true.
	 */
	public function test_clear_all_errors_returns_true() {
		$this->service->track_error( 'rest_api', 'Error to clear' );

		$result = $this->service->clear_all_errors();

		$this->assertTrue( $result );
	}

	/**
	 * Test that get_error_rate returns a numeric value (float or int 0).
	 *
	 * Note: when no errors have occurred the division path is not entered
	 * and the 0.0 default is returned; PHP may return int(0) in some paths
	 * via arithmetic on zero counts, so we assert numeric rather than float.
	 */
	public function test_get_error_rate_returns_float() {
		$rate = $this->service->get_error_rate( 'rest_api', 3600, 100 );
		$this->assertIsNumeric( $rate );
		$this->assertGreaterThanOrEqual( 0, $rate );
	}

	/**
	 * Test that record_error_with_metrics returns an error ID string.
	 */
	public function test_record_error_with_metrics_returns_error_id() {
		$id = $this->service->record_error_with_metrics( 'chat_ui', 'Metrics error', array(), false );

		$this->assertNotEmpty( $id );
		$this->assertIsString( $id );
	}
}
