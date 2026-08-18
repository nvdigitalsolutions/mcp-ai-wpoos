<?php
/**
 * Tests for the Docs Hub indexer.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

/**
 * Docs Hub indexer tests.
 */
class Test_Docs_Hub_Indexer extends WP_UnitTestCase {

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
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-indexer.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cache.php';

		$this->test_dir = sys_get_temp_dir() . '/nvoos-dh-idx-test-' . uniqid();
		wp_mkdir_p( $this->test_dir );
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
	 * Test frontmatter extraction from a Markdown document.
	 *
	 * @return void
	 */
	public function test_frontmatter_extraction() {
		$content = "---\ntitle: My Page\nslug: my-page\norder: 3\ntags: foo\n---\n\n# My Page\n\nContent here.";

		$indexer     = new NV_oOS_Docs_Hub_Indexer();
		$frontmatter = $indexer->extract_frontmatter( $content );

		$this->assertEquals( 'My Page', $frontmatter['title'] );
		$this->assertEquals( 'my-page', $frontmatter['slug'] );
		$this->assertEquals( 3, $frontmatter['order'] );
		$this->assertEquals( 'foo', $frontmatter['tags'] );
	}

	/**
	 * Test that title falls back to the first H1 when no frontmatter is present.
	 *
	 * @return void
	 */
	public function test_title_fallback_to_heading() {
		$content = "# Getting Started\n\nThis is the introduction.";
		$indexer = new NV_oOS_Docs_Hub_Indexer();
		$title   = $indexer->extract_title( $content, array(), 'docs/getting-started.md' );

		$this->assertEquals( 'Getting Started', $title );
	}

	/**
	 * Test that title falls back to titlecased filename when no H1 exists.
	 *
	 * @return void
	 */
	public function test_title_fallback_to_filename() {
		$content = "Some paragraph without a heading.\n";
		$indexer = new NV_oOS_Docs_Hub_Indexer();
		$title   = $indexer->extract_title( $content, array(), 'docs/quick-reference.md' );

		$this->assertEquals( 'Quick Reference', $title );
	}

	/**
	 * Test slug derivation from various relative paths.
	 *
	 * @return void
	 */
	public function test_slug_derivation() {
		$indexer = new NV_oOS_Docs_Hub_Indexer();

		$this->assertEquals( 'readme', $indexer->derive_slug( 'docs/README.md' ) );
		$this->assertEquals( 'features/chat', $indexer->derive_slug( 'docs/features/chat.md' ) );
		$this->assertEquals( 'installation', $indexer->derive_slug( 'docs/installation.md' ) );
		$this->assertEquals( 'readme', $indexer->derive_slug( 'README.md' ) );
		$this->assertEquals( 'changelog', $indexer->derive_slug( 'CHANGELOG.md' ) );
		$this->assertEquals( 'api/rest-api', $indexer->derive_slug( 'docs/api/rest-api.md' ) );
	}

	/**
	 * Test heading tree extraction.
	 *
	 * @return void
	 */
	public function test_heading_tree() {
		$content = "# Title\n\n## Installation\n\nSteps here.\n\n### Requirements\n\nNeeds PHP 7.4.\n\n## Usage\n\nHow to use.\n";
		$indexer = new NV_oOS_Docs_Hub_Indexer();
		$toc     = $indexer->extract_heading_tree( $content );

		$this->assertCount( 4, $toc );
		$this->assertEquals( 1, $toc[0]['level'] );
		$this->assertEquals( 'Title', $toc[0]['text'] );
		$this->assertEquals( 2, $toc[1]['level'] );
		$this->assertEquals( 'Installation', $toc[1]['text'] );
		$this->assertEquals( 'installation', $toc[1]['anchor'] );
		$this->assertEquals( 3, $toc[2]['level'] );
		$this->assertEquals( 'Requirements', $toc[2]['text'] );
		$this->assertEquals( 2, $toc[3]['level'] );
		$this->assertEquals( 'Usage', $toc[3]['text'] );
	}

