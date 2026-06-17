<?php
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Tests for Layer H — Pro fine-tune curriculum exporter.
 *
 * Exercises tool registration, schema validity, dry-run counts, live
 * JSONL line shape, per-case skipping (no-input, no-expected, too-large),
 * file write, and the capability gate.  Does not touch the filesystem for
 * dry-runs; the live-write path is exercised via a mock upload_dir filter.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Harness_Curriculum_Trivial_Verifier' ) ) {
	/**
	 * Always-passes verifier used to satisfy eval-case verifier_slug.
	 */
	class WP_MCP_AI_Harness_Curriculum_Trivial_Verifier extends WP_MCP_AI_Verifier_Base {
		/**
		 * Construct always-passes verifier.
		 *
		 * @inheritdoc
		 */
		public function __construct() {
			$this->slug                 = 'harness_curriculum_trivial';
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
		 * Verify always passes.
		 *
		 * @param array $subject Subject data.
		 * @param array $context  Context data.
		 * @return array
		 */
		public function verify( array $subject, array $context = array() ) {
			return $this->result_pass( 1.0, 1.0, array() );
		}
	}
}

/**
 * Layer H curriculum-exporter tests.
 */
class Test_WP_MCP_AI_Pro_Harness_Fine_Tune_Curriculum extends WP_UnitTestCase {

	/**
	 * Shared assistant post ID.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Temp dir for file-write tests.
	 *
	 * @var string
	 */
	private $tmp_upload_dir;

	/** Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Curriculum Test Assistant',
			)
		);

		// Reset suite registry so tests are isolated.
		if ( method_exists( 'WP_MCP_AI_Eval_Suite_Registry', 'reset_instance' ) ) {
			WP_MCP_AI_Eval_Suite_Registry::reset_instance();
		}
		if ( method_exists( 'WP_MCP_AI_Verifier_Registry', 'reset_instance' ) ) {
			WP_MCP_AI_Verifier_Registry::reset_instance();
		}
		WP_MCP_AI_Verifier_Registry::get_instance()->register( new WP_MCP_AI_Harness_Curriculum_Trivial_Verifier() );

		// Temp directory for file-write assertions.
		$this->tmp_upload_dir = sys_get_temp_dir() . '/wp-mcp-ai-harness-curriculum-test-' . uniqid();
		wp_mkdir_p( $this->tmp_upload_dir );
	}

	/** Tear down test.
	 */
	public function tearDown(): void {
		// Clean up temp files.
		if ( is_dir( $this->tmp_upload_dir ) ) {
			array_map( 'unlink', glob( $this->tmp_upload_dir . '/*' ) );
			rmdir( $this->tmp_upload_dir );
		}
		if ( method_exists( 'WP_MCP_AI_Eval_Suite_Registry', 'reset_instance' ) ) {
			WP_MCP_AI_Eval_Suite_Registry::reset_instance();
		}
		remove_all_filters( 'upload_dir' );
		remove_all_filters( 'wp_mcp_ai_pro_curriculum_per_case_char_cap' );
		parent::tearDown();
	}

