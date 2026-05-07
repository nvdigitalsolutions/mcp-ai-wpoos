<?php
/**
 * Tests for selection_mode / selected_paths / excluded_paths filtering in
 * NV_oOS_Docs_Hub_Remote_Repo::filter_md_files().
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.3.0
 */

/**
 * Selection-mode filter test case.
 */
class Test_Docs_Hub_Remote_Selection extends WP_UnitTestCase {

	/**
	 * Bootstrap the addon classes before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '0.3.0' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_PATH' ) ) {
			define( 'NVOOS_DOCS_HUB_PATH', dirname( __DIR__ ) . '/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_FILE' ) ) {
			define( 'NVOOS_DOCS_HUB_FILE', NVOOS_DOCS_HUB_PATH . 'nvoos-docs-hub.php' );
		}

		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-plugin.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-scanner.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-remote-repo.php';
	}

	/**
	 * Build a synthetic tree.
	 *
	 * @return array
	 */
	private function tree() {
		return array(
			array(
				'type' => 'blob',
				'path' => 'README.md',
				'size' => 1,
			),
			array(
				'type' => 'blob',
				'path' => 'docs/intro.md',
				'size' => 1,
			),
			array(
				'type' => 'blob',
				'path' => 'docs/guide.md',
				'size' => 1,
			),
			array(
				'type' => 'blob',
				'path' => 'docs/sub/deep.md',
				'size' => 1,
			),
			array(
				'type' => 'blob',
				'path' => 'guides/quick.md',
				'size' => 1,
			),
			array(
				'type' => 'blob',
				'path' => 'CHANGELOG.md',
				'size' => 1,
			),
			array(
				'type' => 'tree',
				'path' => 'docs',
				'size' => 0,
			),
		);
	}

	/**
	 * Invoke the protected filter_md_files() method via reflection.
	 *
	 * @param array  $tree         Tree items.
	 * @param array  $repo_config  Repo config.
	 * @param string $path_in_repo Subtree prefix.
	 * @return array
	 */
	private function filter( $tree, $repo_config, $path_in_repo = '' ) {
		$ref = new ReflectionMethod( NV_oOS_Docs_Hub_Remote_Repo::class, 'filter_md_files' );
		$ref->setAccessible( true );
		return $ref->invoke( new NV_oOS_Docs_Hub_Remote_Repo(), $tree, '', $repo_config, $path_in_repo );
	}

	/**
	 * 'all' mode returns every Markdown/.txt blob.
	 */
	public function test_selection_mode_all_returns_everything_minus_defaults() {
		$out   = $this->filter(
			$this->tree(),
			array( 'selection_mode' => 'all' )
		);
		$paths = wp_list_pluck( $out, 'path' );
		$this->assertContains( 'README.md', $paths );
		$this->assertContains( 'docs/intro.md', $paths );
		$this->assertContains( 'docs/sub/deep.md', $paths );
	}

	/**
	 * 'selected' mode keeps only explicit file paths.
	 */
	public function test_selection_mode_selected_filters_to_explicit_paths() {
		$out   = $this->filter(
			$this->tree(),
			array(
				'selection_mode' => 'selected',
				'selected_paths' => array( 'README.md', 'docs/intro.md' ),
			)
		);
		$paths = wp_list_pluck( $out, 'path' );
		sort( $paths );
		$this->assertEquals( array( 'README.md', 'docs/intro.md' ), $paths );
	}

	/**
	 * Trailing-slash entries match every descendant.
	 */
	public function test_selection_mode_selected_with_directory_recurses() {
		$out   = $this->filter(
			$this->tree(),
			array(
				'selection_mode' => 'selected',
				'selected_paths' => array( 'docs/' ),
			)
		);
		$paths = wp_list_pluck( $out, 'path' );
		sort( $paths );
		$this->assertEquals(
			array( 'docs/guide.md', 'docs/intro.md', 'docs/sub/deep.md' ),
			$paths
		);
	}

	/**
	 * 'selected' mode with no entries indexes nothing.
	 */
	public function test_selection_mode_selected_empty_returns_nothing() {
		$out = $this->filter(
			$this->tree(),
			array(
				'selection_mode' => 'selected',
				'selected_paths' => array(),
			)
		);
		$this->assertSame( array(), $out );
	}

	/**
	 * 'excluded_paths' is honoured even when selection_mode is 'all'.
	 */
	public function test_excluded_paths_drops_files_in_all_mode() {
		$out   = $this->filter(
			$this->tree(),
			array(
				'selection_mode' => 'all',
				'excluded_paths' => array( 'CHANGELOG.md', 'docs/sub/' ),
			)
		);
		$paths = wp_list_pluck( $out, 'path' );
		$this->assertNotContains( 'CHANGELOG.md', $paths );
		$this->assertNotContains( 'docs/sub/deep.md', $paths );
		$this->assertContains( 'docs/intro.md', $paths );
	}

	/**
	 * Subtree paths are reconstructed with the path-in-repo prefix.
	 */
	public function test_path_in_repo_is_prepended_for_selected_matching() {
		// Tree fetched from a subtree (path=docs); item paths are RELATIVE
		// to that subtree, but selected_paths are repo-relative.
		$subtree = array(
			array(
				'type' => 'blob',
				'path' => 'intro.md',
				'size' => 1,
			),
			array(
				'type' => 'blob',
				'path' => 'sub/deep.md',
				'size' => 1,
			),
		);
		$out     = $this->filter(
			$subtree,
			array(
				'selection_mode' => 'selected',
				'selected_paths' => array( 'docs/intro.md' ),
			),
			'docs'
		);
		$paths   = wp_list_pluck( $out, 'path' );
		$this->assertEquals( array( 'intro.md' ), $paths );
	}

	/**
	 * Sanitize_path_list() rejects traversal and absolute paths.
	 */
	public function test_sanitize_path_list_rejects_traversal_and_leading_slash() {
		require_once NVOOS_DOCS_HUB_PATH . 'includes/admin/class-nvoos-docs-hub-settings.php';
		$out = NV_oOS_Docs_Hub_Settings::sanitize_path_list(
			array(
				'docs/intro.md',
				'../etc/passwd',          // Rejected: leading '..' segment.
				'a/../b.md',              // Rejected: '..' as middle segment.
				'/absolute/path.md',      // Rejected: leading slash.
				'guides/',
				'',
				'  whitespace.md  ',
				'<script>',               // Rejected: invalid chars.
				'..hidden.md',            // ALLOWED: '..' is part of filename, not a segment.
			)
		);
		sort( $out );
		$this->assertEquals(
			array( '..hidden.md', 'docs/intro.md', 'guides/', 'whitespace.md' ),
			$out
		);
	}
}
