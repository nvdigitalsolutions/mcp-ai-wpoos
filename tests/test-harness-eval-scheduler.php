<?php
/**
 * Tests for the Layer G harness eval scheduler.
 *
 * Validates the no-suite, missing-generator, and success paths plus the
 * per-tick cap. Does not exercise actual model invocations — the
 * generator is a closure that returns deterministic outputs.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only verifier fixture shares this file with its suite.
if ( ! class_exists( 'WP_MCP_AI_Test_Harness_Trivial_Verifier' ) ) {
	/**
	 * Always-passes verifier used purely to satisfy the eval-case
	 * `verifier_slug` requirement in scheduler tests.
	 */
	class WP_MCP_AI_Test_Harness_Trivial_Verifier extends WP_MCP_AI_Verifier_Base {
		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->slug                 = 'wp_mcp_ai_test_harness_trivial';
			$this->kind                 = 'rule';
			$this->label                = 'Trivial';
			$this->independence_profile = array(
				'disallowed_providers' => array(),
				'disallowed_models'    => array(),
				'disallowed_tools'     => array(),
				'allowed_domains'      => array(),
			);
		}
		/**
		 * Always pass.
		 *
		 * @param array $subject Verification subject.
		 * @param array $context Verification context.
		 * @return array Pass result.
		 */
		public function verify( array $subject, array $context = array() ) {
			return $this->result_pass( 1.0, 1.0, array() );
		}
	}
}

/**
 * Layer G eval scheduler tests.
 */
class Test_Harness_Eval_Scheduler extends WP_UnitTestCase {

	/**
	 * Assistant post ID created for the current test.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Set up fixtures and registries for each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Profile::save() gates on current_user_can( 'edit_post' ), so the
		// suite must run as an authenticated administrator; without this the
		// save silently fails and no profile meta is ever written.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Harness Eval Scheduler Test',
			)
		);

		// Reset suite + verifier registries so each test gets a clean slate.
		if ( method_exists( 'WP_MCP_AI_Eval_Suite_Registry', 'reset_instance' ) ) {
			WP_MCP_AI_Eval_Suite_Registry::reset_instance();
		}
		if ( method_exists( 'WP_MCP_AI_Verifier_Registry', 'reset_instance' ) ) {
			WP_MCP_AI_Verifier_Registry::reset_instance();
		}
		WP_MCP_AI_Verifier_Registry::get_instance()->register( new WP_MCP_AI_Test_Harness_Trivial_Verifier() );

		// Strip any leftover generator filter from previous tests.
		remove_all_filters( 'wp_mcp_ai_harness_eval_generator' );
	}

	/**
	 * Tear down test state.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_harness_eval_generator' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Running an unknown suite returns a WP_Error.
	 */
	public function test_unknown_suite_returns_wp_error() {
		$result = WP_MCP_AI_Harness_Eval_Scheduler::run_suite_for_assistant( $this->assistant_id, 'no_such_suite' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_harness_eval_unknown_suite', $result->get_error_code() );
	}

	/**
	 * A registered suite without a generator returns a skip error.
	 */
	public function test_missing_generator_returns_skip_error() {
		$this->register_minimal_suite( 'demo_suite' );

		$result = WP_MCP_AI_Harness_Eval_Scheduler::run_suite_for_assistant( $this->assistant_id, 'demo_suite' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_harness_eval_no_generator', $result->get_error_code() );
	}

	/**
	 * A successful run records run summaries and per-assistant meta.
	 */
	public function test_success_path_records_run_and_last_run_meta() {
		$this->register_minimal_suite( 'demo_suite' );

		add_filter(
			'wp_mcp_ai_harness_eval_generator',
			static function () {
				return static function () {
					return array( 'output' => 'hello world' );
				};
			}
		);

		$report = WP_MCP_AI_Harness_Eval_Scheduler::run_suite_for_assistant( $this->assistant_id, 'demo_suite' );
		$this->assertIsArray( $report );
		$this->assertArrayHasKey( 'summary', $report );

		// Per-assistant meta surfaced for the admin UI.
		$last = WP_MCP_AI_Harness_Eval_Scheduler::get_last_runs( $this->assistant_id );
		$this->assertArrayHasKey( 'demo_suite', $last );
		$this->assertGreaterThan( 0, $last['demo_suite']['started_at'] );
		$this->assertIsArray( $last['demo_suite']['summary'] );

		// Suite-scoped trend history (consumed by the regression detector).
		$store = new WP_MCP_AI_Eval_Run_Store();
		$runs  = $store->get_all( 'demo_suite' );
		$this->assertCount( 1, $runs );
	}

	/**
	 * Invalid arguments return a WP_Error.
	 */
	public function test_invalid_args_returns_wp_error() {
		$result = WP_MCP_AI_Harness_Eval_Scheduler::run_suite_for_assistant( 0, 'demo_suite' );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_harness_eval_invalid_args', $result->get_error_code() );
	}

	/**
	 * Tick skips assistants without an enabled profile.
	 */
	public function test_tick_skips_assistants_without_enabled_profile() {
		$this->register_minimal_suite( 'demo_suite' );
		// Profile is enabled=false but evals_enabled is populated — must skip.
		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled'       => false,
				'evals_enabled' => array( 'demo_suite' ),
			)
		);

		add_filter(
			'wp_mcp_ai_harness_eval_generator',
			static function () {
				return static function () {
					return array( 'output' => 'x' );
				};
			}
		);

		$summary = WP_MCP_AI_Harness_Eval_Scheduler::tick();
		$this->assertSame( 0, $summary['processed'] );
		$this->assertSame( 0, $summary['errors'] );
	}

