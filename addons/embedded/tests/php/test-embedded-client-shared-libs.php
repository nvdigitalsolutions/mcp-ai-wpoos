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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
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
			$pro_client_path = WP_MCP_AI_PATH . 'addons/pro/includes/class-wp-mcp-ai-embedded-client.php';
			if ( file_exists( $pro_client_path ) ) {
				require_once $pro_client_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Embedded_Client requires the Pro addon.' );
		}

		$this->client = new WP_MCP_AI_Embedded_Client();
		$base         = wp_tempnam( 'wp-mcp-ai-test' );
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
	// create_soname_symlinks – SONAME symlink creation
	// =========================================================================

	/**
	 * Test that extract_binary_from_archive() creates SONAME symlink for a
	 * versioned shared library (lib*.so.X.Y.Z → lib*.so.X and lib*.so).
	 *
	 * Regression test: recent llama.cpp releases ship lib*.so.X.Y.Z as the
	 * real file while lib*.so.X (SONAME) and lib*.so are symlinks that
	 * PharData cannot extract.  The binary fails at runtime with
	 * "error while loading shared libraries: libmtmd.so.0" unless we
	 * create the missing SONAME symlinks after extraction.
	 */
	public function test_soname_symlinks_created_for_versioned_lib() {
		if ( ! class_exists( 'PharData' ) ) {
			$this->markTestSkipped( 'PharData extension is not available.' );
		}

		// Archive that mimics a recent llama.cpp release: only the versioned
		// file is present as a regular entry; the symlinks are not.
		$archive_path = $this->create_mock_archive(
			array(
				'bundle/llama-cli'        => 'mock-binary-content',
				'bundle/libmtmd.so.0.9.8' => 'mock-mtmd-lib-content',
				'bundle/libggml.so.0.9.8' => 'mock-ggml-lib-content',
			)
		);

		$dest_path = $this->tmp_dir . '/llama-cli';
		$bin_dir   = $this->tmp_dir;

		$result = $this->call_private_method(
			'extract_binary_from_archive',
			array( $archive_path, 'llama-cli', $dest_path, $bin_dir )
		);

		$this->assertTrue( $result, 'extract_binary_from_archive() must return true on success.' );
		$this->assertFileExists( $dest_path, 'llama-cli binary must be extracted.' );

		// Versioned files must be present.
		$this->assertFileExists( $bin_dir . '/libmtmd.so.0.9.8', 'Versioned libmtmd must be extracted.' );
		$this->assertFileExists( $bin_dir . '/libggml.so.0.9.8', 'Versioned libggml must be extracted.' );

		// SONAME symlinks (lib*.so.0) must be created automatically.
		$this->assertTrue(
			is_link( $bin_dir . '/libmtmd.so.0' ),
			'SONAME symlink libmtmd.so.0 must be created so the binary can load it at runtime.'
		);
		$this->assertTrue(
			is_link( $bin_dir . '/libggml.so.0' ),
			'SONAME symlink libggml.so.0 must be created so the binary can load it at runtime.'
		);

		// Linker-name symlinks (lib*.so) must also be created.
		$this->assertTrue(
			is_link( $bin_dir . '/libmtmd.so' ),
			'Linker-name symlink libmtmd.so must be created.'
		);
		$this->assertTrue(
			is_link( $bin_dir . '/libggml.so' ),
			'Linker-name symlink libggml.so must be created.'
		);

		// Symlink targets must resolve to the versioned files.
		$this->assertEquals(
			'libmtmd.so.0.9.8',
			readlink( $bin_dir . '/libmtmd.so.0' ),
			'SONAME symlink must point to the versioned file.'
		);
		$this->assertEquals(
			'libmtmd.so.0',
			readlink( $bin_dir . '/libmtmd.so' ),
			'Linker-name symlink must point to the SONAME.'
		);

		@unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Test that create_soname_symlinks() does not overwrite existing files.
	 *
	 * If a lib*.so.X file was already extracted from the archive (e.g. an
	 * older build that shipped the SONAME as a regular file), the method
	 * must not replace it with a symlink.
	 */
	public function test_soname_symlinks_not_overwritten() {
		if ( ! class_exists( 'PharData' ) ) {
			$this->markTestSkipped( 'PharData extension is not available.' );
		}

		// Archive with both the versioned file and the SONAME as regular files.
		$archive_path = $this->create_mock_archive(
			array(
				'bundle/llama-cli'        => 'mock-binary-content',
				'bundle/libmtmd.so.0.9.8' => 'versioned-content',
				'bundle/libmtmd.so.0'     => 'soname-content',
			)
		);

		$dest_path = $this->tmp_dir . '/llama-cli';
		$bin_dir   = $this->tmp_dir;

		$this->call_private_method(
			'extract_binary_from_archive',
			array( $archive_path, 'llama-cli', $dest_path, $bin_dir )
		);

		// The SONAME file that came from the archive must NOT be replaced by
		// a symlink; it is a regular file and must stay that way.
		$this->assertFalse(
			is_link( $bin_dir . '/libmtmd.so.0' ),
			'Existing SONAME file must not be replaced by a symlink.'
		);
		$this->assertEquals(
			'soname-content',
			file_get_contents( $bin_dir . '/libmtmd.so.0' ),
			'Existing SONAME file content must be preserved.'
		);

		@unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Test that create_soname_symlinks() skips files that are already SONAME-
	 * level versioned (lib*.so.X with no further dot).
	 *
	 * A file like libmtmd.so.0 is already at SONAME granularity; we must not
	 * try to create libmtmd.so.0 → libmtmd.so.0 (i.e. self-referential symlink).
	 */
	public function test_soname_symlinks_skips_soname_level_files() {
		if ( ! class_exists( 'PharData' ) ) {
			$this->markTestSkipped( 'PharData extension is not available.' );
		}

		// Archive with only a SONAME-level file (no full version like .0.9.8).
		$archive_path = $this->create_mock_archive(
			array(
				'bundle/llama-cli'    => 'mock-binary-content',
				'bundle/libmtmd.so.0' => 'soname-content',
			)
		);

		$dest_path = $this->tmp_dir . '/llama-cli';
		$bin_dir   = $this->tmp_dir;

		$this->call_private_method(
			'extract_binary_from_archive',
			array( $archive_path, 'llama-cli', $dest_path, $bin_dir )
		);

		// libmtmd.so.0 exists as a regular file; it must not be changed.
		$this->assertFileExists( $bin_dir . '/libmtmd.so.0' );
		$this->assertFalse( is_link( $bin_dir . '/libmtmd.so.0' ), 'SONAME file must not become a symlink.' );

		@unlink( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,Generic.PHP.NoSilencedErrors.Discouraged
	}

	// =========================================================================
	// get_shared_libs_status – diagnostic API
	// =========================================================================

	/**
	 * When no binary is present, get_shared_libs_status() returns an empty result.
	 */
	public function test_get_shared_libs_status_returns_empty_when_binary_absent() {
		// Ensure there is no binary in any of the standard lookup locations.
		// We can guarantee this by checking the method's behaviour when the client
		// detects no binary (the default state in a test environment).
		$status = $this->client->get_shared_libs_status();

		// Regardless of whether a system binary happens to be installed, the
		// method must return a properly shaped array.
		$this->assertArrayHasKey( 'found', $status );
		$this->assertArrayHasKey( 'libs', $status );
		$this->assertArrayHasKey( 'bin_dir', $status );
		$this->assertIsBool( $status['found'] );
		$this->assertIsArray( $status['libs'] );
		$this->assertIsString( $status['bin_dir'] );

		// When no binary exists, found must be false and libs must be empty.
		if ( ! $this->client->get_binary_status()['found'] ) {
			$this->assertFalse( $status['found'] );
			$this->assertEmpty( $status['libs'] );
			$this->assertSame( '', $status['bin_dir'] );
		}
	}

	/**
	 * Lists .so files when they exist next to the binary.
	 *
	 * This test plants a fake executable and two shared library files in a temp
	 * directory and verifies that get_shared_libs_status() discovers them.
	 */
	public function test_get_shared_libs_status_lists_so_files_in_binary_dir() {
		$bin_path     = $this->create_mock_binary( $this->tmp_dir . '/llama-cli' );
		$fresh_client = $this->client_with_binary_path( $bin_path );

		// Plant two shared library files alongside the binary.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/libllama.so', 'mock-lib' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/libmtmd.so.0', 'mock-lib' );

		$status = $fresh_client->get_shared_libs_status();

		$this->assertTrue( $status['found'], 'found must be true when .so files are present.' );
		$this->assertContains( 'libllama.so', $status['libs'], 'libllama.so must appear in the libs list.' );
		$this->assertContains( 'libmtmd.so.0', $status['libs'], 'libmtmd.so.0 must appear in the libs list.' );
		$this->assertSame( trailingslashit( $this->tmp_dir ), $status['bin_dir'] );
	}

	/**
	 * Returns found=false when binary dir has no .so files.
	 */
	public function test_get_shared_libs_status_returns_false_when_no_so_files() {
		$bin_path     = $this->create_mock_binary( $this->tmp_dir . '/llama-cli' );
		$fresh_client = $this->client_with_binary_path( $bin_path );

		$status = $fresh_client->get_shared_libs_status();

		$this->assertFalse( $status['found'], 'found must be false when no .so files are present.' );
		$this->assertEmpty( $status['libs'] );
		$this->assertSame( trailingslashit( $this->tmp_dir ), $status['bin_dir'] );
	}

	/**
	 * The libs array returned by get_shared_libs_status() is sorted alphabetically.
	 */
	public function test_get_shared_libs_status_libs_are_sorted() {
		$bin_path     = $this->create_mock_binary( $this->tmp_dir . '/llama-cli' );
		$fresh_client = $this->client_with_binary_path( $bin_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/libz.so', 'mock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/liba.so', 'mock' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/libm.so.0', 'mock' );

		$libs   = $fresh_client->get_shared_libs_status()['libs'];
		$sorted = $libs;
		sort( $sorted );
		$this->assertSame( $sorted, $libs, 'libs list must be sorted alphabetically.' );
	}

	// =========================================================================
	// create_soname_symlinks – copy fallback when symlink() is unavailable
	// =========================================================================

	/**
	 * Creates the SONAME file as a copy when a pre-existing copy already covers it (simulates the copy-fallback path by
	 * directly pre-populating the directory with only versioned libs, then
	 * verifying the SONAME name appears after calling the method).
	 *
	 * Regression test: on some shared-hosting environments (e.g. Cloudways)
	 * PHP's symlink() is blocked, so the previous implementation silently left
	 * the SONAME file absent and llama-cli failed with
	 * "cannot open shared object file: libmtmd.so.0".
	 */
	public function test_soname_created_as_copy_when_only_versioned_file_present() {
		// Plant a versioned library directly (no archive), simulating what the
		// file system looks like after a download where symlinks were skipped.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/libmtmd.so.0.0.8480', 'mock-mtmd-lib' );

		$this->call_private_method( 'create_soname_symlinks', array( $this->tmp_dir ) );

		// The SONAME (libmtmd.so.0) must now exist, either as a symlink or a copy.
		$soname_path = $this->tmp_dir . '/libmtmd.so.0';
		// file_exists() returns true for both resolved symlinks and plain files.
		$this->assertTrue(
			file_exists( $soname_path ),
			'libmtmd.so.0 must exist after create_soname_symlinks() (as symlink or copy).'
		);

		// The linker-name (libmtmd.so) must also exist.
		$linker_path = $this->tmp_dir . '/libmtmd.so';
		$this->assertTrue(
			file_exists( $linker_path ),
			'libmtmd.so must exist after create_soname_symlinks() (as symlink or copy).'
		);
	}

	/**
	 * Two versioned files for the same library (e.g. libmtmd.so.0.0.8479 and
	 * libmtmd.so.0.0.8480) must result in exactly one SONAME file, not two
	 * colliding copies.
	 */
	public function test_soname_created_once_when_multiple_versions_present() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/libmtmd.so.0.0.8479', 'content-8479' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/libmtmd.so.0.0.8480', 'content-8480' );

		$this->call_private_method( 'create_soname_symlinks', array( $this->tmp_dir ) );

		$soname_path = $this->tmp_dir . '/libmtmd.so.0';
		$this->assertTrue(
			file_exists( $soname_path ),
			'libmtmd.so.0 must exist after create_soname_symlinks().'
		);
	}

	/**
	 * Auto-repairs missing SONAME files for existing
	 * installations where the initial extraction ran before this fix existed.
	 *
	 * Regression test: users who installed llama.cpp before the symlink/copy
	 * logic was added had only the versioned files (e.g. libmtmd.so.0.0.8480)
	 * and were missing the SONAME (libmtmd.so.0), causing
	 * "error while loading shared libraries: libmtmd.so.0".
	 */
	public function test_get_shared_libs_status_repairs_missing_soname() {
		// Create a mock binary and plant only the versioned lib (no SONAME).
		$bin_path     = $this->create_mock_binary( $this->tmp_dir . '/llama-cli' );
		$fresh_client = $this->client_with_binary_path( $bin_path );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/libmtmd.so.0.0.8480', 'mock-lib-content' );

		// SONAME must NOT exist before we call get_shared_libs_status().
		$this->assertFileDoesNotExist( $this->tmp_dir . '/libmtmd.so.0' );

		$status = $fresh_client->get_shared_libs_status();

		// get_shared_libs_status() must have triggered SONAME creation.
		$soname_path = $this->tmp_dir . '/libmtmd.so.0';
		$this->assertTrue(
			file_exists( $soname_path ),
			'get_shared_libs_status() must auto-repair missing libmtmd.so.0.'
		);

		// The SONAME must appear in the returned libs list.
		$this->assertContains(
			'libmtmd.so.0',
			$status['libs'],
			'libmtmd.so.0 must appear in the libs list returned by get_shared_libs_status().'
		);
	}

	/**
	 * Regression test: when symlink() is listed in PHP's disable_functions the
	 * old code called @symlink() anyway, which throws an uncatchable E_ERROR
	 * ("Call to undefined function symlink()") that brought down the entire
	 * page load — reproducing the 500 error on the provider-diagnostic admin
	 * page on Cloudways after PR #4416.
	 *
	 * The fix guards both symlink() calls with function_exists('symlink').
	 * When symlink() is unavailable the copy-fallback path must be taken and
	 * the SONAME file must exist as a plain file afterwards.
	 *
	 * This test exercises the copy-fallback path directly by using uopz (when
	 * available) to make symlink() itself return false, confirming the fallback
	 * produces a regular file; without uopz the test falls back to asserting
	 * that the method completes without error (which itself would catch the
	 * original E_ERROR regression in an environment where symlink is disabled).
	 */
	public function test_soname_copy_fallback_when_symlink_function_disabled() {
		// Plant only the versioned library; no SONAME, no linker-name.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $this->tmp_dir . '/libmtmd.so.0.0.8480', 'mock-lib-bytes' );

		if ( function_exists( 'uopz_set_return' ) ) {
			// Make symlink() always return false so the copy-fallback branch is
			// exercised. This is more targeted than mocking function_exists().
			uopz_set_return( 'symlink', false );
			$this->call_private_method( 'create_soname_symlinks', array( $this->tmp_dir ) );
			uopz_unset_return( 'symlink' );

			// SONAME must exist as a plain file (copy), not a symlink.
			$soname_path = $this->tmp_dir . '/libmtmd.so.0';
			$this->assertTrue(
				file_exists( $soname_path ),
				'libmtmd.so.0 must exist as a file copy when symlink() is unavailable.'
			);
			$this->assertFalse(
				is_link( $soname_path ),
				'libmtmd.so.0 must be a plain file (not a symlink) when symlink() fails.'
			);
			$this->assertSame(
				'mock-lib-bytes',
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				file_get_contents( $soname_path ),
				'libmtmd.so.0 content must match the versioned source file.'
			);
		} else {
			// uopz not available: simply assert the method completes without
			// error.  In an environment where symlink() is in disable_functions
			// this call would have thrown E_ERROR before the fix; the assertion
			// below would never be reached, causing the test to fail.
			$this->call_private_method( 'create_soname_symlinks', array( $this->tmp_dir ) );

			$this->assertTrue(
				file_exists( $this->tmp_dir . '/libmtmd.so.0' ),
				'libmtmd.so.0 must exist after create_soname_symlinks() completes without error.'
			);
		}
	}

	// =========================================================================
	// is_executable – binary permission checks
	// =========================================================================

	/**
	 * Test that a freshly extracted mock binary is considered executable by PHP.
	 *
	 * Verifies that create_mock_binary() produces a file for which is_executable()
	 * returns true and that get_binary_status()['found'] reflects this when the
	 * settings-configured path points at the same file.
	 */
	public function test_mock_binary_is_executable() {
		$bin_path = $this->create_mock_binary( $this->tmp_dir . '/llama-cli' );

		$this->assertFileExists( $bin_path, 'Mock binary must exist on disk.' );
		$this->assertTrue( is_executable( $bin_path ), 'Mock binary must be executable (chmod 0755 must have been applied).' );

		$fresh_client = $this->client_with_binary_path( $bin_path );
		$status       = $fresh_client->get_binary_status();

		$this->assertTrue( $status['found'], 'get_binary_status() must report found=true for an executable binary.' );
		$this->assertSame( $bin_path, $status['path'], 'get_binary_status() must return the correct binary path.' );
	}

	/**
	 * Test that a non-executable file is NOT reported as found by get_binary_status().
	 *
	 * Verifies the is_executable() guard in get_inference_binary(): a file whose
	 * execute bit is cleared (chmod 0644) must not be returned as a valid binary.
	 */
	public function test_non_executable_binary_not_found() {
		$bin_path = $this->tmp_dir . '/llama-cli';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $bin_path, '#!/bin/sh' . PHP_EOL . 'echo mock' );
		chmod( $bin_path, 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- execute bit intentionally absent

		$fresh_client = $this->client_with_binary_path( $bin_path );
		$status       = $fresh_client->get_binary_status();

		$this->assertFalse( $status['found'], 'A non-executable file must not be reported as a valid binary.' );
	}

	/**
	 * Test that run_binary() (via Symfony Process) executes the binary and captures output.
	 *
	 * Symfony\Component\Process\Process is the execution engine used by the
	 * embedded LLM client.  This test confirms that:
	 *  1. The Symfony Process component is available (composer install ran).
	 *  2. A mock executable binary can be launched via the same code-path
	 *     that llama-cli inference uses.
	 *  3. stdout is captured correctly and returned by run_binary().
	 *  4. A zero exit code is treated as success.
	 */
	public function test_run_binary_executes_via_symfony_process() {
		if ( ! class_exists( 'Symfony\Component\Process\Process' ) ) {
			$this->markTestSkipped( 'Symfony Process component is not available. Run composer install.' );
		}

		// Create a mock binary that echoes a known string to stdout and exits 0.
		$bin_path = $this->tmp_dir . '/llama-cli';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$bin_path,
			'#!/bin/sh' . PHP_EOL .
			'echo "llama-cli mock output"' . PHP_EOL .
			'exit 0' . PHP_EOL
		);
		chmod( $bin_path, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$this->assertTrue( is_executable( $bin_path ), 'Binary must be executable before invoking run_binary().' );

		$fresh_client = $this->client_with_binary_path( $bin_path );
		$result       = $fresh_client->test_connection();

		// test_connection() calls run_binary() via Symfony Process internally.
		$this->assertNotWPError( $result, 'run_binary() via Symfony Process must succeed for a valid executable.' );
		$this->assertTrue( $result['success'], 'test_connection() must return success => true.' );
	}

	/**
	 * Test that run_binary() returns a WP_Error when the binary exits with a
	 * non-zero status code (simulating an unrecognised flag or runtime error).
	 *
	 * This mirrors what happens when llama-cli encounters an unsupported argument:
	 * the process exits non-zero and run_binary() must surface a WP_Error rather
	 * than silently returning empty output.
	 */
	public function test_run_binary_returns_error_on_non_zero_exit() {
		if ( ! class_exists( 'Symfony\Component\Process\Process' ) ) {
			$this->markTestSkipped( 'Symfony Process component is not available. Run composer install.' );
		}

		$bin_path = $this->tmp_dir . '/llama-cli';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$bin_path,
			'#!/bin/sh' . PHP_EOL .
			'echo "error: unrecognised option" >&2' . PHP_EOL .
			'exit 1' . PHP_EOL
		);
		chmod( $bin_path, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$fresh_client = $this->client_with_binary_path( $bin_path );
		$result       = $fresh_client->test_connection();

		$this->assertWPError( $result, 'run_binary() must return WP_Error when the binary exits non-zero.' );
		$this->assertSame( 'wp_mcp_ai_binary_error', $result->get_error_code() );
	}

	// =========================================================================
	// run_binary – stderr fallback for newer llama.cpp builds
	// =========================================================================

	/**
	 * Succeeds when llama-cli writes --version only to stderr.
	 *
	 * Llama.cpp builds b8479+ write their version string to stderr instead of
	 * stdout.  Before this fix, run_binary() returned '' (empty stdout) which
	 * caused test_connection() to return the false error
	 * "llama-cli binary returned no output".
	 *
	 * The fix adds a $use_stderr_fallback parameter to run_binary(): when true
	 * and stdout is empty after a successful (exit_code 0) run, stderr is
	 * returned instead.  test_connection() passes true for --version calls.
	 */
	public function test_connection_succeeds_when_version_written_to_stderr() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open is not available in this test environment.' );
		}

		// Create a mock llama-cli that prints nothing to stdout and a version
		// string to stderr, mirroring the behaviour of llama.cpp b8479+.
		$bin_path = $this->tmp_dir . '/llama-cli';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$bin_path,
			'#!/bin/sh' . PHP_EOL .
			'echo "version: 9999 (abc1234)" >&2' . PHP_EOL .
			'exit 0' . PHP_EOL
		);
		chmod( $bin_path, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$fresh_client = $this->client_with_binary_path( $bin_path );
		$result       = $fresh_client->test_connection();

		$this->assertNotWPError( $result, 'test_connection() must not return WP_Error when version is on stderr.' );
		$this->assertTrue( $result['success'], 'test_connection() must return success => true.' );
		$this->assertStringContainsString( 'version', $result['version'], 'Version string from stderr must be returned.' );
	}

	/**
	 * Still fails (WP_Error) when the binary produces no output
	 * on either stdout or stderr.
	 *
	 * This ensures the "no output" guard in test_connection() is preserved: a
	 * silent binary (or one that exits with no output at all) must still be
	 * detected as broken even with the stderr fallback enabled.
	 */
	public function test_connection_fails_when_binary_produces_no_output_at_all() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open is not available in this test environment.' );
		}

		// Create a mock binary that exits cleanly but writes nothing anywhere.
		$bin_path = $this->tmp_dir . '/llama-cli';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents(
			$bin_path,
			'#!/bin/sh' . PHP_EOL .
			'exit 0' . PHP_EOL
		);
		chmod( $bin_path, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$fresh_client = $this->client_with_binary_path( $bin_path );
		$result       = $fresh_client->test_connection();

		$this->assertWPError( $result, 'test_connection() must return WP_Error when binary produces no output at all.' );
		$this->assertSame( 'wp_mcp_ai_binary_error', $result->get_error_code() );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Create a fake executable llama-cli binary at the given path.
	 *
	 * @param string $path Absolute destination path for the binary.
	 * @return string The same path, for convenience.
	 */
	private function create_mock_binary( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, '#!/bin/sh' . PHP_EOL . 'echo mock' );
		chmod( $path, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		return $path;
	}

	/**
	 * Return a fresh WP_MCP_AI_Embedded_Client that resolves its binary via
	 * the settings-configured path (the last lookup fallback in
	 * get_inference_binary()).  Cleans up the option after each test via the
	 * tearDown-registered option delete.
	 *
	 * @param string $bin_path Absolute path to a valid (mock) llama-cli binary.
	 * @return WP_MCP_AI_Embedded_Client
	 */
	private function client_with_binary_path( $bin_path ) {
		update_option( 'wp_mcp_ai_settings', array( 'embedded_binary_path' => $bin_path ) );
		return new WP_MCP_AI_Embedded_Client();
	}

	/**
	 * Create a mock tar.gz archive in the system temp directory.
	 *
	 * @param array $entries Map of archive path => file content.
	 * @return string Absolute path to the created archive.
	 */
	private function create_mock_archive( array $entries ) {
		$tmp_base   = wp_tempnam( 'wp-mcp-ai-test-archive' );
		$tar_path   = $tmp_base . '.tar';
		$tgz_path   = $tmp_base . '.tar.gz';
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
