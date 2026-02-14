<?php
/**
 * Test PHP Version Detection in Pro Settings
 *
 * Verifies that the pro settings page correctly displays PHP version from
 * platform requirements and shows the actual installed PHP version.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for PHP version detection.
 */
class WP_MCP_AI_PHP_Version_Detection_Test extends WP_UnitTestCase {

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
	 * Test that get_composer_packages includes PHP platform requirement.
	 */
	public function test_get_composer_packages_includes_php_platform_requirement() {
		$packages = WP_MCP_AI_Pro_Settings::get_composer_packages();

		// Should not have error.
		$this->assertEmpty( $packages['error'], 'Composer packages should load without error' );

		// Should have require array.
		$this->assertIsArray( $packages['require'], 'Should have require array' );

		// Should include PHP platform requirement from config.platform.
		$this->assertArrayHasKey( 'php', $packages['require'], 'Should include PHP platform requirement' );

		// PHP version should match composer.json config.platform.php value.
		$this->assertEquals( '8.1.0', $packages['require']['php'], 'PHP version should be 8.1.0 from platform config' );
	}

	/**
	 * Test that check_composer_package_installed handles PHP specially.
	 */
	public function test_check_composer_package_installed_handles_php() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_composer_package_installed' );
		$method->setAccessible( true );

		// PHP should always return true (it's a platform requirement, not a package).
		$result = $method->invoke( null, 'php' );
		$this->assertTrue( $result, 'PHP should always be detected as "installed" since it\'s running' );
	}

	/**
	 * Test that PHP version from phpversion() is valid.
	 */
	public function test_phpversion_returns_valid_version() {
		$php_version = phpversion();
		
		// Should not be empty.
		$this->assertNotEmpty( $php_version, 'phpversion() should return a non-empty string' );

		// Should be a valid version format (e.g., 8.3.6 or 8.1.0).
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+/',
			$php_version,
			'PHP version should match semantic version format'
		);

		// Should meet minimum requirement of 8.1.0.
		$this->assertTrue(
			version_compare( $php_version, '8.1.0', '>=' ),
			'PHP version should be >= 8.1.0'
		);
	}

	/**
	 * Test version comparison logic for PHP version check.
	 */
	public function test_php_version_comparison_logic() {
		// Simulate the version comparison logic from render_composer_table.
		$required_version = '8.1.0';
		$current_version  = phpversion();
		
		// Remove common version constraint characters.
		$required_clean = trim( $required_version, '^><=~' );
		
		// Compare versions.
		$meets_requirement = version_compare( $current_version, $required_clean, '>=' );
		
		$this->assertTrue(
			$meets_requirement,
			sprintf(
				'Current PHP version %s should meet requirement %s',
				$current_version,
				$required_clean
			)
		);
	}
}
