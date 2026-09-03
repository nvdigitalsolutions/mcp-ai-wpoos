<?php
/**
 * Tests for the Docs Hub link fixer.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.4.2
 */

/**
 * Docs Hub link fixer tests.
 */
class Test_Docs_Hub_Link_Fixer extends WP_UnitTestCase {

	/**
	 * Temporary directory for test markdown files.
	 *
	 * @var string
	 */
	private $test_dir;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '1.0.0' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_PATH' ) ) {
			define( 'NVOOS_DOCS_HUB_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_URL' ) ) {
			define( 'NVOOS_DOCS_HUB_URL', 'http://example.com/wp-content/plugins/nvoos-docs-hub/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_FILE' ) ) {
			define( 'NVOOS_DOCS_HUB_FILE', NVOOS_DOCS_HUB_PATH . 'nvoos-docs-hub.php' );
		}

		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-plugin.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-link-fixer.php';

		$this->test_dir = sys_get_temp_dir() . '/nvoos-dh-fixer-test-' . uniqid();
		wp_mkdir_p( $this->test_dir );
		wp_mkdir_p( $this->test_dir . '/docs/admin-guides' );
		wp_mkdir_p( $this->test_dir . '/docs/reference' );

		// The test files live in a temp directory outside WP_PLUGIN_DIR, so
		// allow the fixer to resolve targets inside it.
		add_filter(
			'nvoos_docs_hub_fixer_allowed_roots',
			function ( $roots ) {
				$roots[] = $this->test_dir;
				return $roots;
			}
		);
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		$this->remove_directory( $this->test_dir );
	}

	/**
	 * Test that a legitimate parent-relative correction is applied.
	 *
	 * Regression: the old guard rejected ANY target containing `..`, so the
	 * most common genuinely-broken links (`../../x.md`) could never be fixed.
	 *
	 * @return void
	 */
	public function test_fix_link_allows_parent_relative_target_inside_root() {
		$source_file = $this->test_dir . '/docs/admin-guides/tools-manager.md';
		file_put_contents( $source_file, '[See tools](../tools/tool-reference.md).' );
		file_put_contents( $this->test_dir . '/docs/reference/tool-reference.md', '# Tool Reference' );

		$fixer = new NV_oOS_Docs_Hub_Link_Fixer();
		$result = $fixer->fix_link(
			$source_file,
			'../tools/tool-reference.md',
			'../reference/tool-reference.md',
			'docs/admin-guides/tools-manager.md'
		);

		$this->assertTrue( $result );
		$content = file_get_contents( $source_file );
		$this->assertStringContainsString( '[See tools](../reference/tool-reference.md).', $content );
	}

	/**
	 * Test that a correction escaping the allowed roots is rejected.
	 *
	 * @return void
	 */
	public function test_fix_link_rejects_target_outside_allowed_roots() {
		$source_file = $this->test_dir . '/docs/admin-guides/tools-manager.md';
		file_put_contents( $source_file, '[See tools](../tools/tool-reference.md).' );

		// Walk far enough above the plugin root to escape every allowed root.
		$fixer  = new NV_oOS_Docs_Hub_Link_Fixer();
		$result = $fixer->fix_link(
			$source_file,
			'../tools/tool-reference.md',
			'../../../../../../../wp-config.php',
			'docs/admin-guides/tools-manager.md'
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'nvoos_dh_fix_path_traversal', $result->get_error_code() );
	}

	/**
	 * Test that URL schemes and absolute paths are rejected outright.
	 *
	 * @return void
	 */
	public function test_fix_link_rejects_absolute_targets() {
		$source_file = $this->test_dir . '/docs/admin-guides/tools-manager.md';
		file_put_contents( $source_file, '[See tools](../tools/tool-reference.md).' );

		$fixer = new NV_oOS_Docs_Hub_Link_Fixer();

		$result = $fixer->fix_link(
			$source_file,
			'../tools/tool-reference.md',
			'https://example.com/evil.md',
			'docs/admin-guides/tools-manager.md'
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'nvoos_dh_fix_path_traversal', $result->get_error_code() );

		$result = $fixer->fix_link(
			$source_file,
			'../tools/tool-reference.md',
			'/etc/passwd',
			'docs/admin-guides/tools-manager.md'
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'nvoos_dh_fix_path_traversal', $result->get_error_code() );
	}

	/**
	 * Test that remote-sourced entries are skipped with a clear reason.
	 *
	 * @return void
	 */
	public function test_apply_fixes_skips_remote_sources() {
		$source_file = $this->test_dir . '/docs/admin-guides/tools-manager.md';
		file_put_contents( $source_file, '[See tools](../tools/tool-reference.md).' );

		$fixer  = new NV_oOS_Docs_Hub_Link_Fixer();
		$result = $fixer->apply_fixes(
			array(
				array(
					'source'     => 'docs/admin-guides/tools-manager.md',
					'old_target' => '../tools/tool-reference.md',
					'new_target' => '../reference/tool-reference.md',
				),
			),
			array(
				'tools-manager' => array(
					'path'          => $source_file,
					'source'        => 'remote',
					'relative_path' => 'docs/admin-guides/tools-manager.md',
				),
			),
			'apply'
		);

		$this->assertEquals( 0, $result['fixed'] );
		$this->assertEquals( 1, $result['skipped'] );
		$this->assertStringContainsString( 'remote', $result['results'][0]['reason'] );
	}

	/**
	 * Test that a local fix is applied end-to-end through apply_fixes().
	 *
	 * @return void
	 */
	public function test_apply_fixes_applies_local_fix() {
		$source_file = $this->test_dir . '/docs/admin-guides/tools-manager.md';
		file_put_contents( $source_file, '[See tools](../tools/tool-reference.md).' );
		file_put_contents( $this->test_dir . '/docs/reference/tool-reference.md', '# Tool Reference' );

		$fixer  = new NV_oOS_Docs_Hub_Link_Fixer();
		$result = $fixer->apply_fixes(
			array(
				array(
					'source'     => 'docs/admin-guides/tools-manager.md',
					'old_target' => '../tools/tool-reference.md',
					'new_target' => '../reference/tool-reference.md',
				),
			),
			array(
				'tools-manager' => array(
					'path'          => $source_file,
					'source'        => 'base',
					'relative_path' => 'docs/admin-guides/tools-manager.md',
				),
			),
			'apply'
		);

		$this->assertEquals( 1, $result['fixed'] );
		$this->assertEquals( 0, $result['skipped'] );
		$this->assertStringContainsString(
			'[See tools](../reference/tool-reference.md).',
			file_get_contents( $source_file )
		);
	}

	/**
	 * Test that slug-based resolution picks the correct file when multiple
	 * sources share the same relative path (e.g. every addon has a
	 * `README.md`).
	 *
	 * @return void
	 */
	public function test_apply_fixes_slug_resolution_wins_over_relative_path() {
		$repo_readme    = $this->test_dir . '/README.md';
		$addon_readme   = $this->test_dir . '/docs/addon/README.md';
		wp_mkdir_p( $this->test_dir . '/docs/addon' );
		file_put_contents( $repo_readme, '[See](../docs/THIRD_PARTY_ASSETS.md).' );
		file_put_contents( $addon_readme, '[See](../../docs/THIRD_PARTY_ASSETS.md).' );
		file_put_contents( $this->test_dir . '/docs/THIRD_PARTY_ASSETS.md', '# Third Party' );

		$fixer = new NV_oOS_Docs_Hub_Link_Fixer();
		// The first relative-path match in the slug map is the repo README —
		// but the fix targets the ADDON README via its slug.
		$result = $fixer->apply_fixes(
			array(
				array(
					'source'     => 'README.md',
					'slug'       => 'addon-readme',
					'old_target' => '../../docs/THIRD_PARTY_ASSETS.md',
					'new_target' => '../THIRD_PARTY_ASSETS.md',
				),
			),
			array(
				'readme'       => array(
					'path'          => $repo_readme,
					'source'        => 'root',
					'relative_path' => 'README.md',
				),
				'addon-readme' => array(
					'path'          => $addon_readme,
					'source'        => 'addons',
					'relative_path' => 'README.md',
				),
			),
			'apply'
		);

		$this->assertEquals( 1, $result['fixed'] );
		$this->assertStringContainsString(
			'[See](../THIRD_PARTY_ASSETS.md).',
			file_get_contents( $addon_readme ),
			'The addon README should be edited, not the repo README.'
		);
		$this->assertStringContainsString(
			'[See](../docs/THIRD_PARTY_ASSETS.md).',
			file_get_contents( $repo_readme ),
			'The repo README must be left untouched.'
		);
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	private function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
