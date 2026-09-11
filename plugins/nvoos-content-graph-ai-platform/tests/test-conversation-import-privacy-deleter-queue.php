<?php
/**
 * Conversation import privacy/deleter/queue port tests (Wave E4,
 * sub-cluster 6).
 *
 * Characterization suite for the ported `ConversationImport\Deleter`,
 * `Privacy`, `QueueBridge`, and `MemoryMiner`: the JetEngine
 * degradation envelopes, the exporter/eraser registration, the
 * async-queue enqueue/status surface (per-mode queue seam), and the
 * memory-mining gate (monolith-only full flow; documented standalone
 * degradation). Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\ConversationImport\Deleter;
use NvoosContentGraphAiPlatform\ConversationImport\MemoryMiner;
use NvoosContentGraphAiPlatform\ConversationImport\Privacy;
use NvoosContentGraphAiPlatform\ConversationImport\QueueBridge;
use NvoosContentGraphAiPlatform\Queues\AsyncJobQueue;

if ( defined( 'WP_MCP_AI_PATH' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-mine-agent-memory.php';
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- The seam/stub fixtures share this file with its test case.

/**
 * Seam exposing the per-mode queue class resolution.
 */
class Conversation_Import_QueueBridge_Seam extends QueueBridge {

	/**
	 * Expose queue_class().
	 *
	 * @return string
	 */
	public static function seam_queue_class() {
		return static::queue_class();
	}
}

/**
 * Stub store_agent_context tool so the mining flow can persist in tests
 * (monolith-only: the tool interface only exists with the base plugin).
 */
class Conversation_Import_Store_Stub implements \WP_MCP_AI_Tool_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'store_agent_context';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return 'Store Agent Context (stub)';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return 'Test stub.';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the stub store.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return array(
			'success'    => true,
			'context_id' => 'stub-' . uniqid(),
		);
	}
}

/**
 * Conversation import privacy/deleter/queue test suite.
 */
class Test_Conversation_Import_Privacy_Deleter_Queue extends \WP_UnitTestCase {

	/**
	 * Temp files created during tests.
	 *
	 * @var string[]
	 */
	protected $temp_files = array();