	/**
	 * Tick processes enabled assistants that have a generator.
	 */
	public function test_tick_processes_enabled_assistants_with_generator() {
		$this->register_minimal_suite( 'demo_suite' );
		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled'       => true,
				'evals_enabled' => array( 'demo_suite' ),
			)
		);

		add_filter(
			'wp_mcp_ai_harness_eval_generator',
			static function () {
				return static function () {
					return array( 'output' => 'x' );
				};
			}
		);

		$summary = WP_MCP_AI_Harness_Eval_Scheduler::tick();
		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame( 0, $summary['skipped'] );
		$this->assertSame( 0, $summary['errors'] );
	}

	/**
	 * Tick counts a missing generator as skipped.
	 */
	public function test_tick_counts_missing_generator_as_skip() {
		$this->register_minimal_suite( 'demo_suite' );
		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled'       => true,
				'evals_enabled' => array( 'demo_suite' ),
			)
		);

		// No generator filter wired up — must increment `skipped`,
		// never `errors`.
		$summary = WP_MCP_AI_Harness_Eval_Scheduler::tick();
		$this->assertSame( 0, $summary['processed'] );
		$this->assertSame( 1, $summary['skipped'] );
		$this->assertSame( 0, $summary['errors'] );
	}

	/**
	 * Metabox save persists evals_enabled from the form.
	 */
	public function test_metabox_save_persists_evals_enabled_from_form() {
		$this->register_minimal_suite( 'demo_suite' );

		// Simulate a form save where the suite checkbox was ticked.
		WP_MCP_AI_Harness_Profile::save(
			$this->assistant_id,
			array(
				'enabled'       => true,
				'evals_enabled' => array( 'demo_suite', 'unknown_but_well_formed_slug' ),
			)
		);

		$profile = WP_MCP_AI_Harness_Profile::get( $this->assistant_id );
		$this->assertContains( 'demo_suite', $profile['evals_enabled'] );
		// The sanitizer accepts well-formed slugs even if the suite isn't
		// registered yet (suites can be registered late) — only invalid
		// non-slug input is dropped.
		$this->assertContains( 'unknown_but_well_formed_slug', $profile['evals_enabled'] );
	}

	/**
	 * Register a minimal one-case suite for testing.
	 *
	 * @param string $slug Suite slug.
	 * @return void
	 */
	private function register_minimal_suite( $slug ) {
		$registry = WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$registry->register(
			array(
				'slug'        => $slug,
				'label'       => 'Demo Suite',
				'description' => 'Minimal suite for scheduler tests.',
				'cases'       => array(
					array(
						'slug'          => 'case_1',
						'verifier_slug' => 'wp_mcp_ai_test_harness_trivial',
						'expected'      => 'hello',
					),
				),
			)
		);
	}
}
