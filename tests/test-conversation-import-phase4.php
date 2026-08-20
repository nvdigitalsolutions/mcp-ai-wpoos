<?php
/**
 * Tests for Phase 4 of the conversation import pipeline.
 *
 * Covers the import-completion action, the memory-mining integration
 * (setting gate + scoped mining via the existing mine_agent_memory flow),
 * and the imported-conversation counts helper.
 *
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Load the conversation-import library classes explicitly (the main loader
 * requires them only on JetEngine-available environments).
 */
require_once WP_MCP_AI_PATH . 'includes/conversation-import/interface-wp-mcp-ai-conversation-import-adapter.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-conversation.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-adapter-chatgpt.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-adapter-gemini.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-archive.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-format-detector.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-cct-writer.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-manager.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-deleter.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-memory-miner.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-mine-agent-memory.php';

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test doubles scoped to this suite.

/**
 * Stub store_agent_context tool so the mining flow can persist in tests.
 */
class WP_MCP_AI_Conversation_Import_Phase4_Store_Stub implements WP_MCP_AI_Tool_Interface {

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
 * Writer stub for Phase 4 manager tests.
 */
class WP_MCP_AI_Conversation_Import_Phase4_Stub_Writer extends WP_MCP_AI_Conversation_Import_CCT_Writer {

	/**
	 * Look up existing rows for a set of session keys.
	 *
	 * @param string[] $session_keys Session keys to look up.
	 * @return array|WP_Error
	 */
	public function find_existing_ids( array $session_keys ) {
		return array();
	}

	/**
	 * Record a write call without touching the database.
	 *
	 * @param WP_MCP_AI_Conversation_Import_Conversation $conversation Canonical conversation.
	 * @param int                                        $user_id      Importing user ID.
	 * @param int                                        $existing_id  Existing row ID (0 = insert).
	 * @return array
	 */
	public function write( WP_MCP_AI_Conversation_Import_Conversation $conversation, $user_id, $existing_id = 0 ) {
		return array(
			'id'     => 1,
			'action' => 'imported',
		);
	}
}

// The MultipleFound sniff stays disabled for this whole file (three structures:
// the two test doubles above and the test suite below).

/**
 * Phase 4 test suite.
 */
class WP_MCP_AI_Conversation_Import_Phase4_Test extends WP_UnitTestCase {

	/**
	 * Temp files created during tests.
	 *
	 * @var string[]
	 */
	protected $temp_files = array();

	/**
	 * Clean up temp files after each test.
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
				'id'                 => 'conv-phase4',
				'title'              => 'Phase 4 conversation',
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

		$file = tempnam( sys_get_temp_dir(), 'wpmcp-phase4-' ) . '.json';
		file_put_contents( $file, wp_json_encode( $fixture ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writing.

		$this->temp_files[] = $file;

		return $file;
	}

	/**
	 * Manager: fires the completion action with the report and imported keys.
	 *
	 * @return void
	 */
	public function test_manager_fires_completion_action_with_keys() {
		$file = $this->write_fixture_file();

		$stub    = new WP_MCP_AI_Conversation_Import_Phase4_Stub_Writer();
		$manager = new WP_MCP_AI_Conversation_Import_Manager( $stub );

		$captured = array();
		add_action(
			'wp_mcp_ai_conversation_import_completed',
			function ( $report, $user_id ) use ( &$captured ) {
				$captured = array(
					'report'  => $report,
					'user_id' => $user_id,
				);
			},
			10,
			2
		);

		$report = $manager->run(
			array(
				'source'  => $file,
				'user_id' => 7,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertNotEmpty( $captured );
		$this->assertSame( 7, $captured['user_id'] );
		$this->assertSame( 'completed', $captured['report']['status'] );
		$this->assertContains( 'import-chatgpt-' . substr( sha1( 'conv-phase4' ), 0, 12 ), $captured['report']['imported_session_keys'] );
	}

	/**
	 * Miner: registers the setting with a safe default of off.
	 *
	 * @return void
	 */
	public function test_miner_registers_default_setting() {
		$defaults = WP_MCP_AI_Conversation_Import_Memory_Miner::add_default_setting( array() );

		$this->assertArrayHasKey( 'conversation_import_mine_memory', $defaults );
		$this->assertFalse( $defaults['conversation_import_mine_memory'] );
		$this->assertFalse( WP_MCP_AI_Conversation_Import_Memory_Miner::is_enabled() );
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

		WP_MCP_AI_Conversation_Import_Memory_Miner::on_import_completed(
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

		WP_MCP_AI_Conversation_Import_Memory_Miner::on_import_completed(
			array(
				'dry_run'               => true,
				'imported_session_keys' => array( 'import-chatgpt-abc123' ),
			),
			1
		);

		$this->assertSame( 0, $mined );
	}

	/**
	 * Miner: enabled gate mines scoped session keys via the existing flow.
	 *
	 * @return void
	 */
	public function test_miner_mines_imported_sessions() {
		update_option( 'wp_mcp_ai_settings', array( 'conversation_import_mine_memory' => true ) );

		// Replace the real store_agent_context tool with a stub so the mining
		// flow can persist memory records in this JetEngine-free test env.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$original_store = $registry->get_tool( 'store_agent_context' );
		$registry->unregister_tool( 'store_agent_context' );
		$registry->register_tool( new WP_MCP_AI_Conversation_Import_Phase4_Store_Stub() );

		$session_key = 'import-chatgpt-' . substr( sha1( 'conv-phase4' ), 0, 12 );

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

		WP_MCP_AI_Conversation_Import_Memory_Miner::on_import_completed(
			array(
				'dry_run'               => false,
				'imported_session_keys' => array( $session_key ),
			),
			1
		);

		// Restore the real store tool for any later tests in this run.
		if ( $original_store instanceof WP_MCP_AI_Tool_Interface ) {
			$registry->unregister_tool( 'store_agent_context' );
			$registry->register_tool( $original_store );
		}

		$this->assertNotEmpty( $mined );
		$this->assertContains( $session_key, $mined['keys'] );
		$this->assertNotInstanceOf( 'WP_Error', $mined['result'] );
	}

	/**
	 * Miner: empty session keys yield a WP_Error from mine().
	 *
	 * @return void
	 */
	public function test_miner_rejects_empty_keys() {
		$result = WP_MCP_AI_Conversation_Import_Memory_Miner::mine( array() );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_mine_no_keys', $result->get_error_code() );
	}

	/**
	 * Deleter: count_imported fails cleanly without JetEngine.
	 *
	 * @return void
	 */
	public function test_deleter_count_imported_requires_jetengine() {
		$deleter = new WP_MCP_AI_Conversation_Import_Deleter();

		$result = $deleter->count_imported( 'chatgpt' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_jetengine_missing', $result->get_error_code() );
	}
}
