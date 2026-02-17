<?php
/**
 * Tests for Pro Settings pdf-parse and qrcode Package Detection
 *
 * Verifies that the pro settings page correctly detects pdf-parse and qrcode packages,
 * and that Composer packages like smalot/pdfparser are properly displayed.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for pdf-parse and qrcode package detection in Pro Settings.
 */
class WP_MCP_AI_Pro_Settings_Pdf_Parse_Qrcode_Test extends WP_UnitTestCase {

	/**
	 * Pro packages that should be detected.
	 *
	 * @var array
	 */
	private $pro_packages = array(
		'pdf-parse',
		'qrcode',
	);

	/**
	 * Composer packages that should be detected.
	 *
	 * @var array
	 */
	private $composer_packages = array(
		'smalot/pdfparser',
	);

	/**
	 * Test that pdf-parse and qrcode are included in Document Generation Toolkit's npm_packages.
	 */
	public function test_packages_in_document_generation_toolkit() {
		$toolkits = WP_MCP_AI_Pro_Settings::get_individual_toolkit_status();

		$this->assertArrayHasKey( 'document_generation', $toolkits );
		$this->assertArrayHasKey( 'npm_packages', $toolkits['document_generation'] );

		foreach ( $this->pro_packages as $package ) {
			$this->assertContains(
				$package,
				$toolkits['document_generation']['npm_packages'],
				"Package {$package} should be in Document Generation Toolkit npm_packages"
			);
		}
	}

	/**
	 * Test that smalot/pdfparser is included in Document Generation Toolkit's composer_packages.
	 */
	public function test_composer_packages_in_document_generation_toolkit() {
		$toolkits = WP_MCP_AI_Pro_Settings::get_individual_toolkit_status();

		$this->assertArrayHasKey( 'document_generation', $toolkits );
		$this->assertArrayHasKey( 'composer_packages', $toolkits['document_generation'] );

		foreach ( $this->composer_packages as $package ) {
			$this->assertContains(
				$package,
				$toolkits['document_generation']['composer_packages'],
				"Package {$package} should be in Document Generation Toolkit composer_packages"
			);
		}
	}