	/**
	 * Reset settings state after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->temp_files = array();

		update_option( 'wp_mcp_ai_settings', array() );

		parent::tearDown();
	}

	/**
	 * Write a temp ChatGPT fixture file with one conversation.
	 *
	 * @return string Absolute path.
	 */
	protected function write_fixture_file() {
		$fixture = array(
			array(
				'id'                 => 'conv-miner',
				'title'              => 'Miner conversation',
				'create_time'        => 1700000000.0,
				'update_time'        => 1700000100.0,
				'default_model_slug' => 'gpt-4',
				'current_node'       => 'msg-2',
				'mapping'            => array(
					'root'  => array(
						'id'       => 'root',
						'message'  => null,
						'parent'   => null,
						'children' => array( 'msg-1' ),
					),
					'msg-1' => array(
						'id'       => 'msg-1',
						'message'  => array(
							'id'          => 'msg-1',
							'author'      => array( 'role' => 'user' ),
							'create_time' => 1700000001.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'Hello for mining!' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'root',
						'children' => array( 'msg-2' ),
					),
					'msg-2' => array(
						'id'       => 'msg-2',
						'message'  => array(
							'id'          => 'msg-2',
							'author'      => array( 'role' => 'assistant' ),
							'create_time' => 1700000002.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'Hello there!' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'msg-1',
						'children' => array(),
					),
				),
			),
		);

		$file = tempnam( sys_get_temp_dir(), 'wpmcp-port-pdq-' ) . '.json';
		file_put_contents( $file, wp_json_encode( $fixture ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writing.

		$this->temp_files[] = $file;

		return $file;
	}

	/**
	 * Queue bridge: the queue class resolves per install mode.
	 *
	 * @return void
	 */
	public function test_queue_class_resolves_per_install_mode() {
		$queue_class = Conversation_Import_QueueBridge_Seam::seam_queue_class();

		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'WP_MCP_AI_Async_Job_Queue', $queue_class );
		} else {
			$this->assertSame( AsyncJobQueue::class, $queue_class );
		}
	}

	/**
	 * Queue bridge: enqueue creates a queued job row.
	 *
	 * @return void
	 */
	public function test_queue_bridge_enqueues_job() {
		$queue_class = Conversation_Import_QueueBridge_Seam::seam_queue_class();
		$queue_class::create_table();

		$file   = $this->write_fixture_file();
		$job_id = QueueBridge::enqueue(
			array(
				'source'  => $file,
				'user_id' => 1,
				'policy'  => 'skip',
			)
		);

		if ( is_wp_error( $job_id ) && 'insert_failed' === $job_id->get_error_code() ) {
			$this->markTestSkipped( 'Job queue table unavailable in this test environment.' );
		}

		$this->assertIsInt( $job_id );
		$this->assertGreaterThan( 0, $job_id );

		$job = $queue_class::get_job( $job_id );
		$this->assertSame( 'conversation_import', $job['job_type'] );
		$this->assertSame( 'queued', $job['status'] );
	}

	/**
	 * Queue bridge: unknown job IDs yield WP_Error from get_status.
	 *
	 * @return void
	 */
	public function test_queue_bridge_status_unknown_job() {
		$status = QueueBridge::get_status( 999999999 );

		$this->assertInstanceOf( 'WP_Error', $status );
		$this->assertSame( 'wp_mcp_ai_import_job_not_found', $status->get_error_code() );
	}

	/**
	 * Queue bridge: enqueue rejects a missing source.
	 *
	 * @return void
	 */
	public function test_queue_bridge_rejects_missing_source() {
		$result = QueueBridge::enqueue( array( 'user_id' => 1 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_missing_source', $result->get_error_code() );
	}

	/**
	 * Deleter: find/delete fail cleanly without JetEngine.
	 *
	 * @return void
	 */
	public function test_deleter_fails_cleanly_without_jetengine() {
		$deleter = new Deleter();

		$this->assertInstanceOf( 'WP_Error', $deleter->find_ids( 'chatgpt' ) );
		$this->assertInstanceOf( 'WP_Error', $deleter->delete( 'chatgpt' ) );
		$this->assertFalse( $deleter->delete_by_session_key( 'import-chatgpt-abc' ) );
	}

	/**
	 * Deleter: count_imported fails cleanly without JetEngine.
	 *
	 * @return void
	 */
	public function test_deleter_count_imported_requires_jetengine() {
		$deleter = new Deleter();

		$result = $deleter->count_imported( 'chatgpt' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_jetengine_missing', $result->get_error_code() );
	}

	/**
	 * Deleter: rejects invalid platforms and non-import session keys.
	 *
	 * @return void
	 */
	public function test_deleter_validates_input() {
		$deleter = new Deleter();

		$invalid_platform = $deleter->find_ids( '' );
		$this->assertInstanceOf( 'WP_Error', $invalid_platform );
		$this->assertSame( 'wp_mcp_ai_import_delete_invalid_platform', $invalid_platform->get_error_code() );

		$this->assertFalse( $deleter->delete_by_session_key( 'ordinary-session-key' ) );
	}

	/**
	 * Privacy: exporter and eraser are registered with the expected keys.
	 *
	 * @return void
	 */
	public function test_privacy_registers_exporter_and_eraser() {
		$exporters = Privacy::register_exporter( array() );
		$erasers   = Privacy::register_eraser( array() );

		$this->assertArrayHasKey( 'wp-mcp-ai-imported-conversations', $exporters );
		$this->assertArrayHasKey( 'wp-mcp-ai-imported-conversations', $erasers );
		$this->assertArrayHasKey( 'callback', $exporters['wp-mcp-ai-imported-conversations'] );
		$this->assertArrayHasKey( 'callback', $erasers['wp-mcp-ai-imported-conversations'] );
	}

	/**
	 * Privacy: unknown email yields empty export and erase results.
	 *
	 * @return void
	 */
	public function test_privacy_unknown_email_returns_empty() {
		$export = Privacy::export( 'nobody-' . uniqid() . '@example.com', 1 );
		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );

		$erase = Privacy::erase( 'nobody-' . uniqid() . '@example.com', 1 );
		$this->assertFalse( $erase['items_removed'] );
		$this->assertTrue( $erase['done'] );
	}

	/**
	 * Miner: registers the setting with a safe default of off.
	 *
	 * @return void
	 */
	public function test_miner_registers_default_setting() {
		$defaults = MemoryMiner::add_default_setting( array() );

		$this->assertArrayHasKey( 'conversation_import_mine_memory', $defaults );
		$this->assertFalse( $defaults['conversation_import_mine_memory'] );
		$this->assertFalse( MemoryMiner::is_enabled() );
	}

	/**
	 * Miner: disabled gate means no mining runs after an import.
	 *
	 * @return void
	 */
	public function test_miner_gate_disabled_skips_mining() {
		update_option( 'wp_mcp_ai_settings', array( 'conversation_import_mine_memory' => false ) );

		$mined = 0;
		add_action(
			'wp_mcp_ai_conversation_import_mined',
			function () use ( &$mined ) {
				$mined++;
			}
		);

		MemoryMiner::on_import_completed(
			array(
				'dry_run'               => false,
				'imported_session_keys' => array( 'import-chatgpt-abc123' ),
			),
			1
		);

		$this->assertSame( 0, $mined );
	}

	/**
	 * Miner: dry-run imports never trigger mining.
	 *
	 * @return void
	 */
	public function test_miner_skips_dry_run_reports() {
		update_option( 'wp_mcp_ai_settings', array( 'conversation_import_mine_memory' => true ) );

		$mined = 0;
		add_action(
			'wp_mcp_ai_conversation_import_mined',
			function () use ( &$mined ) {
				$mined++;
			}
		);

		MemoryMiner::on_import_completed(
			array(
				'dry_run'               => true,
				'imported_session_keys' => array( 'import-chatgpt-abc123' ),
			),
			1
		);

		$this->assertSame( 0, $mined );
	}

	/**
	 * Miner: empty session keys yield a WP_Error from mine().
	 *
	 * Monolith reports the no-keys envelope (the tool exists); standalone
	 * reports the documented unavailable degradation (the tool does not).
	 *
	 * @return void
	 */
	public function test_miner_rejects_empty_keys() {
		$result = MemoryMiner::mine( array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->assertSame( 'wp_mcp_ai_import_mine_no_keys', $result->get_error_code() );
		} else {
			$this->assertSame( 'wp_mcp_ai_import_mine_unavailable', $result->get_error_code() );
		}
	}

	/**
	 * Miner: enabled gate mines scoped session keys via the existing flow
	 * (monolith) or degrades documented standalone.
	 *
	 * @return void
	 */
	public function test_miner_mines_imported_sessions() {
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			// Standalone: mining is monolith-only (base settings registry +
			// mining tool); the documented degradation envelope applies.
			$result = MemoryMiner::mine( array( 'import-chatgpt-abc123' ) );

			$this->assertInstanceOf( 'WP_Error', $result );
			$this->assertSame( 'wp_mcp_ai_import_mine_unavailable', $result->get_error_code() );

			return;
		}

		update_option( 'wp_mcp_ai_settings', array( 'conversation_import_mine_memory' => true ) );

		// Replace the real store_agent_context tool with a stub so the mining
		// flow can persist memory records in this JetEngine-free test env.
		$registry = \WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$original_store = $registry->get_tool( 'store_agent_context' );
		$registry->unregister_tool( 'store_agent_context' );
		$registry->register_tool( new Conversation_Import_Store_Stub() );

		$session_key = 'import-chatgpt-' . substr( sha1( 'conv-miner' ), 0, 12 );

		// Mock the transcript repository reads so the mining flow works
		// without a live JetEngine CCT in the test environment.
		add_filter(
			'wp_mcp_ai_mine_transcripts_sessions',
			function () use ( $session_key ) {
				return array(
					array(
						'session_key'     => $session_key,
						'assistant_id'    => 'import-chatgpt',
						'assistant_model' => 'gpt-4',
						'turn_count'      => 2,
						'started_at'      => '2023-11-14 22:13:20',
						'last_created'    => '2023-11-14 22:13:21',
					),
				);
			}
		);

		add_filter(
			'wp_mcp_ai_mine_transcripts_session_messages',
			function () {
				return array(
					array(
						'role'          => 'user',
						'content'       => 'Hello for mining!',
						'message_index' => 0,
					),
					array(
						'role'          => 'assistant',
						'content'       => 'Hello there!',
						'message_index' => 1,
					),
				);
			}
		);

		$mined = array();
		add_action(
			'wp_mcp_ai_conversation_import_mined',
			function ( $result, $keys ) use ( &$mined ) {
				$mined = array(
					'result' => $result,
					'keys'   => $keys,
				);
			},
			10,
			2
		);

		MemoryMiner::on_import_completed(
			array(
				'dry_run'               => false,
				'imported_session_keys' => array( $session_key ),
			),
			1
		);

		// Restore the real store tool for any later tests in this run.
		if ( $original_store instanceof \WP_MCP_AI_Tool_Interface ) {
			$registry->unregister_tool( 'store_agent_context' );
			$registry->register_tool( $original_store );
		}

		$this->assertNotEmpty( $mined );
		$this->assertContains( $session_key, $mined['keys'] );
		$this->assertNotInstanceOf( 'WP_Error', $mined['result'] );
	}
}