	/**
	 * Test that broken internal links are detected.
	 *
	 * @return void
	 */
	public function test_broken_link_detection() {
		// Create an actual file to test against.
		$file_path = $this->test_dir . '/index.md';
		$content   = "# Index\n\nSee [installation](./install.md) and [missing](./nonexistent.md).";
		file_put_contents( $file_path, $content );

		// Create the referenced install.md so only nonexistent.md is broken.
		file_put_contents( $this->test_dir . '/install.md', '# Install' );

		$indexer = new NV_oOS_Docs_Hub_Indexer();

		// Use reflection to test the private method.
		$reflection = new ReflectionClass( $indexer );
		$method     = $reflection->getMethod( 'detect_broken_links' );
		$method->setAccessible( true );

		$broken = $method->invoke( $indexer, $content, $file_path, 'docs/index.md' );

		$this->assertCount( 1, $broken );
		$this->assertEquals( 'docs/index.md', $broken[0]['source'] );
		$this->assertEquals( './nonexistent.md', $broken[0]['target'] );
	}

	/**
	 * Test that build_manifest returns the expected shape.
	 *
	 * @return void
	 */
	public function test_build_manifest_shape() {
		$file_a = $this->test_dir . '/readme.md';
		$file_b = $this->test_dir . '/install.md';
		file_put_contents( $file_a, "# README\n\nWelcome." );
		file_put_contents( $file_b, "# Installation\n\nSteps." );

		$entries = array(
			array(
				'path'          => $file_a,
				'source'        => 'base',
				'plugin_name'   => 'Test Plugin',
				'relative_path' => 'docs/readme.md',
			),
			array(
				'path'          => $file_b,
				'source'        => 'base',
				'plugin_name'   => 'Test Plugin',
				'relative_path' => 'docs/install.md',
			),
		);

		$indexer  = new NV_oOS_Docs_Hub_Indexer();
		$manifest = $indexer->build_manifest( $entries );

		$this->assertIsArray( $manifest );
		$this->assertArrayHasKey( 'version', $manifest );
		$this->assertArrayHasKey( 'built_at', $manifest );
		$this->assertArrayHasKey( 'tree', $manifest );
		$this->assertArrayHasKey( 'slug_map', $manifest );
		$this->assertArrayHasKey( 'total_pages', $manifest );
		$this->assertArrayHasKey( 'broken_links', $manifest );
		$this->assertEquals( 2, $manifest['total_pages'] );
		$this->assertGreaterThan( 0, $manifest['built_at'] );
	}

	/**
	 * Test that code language extraction works correctly.
	 *
	 * @return void
	 */
	public function test_code_language_extraction() {
		$content = "# Code examples\n\n```php\n<?php echo 'hello';\n```\n\n```javascript\nconsole.log('hi');\n```\n\n```php\necho 'another';\n```\n";
		$indexer = new NV_oOS_Docs_Hub_Indexer();
		$langs   = $indexer->extract_code_languages( $content );

		$this->assertContains( 'php', $langs );
		$this->assertContains( 'javascript', $langs );
		// Should be deduplicated.
		$this->assertCount( 2, $langs );
	}

	/**
	 * Test that relative links resolve through the slug map for remote
	 * entries whose local files are cached under flat hash names.
	 *
	 * A filesystem realpath() can never resolve repo-relative links against
	 * flat cache files, so every such link used to be flagged broken. The
	 * slug map is authoritative and must win.
	 *
	 * @return void
	 */
	public function test_broken_links_resolve_via_slug_map() {
		$indexer = new NV_oOS_Docs_Hub_Indexer();
		$indexer->set_slug_map(
			array(
				'features/pro-toolkit-optimization' => array(
					'path'          => $this->test_dir . '/hash-1.md',
					'title'         => 'Pro Toolkit',
					'source'        => 'remote',
					'plugin_name'   => 'repo',
					'relative_path' => 'features/pro-toolkit-optimization.md',
				),
			)
		);

		// The local cache file exists, but under a flat name the link can
		// never resolve to.
		file_put_contents( $this->test_dir . '/hash-1.md', '# Pro Toolkit' );

		$broken = $indexer->detect_broken_links(
			'[See](features/pro-toolkit-optimization.md) and [missing](features/does-not-exist.md).',
			$this->test_dir . '/hash-0.md',
			'DOCUMENTATION_INDEX.md'
		);

		$this->assertCount( 1, $broken );
		$this->assertEquals( 'features/does-not-exist.md', $broken[0]['target'] );
	}

