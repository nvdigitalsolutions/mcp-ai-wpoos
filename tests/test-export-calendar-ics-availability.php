<?php
/**
 * Tests for the Export Calendar ICS tool's availability gate.
 *
 * Generation is delegated to the Media Worker sidecar (or a filter), so the
 * gate must not depend on a local `node` binary or on the vendored ics marker
 * file — that marker is not loadable and is never executed.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Availability gate tests for WP_MCP_AI_Tool_Export_Calendar_ICS.
 */
class WP_MCP_AI_Export_Calendar_ICS_Availability_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Tool_Export_Calendar_ICS' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Export_Calendar_ICS class not available' );
		}
	}

	/**
	 * Invoke the private availability check with a forced sidecar state.
	 *
	 * The trait caches the sidecar health per instance; seeding that cache keeps
	 * these assertions independent of whether a worker happens to be running.
	 *
	 * @param bool $sidecar_available Whether the sidecar should appear reachable.
	 * @return bool Availability result.
	 */
	private function invoke_availability( $sidecar_available ) {
		$tool = new WP_MCP_AI_Tool_Export_Calendar_ICS();

		$property = new ReflectionProperty( $tool, 'sidecar_available' );
		$property->setAccessible( true );
		$property->setValue( $tool, $sidecar_available );

		$method = new ReflectionMethod( $tool, 'check_ics_availability' );
		$method->setAccessible( true );

		return $method->invoke( $tool );
	}

	/**
	 * A reachable sidecar is sufficient on its own.
	 */
	public function test_available_when_sidecar_reachable() {
		$this->assertTrue(
			$this->invoke_availability( true ),
			'ICS generation should be available when the Media Worker sidecar is reachable'
		);
	}

	/**
	 * With no sidecar and no filter, generation is unavailable.
	 */
	public function test_unavailable_without_sidecar_or_filter() {
		$this->assertFalse(
			$this->invoke_availability( false ),
			'ICS generation should be unavailable with no sidecar and no filter implementation'
		);
	}

	/**
	 * A registered generator filter makes generation available.
	 */
	public function test_available_when_filter_provides_implementation() {
		$callback = static function () {
			return array( 'content' => 'BEGIN:VCALENDAR' );
		};

		add_filter( 'wp_mcp_ai_ics_generate_calendar', $callback );

		try {
			$this->assertTrue(
				$this->invoke_availability( false ),
				'A registered wp_mcp_ai_ics_generate_calendar filter should make ICS generation available'
			);
		} finally {
			remove_filter( 'wp_mcp_ai_ics_generate_calendar', $callback );
		}
	}

	/**
	 * An existing vendored marker must not, by itself, enable generation.
	 *
	 * Regression guard: the gate previously returned true purely because
	 * assets/vendor/ics/index.js existed, even though that file cannot load
	 * (it requires an unshipped ./pipeline) and is never executed.
	 */
	public function test_unloadable_vendored_marker_does_not_enable_generation() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon path not defined' );
		}

		$marker = WP_MCP_AI_PRO_PATH . 'assets/vendor/ics/index.js';
		if ( ! file_exists( $marker ) ) {
			$this->markTestSkipped( 'Vendored ics marker not present in this build' );
		}

		$this->assertFalse(
			$this->invoke_availability( false ),
			'A vendored marker file must not enable ICS generation without a sidecar or filter'
		);
	}
}
