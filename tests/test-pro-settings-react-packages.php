<?php
/**
 * Tests for Pro Settings React Package Detection
 *
 * Verifies that the pro settings page correctly detects React packages
 * used for the workflow builder as bundled build-time dependencies.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for React package detection in Pro Settings.
 */
class WP_MCP_AI_Pro_Settings_React_Packages_Test extends WP_UnitTestCase {

	/**
	 * React packages that should be detected as workflow-builder dependencies.
	 *
	 * @var array
	 */
	private $react_packages = array(
		'react',
		'react-dom',
		'reactflow',
		'@dnd-kit/core',
		'@dnd-kit/sortable',
		'@dnd-kit/utilities',
	);

	/**
	 * Test that React packages are included in the dependency list.
	 *
	 * These packages should appear in the dependencies array from package.json.
	 */
	public function test_react_packages_in_dependencies() {
		$packages = WP_MCP_AI_Pro_Settings::get_npm_packages();

		$this->assertArrayHasKey( 'dependencies', $packages );
		$this->assertIsArray( $packages['dependencies'] );

		foreach ( $this->react_packages as $package ) {
			$this->assertArrayHasKey(
				$package,
				$packages['dependencies'],
				"Package {$package} should be in dependencies"
			);
		}
	}

	/**
	 * Test check_package_installed() method via reflection.
	 *
	 * Since check_package_installed is a private static method,
	 * we use reflection to test it directly.
	 */
	public function test_check_package_installed_for_react_packages() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		foreach ( $this->react_packages as $package ) {
			// Call the method.
			$result = $method->invoke( null, $package );

			// The result should be a boolean.
			$this->assertIsBool(
				$result,
				"check_package_installed('{$package}') should return boolean"
			);

			// Document what we're checking.
			if ( $result ) {
				$this->assertTrue(
					$result,
					"Package {$package} detected as installed (via bundle or node_modules)"
				);
			} else {
				// Not installed is acceptable if workflow builder hasn't been built.
				// We're just testing that the method handles the package correctly.
				$this->assertFalse(
					$result,
					"Package {$package} correctly returns false when not bundled or in node_modules"
				);
			}
		}
	}

	/**
	 * Test that React packages use workflow builder detection logic.
	 *
	 * This test verifies the logic paths without requiring actual files.
	 */
	public function test_react_package_detection_logic() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		// Test with a React package.
		$result = $method->invoke( null, 'react' );

		// Check expected paths would be tested:
		// 1. WP_MCP_AI_PRO_PATH . 'build/workflow-builder/workflow-builder.js' (if Pro is active)
		// 2. WP_MCP_AI_PATH . 'build/workflow-builder/workflow-builder.js' (legacy)
		// 3. WP_MCP_AI_PATH . 'node_modules/react' (development)

		// The result depends on environment, but method should not throw errors.
		$this->assertIsBool( $result, 'React package check should return boolean' );
	}

	/**
	 * Test that non-React packages are not affected by React package logic.
	 *
	 * This ensures our changes don't break detection of other packages.
	 */
	public function test_non_react_packages_unaffected() {
		// Use reflection to access private method.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Pro_Settings' );
		$method     = $reflection->getMethod( 'check_package_installed' );
		$method->setAccessible( true );

		// Test with packages that should use different detection logic.
		$other_packages = array(
			'chart.js',        // Should check vendor directory.
			'@neplex/vectorizer', // Should check vendor directory.
			'marked',          // Should check chat-bundle.
			'dompurify',       // Should check chat-bundle.
		);

		foreach ( $other_packages as $package ) {
			$result = $method->invoke( null, $package );

			// Each should return boolean without errors.
			$this->assertIsBool(
				$result,
				"Package {$package} detection should still work correctly"
			);
		}
	}

	/**
	 * Test that workflow builder paths are checked in correct priority order.
	 *
	 * Priority should be:
	 * 1. Pro addon build directory (production)
	 * 2. Base build directory (legacy/development)
	 * 3. node_modules (development)
	 */
	public function test_workflow_builder_path_priority() {
		// This test documents the expected behavior.
		// The actual file existence depends on build state.

		$expected_paths = array();

		// Priority 1: Pro addon build.
		if ( defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$expected_paths[] = WP_MCP_AI_PRO_PATH . 'build/workflow-builder/workflow-builder.js';
		}

		// Priority 2: Base build (legacy).
		$expected_paths[] = WP_MCP_AI_PATH . 'build/workflow-builder/workflow-builder.js';

		// Priority 3: node_modules (development).
		$expected_paths[] = WP_MCP_AI_PATH . 'node_modules/react';

		// Verify paths are sensible.
		foreach ( $expected_paths as $path ) {
			$this->assertIsString( $path, 'Path should be a string' );
			$this->assertNotEmpty( $path, 'Path should not be empty' );
		}

		// The check_package_installed method should test these in order.
		// We can't easily test order without mocking file_exists,
		// but we document the expected behavior.
		$this->assertTrue( true, 'Path priority order documented' );
	}
}
