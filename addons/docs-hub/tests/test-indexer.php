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
