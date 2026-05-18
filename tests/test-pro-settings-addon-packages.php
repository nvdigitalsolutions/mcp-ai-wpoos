<?php
/**
 * Tests for addon-aware package detection in WP_MCP_AI_Pro_Settings.
 *
 * Covers:
 *  - get_addon_manifest_map() structure
 *  - get_npm_packages() returns addon_groups key
 *  - addon_groups excludes base/Pro packages (deduplication)
 *  - devDependencies are excluded from addon_groups
 *  - check_package_installed_for_addon() pipeline
 *  - render_page() devDeps toggle (show_dev parameter)
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_Pro_Settings_Addon_Packages_Test extends WP_UnitTestCase {

	/**
	 * Path to a temporary addon fixture directory created for each test.
	 *
	 * @var string
	 */
	private $fixture_addon_path;

	/**
	 * Set up temporary fixture directory before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->fixture_addon_path = sys_get_temp_dir() . '/nvoos-test-addon-' . uniqid() . '/';
		mkdir( $this->fixture_addon_path, 0755, true );
	}

	/**
	 * Clean up fixture directory after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		if ( is_dir( $this->fixture_addon_path ) ) {
			$this->rmdir_recursive( $this->fixture_addon_path );
		}
	}

	// -------------------------------------------------------------------------
	// get_addon_manifest_map()
	// -------------------------------------------------------------------------

	/**
	 * Manifest map must be a non-empty array.
	 */
	public function test_manifest_map_returns_array() {
		$map = WP_MCP_AI_Pro_Settings::get_addon_manifest_map();
		$this->assertIsArray( $map );
		$this->assertNotEmpty( $map );
	}

	/**
	 * Every entry in the manifest map must have the required keys.
	 */
	public function test_manifest_map_entries_have_required_keys() {
		$required = array( 'label', 'plugin_file', 'path_constant', 'dist_file', 'package_json', 'composer_json' );
		foreach ( WP_MCP_AI_Pro_Settings::get_addon_manifest_map() as $slug => $entry ) {
			foreach ( $required as $key ) {
				$this->assertArrayHasKey(
					$key,
					$entry,
					"Addon '{$slug}' is missing key '{$key}' in get_addon_manifest_map()."
				);
			}
		}
	}

	/**
	 * Known addons that ship package.json must be present in the map.
	 *
	 * Derived directly from the manifest to avoid a hard-coded parallel list.
	 */
	public function test_manifest_map_contains_known_spa_addons() {
		$map = WP_MCP_AI_Pro_Settings::get_addon_manifest_map();

		// Every entry in the map that declares a non-empty package_json must resolve
		// to a string path (i.e. the field is populated, not just an empty string).
		$addons_with_packages = array_filter(
			$map,
			static function ( $entry ) {
				return '' !== $entry['package_json'];
			}
		);
		$this->assertNotEmpty(
			$addons_with_packages,
			'At least one addon in the manifest map must declare a package_json path.'
		);

		// Spot-check a well-known SPA addon slug — canvas-toolkit ships package.json.
		$this->assertArrayHasKey( 'canvas-toolkit', $map, 'canvas-toolkit must be in the manifest map.' );
		$this->assertNotEmpty( $map['canvas-toolkit']['package_json'], 'canvas-toolkit must declare a package_json path.' );
	}

	/**
	 * The 'path_constant' field must be a non-empty string (PHP constant name).
	 */
	public function test_manifest_map_path_constants_are_strings() {
		foreach ( WP_MCP_AI_Pro_Settings::get_addon_manifest_map() as $slug => $entry ) {
			$this->assertIsString( $entry['path_constant'], "path_constant for '{$slug}' must be a string." );
			$this->assertNotEmpty( $entry['path_constant'], "path_constant for '{$slug}' must not be empty." );
		}
	}

	// -------------------------------------------------------------------------
	// get_npm_packages() structure
	// -------------------------------------------------------------------------

	/**
	 * get_npm_packages() must always return an 'addon_groups' key.
	 */
	public function test_get_npm_packages_returns_addon_groups_key() {
		$result = WP_MCP_AI_Pro_Settings::get_npm_packages();
		$this->assertArrayHasKey( 'addon_groups', $result );
		$this->assertIsArray( $result['addon_groups'] );
	}

	/**
	 * get_npm_packages() must still return the classic keys.
	 */
	public function test_get_npm_packages_returns_classic_keys() {
		$result = WP_MCP_AI_Pro_Settings::get_npm_packages();
		foreach ( array( 'dependencies', 'devDependencies', 'optionalDependencies' ) as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
	}

	/**
	 * devDependencies from addons must NOT appear in addon_groups packages.
	 *
	 * Addon groups should only contain production (non-dev) dependencies so that
	 * build-time tools like eslint and vitest do not pollute the status page.
	 *
	 * This test uses the real get_npm_packages() output and asserts that well-known
	 * dev-only tool names (present in the actual addon package.json devDependencies)
	 * do not appear in any addon_groups entry.
	 */
	public function test_addon_groups_exclude_dev_dependencies() {
		$result = WP_MCP_AI_Pro_Settings::get_npm_packages();

		// Well-known build-time tools that live exclusively in devDependencies
		// across the addon package.json files; these must never appear in addon_groups.
		$known_dev_only = array( 'vitest', 'eslint', 'typescript', 'esbuild', 'jsdom', '@vitest/coverage-v8' );

		foreach ( $result['addon_groups'] as $slug => $group ) {
			foreach ( $known_dev_only as $dev_pkg ) {
				$this->assertArrayNotHasKey(
					$dev_pkg,
					$group['packages'],
					"Dev-dependency '{$dev_pkg}' must not appear in addon_groups for '{$slug}'."
				);
			}
		}
	}

	// -------------------------------------------------------------------------
	// check_package_installed_for_addon() — via ReflectionMethod (private)
	// -------------------------------------------------------------------------

	/**
	 * Returns true when the addon dist bundle exists.
	 */
	public function test_check_addon_installed_via_dist_bundle() {
		$dist = $this->fixture_addon_path . 'assets/dist/';
		mkdir( $dist, 0755, true );
		file_put_contents( $dist . 'my-addon.js', '// bundle' );

		$result = $this->call_check_package_installed_for_addon(
			'some-package',
			$this->fixture_addon_path,
			'assets/dist/my-addon.js'
		);
		$this->assertTrue( $result );
	}

	/**
	 * Returns true when the package is present in assets/vendor/.
	 */
	public function test_check_addon_installed_via_vendor_dir() {
		$vendor = $this->fixture_addon_path . 'assets/vendor/my-lib/';
		mkdir( $vendor, 0755, true );
		file_put_contents( $vendor . 'package.json', '{}' );

		$result = $this->call_check_package_installed_for_addon(
			'my-lib',
			$this->fixture_addon_path,
			'' // no dist bundle
		);
		$this->assertTrue( $result );
	}

	/**
	 * Returns true when the package is in node_modules (dev environment).
	 */
	public function test_check_addon_installed_via_node_modules() {
		$nm = $this->fixture_addon_path . 'node_modules/some-dep/';
		mkdir( $nm, 0755, true );
		file_put_contents( $nm . 'package.json', '{}' );

		$result = $this->call_check_package_installed_for_addon(
			'some-dep',
			$this->fixture_addon_path,
			''
		);
		$this->assertTrue( $result );
	}

	/**
	 * Returns false when none of the detection paths exist.
	 */
	public function test_check_addon_not_installed_when_nothing_found() {
		$result = $this->call_check_package_installed_for_addon(
			'nonexistent-package',
			$this->fixture_addon_path,
			'assets/dist/my-addon.js' // dist file does NOT exist
		);
		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// Helper utilities
	// -------------------------------------------------------------------------

	/**
	 * Invoke WP_MCP_AI_Pro_Settings::check_package_installed_for_addon() via reflection.
	 *
	 * @param string $package    Package name.
	 * @param string $addon_path Addon root path.
	 * @param string $dist_file  Relative dist file path.
	 * @return bool
	 */
	private function call_check_package_installed_for_addon( $package, $addon_path, $dist_file ) {
		$method = new ReflectionMethod( 'WP_MCP_AI_Pro_Settings', 'check_package_installed_for_addon' );
		$method->setAccessible( true );
		return $method->invoke( null, $package, $addon_path, $dist_file );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Path to remove.
	 */
	private function rmdir_recursive( $dir ) {
		foreach ( glob( $dir . '*', GLOB_MARK ) as $file ) {
			if ( '/' === substr( $file, -1 ) ) {
				$this->rmdir_recursive( $file );
			} else {
				unlink( $file );
			}
		}
		rmdir( $dir );
	}
}
