<?php
/**
 * Tests for the Docs Hub rebuild job update/activation handling.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.4.1
 */

/**
 * Rebuild job update-handling tests.
 *
 * Covers:
 *  - Plugin-update / activation scoping (only NV-oOS-related plugins
 *    invalidate the docs cache).
 *  - Cache invalidation now enqueues an async rebuild instead of only
 *    clearing.
 *  - The admin_init version guard rebuilds once when the manifest was
 *    built against an older plugin version and stays quiet afterwards.
 */
class Test_Docs_Hub_Rebuild_Job extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '0.4.1' );
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
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-indexer.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/class-nvoos-docs-hub-cache.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-state.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-job.php';
		require_once NVOOS_DOCS_HUB_PATH . 'includes/jobs/class-nvoos-docs-hub-rebuild-pipeline.php';

		// Reset persistent state between tests.
		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
		NV_oOS_Docs_Hub_Rebuild_State::reset();
		( new NV_oOS_Docs_Hub_Cache() )->clear();

		// The version guard requires an administrator.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		delete_option( NV_oOS_Docs_Hub_Plugin::OPTION_KEY );
		NV_oOS_Docs_Hub_Rebuild_State::reset();
		( new NV_oOS_Docs_Hub_Cache() )->clear();
	}

	/**
	 * Test that only NV-oOS-related plugin basenames count as
	 * docs-affecting.
	 *
	 * @return void
	 */
	public function test_is_docs_related_plugin() {
		$this->assertTrue( NV_oOS_Docs_Hub_Rebuild_Job::is_docs_related_plugin( array( 'mcp-ai-wpoos/mcp-ai-wpoos.php' ) ) );
		$this->assertTrue( NV_oOS_Docs_Hub_Rebuild_Job::is_docs_related_plugin( array( 'mcp-ai-wpoos-pro/mcp-ai-wpoos-pro.php' ) ) );
		$this->assertTrue( NV_oOS_Docs_Hub_Rebuild_Job::is_docs_related_plugin( array( 'mcp-ai-wpoos/addons/docs-hub/nvoos-docs-hub.php' ) ) );
		$this->assertTrue( NV_oOS_Docs_Hub_Rebuild_Job::is_docs_related_plugin( array( 'yoast/yoast.php', 'mcp-ai-wpoos/mcp-ai-wpoos.php' ) ) );
		$this->assertFalse( NV_oOS_Docs_Hub_Rebuild_Job::is_docs_related_plugin( array( 'yoast/yoast.php' ) ) );
		$this->assertFalse( NV_oOS_Docs_Hub_Rebuild_Job::is_docs_related_plugin( array() ) );
	}

	/**
	 * Test that updating an unrelated plugin leaves the manifest intact.
	 *
	 * @return void
	 */
	public function test_handle_upgrade_ignores_unrelated_plugins() {
		$cache    = new NV_oOS_Docs_Hub_Cache();
		$manifest = array(
			'version'     => NVOOS_DOCS_HUB_VERSION,
			'total_pages' => 7,
		);
		$this->assertTrue( $cache->set_manifest( $manifest ) );

		NV_oOS_Docs_Hub_Rebuild_Job::handle_upgrade(
			null,
			array(
				'type'    => 'plugin',
				'plugins' => array( 'yoast/yoast.php' ),
			)
		);

		$reloaded = $cache->get_manifest();
		$this->assertIsArray( $reloaded );
		$this->assertEquals( 7, $reloaded['total_pages'] );
	}

	/**
	 * Test that updating an NV-oOS plugin clears the cache AND enqueues
	 * an async rebuild.
	 *
	 * @return void
	 */
	public function test_handle_upgrade_clears_and_rebuilds_for_nvoos_plugin() {
		$cache    = new NV_oOS_Docs_Hub_Cache();
		$manifest = array(
			'version'     => '0.0.1-stale',
			'total_pages' => 7,
		);
		$this->assertTrue( $cache->set_manifest( $manifest ) );

		NV_oOS_Docs_Hub_Rebuild_Job::handle_upgrade(
			null,
			array(
				'type'    => 'plugin',
				'plugins' => array( 'mcp-ai-wpoos/mcp-ai-wpoos.php' ),
			)
		);

		// The stale manifest must be gone (cache cleared).
		$this->assertFalse( ( new NV_oOS_Docs_Hub_Cache() )->get_manifest() );

		// And a chunked rebuild must have been enqueued (the enqueue call
		// runs the cheap scan phase synchronously).
		$this->assertTrue( NV_oOS_Docs_Hub_Rebuild_State::is_running() );
	}

	/**
	 * Test that activating an unrelated plugin leaves the manifest intact.
	 *
	 * @return void
	 */
	public function test_handle_plugin_change_ignores_unrelated_plugins() {
		$cache    = new NV_oOS_Docs_Hub_Cache();
		$manifest = array(
			'version'     => NVOOS_DOCS_HUB_VERSION,
			'total_pages' => 3,
		);
		$this->assertTrue( $cache->set_manifest( $manifest ) );

		NV_oOS_Docs_Hub_Rebuild_Job::handle_plugin_change( 'yoast/yoast.php' );

		$reloaded = ( new NV_oOS_Docs_Hub_Cache() )->get_manifest();
		$this->assertIsArray( $reloaded );
		$this->assertEquals( 3, $reloaded['total_pages'] );
	}

	/**
	 * Test the base plugin updater notice clears and rebuilds for the
	 * base plugin, and ignores unrelated basenames.
	 *
	 * @return void
	 */
	public function test_handle_plugin_update_notice() {
		$cache = new NV_oOS_Docs_Hub_Cache();
		$this->assertTrue(
			$cache->set_manifest(
				array(
					'version'     => NVOOS_DOCS_HUB_VERSION,
					'total_pages' => 5,
				)
			)
		);

		NV_oOS_Docs_Hub_Rebuild_Job::handle_plugin_update_notice( 'some-other/some-other.php' );
		$this->assertIsArray( $cache->get_manifest() );

		NV_oOS_Docs_Hub_Rebuild_Job::handle_plugin_update_notice( 'mcp-ai-wpoos/mcp-ai-wpoos.php' );
		$this->assertFalse( ( new NV_oOS_Docs_Hub_Cache() )->get_manifest() );
		$this->assertTrue( NV_oOS_Docs_Hub_Rebuild_State::is_running() );
	}

	/**
	 * Test the admin_init version guard rebuilds when the manifest was
	 * built against an older version and stays quiet once versions match.
	 *
	 * @return void
	 */
	public function test_maybe_rebuild_after_version_change() {
		$current_base = defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : '';

		$cache = new NV_oOS_Docs_Hub_Cache();
		$this->assertTrue(
			$cache->set_manifest(
				array(
					'version'      => '0.0.0-old',
					'base_version' => '0.0.0-old',
					'total_pages'  => 1,
				)
			)
		);

		NV_oOS_Docs_Hub_Plugin::maybe_rebuild_after_version_change();

		// Stale manifest cleared, rebuild enqueued.
		$this->assertFalse( ( new NV_oOS_Docs_Hub_Cache() )->get_manifest() );
		$this->assertTrue( NV_oOS_Docs_Hub_Rebuild_State::is_running() );

		// A manifest built against the current versions must be kept.
		$this->assertTrue(
			$cache->set_manifest(
				array(
					'version'      => NVOOS_DOCS_HUB_VERSION,
					'base_version' => $current_base,
					'total_pages'  => 1,
				)
			)
		);

		NV_oOS_Docs_Hub_Plugin::maybe_rebuild_after_version_change();

		$reloaded = ( new NV_oOS_Docs_Hub_Cache() )->get_manifest();
		$this->assertIsArray( $reloaded );
		$this->assertEquals( NVOOS_DOCS_HUB_VERSION, $reloaded['version'] );
	}
}