	/**
	 * Test that a link using a `../` segment resolves through the slug map.
	 *
	 * @return void
	 */
	public function test_broken_link_parent_segment_resolution() {
		$indexer = new NV_oOS_Docs_Hub_Indexer();
		$indexer->set_slug_map(
			array(
				'getting-started' => array(
					'path'          => $this->test_dir . '/getting-started.md',
					'title'         => 'Getting Started',
					'source'        => 'base',
					'plugin_name'   => 'plugin',
					'relative_path' => 'docs/getting-started.md',
				),
			)
		);

		$source_file = $this->test_dir . '/chat.md';
		file_put_contents( $source_file, '# Chat' );

		$broken = $indexer->detect_broken_links(
			'[Intro](../getting-started.md).',
			$source_file,
			'docs/features/chat.md'
		);

		$this->assertEmpty( $broken );
	}

	/**
	 * Test that a broken link to a file moved between directories gets a
	 * suggestion (the case from the settings UI: a root page linking to
	 * features/pro-toolkit-optimization.md when that file now lives at the
	 * repo root).
	 *
	 * @return void
	 */
	public function test_suggestion_for_moved_file() {
		$indexer = new NV_oOS_Docs_Hub_Indexer();
		$indexer->set_slug_map(
			array(
				'pro-toolkit-optimization' => array(
					'path'          => $this->test_dir . '/pro-toolkit-optimization.md',
					'title'         => 'Pro Toolkit Optimization',
					'source'        => 'remote',
					'plugin_name'   => 'repo',
					'relative_path' => 'pro-toolkit-optimization.md',
				),
			)
		);

		$source_file = $this->test_dir . '/index.md';
		file_put_contents( $source_file, '# Index' );

		$broken = $indexer->detect_broken_links(
			'[See](features/pro-toolkit-optimization.md).',
			$source_file,
			'DOCUMENTATION_INDEX.md'
		);

		$this->assertCount( 1, $broken );
		$this->assertNotEmpty( $broken[0]['suggestions'] );

		$best = $broken[0]['suggestions'][0];
		$this->assertEquals( 'pro-toolkit-optimization.md', $best['target'] );
		$this->assertLessThanOrEqual( 1.0, $best['confidence'] );
		$this->assertGreaterThanOrEqual( 0.0, $best['confidence'] );
	}

	/**
	 * Test that suggest_fix() is case-insensitive and never produces a
	 * confidence above 1.0 (regression: an exact basename match previously
	 * yielded 1.05).
	 *
	 * @return void
	 */
	public function test_suggest_fix_is_case_insensitive_and_clamped() {
		$indexer = new NV_oOS_Docs_Hub_Indexer();
		$indexer->set_slug_map(
			array(
				'pro-toolkit-optimization' => array(
					'path'          => $this->test_dir . '/pro-toolkit-optimization.md',
					'title'         => 'Pro Toolkit Optimization',
					'source'        => 'remote',
					'plugin_name'   => 'repo',
					'relative_path' => 'pro-toolkit-optimization.md',
				),
			)
		);

		$suggestions = $indexer->suggest_fix(
			'FEATURES/PRO-TOOLKIT-OPTIMIZATION.md',
			$this->test_dir . '/index.md'
		);

		$this->assertNotEmpty( $suggestions );
		$this->assertEquals( 'pro-toolkit-optimization', $suggestions[0]['slug'] );
		$this->assertEquals( 1.0, $suggestions[0]['confidence'] );
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
