<?php
/**
 * Tests for the chunked-rebuild pipeline, vendor exclusion, and source priority.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.2.0
 */

/**
 * Chunked rebuild tests.
 *
 * Covers:
 *  - Vendor / node_modules / .git pruning during scan against the real repo.
 *  - Source priority awarding the canonical `readme` slug to the
 *    plugin-root README over an addon README.
 *  - Sync rebuild marks the rebuild state PHASE_DONE.
 *  - The chunked pipeline transitions through every phase to PHASE_DONE
 *    when ticks are run inline.
 *  - Cancel + resume state transitions.
 */
class Test_Docs_Hub_Rebuild_Chunked extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'NVOOS_DOCS_HUB_VERSION' ) ) {
			define( 'NVOOS_DOCS_HUB_VERSION', '0.2.0' );
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
		NV_oOS_Docs_Hub_Rebuild_State::reset();
		( new NV_oOS_Docs_Hub_Cache() )->clear();
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		parent::tearDown();
		NV_oOS_Docs_Hub_Rebuild_State::reset();
		( new NV_oOS_Docs_Hub_Cache() )->clear();
	}

	/**
	 * Vendor / node_modules / .git directories are pruned by default
	 * when scanning the real repository. This is the regression test
	 * for "vendor folders mixing in".
	 *
	 * @return void
	 */
	public function test_default_exclusion_drops_vendor_and_node_modules() {
		$entries = ( new NV_oOS_Docs_Hub_Scanner() )->scan();
		$paths   = array_column( $entries, 'path' );

		foreach ( $paths as $p ) {
			$this->assertStringNotContainsString(
				DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
				$p,
				'vendor/ leaked into the index: ' . $p
			);
			$this->assertStringNotContainsString(
				DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR,
				$p,
				'node_modules/ leaked into the index: ' . $p
			);
			$this->assertStringNotContainsString(
				DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
				$p,
				'.git/ leaked into the index: ' . $p
			);
		}
	}

	/**
	 * Plugin-root README outranks an addon README and owns the canonical
	 * `readme` slug. This is the regression test for "main CHANGELOG /
	 * README should win".
	 *
	 * @return void
	 */
	public function test_plugin_root_readme_wins_canonical_slug() {
		$entries = array(
			array(
				'path'          => __FILE__,
				'source'        => 'addons',
				'plugin_name'   => 'Foo Addon',
				'relative_path' => 'README.md',
			),
			array(
				'path'          => __FILE__,
				'source'        => 'root',
				'plugin_name'   => 'Repository',
				'relative_path' => 'README.md',
			),
		);

		$indexer = new NV_oOS_Docs_Hub_Indexer();
		$indexer->build_manifest( $entries, false );
		$slug_map = $indexer->get_slug_map();

		$this->assertArrayHasKey( 'readme', $slug_map, 'Canonical `readme` slug must exist.' );
		$this->assertSame(
			'root',
			$slug_map['readme']['source'],
			'Plugin-root README must own the canonical `readme` slug.'
		);

		// Addon collision should be suffixed.
		$collision = array_filter(
			array_keys( $slug_map ),
			static function ( $slug ) {
				return preg_match( '/^readme-\d+$/', $slug );
			}
		);
		$this->assertNotEmpty( $collision, 'Addon README must take a suffixed slug.' );
	}

	/**
	 * Sync rebuild populates the rebuild state with PHASE_DONE.
	 *
	 * @return void
	 */
	public function test_sync_rebuild_marks_state_done() {
		$result = NV_oOS_Docs_Hub_Rebuild_Job::run();
		$this->assertTrue( $result['success'], 'Sync rebuild should succeed.' );

		$state = NV_oOS_Docs_Hub_Rebuild_State::get();
		$this->assertSame(
			NV_oOS_Docs_Hub_Rebuild_State::PHASE_DONE,
			$state['phase'],
			'Sync rebuild should leave the state in PHASE_DONE.'
		);
		$this->assertGreaterThan( 0, $state['pages'] );
	}

	/**
	 * The chunked pipeline transitions through every phase to PHASE_DONE
	 * when ticks are run inline. Provides safety against the
	 * "single-shot rebuild crashes on a large repo" regression.
	 *
	 * @return void
	 */
	public function test_chunked_pipeline_completes_via_inline_ticks() {
		// Tiny chunk size so multiple ticks are required.
		$chunk_cb = static function () {
			return 5;
		};
		add_filter( 'nvoos_docs_hub_rebuild_chunk_size', $chunk_cb );

		// Generous wall-clock budget for CI determinism.
		$budget_cb = static function () {
			return 60;
		};
		add_filter( 'nvoos_docs_hub_rebuild_tick_budget', $budget_cb );

		NV_oOS_Docs_Hub_Rebuild_Pipeline::enqueue();

		// Drive ticks inline until terminal.
		$max_ticks = 500;
		while ( $max_ticks-- > 0 ) {
			$state = NV_oOS_Docs_Hub_Rebuild_State::get();
			if ( ! NV_oOS_Docs_Hub_Rebuild_State::is_running( $state ) ) {
				break;
			}
			NV_oOS_Docs_Hub_Rebuild_Pipeline::tick();
		}

		remove_filter( 'nvoos_docs_hub_rebuild_chunk_size', $chunk_cb );
		remove_filter( 'nvoos_docs_hub_rebuild_tick_budget', $budget_cb );

		$state = NV_oOS_Docs_Hub_Rebuild_State::get();
		$this->assertSame(
			NV_oOS_Docs_Hub_Rebuild_State::PHASE_DONE,
			$state['phase'],
			'Chunked pipeline should reach PHASE_DONE.'
		);
		$this->assertGreaterThan( 0, $state['pages'] );
	}

	/**
	 * Cancel sets the phase to PHASE_CANCELED.
	 *
	 * @return void
	 */
	public function test_cancel_marks_state_canceled() {
		NV_oOS_Docs_Hub_Rebuild_Pipeline::enqueue();
		NV_oOS_Docs_Hub_Rebuild_Pipeline::cancel();

		$state = NV_oOS_Docs_Hub_Rebuild_State::get();
		$this->assertSame( NV_oOS_Docs_Hub_Rebuild_State::PHASE_CANCELED, $state['phase'] );
	}

	/**
	 * Resume from PHASE_FAILED moves the state back into a runnable phase
	 * and clears the last error.
	 *
	 * @return void
	 */
	public function test_resume_from_failed_returns_to_runnable_phase() {
		NV_oOS_Docs_Hub_Rebuild_Pipeline::enqueue();

		// Simulate a fault mid-pages.
		NV_oOS_Docs_Hub_Rebuild_State::update(
			array(
				'phase'      => NV_oOS_Docs_Hub_Rebuild_State::PHASE_FAILED,
				'last_error' => 'simulated fault',
			)
		);

		NV_oOS_Docs_Hub_Rebuild_Pipeline::resume();
		$state = NV_oOS_Docs_Hub_Rebuild_State::get();

		$this->assertContains(
			$state['phase'],
			array(
				NV_oOS_Docs_Hub_Rebuild_State::PHASE_SCAN,
				NV_oOS_Docs_Hub_Rebuild_State::PHASE_PAGES,
				NV_oOS_Docs_Hub_Rebuild_State::PHASE_LINKS,
				NV_oOS_Docs_Hub_Rebuild_State::PHASE_SEARCH,
				NV_oOS_Docs_Hub_Rebuild_State::PHASE_FINALIZE,
				NV_oOS_Docs_Hub_Rebuild_State::PHASE_DONE,
			),
			'Resume must return the state to a runnable phase, got: ' . $state['phase']
		);
		$this->assertSame( '', $state['last_error'] );
	}
}