	/**
	 * Test check_package_installed() method for pdf-parse and qrcode via reflection.
	 *
	 * Since check_package_installed is a private static method,
	 * we use reflection to test it directly.
	 */
	public function test_check_package_installed_for_pro_packages() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		foreach ( $this->pro_packages as $package ) {
			// Call the method.
			$result = $method->invoke( null, $package );

			// The result should be a boolean.
			$this->assertIsBool(
				$result,
				"check_package_installed('{$package}') should return boolean"
			);

			// If Pro addon is defined, check if files exist.
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				if ( 'pdf-parse' === $package ) {
					$vendor_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/pdf-parse/lib/pdf-parse.js';
					$pkg_path    = WP_MCP_AI_PRO_PATH . 'assets/vendor/pdf-parse/package.json';
					if ( file_exists( $vendor_path ) || file_exists( $pkg_path ) ) {
						$this->assertTrue(
							$result,
							'pdf-parse should be detected when vendor file exists'
						);
					}
				} elseif ( 'qrcode' === $package ) {
					$pkg_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/qrcode/package.json';
					if ( file_exists( $pkg_path ) ) {
						$this->assertTrue(
							$result,
							'qrcode should be detected when vendor package.json exists'
						);
					}
				}
			}
		}
	}

	/**
	 * Test get_composer_package_status() method via reflection.
	 *
	 * Since get_composer_package_status is a private static method,
	 * we use reflection to test it directly.
	 */
	public function test_get_composer_package_status() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'get_composer_package_status' );
		$method->setAccessible( true );

		foreach ( $this->composer_packages as $package ) {
			// Call the method.
			$status = $method->invoke( null, $package );

			// The result should be an array with specific keys.
			$this->assertIsArray( $status, "get_composer_package_status('{$package}') should return array" );
			$this->assertArrayHasKey( 'available', $status );
			$this->assertArrayHasKey( 'source', $status );
			$this->assertArrayHasKey( 'message', $status );

			// Check if package is available when vendor exists.
			if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
				$pro_vendor_path = WP_MCP_AI_PRO_PATH . 'vendor/' . $package;
				if ( file_exists( $pro_vendor_path ) ) {
					$this->assertTrue(
						$status['available'],
						"Package {$package} should be available when vendor directory exists"
					);
					$this->assertEquals( 'composer', $status['source'] );
				}
			}
		}
	}

	/**
	 * Test that Document Generation Toolkit has composer_status array.
	 */
	public function test_document_generation_toolkit_has_composer_status() {
		$toolkits = WP_MCP_AI_Pro_Settings::get_individual_toolkit_status();

		$this->assertArrayHasKey( 'document_generation', $toolkits );
		$this->assertArrayHasKey( 'composer_status', $toolkits['document_generation'] );
		$this->assertIsArray( $toolkits['document_generation']['composer_status'] );
	}

	/**
	 * Test that Document Generation Toolkit has composer_available flag.
	 */
	public function test_document_generation_toolkit_has_composer_available_flag() {
		$toolkits = WP_MCP_AI_Pro_Settings::get_individual_toolkit_status();

		$this->assertArrayHasKey( 'document_generation', $toolkits );
		$this->assertArrayHasKey( 'composer_available', $toolkits['document_generation'] );
		$this->assertIsBool( $toolkits['document_generation']['composer_available'] );
	}

	/**
	 * Test that overall toolkit status includes composer_available in fully_operational check.
	 */
	public function test_toolkit_fully_operational_includes_composer_check() {
		$toolkits = WP_MCP_AI_Pro_Settings::get_individual_toolkit_status();

		$this->assertArrayHasKey( 'document_generation', $toolkits );
		$toolkit = $toolkits['document_generation'];

		// fully_operational should consider composer_available.
		if ( $toolkit['enabled'] && $toolkit['php_available'] && $toolkit['npm_available'] && $toolkit['composer_available'] ) {
			$this->assertTrue(
				$toolkit['fully_operational'],
				'Toolkit should be fully_operational when all checks pass including composer'
			);
		}

		if ( ! $toolkit['composer_available'] ) {
			$this->assertTrue(
				$toolkit['has_issues'],
				'Toolkit should have_issues when composer packages are missing'
			);
		}
	}

	/**
	 * Test pdf-parse detection paths.
	 */
	public function test_pdf_parse_detection_paths() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'pdf-parse' );

		// Method should handle the package without errors.
		$this->assertIsBool( $result, 'pdf-parse detection should return boolean' );

		// If Pro addon exists, verify the detection logic paths exist in code.
		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			// Priority 1: lib/pdf-parse.js
			// Priority 2: package.json
			// Priority 3: node_modules/pdf-parse
			// Priority 4: WP_MCP_AI_PRO_VERSION constant

			// If Pro version constant is defined, package should be detected.
			if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				$this->assertTrue(
					$result,
					'pdf-parse should be detected when WP_MCP_AI_PRO_VERSION is defined'
				);
			}
		}
	}

	/**
	 * Test qrcode detection with package.json fallback.
	 */
	public function test_qrcode_detection_with_package_json_fallback() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		$result = $method->invoke( null, 'qrcode' );

		// Method should handle the package without errors.
		$this->assertIsBool( $result, 'qrcode detection should return boolean' );

		// If Pro addon exists, verify package.json fallback works.
		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$pkg_path = WP_MCP_AI_PRO_PATH . 'assets/vendor/qrcode/package.json';
			if ( file_exists( $pkg_path ) ) {
				$this->assertTrue(
					$result,
					'qrcode should be detected via package.json fallback'
				);
			}

			// If Pro version constant is defined, package should be detected.
			if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				$this->assertTrue(
					$result,
					'qrcode should be detected when WP_MCP_AI_PRO_VERSION is defined'
				);
			}
		}
	}
}
