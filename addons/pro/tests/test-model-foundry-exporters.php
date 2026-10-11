<?php
/**
 * Tests for the Model Foundry Corpus exporters (Pro, Phase 1).
 *
 * Exercises the three export tools: registration, metadata, capability
 * gate, fail-closed consent, dry-run counts, live writes, row shapes, and
 * per-tool skip accounting.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.4
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Corpus exporter tests.
 */
class Test_Model_Foundry_Exporters extends WP_UnitTestCase {

	/**
	 * Assistant post ID used by tests.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Directories created by tests, wiped in tearDown.
	 *
	 * @var array<int,string>
	 */
	private $cleanup_dirs = array();

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_status' => 'publish',
				'post_title'  => 'Foundry Exporter Assistant',
			)
		);
		update_post_meta( $this->assistant_id, WP_MCP_AI_Corpus_Governance::CONSENT_META, '1' );
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_assistant_instructions', 'You are a test assistant.' );

		$this->load_classes();
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		WP_MCP_AI_Harness_Trace_Store::delete_all_for_assistant( $this->assistant_id );
		WP_MCP_AI_Preference_Pair_Store::clear( $this->assistant_id );
		remove_all_filters( 'wp_mcp_ai_model_foundry_run_user_prompt' );
		remove_all_filters( 'wp_mcp_ai_model_foundry_preference_pairs' );
		remove_all_filters( 'upload_dir' );
		foreach ( $this->cleanup_dirs as $dir ) {
			$this->wipe_dir( $dir );
		}
		$this->cleanup_dirs = array();
		parent::tearDown();
	}

	/**
	 * Recursively delete a directory's contents (the directory itself stays).
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private function wipe_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$this->wipe_dir( $path );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
				rmdir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $path );
			}
		}
	}

	/**
	 * Load foundry classes and the trace store.
	 */
	private function load_classes() {
		if ( ! class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-harness-trace-store.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Corpus_Governance' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/model-foundry/class-wp-mcp-ai-corpus-governance.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Preference_Pair_Store' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/model-foundry/class-wp-mcp-ai-preference-pair-store.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Export_Trajectory_Corpus' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/model-foundry/class-wp-mcp-ai-tool-export-trajectory-corpus.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Export_Preference_Pairs' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/model-foundry/class-wp-mcp-ai-tool-export-preference-pairs.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/model-foundry/class-wp-mcp-ai-tool-export-plugin-docs-corpus.php';
		}
	}

	/**
	 * Create a completed trace run with tool calls, a retrieval query, and
	 * a final response.
	 *
	 * @param bool $with_retrieval Whether to record a retrieval query.
	 * @param bool $with_tool_calls Whether to record tool calls.
	 * @param bool $with_response Whether to record a final response.
	 * @return string|WP_Error Run ID.
	 */
	private function create_trace_run( $with_retrieval = true, $with_tool_calls = true, $with_response = true ) {
		$run_id = WP_MCP_AI_Harness_Trace_Store::start_run( $this->assistant_id, array( 'started_at' => time() ) );
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}

		if ( $with_retrieval ) {
			WP_MCP_AI_Harness_Trace_Store::write_artifact( $run_id, 'retrieval.json', array( 'query' => 'What is the refund policy?' ) );
		}
		if ( $with_tool_calls ) {
			WP_MCP_AI_Harness_Trace_Store::append_jsonl(
				$run_id,
				'tool_calls.jsonl',
				array(
					'seq'            => 1,
					'slug'           => 'get_post',
					'args_summary'   => '{"id":42}',
					'result_success' => true,
					'result_type'    => 'array',
					'result_summary'  => '5 keys',
				)
			);
			WP_MCP_AI_Harness_Trace_Store::append_jsonl(
				$run_id,
				'tool_calls.jsonl',
				array(
					'seq'            => 2,
					'slug'           => 'send_email',
					'args_summary'   => '{"to":"x@example.com"}',
					'result_success' => false,
					'result_type'    => 'wp_error',
					'result_summary'  => 'wp_mcp_ai_smtp_down',
				)
			);
		}
		if ( $with_response ) {
			WP_MCP_AI_Harness_Trace_Store::write_text( $run_id, 'model_response.txt', 'Refunds take 5 business days.' );
		}

		WP_MCP_AI_Harness_Trace_Store::finish_run( $run_id, array() );
		return $run_id;
	}

	// ─────────────────────────────────────────────────────────
	// Registration + metadata.
	// ─────────────────────────────────────────────────────────

	/**
	 * All three tool classes load and implement the contracts.
	 */
	public function test_tool_classes_load() {
		foreach ( array( 'WP_MCP_AI_Tool_Export_Trajectory_Corpus', 'WP_MCP_AI_Tool_Export_Preference_Pairs', 'WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus' ) as $class ) {
			$tool = new $class();
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $tool );
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $tool );
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Data_Contract_Interface', $tool );
			$this->assertInstanceOf( 'WP_MCP_AI_Tool_Usage_Guidance_Interface', $tool );
			$this->assertNotEmpty( $tool->get_slug() );
			$this->assertSame( 'manage_options', $tool->get_required_capability() );
			$this->assertContains( 'pro', $tool->get_capability_flags() );
			$this->assertContains( 'read-only', $tool->get_capability_flags() );
		}
	}

	/**
	 * Registration map carries the three new tools.
	 */
	public function test_registration_map() {
		$file = WP_MCP_AI_PRO_PATH . 'includes/model-foundry/model-foundry-init.php';
		require_once $file;

		$tools = wp_mcp_ai_pro_register_model_foundry_tools( array() );

		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Export_Trajectory_Corpus', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Export_Preference_Pairs', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus', $tools );
	}

	/**
	 * Subscriber is blocked by the capability gate.
	 */
	public function test_capability_gate_blocks_subscriber() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Export_Trajectory_Corpus();
		$result = $tool->execute( array( 'assistant_id' => $this->assistant_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * Consent is enforced before trace data is read.
	 */
	public function test_trajectory_consent_fails_closed() {
		delete_post_meta( $this->assistant_id, WP_MCP_AI_Corpus_Governance::CONSENT_META );
		wp_set_current_user( 1 );

		$tool   = new WP_MCP_AI_Tool_Export_Trajectory_Corpus();
		$result = $tool->execute( array( 'assistant_id' => $this->assistant_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_training_consent_required', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	// ─────────────────────────────────────────────────────────
	// Trajectory exporter.
	// ─────────────────────────────────────────────────────────

	/**
	 * Dry run builds the documented tool-calling row shape.
	 */
	public function test_trajectory_dry_run_row_shape() {
		wp_set_current_user( 1 );
		$this->create_trace_run();

		$tool   = new WP_MCP_AI_Tool_Export_Trajectory_Corpus();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'dry_run' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['rows'] );

		$row      = json_decode( $result['preview'], true );
		$messages = $row['messages'];

		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertSame( 'You are a test assistant.', $messages[0]['content'] );
		$this->assertSame( 'user', $messages[1]['role'] );
		$this->assertSame( 'What is the refund policy?', $messages[1]['content'] );

		$assistant_calls = $messages[2];
		$this->assertSame( 'assistant', $assistant_calls['role'] );
		$this->assertNull( $assistant_calls['content'] );
		$this->assertCount( 2, $assistant_calls['tool_calls'] );
		$this->assertSame( 'get_post', $assistant_calls['tool_calls'][0]['function']['name'] );

		$this->assertSame( 'tool', $messages[3]['role'] );
		$this->assertSame( $assistant_calls['tool_calls'][0]['id'], $messages[3]['tool_call_id'] );
		$this->assertStringStartsWith( 'success: ', $messages[3]['content'] );
		$this->assertStringStartsWith( 'error: ', $messages[4]['content'] );

		$final = end( $messages );
		$this->assertSame( 'assistant', $final['role'] );
		$this->assertSame( 'Refunds take 5 business days.', $final['content'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Live export writes shards + manifest into the guarded corpus dir.
	 */
	public function test_trajectory_live_export_writes_files() {
		wp_set_current_user( 1 );
		$this->create_trace_run();

		$tool   = new WP_MCP_AI_Tool_Export_Trajectory_Corpus();
		$result = $tool->execute( array( 'assistant_id' => $this->assistant_id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'trajectory_v1', $result['schema'] );

		// The single row may land in either shard (content-hash split).
		$names = wp_list_pluck( $result['files'], 'name' );
		$this->assertContains( 'manifest.json', $names );
		$shard_names = array_intersect( $names, array( 'train.jsonl', 'holdout.jsonl' ) );
		$this->assertNotEmpty( $shard_names );
		$this->assertSame( 1, $result['rows']['train'] + $result['rows']['holdout'] );
		foreach ( $result['files'] as $file ) {
			$this->assertFileExists( $file['path'] );
		}

		wp_set_current_user( 0 );
	}

	/**
	 * Runs without a retrievable user prompt are skipped with a reason.
	 */
	public function test_trajectory_skips_runs_without_user_prompt() {
		wp_set_current_user( 1 );
		$this->create_trace_run( false, true, true );

		$tool   = new WP_MCP_AI_Tool_Export_Trajectory_Corpus();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'dry_run' => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_trajectory_corpus_empty', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * The run-user-prompt filter supplies prompts for retrieval-less runs.
	 */
	public function test_trajectory_user_prompt_filter_seam() {
		wp_set_current_user( 1 );
		$this->create_trace_run( false, true, true );
		add_filter(
			'wp_mcp_ai_model_foundry_run_user_prompt',
			static function () {
				return 'Filter-supplied prompt';
			}
		);

		$tool   = new WP_MCP_AI_Tool_Export_Trajectory_Corpus();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'dry_run' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['rows'] );
		$this->assertStringContainsString( 'Filter-supplied prompt', $result['preview'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Runs without tool calls are skipped with a reason.
	 */
	public function test_trajectory_skips_runs_without_tool_calls() {
		wp_set_current_user( 1 );
		$this->create_trace_run( true, false, true );

		$tool   = new WP_MCP_AI_Tool_Export_Trajectory_Corpus();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'dry_run' => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_trajectory_corpus_empty', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	// ─────────────────────────────────────────────────────────
	// Preference-pairs exporter.
	// ─────────────────────────────────────────────────────────

	/**
	 * Store rejects malformed pairs (R4 shape).
	 */
	public function test_preference_store_rejects_invalid_shape() {
		$result = WP_MCP_AI_Preference_Pair_Store::record( $this->assistant_id, 'prompt', '', 'rejected' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_preference_invalid_shape', $result->get_error_code() );
	}

	/**
	 * Store records valid pairs and the exporter emits them.
	 */
	public function test_preference_export_rows() {
		WP_MCP_AI_Preference_Pair_Store::record( $this->assistant_id, 'How do I reset my password?', 'Use the lost-password link.', 'Turn it off and on again.', 'test', 0.8 );
		WP_MCP_AI_Preference_Pair_Store::record( $this->assistant_id, 'Where is my order?', 'Check the tracking page.', 'I do not know.', 'test', null );

		wp_set_current_user( 1 );
		$tool   = new WP_MCP_AI_Tool_Export_Preference_Pairs();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'dry_run' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['rows'] );
		$this->assertSame( 'preference_v1', $result['schema'] );

		$row = json_decode( $result['preview'], true );
		$this->assertArrayHasKey( 'prompt', $row );
		$this->assertArrayHasKey( 'chosen', $row );
		$this->assertArrayHasKey( 'rejected', $row );
		$this->assertIsString( $row['chosen'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Skips low-margin pairs; margin-less pairs always pass.
	 */
	public function test_preference_min_margin_skips() {
		WP_MCP_AI_Preference_Pair_Store::record( $this->assistant_id, 'p1', 'c1', 'r1', 'test', 0.5 );
		WP_MCP_AI_Preference_Pair_Store::record( $this->assistant_id, 'p2', 'c2', 'r2', 'test', null );

		wp_set_current_user( 1 );
		$tool   = new WP_MCP_AI_Tool_Export_Preference_Pairs();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'min_margin'   => 0.8,
				'dry_run'      => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( 1, $result['skipped_low_margin'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Empty store → honest error.
	 */
	public function test_preference_empty_errors() {
		wp_set_current_user( 1 );
		$tool   = new WP_MCP_AI_Tool_Export_Preference_Pairs();
		$result = $tool->execute( array( 'assistant_id' => $this->assistant_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_preference_corpus_empty', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * The preference-pairs filter contributes pairs at export time.
	 */
	public function test_preference_filter_contributes_pairs() {
		add_filter(
			'wp_mcp_ai_model_foundry_preference_pairs',
			static function ( $pairs ) {
				$pairs[] = array(
					'prompt' => 'filter prompt',
					'chosen' => 'filter chosen',
					'rejected' => 'filter rejected',
				);
				return $pairs;
			}
		);

		wp_set_current_user( 1 );
		$tool   = new WP_MCP_AI_Tool_Export_Preference_Pairs();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'dry_run' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['rows'] );
		$this->assertStringContainsString( 'filter prompt', $result['preview'] );

		wp_set_current_user( 0 );
	}

	// ─────────────────────────────────────────────────────────
	// Docs exporter.
	// ─────────────────────────────────────────────────────────

	/**
	 * Docs exporter scans the default docs-hub folder (md + txt, exclusions).
	 */
	public function test_docs_dry_run_scans_default_dir() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'nvoos-docs-hub/content';
		wp_mkdir_p( $dir );
		$this->wipe_dir( $dir );
		$this->cleanup_dirs[] = $dir;
		wp_mkdir_p( $dir . '/guide' );
		wp_mkdir_p( $dir . '/vendor' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writes.
		file_put_contents( $dir . '/hello.md', '# Hello' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writes.
		file_put_contents( $dir . '/guide/start.txt', 'Start here' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writes.
		file_put_contents( $dir . '/vendor/secret.md', '# Secret' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writes.
		file_put_contents( $dir . '/ignored.pdf', 'x' );

		wp_set_current_user( 1 );
		$tool   = new WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'dry_run' => true,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['rows'] );
		$this->assertSame( 'docs_v1', $result['schema'] );

		// Directory order is not deterministic — assert the row shape, not
		// which document landed first.
		$preview = json_decode( $result['preview'], true );
		$this->assertIsArray( $preview );
		$this->assertNotEmpty( $preview['text'] );
		$this->assertSame( 'docs-hub', $preview['meta']['source'] );
		$this->assertArrayHasKey( 'hash', $preview['meta'] );

		wp_set_current_user( 0 );
	}

	/**
	 * Explicit dir outside uploads is rejected (path-traversal guard).
	 */
	public function test_docs_dir_outside_uploads_rejected() {
		$outside = sys_get_temp_dir() . '/mf-docs-outside-' . uniqid();
		wp_mkdir_p( $outside );
		$this->cleanup_dirs[] = $outside;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writes.
		file_put_contents( $outside . '/x.md', '# X' );

		wp_set_current_user( 1 );
		$tool   = new WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus();
		$result = $tool->execute(
			array(
				'assistant_id' => $this->assistant_id,
				'dir'          => $outside,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_docs_dir_outside_uploads', $result->get_error_code() );

		wp_set_current_user( 0 );
	}

	/**
	 * Docs live export writes rows with per-document meta.
	 */
	public function test_docs_live_export_writes_files() {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'nvoos-docs-hub/content';
		wp_mkdir_p( $dir );
		$this->wipe_dir( $dir );
		$this->cleanup_dirs[] = $dir;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writes.
		file_put_contents( $dir . '/policy.md', 'Refunds take 5 days.' );

		wp_set_current_user( 1 );
		$tool   = new WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus();
		$result = $tool->execute( array( 'assistant_id' => $this->assistant_id ) );

		$this->assertNotWPError( $result );

		// The single row may land in either shard (content-hash split) —
		// read whichever shard exists and assert the row shape.
		$found = null;
		foreach ( $result['files'] as $file ) {
			if ( ! in_array( $file['name'], array( 'train.jsonl', 'holdout.jsonl' ), true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading test-fixture corpus shard.
			$lines = array_filter( explode( "\n", (string) file_get_contents( $file['path'] ) ) );
			foreach ( $lines as $line ) {
				$decoded = json_decode( trim( $line ), true );
				if ( is_array( $decoded ) && isset( $decoded['text'] ) ) {
					$found = $decoded;
					break 2;
				}
			}
		}

		$this->assertNotNull( $found, 'No docs row found in any shard.' );
		$this->assertSame( 'Refunds take 5 days.', $found['text'] );
		$this->assertArrayHasKey( 'hash', $found['meta'] );
		$this->assertArrayHasKey( 'path', $found['meta'] );

		wp_set_current_user( 0 );
	}
}
