<?php
/**
 * Test Pro Settings Composer Packages and node-ensure Detection
 *
 * Verifies that the pro settings page correctly merges Pro addon Composer packages
 * and detects node-ensure package status.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for Composer packages merging and node-ensure detection.
 */
class WP_MCP_AI_Pro_Settings_Dependencies_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure the Pro Settings class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Settings' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Pro_Settings class not available' );
		}
	}

	/**
	 * Test that get_composer_packages includes Pro addon packages.
	 */
	public function test_get_composer_packages_includes_pro_addon() {
		$packages = WP_MCP_AI_Pro_Settings::get_composer_packages();

		// Should not have error.
		$this->assertTrue( empty( $packages['error'] ), 'Composer packages should load without error' );

		// Should have require array.
		$this->assertIsArray( $packages['require'], 'Should have require array' );

		// Should include base packages (from base composer.json).
		$this->assertArrayHasKey( 'psr/event-dispatcher', $packages['require'], 'Should include base package psr/event-dispatcher' );
		$this->assertArrayHasKey( 'symfony/http-client', $packages['require'], 'Should include base package symfony/http-client' );

		// rahul900day/tiktoken-php moved to the optional "suggest" section, so
		// it must no longer be advertised as a hard requirement.
		$this->assertArrayNotHasKey( 'rahul900day/tiktoken-php', $packages['require'], 'tiktoken-php should no longer be a hard requirement' );

		// If Pro addon exists, should include Pro packages.
		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$pro_composer_json = WP_MCP_AI_PRO_PATH . 'composer.json';
			if ( file_exists( $pro_composer_json ) ) {
				// Should include Pro packages like smalot/pdfparser, phpoffice, etc.
				$this->assertArrayHasKey( 'smalot/pdfparser', $packages['require'], 'Should include Pro package smalot/pdfparser' );
				$this->assertArrayHasKey( 'phpoffice/phpspreadsheet', $packages['require'], 'Should include Pro package phpoffice/phpspreadsheet' );
				$this->assertArrayHasKey( 'dompdf/dompdf', $packages['require'], 'Should include Pro package dompdf/dompdf' );
			}
		}
	}

	/**
	 * Test that check_composer_package_installed checks Pro vendor directory.
	 */
	public function test_check_composer_package_installed_checks_pro_vendor() {
		// Skip if Pro addon not available.
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not available' );
		}

		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_composer_package_installed' );
		$method->setAccessible( true );

		// Test Pro addon package (smalot/pdfparser).
		$pro_vendor_path = WP_MCP_AI_PRO_PATH . 'vendor/smalot/pdfparser';
		if ( file_exists( $pro_vendor_path ) ) {
			$result = $method->invoke( null, 'smalot/pdfparser' );
			$this->assertTrue( $result, 'smalot/pdfparser should be detected in Pro vendor directory' );
		}

		// Test base package (symfony/http-client).
		$base_vendor_path = WP_MCP_AI_PATH . 'vendor/symfony/http-client';
		if ( file_exists( $base_vendor_path ) ) {
			$result = $method->invoke( null, 'symfony/http-client' );
			$this->assertTrue( $result, 'symfony/http-client should be detected in base vendor directory' );
		}
	}

	/**
	 * Test that node-ensure package check returns true when Pro is active.
	 */
	public function test_node_ensure_available_when_pro_active() {
		// Skip if Pro addon not available.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		// Test node-ensure check - should return true when Pro is active.
		$result = $method->invoke( null, 'node-ensure' );
		$this->assertTrue( $result, 'node-ensure should be available when Pro addon is active' );
	}

	/**
	 * Test that get_package_status returns correct status for node-ensure.
	 */
	public function test_get_package_status_node_ensure() {
		// Skip if Pro addon not available.
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'get_package_status' );
		$method->setAccessible( true );

		// Get status for node-ensure.
		$status = $method->invoke( null, 'node-ensure' );

		// Should be available.
		$this->assertIsArray( $status, 'Status should be an array' );
		$this->assertTrue( $status['available'], 'node-ensure should be marked as available' );
		$this->assertEquals( 'Installed', $status['message'], 'node-ensure status message should be "Installed"' );
	}

	/**
	 * Seed the cached Media Worker health result.
	 *
	 * Seeding this transient bypasses the HTTP probe in
	 * is_media_worker_reachable(), which makes the assertions deterministic.
	 *
	 * @param bool $ok Whether the worker should appear reachable.
	 */
	private function set_worker_health( $ok ) {
		set_transient( 'wp_mcp_ai_worker_health', array( 'ok' => $ok ), MINUTE_IN_SECONDS );
	}

	/**
	 * Invoke the private get_package_status() helper.
	 *
	 * @param string $package Package name.
	 * @return array Status array.
	 */
	private function get_package_status( $package ) {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'get_package_status' );
		$method->setAccessible( true );

		return $method->invoke( null, $package );
	}

	/**
	 * Clear the seeded worker health cache.
	 */
	public function tearDown(): void {
		delete_transient( 'wp_mcp_ai_worker_health' );
		parent::tearDown();
	}

	/**
	 * Sidecar-only packages are available while the worker is reachable.
	 */
	public function test_sidecar_only_packages_available_when_worker_reachable() {
		$this->set_worker_health( true );

		$status = $this->get_package_status( 'ics' );

		$this->assertTrue( $status['available'], 'ics should be available via the sidecar' );
		$this->assertSame( 'sidecar', $status['source'], 'ics should report the sidecar as its source' );
	}

	/**
	 * Sidecar-only packages must not be reported installed when the worker is down.
	 *
	 * Regression guard: the vendored marker files exist on disk but cannot
	 * execute, so a bare file_exists() probe used to report "Installed" for a
	 * feature that could not run.
	 */
	public function test_sidecar_only_packages_unavailable_when_worker_unreachable() {
		$this->set_worker_health( false );

		$status = $this->get_package_status( 'ics' );

		$this->assertFalse( $status['available'], 'ics must not be available when the worker is unreachable' );
		$this->assertSame( 'sidecar_unreachable', $status['source'], 'ics should report the worker as unreachable' );
	}

	/**
	 * Locally-capable worker packages keep their local fallback.
	 *
	 * Packages such as sharp/katex/pdfkit also have usable local paths, so they
	 * must not be forced into the sidecar-only "unreachable" state.
	 */
	public function test_local_capable_worker_package_falls_through_when_worker_unreachable() {
		$this->set_worker_health( false );

		$status = $this->get_package_status( 'sharp' );

		$this->assertNotSame( 'sidecar_unreachable', $status['source'], 'Locally-capable worker packages must keep their local fallback' );
	}
}
