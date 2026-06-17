<?php
/**
 * Regression tests for the §A defensive-coercion hardening of the settings page.
 *
 * Covers:
 *  - NV_oOS_Docs_Hub_Plugin::get_settings() filters out non-array remote_repos rows.
 *  - NV_oOS_Docs_Hub_Settings::render_remote_repos() does not fatal on a malformed row.
 *  - NV_oOS_Docs_Hub_Settings::coerce_path_list() tolerates strings, nested arrays, and scalars.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.3.6
 */

/**
 * Defensive-coercion test case.
 */
class Test_Docs_Hub_Remote_Repos_Defensive extends WP_UnitTestCase {

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
		if ( ! defined( 'NVOOS_DOCS_HUB_URL' ) ) {
			define( 'NVOOS_DOCS_HUB_URL', 'http://example.com/wp-content/plugins/nvoos-docs-hub/' );
		}
		if ( ! defined( 'NVOOS_DOCS_HUB_FILE' ) ) {
			define( 'NVOOS_DOCS_HUB_FILE', NVOOS_DOCS_HUB_PATH . 'nvoos-docs-hub.php' );
		}

		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-plugin.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-scanner.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-remote-repo.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cache.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-indexer.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-state.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-job.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-pipeline.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/rest/class-nvoos-docs-hub-rest.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/admin/class-nvoos-docs-hub-settings.php';
	}

	/**
	 * Reset persisted settings between tests.
	 */
	public function tearDown(): void {
		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
		parent::tearDown();
	}

	/**
	 * Get_settings() must drop non-array rows from remote_repos so the
	 * indexer and renderer never see a malformed row.
	 */
	public function test_get_settings_filters_malformed_rows() {
		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array(
				'remote_repos' => array(
					array(
						'owner' => 'acme',
						'repo'  => 'widget',
					),
					'a bare string',           // malformed: must be filtered.
					null,                      // malformed: must be filtered.
					42,                        // malformed: must be filtered.
					array(
						'owner' => 'beta',
						'repo'  => 'project',
					),
				),
			)
		);

		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$this->assertCount( 2, $settings['remote_repos'] );
		$this->assertEquals( 'acme', $settings['remote_repos'][0]['owner'] );
		$this->assertEquals( 'beta', $settings['remote_repos'][1]['owner'] );
	}

	/**
	 * Get_settings() must also handle remote_repos being stored as a non-array
	 * value entirely (e.g. a string from a corrupted import).
	 */
	public function test_get_settings_handles_non_array_remote_repos() {
		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array(
				'remote_repos' => 'totally not an array',
			)
		);

		$settings = NV_oOS_Docs_Hub_Plugin::get_settings();
		$this->assertSame( array(), $settings['remote_repos'] );
	}

	/**
	 * Renderer's loop must survive a non-array row even when the
	 * defensive filter in get_settings() is bypassed (e.g. callers that
	 * pass settings through their own pipeline). Render is captured
	 * via output buffering and the absence of a fatal is the assertion.
	 */
	public function test_render_remote_repos_survives_malformed_row() {
		// Inject directly into the option so a future caller that reads it raw
		// would see the malformed row. The renderer reads via get_settings(),
		// which now filters — but we still want the inline guard to function
		// as defence in depth, so we exercise both layers.
		update_option(
			NV_oOS_Docs_Hub_Plugin::OPTION_KEY,
			array(
				'remote_repos' => array(
					'malformed scalar row',
					array(
						'owner'          => 'acme',
						'repo'           => 'widget',
						'selected_paths' => "docs/intro.md\nguides/",
						'excluded_paths' => array( 'CHANGELOG.md' ),
					),
				),
			)
		);

		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		ob_start();
		NV_oOS_Docs_Hub_Settings::render_remote_repos();
		$html = ob_get_clean();

		$this->assertIsString( $html );
		$this->assertStringContainsString( 'nvoos-dh-remote-repos-wrap', $html );
		// The valid row's owner must still be rendered.
		$this->assertStringContainsString( 'value="acme"', $html );
	}

	/**
	 * Coerce_path_list() must accept a newline string and split on newlines.
	 */
	public function test_coerce_path_list_accepts_newline_string() {
		$result = NV_oOS_Docs_Hub_Settings::coerce_path_list( "docs/intro.md\nguides/\nREADME.md" );
		$this->assertEquals(
			array( 'docs/intro.md', 'guides/', 'README.md' ),
			$result
		);
	}

	/**
	 * Coerce_path_list() must drop nested arrays and trim whitespace.
	 */
	public function test_coerce_path_list_drops_nested_and_trims() {
		$result = NV_oOS_Docs_Hub_Settings::coerce_path_list(
			array(
				'  docs/intro.md  ',
				array( 'should be dropped' ),
				'',
				'docs/intro.md', // duplicate.
				'guides/',
			)
		);
		$this->assertEquals(
			array( 'docs/intro.md', 'guides/' ),
			$result
		);
	}

	/**
	 * Coerce_path_list() must return an empty array for completely bogus input.
	 */
	public function test_coerce_path_list_returns_empty_for_bogus_input() {
		$this->assertSame( array(), NV_oOS_Docs_Hub_Settings::coerce_path_list( null ) );
		$this->assertSame( array(), NV_oOS_Docs_Hub_Settings::coerce_path_list( 42 ) );
		$this->assertSame( array(), NV_oOS_Docs_Hub_Settings::coerce_path_list( new stdClass() ) );
	}
}
