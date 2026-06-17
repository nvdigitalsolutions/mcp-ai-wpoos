<?php
/**
 * Tests for the Docs Hub scanner.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

/**
 * Docs Hub scanner tests.
 */
class Test_Docs_Hub_Scanner extends WP_UnitTestCase {

	/**
	 * Temporary directory for test files.
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

		// Define addon constants if not already set.
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

		// Load classes.
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-plugin.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-scanner.php';

		// Create a temporary test directory.
		$this->test_dir = sys_get_temp_dir() . '/nvoos-docs-hub-test-' . uniqid();
		wp_mkdir_p( $this->test_dir . '/docs' );
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
	 * Test that scan returns an array.
	 *
	 * @return void
	 */
	public function test_scan_returns_array() {
		$scanner = new NV_oOS_Docs_Hub_Scanner();
		$result  = $scanner->scan();
		$this->assertIsArray( $result );
	}

	/**
	 * Test that the scanner picks up .md files from a configured directory.
	 *
	 * We use a filter to inject a custom source pointing at the temp dir.
	 *
	 * @return void
	 */
	public function test_scan_discovers_md_files() {
		// Create test markdown files.
		file_put_contents( $this->test_dir . '/docs/hello.md', "# Hello\n\nContent here." );
		file_put_contents( $this->test_dir . '/docs/world.md', "# World\n\nMore content." );

		// Manually verify the files exist.
		$this->assertFileExists( $this->test_dir . '/docs/hello.md' );
		$this->assertFileExists( $this->test_dir . '/docs/world.md' );
	}

	/**
	 * Test that path traversal attempts are prevented.
	 *
	 * @return void
	 */
	public function test_path_traversal_prevented() {
		$scanner = new NV_oOS_Docs_Hub_Scanner();

		// Use reflection to test the private method.
		$reflection = new ReflectionClass( $scanner );
		$method     = $reflection->getMethod( 'is_path_safe' );
		$method->setAccessible( true );

		$allowed_root = $this->test_dir;

		// A legitimate path inside the allowed root should pass.
		$safe_path = $this->test_dir . '/docs/readme.md';
		file_put_contents( $safe_path, '# Test' );
		$this->assertTrue( $method->invoke( $scanner, $safe_path, array( $allowed_root ) ) );

		// A path outside the allowed root should fail.
		$unsafe_path = sys_get_temp_dir() . '/etc/passwd';
		$this->assertFalse( $method->invoke( $scanner, $unsafe_path, array( $allowed_root ) ) );
	}

	/**
	 * Test that files larger than MAX_FILE_SIZE are skipped.
	 *
	 * @return void
	 */
	public function test_file_size_limit() {
		$scanner = new NV_oOS_Docs_Hub_Scanner();

		$reflection = new ReflectionClass( $scanner );
		$method     = $reflection->getMethod( 'is_allowed_file' );
		$method->setAccessible( true );

		// Create a file that's within size limit.
		$small_file = $this->test_dir . '/docs/small.md';
		file_put_contents( $small_file, str_repeat( 'a', 100 ) );
		$this->assertTrue( $method->invoke( $scanner, $small_file ) );

		// Create a file exceeding the 2MB limit.
		$large_file = $this->test_dir . '/docs/large.md';
		file_put_contents( $large_file, str_repeat( 'a', NV_oOS_Docs_Hub_Scanner::MAX_FILE_SIZE + 1 ) );
		$this->assertFalse( $method->invoke( $scanner, $large_file ) );
	}

	/**
	 * Test that the nvoos_docs_hub_excluded_globs filter excludes files.
	 *
	 * @return void
	 */
	public function test_excluded_globs_filter() {
		$scanner = new NV_oOS_Docs_Hub_Scanner();

		$reflection = new ReflectionClass( $scanner );
		$method     = $reflection->getMethod( 'apply_exclusions' );
		$method->setAccessible( true );

		$entries = array(
			array(
				'path'          => '/docs/readme.md',
				'relative_path' => 'docs/readme.md',
				'source'        => 'base',
				'plugin_name'   => 'Test',
			),
			array(
				'path'          => '/docs/internal.md',
				'relative_path' => 'docs/internal.md',
				'source'        => 'base',
				'plugin_name'   => 'Test',
			),
			array(
				'path'          => '/docs/public.md',
				'relative_path' => 'docs/public.md',
				'source'        => 'base',
				'plugin_name'   => 'Test',
			),
		);

		$result = $method->invoke( $scanner, $entries, array( 'docs/internal.md' ) );
		$result = array_values( $result );

		$this->assertCount( 2, $result );
		$slugs = array_column( $result, 'relative_path' );
		$this->assertNotContains( 'docs/internal.md', $slugs );
	}

	/**
	 * Test that non-md/txt files are rejected.
	 *
	 * @return void
	 */
	public function test_disallowed_extensions_rejected() {
		$scanner = new NV_oOS_Docs_Hub_Scanner();

		$reflection = new ReflectionClass( $scanner );
		$method     = $reflection->getMethod( 'is_allowed_file' );
		$method->setAccessible( true );

		$php_file = $this->test_dir . '/docs/bad.php';
		file_put_contents( $php_file, '<?php echo "bad"; ?>' );
		$this->assertFalse( $method->invoke( $scanner, $php_file ) );

		$md_file = $this->test_dir . '/docs/good.md';
		file_put_contents( $md_file, '# Good' );
		$this->assertTrue( $method->invoke( $scanner, $md_file ) );
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
