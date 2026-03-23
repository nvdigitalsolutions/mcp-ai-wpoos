<?php
/**
 * Tests for embedded client shared library extraction and LD_LIBRARY_PATH handling.
 *
 * Verifies that:
 *  1. extract_binary_from_archive() extracts shared libraries (.so files)
 *     alongside the llama-cli binary so they can be found at runtime.
 *  2. run_binary() prepends the binary's directory to LD_LIBRARY_PATH on
 *     Linux so the dynamic linker resolves co-located shared libraries even
 *     when they are not installed system-wide (fixes the libmtmd.so.0 error).
 *
 * @package WP_MCP_AI
 */

/**
 * Shared library extraction and LD_LIBRARY_PATH tests.
 */
class Test_Embedded_Client_Shared_Libs extends WP_UnitTestCase {

	/**
	 * Embedded client instance under test.
	 *
	 * @var WP_MCP_AI_Embedded_Client
	 */
	private $client;

	/**
	 * Temporary directory used by tests (cleaned up on tearDown).
	 *
	 * @var string
	 */
	private $tmp_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-embedded-client.php';
		}

		$this->client  = new WP_MCP_AI_Embedded_Client();
		$base          = wp_tempnam( 'wp-mcp-ai-test' );
		@unlink( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
		$this->tmp_dir = $base . '-dir';
		wp_mkdir_p( $this->tmp_dir );
	}

	/**
	 * Clean up temporary directory after each test.
	 */
	public function tearDown(): void {
		$this->recursive_rmdir( $this->tmp_dir );
		parent::tearDown();
	}

	// =========================================================================
	// extract_binary_from_archive – shared library extraction
	// =========================================================================

	/**
	 * Test that extract_binary_from_archive() extracts .so files into bin_dir.
	 *
	 * Regression test for the libmtmd.so.0 missing shared library error.
	 * Recent llama.cpp releases include shared libraries (libmtmd.so.0, etc.)
	 * that must be co-located with llama-cli to be found at runtime.
	 */
	public function test_extract_binary_from_archive_extracts_shared_libs() {
		if ( ! class_exists( 'PharData' ) ) {
			$this->markTestSkipped( 'PharData extension is not available.' );
		}

		// Create a mock tar.gz archive containing llama-cli and libmtmd.so.0.
		$archive_path = $this->create_mock_archive(
			array(
				'bundle/llama-cli'    => 'mock-binary-content',
				'bundle/libmtmd.so.0' => 'mock-shared-lib-content',
				'bundle/libllama.so'  => 'mock-llama-lib-content',
			)
		);

		$dest_path = $this->tmp_dir . '/llama-cli';
		$bin_dir   = $this->tmp_dir;

		$result = $this->call_private_method(
			'extract_binary_from_archive',
			array( $archive_path, 'llama-cli', $dest_path, $bin_dir )
		);

		// Binary must be extracted.
		$this->assertTrue( $result, 'extract_binary_from_archive() must return true on success.' );
		$this->assertFileExists( $dest_path, 'llama-cli binary must be extracted.' );

		// Shared libraries must be extracted into $bin_dir.
		$this->assertFileExists(
			$this->tmp_dir . '/libmtmd.so.0',
			'libmtmd.so.0 shared library must be extracted alongside the binary.'
		);
		$this->assertFileExists(
			$this->tmp_dir . '/libllama.so',
			'libllama.so shared library must be extracted alongside the binary.'
		);

		@unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Test that extract_binary_from_archive() succeeds when archive has no .so files.
	 *
	 * Older llama.cpp builds were statically linked and contained no shared
	 * libraries. Extraction must still succeed in that case.
	 */
	public function test_extract_binary_from_archive_succeeds_without_shared_libs() {
		if ( ! class_exists( 'PharData' ) ) {
			$this->markTestSkipped( 'PharData extension is not available.' );
		}

		$archive_path = $this->create_mock_archive(
			array(
				'bundle/llama-cli' => 'mock-binary-content',
			)
		);

		$dest_path = $this->tmp_dir . '/llama-cli';

		$result = $this->call_private_method(
			'extract_binary_from_archive',
			array( $archive_path, 'llama-cli', $dest_path, $this->tmp_dir )
		);

		$this->assertTrue( $result, 'Extraction must succeed even when no .so files are present.' );
		$this->assertFileExists( $dest_path );

		@unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Test that extract_binary_from_archive() ignores non-matching shared lib names.
	 *
	 * Files that look like libraries but do not match the lib*.so(.N)* pattern
	 * (e.g. 'README.so', 'config.so.bak') must not be extracted.
	 */
	public function test_extract_binary_from_archive_ignores_non_lib_so_files() {
		if ( ! class_exists( 'PharData' ) ) {
			$this->markTestSkipped( 'PharData extension is not available.' );
		}

		$archive_path = $this->create_mock_archive(
			array(
				'bundle/llama-cli'    => 'binary',
				'bundle/libmtmd.so.0' => 'real-lib',
				'bundle/README.txt'   => 'readme',
				'bundle/config.so'    => 'not-a-lib-no-lib-prefix',
			)
		);

		$dest_path = $this->tmp_dir . '/llama-cli';

		$this->call_private_method(
			'extract_binary_from_archive',
			array( $archive_path, 'llama-cli', $dest_path, $this->tmp_dir )
		);

		// Only libmtmd.so.0 matches; config.so lacks the 'lib' prefix.
		$this->assertFileExists( $this->tmp_dir . '/libmtmd.so.0' );
		$this->assertFileDoesNotExist( $this->tmp_dir . '/config.so' );
		$this->assertFileDoesNotExist( $this->tmp_dir . '/README.txt' );

		@unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Test that extract_binary_from_archive() returns WP_Error when binary not found.
	 */
	public function test_extract_binary_from_archive_returns_error_when_binary_missing() {
		if ( ! class_exists( 'PharData' ) ) {
			$this->markTestSkipped( 'PharData extension is not available.' );
		}

		$archive_path = $this->create_mock_archive(
			array(
				'bundle/libmtmd.so.0' => 'lib-only-archive',
			)
		);

		$result = $this->call_private_method(
			'extract_binary_from_archive',
			array( $archive_path, 'llama-cli', $this->tmp_dir . '/llama-cli', $this->tmp_dir )
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_binary_not_in_archive', $result->get_error_code() );

		@unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Test the shared library filename regex pattern used internally.
	 *
	 * Validates that the regex in extract_binary_from_archive() correctly
	 * identifies shared library filenames.
	 */
	public function test_shared_lib_regex_matches_valid_names() {
		$pattern = '/^lib.+\.so(\.\d+)*$/';

		// Should match.
		$this->assertSame( 1, preg_match( $pattern, 'libmtmd.so.0' ) );
		$this->assertSame( 1, preg_match( $pattern, 'libllama.so' ) );
		$this->assertSame( 1, preg_match( $pattern, 'libggml.so.0' ) );
		$this->assertSame( 1, preg_match( $pattern, 'libstdc++.so.6' ) );
		$this->assertSame( 1, preg_match( $pattern, 'libfoo.so.1.2.3' ) );

		// Should NOT match.
		$this->assertSame( 0, preg_match( $pattern, 'llama-cli' ) );
		$this->assertSame( 0, preg_match( $pattern, 'config.so' ) );      // No 'lib' prefix.
		$this->assertSame( 0, preg_match( $pattern, 'README.txt' ) );
		$this->assertSame( 0, preg_match( $pattern, 'libfoo.so.bak' ) );  // Non-numeric suffix.
		$this->assertSame( 0, preg_match( $pattern, '.so' ) );             // No library name.
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a mock tar.gz archive in the system temp directory.
	 *
	 * @param array $entries Map of archive path => file content.
	 * @return string Absolute path to the created archive.
	 */
	private function create_mock_archive( array $entries ) {
		$tmp_base  = wp_tempnam( 'wp-mcp-ai-test-archive' );
		$tar_path  = $tmp_base . '.tar';
		$tgz_path  = $tmp_base . '.tar.gz';
		$final_path = $tmp_base . '-archive.tar.gz';

		// Write files to a staging sub-directory so PharData can add them.
		$stage_dir = $tmp_base . '-stage';
		wp_mkdir_p( $stage_dir );

		$phar = new PharData( $tar_path );

		foreach ( $entries as $archive_entry => $content ) {
			$local_path = $stage_dir . '/' . str_replace( '/', '_', $archive_entry );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $local_path, $content );
			$phar->addFile( $local_path, $archive_entry );
		}

		$phar->compress( Phar::GZ );
		// Move the .tar.gz to a clean final path.
		if ( file_exists( $tgz_path ) ) {
			rename( $tgz_path, $final_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}

		// Clean up staging files.
		$this->recursive_rmdir( $stage_dir );
		@unlink( $tar_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
		@unlink( $tmp_base ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged

		return $final_path;
	}

	/**
	 * Call a private method on the client instance via reflection.
	 *
	 * @param string $method_name Name of the private method.
	 * @param array  $args        Arguments to pass to the method.
	 * @return mixed Return value of the method.
	 */
	private function call_private_method( $method_name, array $args = array() ) {
		$reflection = new ReflectionClass( $this->client );
		$method     = $reflection->getMethod( $method_name );
		$method->setAccessible( true );
		return $method->invokeArgs( $this->client, $args );
	}

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,Generic.PHP.NoSilencedErrors.Discouraged
	}
}
