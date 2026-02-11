<?php
/**
 * Tests for Pro Settings Optional Dependencies Detection
 *
 * Verifies that the pro settings page correctly detects optional dependencies
 * (LangChain packages) and Composer packages.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for optional dependencies detection in Pro Settings.
 */
class WP_MCP_AI_Pro_Settings_Optional_Dependencies_Test extends WP_UnitTestCase {

	/**
	 * LangChain packages that should be detected as optional dependencies.
	 *
	 * @var array
	 */
	private $langchain_packages = array(
		'langchain',
		'@langchain/core',
		'@langchain/community',
	);

	/**
	 * Test that optional dependencies are included in the package list.
	 *
	 * These packages should appear in the optionalDependencies array from package.json.
	 */
	public function test_optional_dependencies_extracted() {
		$packages = WP_MCP_AI_Pro_Settings::get_npm_packages();

		$this->assertArrayHasKey( 'optionalDependencies', $packages );
		$this->assertIsArray( $packages['optionalDependencies'] );

		foreach ( $this->langchain_packages as $package ) {
			$this->assertArrayHasKey(
				$package,
				$packages['optionalDependencies'],
				"Package {$package} should be in optionalDependencies"
			);
		}
	}

	/**
	 * Test check_package_installed() method for LangChain packages via reflection.
	 *
	 * Since check_package_installed is a private static method,
	 * we use reflection to test it directly.
	 */
	public function test_check_package_installed_for_langchain_packages() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		foreach ( $this->langchain_packages as $package ) {
			// Call the method.
			$result = $method->invoke( null, $package );

			// The result should be a boolean.
			$this->assertIsBool(
				$result,
				"check_package_installed('{$package}') should return boolean"
			);
		}
	}

	/**
	 * Test that LangChain packages use correct detection logic.
	 *
	 * This test verifies the logic paths without requiring actual files.
	 */
	public function test_langchain_package_detection_logic() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		// Test with a LangChain package.
		$result = $method->invoke( null, 'langchain' );

		// Check expected paths would be tested:
		// 1. WP_MCP_AI_PATH . 'assets/js/langchain-orchestration.min.js' (production)
		// 2. WP_MCP_AI_PATH . 'assets/js/langchain-tool-adapter.min.js' (production)
		// 3. WP_MCP_AI_PATH . 'assets/js/langchain-orchestration.js' (development)
		// 4. WP_MCP_AI_PATH . 'assets/js/langchain-tool-adapter.js' (development)
		// 5. WP_MCP_AI_PATH . 'node_modules/langchain' (development)

		// The result depends on environment, but method should not throw errors.
		$this->assertIsBool( $result, 'LangChain package check should return boolean' );
	}

	/**
	 * Test that Composer packages are extracted correctly.
	 */
	public function test_composer_packages_extracted() {
		$composer = WP_MCP_AI_Pro_Settings::get_composer_packages();

		$this->assertArrayHasKey( 'require', $composer );
		$this->assertArrayHasKey( 'require-dev', $composer );
		$this->assertIsArray( $composer['require'] );
		$this->assertIsArray( $composer['require-dev'] );

		// Check for known production dependencies.
		$this->assertArrayHasKey( 'rahul900day/tiktoken-php', $composer['require'] );
		$this->assertArrayHasKey( 'symfony/http-client', $composer['require'] );

		// Check for known dev dependencies.
		$this->assertArrayHasKey( 'phpunit/phpunit', $composer['require-dev'] );
		$this->assertArrayHasKey( 'squizlabs/php_codesniffer', $composer['require-dev'] );
	}

	/**
	 * Test check_composer_package_installed() method via reflection.
	 *
	 * Since check_composer_package_installed is a private static method,
	 * we use reflection to test it directly.
	 */
	public function test_check_composer_package_installed() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_composer_package_installed' );
		$method->setAccessible( true );

		// Test with a known package.
		$result = $method->invoke( null, 'rahul900day/tiktoken-php' );

		// The result should be a boolean.
		$this->assertIsBool(
			$result,
			"check_composer_package_installed('rahul900day/tiktoken-php') should return boolean"
		);

		// In development environment, vendor should exist.
		if ( file_exists( WP_MCP_AI_PATH . 'vendor/autoload.php' ) ) {
			$this->assertTrue(
				$result,
				'Package should be detected when vendor/autoload.php exists'
			);
		}
	}

	/**
	 * Test that composer.json metadata is extracted.
	 */
	public function test_composer_metadata_extracted() {
		$composer = WP_MCP_AI_Pro_Settings::get_composer_packages();

		$this->assertArrayHasKey( 'name', $composer );
		$this->assertArrayHasKey( 'description', $composer );
		$this->assertArrayHasKey( 'type', $composer );

		// Verify expected values.
		$this->assertEquals( 'mcp-ai-wpoos/mcp-ai-wpoos', $composer['name'] );
		$this->assertEquals( 'wordpress-plugin', $composer['type'] );
	}

	/**
	 * Test that total package count includes optionalDependencies.
	 */
	public function test_total_package_count_includes_optional() {
		$packages       = WP_MCP_AI_Pro_Settings::get_npm_packages();
		$total_packages = count( $packages['dependencies'] ) +
			count( $packages['devDependencies'] ) +
			count( $packages['optionalDependencies'] );

		// Should have at least the 3 LangChain packages in optional.
		$this->assertGreaterThanOrEqual( 3, count( $packages['optionalDependencies'] ) );

		// Total should be greater than just dependencies + devDependencies.
		$old_total = count( $packages['dependencies'] ) + count( $packages['devDependencies'] );
		$this->assertGreaterThan( $old_total, $total_packages );
	}
}
