<?php
/**
 * Test Pro Settings Composer Packages and node-ensure Detection
 *
 * Verifies that the pro settings page correctly merges Pro addon Composer packages
 * and detects node-ensure package status.
 *
 * @package WP_MCP_AI
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
		$this->assertArrayHasKey( 'rahul900day/tiktoken-php', $packages['require'], 'Should include base package tiktoken-php' );
		$this->assertArrayHasKey( 'symfony/http-client', $packages['require'], 'Should include base package symfony/http-client' );

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
}
