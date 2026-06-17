<?php
/**
 * Regression tests for the §G force-clears-cache behaviour and §C SSRF helper.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.3.6
 */

/**
 * Force-clear + resolve_public_ip test case.
 */
class Test_Docs_Hub_Force_Clears_Cache extends WP_UnitTestCase {

	/**
	 * Bootstrap the addon classes before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '0.3.6' );
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
	 * Clear_local_cache_for_files() must delete files whose key matches
	 * (owner, repo, ref, path) and leave unrelated files alone.
	 */
	public function test_clear_local_cache_for_files_deletes_matching_files_only() {
		$repo = new NV_oOS_Docs_Hub_Remote_Repo();

		// Seed two cache files: one we will clear, one for an unrelated repo.
		$key_target  = $this->seed_cache_file( $repo, 'acme', 'widget', 'abc123', 'docs/intro.md', 'TARGET' );
		$key_keep    = $this->seed_cache_file( $repo, 'beta', 'other', 'def456', 'README.md', 'KEEP' );
		$path_target = $repo->local_cache_path( $key_target );
		$path_keep   = $repo->local_cache_path( $key_keep );

		$this->assertFileExists( $path_target );
		$this->assertFileExists( $path_keep );

		$deleted = $repo->clear_local_cache_for_files(
			'acme',
			'widget',
			'abc123',
			array(
				array(
					'path' => 'docs/intro.md',
					'size' => 100,
				),
				array(
					'path' => 'docs/missing.md', // not seeded — must not blow up.
					'size' => 50,
				),
			)
		);

		$this->assertEquals( 1, $deleted );
		$this->assertFileDoesNotExist( $path_target );
		$this->assertFileExists( $path_keep, 'Unrelated repo cache must survive.' );

		// Cleanup.
		@unlink( $path_keep ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Clear_local_cache_for_files() must tolerate non-array input gracefully.
	 */
	public function test_clear_local_cache_for_files_tolerates_bad_input() {
		$repo = new NV_oOS_Docs_Hub_Remote_Repo();
		$this->assertSame( 0, $repo->clear_local_cache_for_files( 'a', 'b', 'c', 'not an array' ) );
		$this->assertSame( 0, $repo->clear_local_cache_for_files( 'a', 'b', 'c', array() ) );
		$this->assertSame(
			0,
			$repo->clear_local_cache_for_files(
				'a',
				'b',
				'c',
				array( array( 'size' => 1 ) ) // missing path.
			)
		);
	}

	/**
	 * Resolve_public_ip() must reject IP literals in private/reserved ranges.
	 *
	 * Uses reflection because the helper is private — exercising it directly
	 * is the cleanest way to assert IPv6 ULA rejection without making real
	 * DNS calls.
	 */
	public function test_resolve_public_ip_rejects_private_ipv4_literal() {
		$result = $this->invoke_resolve( '10.0.0.1' );
		$this->assertWPError( $result );
		$this->assertEquals( 'nvoos_docs_hub_ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * IPv6 unique-local-address (fc00::/7) literals must be rejected.
	 */
	public function test_resolve_public_ip_rejects_ipv6_ula_literal() {
		$result = $this->invoke_resolve( 'fd00::1' );
		$this->assertWPError( $result );
		$this->assertEquals( 'nvoos_docs_hub_ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * IPv6 loopback (::1) must be rejected as reserved.
	 */
	public function test_resolve_public_ip_rejects_ipv6_loopback() {
		$result = $this->invoke_resolve( '::1' );
		$this->assertWPError( $result );
		$this->assertEquals( 'nvoos_docs_hub_ssrf_blocked', $result->get_error_code() );
	}

	/**
	 * Public IPv4 literal must be returned unchanged.
	 */
	public function test_resolve_public_ip_passes_through_public_literal() {
		$result = $this->invoke_resolve( '140.82.121.4' ); // GitHub-ish public IP.
		$this->assertSame( '140.82.121.4', $result );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * Write a synthetic cache file and return its key.
	 *
	 * @param NV_oOS_Docs_Hub_Remote_Repo $repo     Repo instance.
	 * @param string                      $owner    Owner.
	 * @param string                      $name     Repo name.
	 * @param string                      $ref      Resolved ref.
	 * @param string                      $filepath File path within the repo.
	 * @param string                      $contents Cache contents.
	 * @return string Cache key.
	 */
	private function seed_cache_file( $repo, $owner, $name, $ref, $filepath, $contents ) {
		$ref_method = new ReflectionMethod( $repo, 'local_cache_key' );
		$ref_method->setAccessible( true );
		$key  = $ref_method->invoke( $repo, $owner, $name, $ref, $filepath );
		$path = $repo->local_cache_path( $key );
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $key;
	}

	/**
	 * Invoke the private resolve_public_ip() helper via reflection.
	 *
	 * @param string $host Host argument.
	 * @return string|WP_Error
	 */
	private function invoke_resolve( $host ) {
		$repo = new NV_oOS_Docs_Hub_Remote_Repo();
		$ref  = new ReflectionMethod( $repo, 'resolve_public_ip' );
		$ref->setAccessible( true );
		return $ref->invoke( $repo, $host );
	}
}
