<?php
/**
 * Tests for Pro Settings Document Generation and Cornerstone3D Package Detection
 *
 * Verifies that:
 * - pdfkit, docx, exceljs are detected via their pre-packed bundle files in bin/
 * - @cornerstonejs/* packages are detected via the imaging-viewer.js CDN loader
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for document generation and Cornerstone3D package detection.
 */
class WP_MCP_AI_Pro_Settings_Doc_Cornerstone_Packages_Test extends WP_UnitTestCase {

	/**
	 * Document generation packages and their expected bundle paths.
	 *
	 * @var array
	 */
	private $doc_gen_packages = array(
		'pdfkit'  => 'bin/generate-pdf.bundle.js',
		'docx'    => 'bin/generate-word.bundle.js',
		'exceljs' => 'bin/generate-excel.bundle.js',
	);

	/**
	 * Cornerstone3D packages that should be detected via imaging-viewer.js.
	 *
	 * @var array
	 */
	private $cornerstone_packages = array(
		'@cornerstonejs/core',
		'@cornerstonejs/tools',
		'@cornerstonejs/dicom-image-loader',
	);

	/**
	 * Test that check_package_installed() returns boolean for doc generation packages.
	 */
	public function test_check_package_installed_doc_gen_returns_bool() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		foreach ( array_keys( $this->doc_gen_packages ) as $package ) {
			$result = $method->invoke( null, $package );
			$this->assertIsBool( $result, "check_package_installed('{$package}') should return boolean" );
		}
	}

	/**
	 * Test that doc generation packages are detected when bundle files exist.
	 *
	 * If the Pro addon is active and the bundle files are present, the packages
	 * should be reported as installed.
	 */
	public function test_doc_gen_packages_detected_when_bundles_exist() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		foreach ( $this->doc_gen_packages as $package => $bundle_path ) {
			$full_bundle_path = WP_MCP_AI_PRO_PATH . $bundle_path;
			if ( file_exists( $full_bundle_path ) ) {
				$result = $method->invoke( null, $package );
				$this->assertTrue(
					$result,
					"Package '{$package}' should be detected as installed when bundle file '{$bundle_path}' exists"
				);
			}
		}
	}

	/**
	 * Test that Cornerstone3D packages are detected when imaging-viewer.js exists.
	 *
	 * If the Pro addon is active and imaging-viewer.js is present, all Cornerstone3D
	 * packages should be reported as installed (they are CDN-loaded by that file).
	 */
	public function test_cornerstone_packages_detected_when_imaging_viewer_exists() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		$imaging_viewer_path = WP_MCP_AI_PRO_PATH . 'assets/js/imaging-viewer.js';
		if ( ! file_exists( $imaging_viewer_path ) ) {
			$this->markTestSkipped( 'imaging-viewer.js not present' );
		}

		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		foreach ( $this->cornerstone_packages as $package ) {
			$result = $method->invoke( null, $package );
			$this->assertTrue(
				$result,
				"Package '{$package}' should be detected as installed when imaging-viewer.js exists (CDN-loaded)"
			);
		}
	}

	/**
	 * Test that check_package_installed() returns boolean for Cornerstone3D packages.
	 */
	public function test_check_package_installed_cornerstone_returns_bool() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		foreach ( $this->cornerstone_packages as $package ) {
			$result = $method->invoke( null, $package );
			$this->assertIsBool( $result, "check_package_installed('{$package}') should return boolean" );
		}
	}

	/**
	 * Test wp_mcp_ai_is_npm_package_available() for doc generation packages.
	 */
	public function test_npm_package_available_doc_gen_bundles() {
		if ( ! function_exists( 'wp_mcp_ai_is_npm_package_available' ) ) {
			$this->markTestSkipped( 'wp_mcp_ai_is_npm_package_available() not available' );
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		foreach ( $this->doc_gen_packages as $package => $bundle_path ) {
			$full_bundle_path = WP_MCP_AI_PRO_PATH . $bundle_path;
			if ( file_exists( $full_bundle_path ) ) {
				$result = wp_mcp_ai_is_npm_package_available( $package );
				$this->assertTrue(
					$result,
					"wp_mcp_ai_is_npm_package_available('{$package}') should return true when bundle exists"
				);
			}
		}
	}

	/**
	 * Test wp_mcp_ai_is_npm_package_available() for Cornerstone3D packages.
	 */
	public function test_npm_package_available_cornerstone_via_imaging_viewer() {
		if ( ! function_exists( 'wp_mcp_ai_is_npm_package_available' ) ) {
			$this->markTestSkipped( 'wp_mcp_ai_is_npm_package_available() not available' );
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		$imaging_viewer_path = WP_MCP_AI_PRO_PATH . 'assets/js/imaging-viewer.js';
		if ( ! file_exists( $imaging_viewer_path ) ) {
			$this->markTestSkipped( 'imaging-viewer.js not present' );
		}

		foreach ( $this->cornerstone_packages as $package ) {
			$result = wp_mcp_ai_is_npm_package_available( $package );
			$this->assertTrue(
				$result,
				"wp_mcp_ai_is_npm_package_available('{$package}') should return true when imaging-viewer.js exists"
			);
		}
	}

	/**
	 * Test wp_mcp_ai_get_npm_package_status() returns correct source for doc gen bundles.
	 */
	public function test_npm_package_status_doc_gen_source_is_bundled() {
		if ( ! function_exists( 'wp_mcp_ai_get_npm_package_status' ) ) {
			$this->markTestSkipped( 'wp_mcp_ai_get_npm_package_status() not available' );
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		foreach ( $this->doc_gen_packages as $package => $bundle_path ) {
			$full_bundle_path = WP_MCP_AI_PRO_PATH . $bundle_path;
			if ( file_exists( $full_bundle_path ) ) {
				$status = wp_mcp_ai_get_npm_package_status( $package );
				$this->assertIsArray( $status, "Status for '{$package}' should be array" );
				$this->assertTrue( $status['available'], "Status for '{$package}' should be available" );
				$this->assertEquals( 'bundled', $status['source'], "Source for '{$package}' should be 'bundled'" );
			}
		}
	}

	/**
	 * Test wp_mcp_ai_get_npm_package_status() returns correct source for Cornerstone3D.
	 */
	public function test_npm_package_status_cornerstone_source_is_cdn() {
		if ( ! function_exists( 'wp_mcp_ai_get_npm_package_status' ) ) {
			$this->markTestSkipped( 'wp_mcp_ai_get_npm_package_status() not available' );
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$this->markTestSkipped( 'Pro addon not active' );
		}

		$imaging_viewer_path = WP_MCP_AI_PRO_PATH . 'assets/js/imaging-viewer.js';
		if ( ! file_exists( $imaging_viewer_path ) ) {
			$this->markTestSkipped( 'imaging-viewer.js not present' );
		}

		foreach ( $this->cornerstone_packages as $package ) {
			$status = wp_mcp_ai_get_npm_package_status( $package );
			$this->assertIsArray( $status, "Status for '{$package}' should be array" );
			$this->assertTrue( $status['available'], "Status for '{$package}' should be available" );
			$this->assertEquals( 'cdn', $status['source'], "Source for '{$package}' should be 'cdn'" );
		}
	}
}
