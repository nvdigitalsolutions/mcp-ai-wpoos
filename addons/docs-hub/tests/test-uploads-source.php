<?php
/**
 * Tests for the uploads/docs local source and the remote-import opt-in gate.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.5.0
 */

/**
 * Uploads source + remote toggle tests.
 */
class Test_Docs_Hub_Uploads_Source extends WP_UnitTestCase {

	/**
	 * Temporary uploads root for the tests.
	 *
	 * @var string
	 */
	private $test_uploads;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Define addon constants if not already set.
		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '0.5.0' );
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
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-remote-repo.php';

		// Point the uploads dir at a fresh temp directory.
		$this->test_uploads = sys_get_temp_dir() . '/nvoos-dh-uploads-' . uniqid();
		wp_mkdir_p( $this->test_uploads );
		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		// Fresh settings: uploads source on, remote import off.
		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
		$this->remove_directory( $this->test_uploads );
		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * Redirect wp_upload_dir() at the temp directory.
	 *
	 * @param array $uploads Upload dir info.
	 * @return array
	 */
	public function filter_upload_dir( $uploads ) {
		$uploads['basedir'] = $this->test_uploads;
		$uploads['baseurl'] = 'http://example.com/uploads';
		return $uploads;
	}

	/**
	 * The uploads source indexes .md and .txt recursively, and ignores
	 * other extensions.
	 */
	public function test_uploads_source_scans_md_and_txt_recursively() {
		wp_mkdir_p( $this->test_uploads . '/docs/sub' );
		file_put_contents( $this->test_uploads . '/docs/hello.md', '# Hello' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		file_put_contents( $this->test_uploads . '/docs/sub/notes.txt', 'Notes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		file_put_contents( $this->test_uploads . '/docs/ignored.pdf', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		$entries = ( new NV_oOS_Docs_Hub_Scanner() )->scan();
		$paths   = wp_list_pluck( $entries, 'relative_path' );

		$this->assertContains( 'hello.md', $paths );
		$this->assertContains( 'sub/notes.txt', $paths );
		$this->assertNotContains( 'ignored.pdf', $paths );

		foreach ( $entries as $entry ) {
			$this->assertEquals( 'uploads', $entry['source'] );
			$this->assertEquals( 'Uploaded Docs', $entry['plugin_name'] );
			$this->assertFileExists( $entry['path'] );
		}
	}

	/**
	 * Vendor/ build-noise directories and oversized files are excluded from
	 * the uploads source, same as every other local source.
	 */
	public function test_uploads_source_applies_exclusions_and_size_limit() {
		wp_mkdir_p( $this->test_uploads . '/docs/vendor' );
		file_put_contents( $this->test_uploads . '/docs/vendor/secret.md', '# Secret' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		file_put_contents( $this->test_uploads . '/docs/big.md', str_repeat( 'a', NV_oOS_Docs_Hub_Scanner::MAX_FILE_SIZE + 1 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		$entries = ( new NV_oOS_Docs_Hub_Scanner() )->scan();
		$paths   = wp_list_pluck( $entries, 'relative_path' );

		$this->assertNotContains( 'vendor/secret.md', $paths );
		$this->assertNotContains( 'big.md', $paths );
	}

	/**
	 * A symlinked subdirectory inside uploads/docs must not leak files from
	 * outside the docs root (symlink escape).
	 */
	public function test_uploads_source_does_not_follow_symlinks_outside_root() {
		wp_mkdir_p( $this->test_uploads . '/docs' );

		// External directory with a sentinel Markdown file.
		$external = sys_get_temp_dir() . '/nvoos-dh-external-' . uniqid();
		wp_mkdir_p( $external );
		file_put_contents( $external . '/sentinel.md', '# sentinel' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		// Symlink inside uploads/docs pointing at the external directory.
		$link = $this->test_uploads . '/docs/escape';
		if ( ! @symlink( $external, $link ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; skip on platforms without symlink support.
			$this->remove_directory( $external );
			$this->markTestSkipped( 'symlink() unavailable on this platform' );
		}

		$entries = ( new NV_oOS_Docs_Hub_Scanner() )->scan();
		$paths   = wp_list_pluck( $entries, 'relative_path' );

		unlink( $link );
		$this->remove_directory( $external );

		$this->assertNotContains( 'escape/sentinel.md', $paths );
	}

	/**
	 * With the opt-in toggle OFF, a rebuild makes zero HTTP requests even
	 * when remote repositories are configured.
	 */
	public function test_remote_toggle_off_makes_zero_http_requests() {
		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array(
				'sources'             => array( 'remote' ),
				'enable_remote_repos' => false,
				'remote_repos'        => array(
					array(
						'owner' => 'acme',
						'repo'  => 'widget',
						'ref'   => 'HEAD',
					),
				),
			)
		);

		$attempts = 0;
		$blocker  = static function () use ( &$attempts ) {
			++$attempts;
			return new WP_Error( 'http_blocked', 'blocked' );
		};
		add_filter( 'pre_http_request', $blocker, 10, 3 );

		$entries = ( new NV_oOS_Docs_Hub_Scanner() )->scan();

		remove_filter( 'pre_http_request', $blocker, 10 );

		$this->assertEquals( 0, $attempts, 'No HTTP requests may be made while the remote toggle is off' );
		$this->assertEquals( array(), $entries );
	}

	/**
	 * With the opt-in toggle ON and repos configured, the rebuild reaches
	 * out over HTTP (the fetch itself is blocked in tests, but the attempt
	 * proves the gate opens).
	 */
	public function test_remote_toggle_on_allows_requests() {
		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array(
				'sources'             => array( 'remote' ),
				'enable_remote_repos' => true,
				'remote_repos'        => array(
					array(
						'owner' => 'acme',
						'repo'  => 'widget',
						'ref'   => 'HEAD',
					),
				),
			)
		);

		$attempts = 0;
		$blocker  = static function () use ( &$attempts ) {
			++$attempts;
			return new WP_Error( 'http_blocked', 'blocked' );
		};
		add_filter( 'pre_http_request', $blocker, 10, 3 );

		( new NV_oOS_Docs_Hub_Scanner() )->scan();

		remove_filter( 'pre_http_request', $blocker, 10 );

		$this->assertGreaterThan( 0, $attempts );
	}

	/**
	 * Sanitizing settings keeps the dedicated toggle and the 'remote'
	 * source key in sync in both directions.
	 */
	public function test_sanitize_keeps_toggle_and_sources_in_sync() {
		require_once NVOOS_DOCS_HUB_PATH . 'includes/admin/class-nvoos-docs-hub-settings.php';

		// Toggle on: 'remote' is added to sources.
		$out = NV_oOS_Docs_Hub_Settings::sanitize_settings(
			array(
				'enable_remote_repos' => '1',
				'sources'             => array( 'uploads' ),
			)
		);
		$this->assertTrue( $out['enable_remote_repos'] );
		$this->assertContains( 'uploads', $out['sources'] );
		$this->assertContains( 'remote', $out['sources'] );

		// Toggle off (key absent from the form): 'remote' is removed.
		$out = NV_oOS_Docs_Hub_Settings::sanitize_settings(
			array(
				'sources' => array( 'uploads', 'remote' ),
			)
		);
		$this->assertFalse( $out['enable_remote_repos'] );
		$this->assertContains( 'uploads', $out['sources'] );
		$this->assertNotContains( 'remote', $out['sources'] );
	}

	/**
	 * Recursively delete a temp directory.
	 *
	 * @param string $dir Directory path.
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