	/**
	 * Load the tool class if not already loaded.
	 */
	private function load_tool() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/harness/class-wp-mcp-ai-tool-export-fine-tune-curriculum.php';
		}
	}

	/**
	 * Build a minimal suite registered in the suite registry.
	 *
	 * @param string $suite_slug Suite slug.
	 * @param array  $cases      Array of ['slug','input','expected'] maps.
	 * @return WP_MCP_AI_Eval_Suite
	 */
	private function make_suite( $suite_slug, array $cases ) {
		$suite = new WP_MCP_AI_Eval_Suite(
			array(
				'slug'  => $suite_slug,
				'label' => 'Test Suite ' . $suite_slug,
			)
		);
		foreach ( $cases as $c ) {
			$suite->add_case(
				new WP_MCP_AI_Eval_Case(
					array(
						'slug'          => $c['slug'],
						'label'         => $c['slug'],
						'input'         => $c['input'],
						'expected'      => $c['expected'],
						'verifier_slug' => 'harness_curriculum_trivial',
					)
				)
			);
		}
		WP_MCP_AI_Eval_Suite_Registry::get_instance()->register( $suite );
		return $suite;
	}

	/**
	 * Force `wp_upload_dir()` to return the temp dir so write tests
	 * don't touch the real filesystem.
	 *
	 * @param string $dir Temporary directory path.
	 * @return void
	 */
	private function redirect_upload_dir( $dir ) {
		$filter = static function () use ( $dir ) {
			return array(
				'basedir' => $dir,
				'baseurl' => 'https://example.com/wp-content/uploads',
			);
		};
		add_filter( 'upload_dir', $filter );
	}

	// ─────────────────────────────────────────────────────────
	// Registrations.
	// ─────────────────────────────────────────────────────────

	/**
	 * Tool class exists and is loadable.
	 */
	public function test_tool_class_exists() {
		$this->load_tool();
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum' ) );
	}

	/**
	 * Tool implements the expected interfaces.
	 */
	public function test_tool_implements_interfaces() {
		$this->load_tool();
		$tool = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();

		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $tool );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->load_tool();
		$tool = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();

		$this->assertSame( 'export_fine_tune_curriculum', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
		$this->assertSame( 'manage_options', $tool->get_required_capability() );
		$this->assertContains( 'pro', $tool->get_capability_flags() );
		$this->assertContains( 'read-only', $tool->get_capability_flags() );
	}

	/**
	 * Parameter schema passes OpenAI basic validity rules.
	 */
	public function test_parameter_schema_structure() {
		$this->load_tool();
		$schema = ( new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum() )->get_parameters_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertContains( 'assistant_id', $schema['required'] );

		// No 'mixed' types anywhere.
		$encoded = wp_json_encode( $schema );
		$this->assertStringNotContainsString( '"mixed"', $encoded );

		// assistant_id must be integer type.
		$this->assertSame( 'integer', $schema['properties']['assistant_id']['type'] );
	}

	// ─────────────────────────────────────────────────────────
	// Capability gate.
	// ─────────────────────────────────────────────────────────

	/**
	 * Non-privileged user gets WP_Error forbidden.
	 */
	public function test_capability_gate_blocks_subscriber() {
		$this->load_tool();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute( array( 'assistant_id' => $this->assistant_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	// ─────────────────────────────────────────────────────────
	// Validation guards.
	// ─────────────────────────────────────────────────────────

	/**
	 * Missing / invalid assistant_id returns WP_Error.
	 */
	public function test_invalid_assistant_id_returns_error() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute( array( 'assistant_id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_unknown_assistant', $result->get_error_code() );
	}

	/**
	 * No suites enabled → WP_Error.
	 */
	public function test_no_suites_selected_returns_error() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute( array( 'assistant_id' => $this->assistant_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_no_suites_selected', $result->get_error_code() );
	}

	// ─────────────────────────────────────────────────────────
	// Dry-run.
	// ─────────────────────────────────────────────────────────

	/**
	 * Dry-run with two valid cases returns correct counts and no file.
	 */
	public function test_dry_run_counts() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'drysuite',
			array(
				array(
					'slug'     => 'c1',
					'input'    => 'Q1',
					'expected' => 'A1',
				),
				array(
					'slug'     => 'c2',
					'input'    => 'Q2',
					'expected' => 'A2',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'drysuite' ),
				'dry_run'      => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 2, $result['rows'] );
		$this->assertArrayNotHasKey( 'file_path', $result );
	}

	/**
	 * Missing suite slug is reported but does not abort.
	 */
	public function test_missing_suite_slug_reported() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'real_suite',
			array(
				array(
					'slug'     => 'c1',
					'input'    => 'Q1',
					'expected' => 'A1',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'real_suite', 'nonexistent_suite' ),
				'dry_run'      => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertContains( 'nonexistent_suite', $result['missing_suites'] );
		$this->assertSame( 1, $result['rows'] );
	}

	/**
	 * Empty-input case is skipped.
	 */
	public function test_empty_input_case_skipped() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'skip_input_suite',
			array(
				array(
					'slug'     => 'empty_input',
					'input'    => '',
					'expected' => 'A1',
				),
				array(
					'slug'     => 'good_case',
					'input'    => 'Q2',
					'expected' => 'A2',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'skip_input_suite' ),
				'dry_run'      => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( 1, $result['skipped_no_input'] );
	}

	/**
	 * Empty-expected case is skipped.
	 */
	public function test_empty_expected_case_skipped() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'skip_expect_suite',
			array(
				array(
					'slug'     => 'no_expect',
					'input'    => 'Q1',
					'expected' => '',
				),
				array(
					'slug'     => 'good_case',
					'input'    => 'Q2',
					'expected' => 'A2',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'skip_expect_suite' ),
				'dry_run'      => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( 1, $result['skipped_no_expect'] );
	}

	/**
	 * Over-long case is skipped.
	 */
	public function test_over_long_case_skipped() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		// Cap to 256 chars then create a case larger than that.
		add_filter(
			'wp_mcp_ai_pro_curriculum_per_case_char_cap',
			static function () {
				return 256;
			}
		);

		$long = str_repeat( 'x', 200 );
		$this->make_suite(
			'overlong_suite',
			array(
				array(
					'slug'     => 'long_case',
					'input'    => $long,
					'expected' => $long,
				),
				array(
					'slug'     => 'ok_case',
					'input'    => 'Q',
					'expected' => 'A',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'overlong_suite' ),
				'dry_run'      => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( 1, $result['skipped_too_large'] );
	}

	/**
	 * Max_cases cap is enforced.
	 */
	public function test_max_cases_cap_honoured() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$cases = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$cases[] = array(
				'slug'     => "c{$i}",
				'input'    => "Q{$i}",
				'expected' => "A{$i}",
			);
		}
		$this->make_suite( 'cap_suite', $cases );

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'cap_suite' ),
				'max_cases'    => 3,
				'dry_run'      => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 3, $result['rows'] );
	}

	/**
	 * Array-typed input/expected is JSON-encoded in the row.
	 */
	public function test_array_input_is_json_encoded() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'array_suite',
			array(
				array(
					'slug'     => 'arr_case',
					'input'    => array( 'question' => 'What is PHP?' ),
					'expected' => array( 'answer' => 'A language.' ),
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'array_suite' ),
				'dry_run'      => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['rows'] );

		// Preview should be valid JSON.
		$decoded = json_decode( $result['preview'], true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'messages', $decoded );
	}

	// ─────────────────────────────────────────────────────────
	// JSONL row shape.
	// ─────────────────────────────────────────────────────────

	/**
	 * Preview row is valid JSON with correct OpenAI chat message shape.
	 */
	public function test_preview_row_shape() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'shape_suite',
			array(
				array(
					'slug'     => 'c1',
					'input'    => 'What is 2+2?',
					'expected' => '4',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id'  => $this->assistant_id,
				'suite_slugs'   => array( 'shape_suite' ),
				'system_prompt' => 'You are helpful.',
				'dry_run'       => true,
			)
		);

		$this->assertNotWPError( $result );
		$row = json_decode( $result['preview'], true );
		$this->assertIsArray( $row );
		$this->assertArrayHasKey( 'messages', $row );

		$messages = $row['messages'];
		$this->assertCount( 3, $messages );

		$roles = array_column( $messages, 'role' );
		$this->assertSame( array( 'system', 'user', 'assistant' ), $roles );

		$this->assertSame( 'You are helpful.', $messages[0]['content'] );
		$this->assertSame( 'What is 2+2?', $messages[1]['content'] );
		$this->assertSame( '4', $messages[2]['content'] );
	}

	/**
	 * When no system prompt is given and no assistant instructions exist,
	 * the row has only two messages (user + assistant).
	 */
	public function test_row_without_system_prompt_has_two_messages() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'nosys_suite',
			array(
				array(
					'slug'     => 'c1',
					'input'    => 'Q',
					'expected' => 'A',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id'  => $this->assistant_id,
				'suite_slugs'   => array( 'nosys_suite' ),
				'system_prompt' => '',
				'dry_run'       => true,
			)
		);

		$this->assertNotWPError( $result );
		$row = json_decode( $result['preview'], true );
		$this->assertCount( 2, $row['messages'] );
		$roles = array_column( $row['messages'], 'role' );
		$this->assertSame( array( 'user', 'assistant' ), $roles );
	}

	// ─────────────────────────────────────────────────────────
	// File write.
	// ─────────────────────────────────────────────────────────

	/**
	 * Live run writes a JSONL file with one row per case.
	 */
	public function test_live_write_produces_valid_jsonl() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'write_suite',
			array(
				array(
					'slug'     => 'w1',
					'input'    => 'Hello',
					'expected' => 'World',
				),
				array(
					'slug'     => 'w2',
					'input'    => 'Foo',
					'expected' => 'Bar',
				),
			)
		);

		$this->redirect_upload_dir( $this->tmp_upload_dir );

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'write_suite' ),
				'dry_run'      => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( 2, $result['rows'] );
		$this->assertArrayHasKey( 'file_path', $result );
		$this->assertFileExists( $result['file_path'] );

		// Validate every line is valid JSON with a 'messages' key.
		$lines = array_filter( explode( "\n", file_get_contents( $result['file_path'] ) ) );
		$this->assertCount( 2, $lines );
		foreach ( $lines as $line ) {
			$decoded = json_decode( $line, true );
			$this->assertIsArray( $decoded, 'Each JSONL line must be valid JSON' );
			$this->assertArrayHasKey( 'messages', $decoded );
		}
	}

	/**
	 * Guards (.htaccess, index.php) are created next to the file.
	 */
	public function test_guards_created() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'guard_suite',
			array(
				array(
					'slug'     => 'g1',
					'input'    => 'Q',
					'expected' => 'A',
				),
			)
		);

		$this->redirect_upload_dir( $this->tmp_upload_dir );

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'guard_suite' ),
			)
		);

		$this->assertNotWPError( $result );
		$dir = dirname( $result['file_path'] );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/index.php' );
	}

	/**
	 * Format key in response is 'openai_chat_jsonl'.
	 */
	public function test_format_key_correct() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'fmt_suite',
			array(
				array(
					'slug'     => 'f1',
					'input'    => 'Q',
					'expected' => 'A',
				),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'suite_slugs'  => array( 'fmt_suite' ),
				'dry_run'      => true,
			)
		);

		$this->assertSame( 'openai_chat_jsonl', $result['format'] );
	}

	/**
	 * Evals_enabled from harness profile is used when no suite_slugs arg given.
	 */
	public function test_profile_evals_enabled_used_as_default() {
		$this->load_tool();
		wp_set_current_user( 1 );
		grant_super_admin( 1 );

		$this->make_suite(
			'profile_suite',
			array(
				array(
					'slug'     => 'p1',
					'input'    => 'Q',
					'expected' => 'A',
				),
			)
		);

		// Write a harness profile with evals_enabled.
		update_post_meta(
			$this->assistant_id,
			'_wp_mcp_ai_harness_profile',
			array(
				'enabled'       => true,
				'evals_enabled' => array( 'profile_suite' ),
			)
		);

		$tool   = new WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'dry_run'      => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['rows'] );
	}
}
