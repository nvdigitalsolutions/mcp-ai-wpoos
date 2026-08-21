<?php
/**
 * Tests for Phase 2 of the conversation import pipeline.
 *
 * Covers manager progress callbacks, the async queue bridge, the deleter,
 * the privacy exporter/eraser registration, the delete tool, and the admin
 * page surface. JetEngine-dependent paths assert clean WP_Error behaviour in
 * this JetEngine-free test environment.
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
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-privacy.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-queue.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-conversation-import-admin.php';
require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-conversation-import-delete.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test doubles scoped to this suite.

/**
 * Stub writer for Phase 2 manager tests.
 */
class WP_MCP_AI_Conversation_Import_Phase2_Stub_Writer extends WP_MCP_AI_Conversation_Import_CCT_Writer {

	/**
	 * Session keys written during the run.
	 *
	 * @var string[]
	 */
	public $written = array();

	/**
	 * Existing rows returned by the dedupe lookup.
	 *
	 * @var array
	 */
	public $existing = array();

	/**
	 * Look up existing rows for a set of session keys.
	 *
	 * @param string[] $session_keys Session keys to look up.
	 * @return array|WP_Error
	 */
	public function find_existing_ids( array $session_keys ) {
		return $this->existing;
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
		$this->written[] = $conversation->get_session_key();

		return array(
			'id'     => 1,
			'action' => 0 !== $existing_id ? 'updated' : 'imported',
		);
	}
}

// The MultipleFound sniff stays disabled for this whole file (two structures:
// the stub writer test double above and the test suite below).

/**
 * Phase 2 test suite.
 */
class WP_MCP_AI_Conversation_Import_Phase2_Test extends WP_UnitTestCase {

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

		parent::tearDown();
	}

	/**
	 * Build a minimal ChatGPT conversations.json fixture.
	 *
	 * @return array
	 */
	protected function chatgpt_fixture() {
		return array(
			array(
				'id'                 => 'conv-1',
				'title'              => 'Hello World',
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
								'parts'        => array( 'Hello!' ),
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
								'parts'        => array( 'Hi there!' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'msg-1',
						'children' => array(),
					),
				),
			),
			array(
				'id'           => 'conv-2',
				'title'        => 'Second conversation',
				'create_time'  => 1700000200.0,
				'update_time'  => 1700000200.0,
				'current_node' => 'b-2',
				'mapping'      => array(
					'b-root' => array(
						'id'       => 'b-root',
						'message'  => null,
						'parent'   => null,
						'children' => array( 'b-1' ),
					),
					'b-1'    => array(
						'id'       => 'b-1',
						'message'  => array(
							'id'          => 'b-1',
							'author'      => array( 'role' => 'user' ),
							'create_time' => 1700000201.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'Another question.' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'b-root',
						'children' => array( 'b-2' ),
					),
					'b-2'    => array(
						'id'       => 'b-2',
						'message'  => array(
							'id'          => 'b-2',
							'author'      => array( 'role' => 'assistant' ),
							'create_time' => 1700000202.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'Another answer.' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'b-1',
						'children' => array(),
					),
				),
			),
		);
	}

	/**
	 * Write a temp JSON fixture file.
	 *
	 * @return string Absolute path.
	 */
	protected function write_fixture_file() {
		$file = tempnam( sys_get_temp_dir(), 'wpmcp-phase2-' ) . '.json';
		file_put_contents( $file, wp_json_encode( $this->chatgpt_fixture() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writing.

		$this->temp_files[] = $file;

		return $file;
	}

	/**
	 * Manager: progress callback fires after each batch and at completion.
	 *
	 * @return void
	 */
	public function test_manager_progress_callback_fires_per_batch() {
		$file = $this->write_fixture_file();

		$stub    = new WP_MCP_AI_Conversation_Import_Phase2_Stub_Writer();
		$manager = new WP_MCP_AI_Conversation_Import_Manager( $stub );

		$events = array();
		$manager->set_progress_callback(
			function ( $progress ) use ( &$events ) {
				$events[] = $progress;
			}
		);

		$report = $manager->run(
			array(
				'source'     => $file,
				'batch_size' => 1,
				'user_id'    => 1,
				'estimate'   => 2,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertNotEmpty( $events );

		$first = $events[0];
		$this->assertSame( 1, $first['processed'] );
		$this->assertSame( 2, $first['estimated_total'] );

		$last = $events[ count( $events ) - 1 ];
		$this->assertSame( 2, $last['processed'] );
		$this->assertSame( 2, $last['totals']['imported'] );
	}

	/**
	 * Queue bridge: enqueue creates a queued job row.
	 *
	 * @return void
	 */
	public function test_queue_bridge_enqueues_job() {
		if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$this->markTestSkipped( 'Async job queue unavailable.' );
		}

		WP_MCP_AI_Async_Job_Queue::create_table();

		$file   = $this->write_fixture_file();
		$job_id = WP_MCP_AI_Conversation_Import_Queue::enqueue(
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

		$job = WP_MCP_AI_Async_Job_Queue::get_job( $job_id );
		$this->assertSame( 'conversation_import', $job['job_type'] );
		$this->assertSame( 'queued', $job['status'] );
	}

	/**
	 * Queue bridge: unknown job IDs yield WP_Error from get_status.
	 *
	 * @return void
	 */
	public function test_queue_bridge_status_unknown_job() {
		$status = WP_MCP_AI_Conversation_Import_Queue::get_status( 999999999 );

		$this->assertInstanceOf( 'WP_Error', $status );
		$this->assertSame( 'wp_mcp_ai_import_job_not_found', $status->get_error_code() );
	}

	/**
	 * Queue bridge: enqueue rejects a missing source.
	 *
	 * @return void
	 */
	public function test_queue_bridge_rejects_missing_source() {
		$result = WP_MCP_AI_Conversation_Import_Queue::enqueue( array( 'user_id' => 1 ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_missing_source', $result->get_error_code() );
	}

	/**
	 * Deleter: find/delete fail cleanly without JetEngine.
	 *
	 * @return void
	 */
	public function test_deleter_fails_cleanly_without_jetengine() {
		$deleter = new WP_MCP_AI_Conversation_Import_Deleter();

		$this->assertInstanceOf( 'WP_Error', $deleter->find_ids( 'chatgpt' ) );
		$this->assertInstanceOf( 'WP_Error', $deleter->delete( 'chatgpt' ) );
		$this->assertFalse( $deleter->delete_by_session_key( 'import-chatgpt-abc' ) );
	}

	/**
	 * Deleter: rejects invalid platforms and non-import session keys.
	 *
	 * @return void
	 */
	public function test_deleter_validates_input() {
		$deleter = new WP_MCP_AI_Conversation_Import_Deleter();

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
		$exporters = WP_MCP_AI_Conversation_Import_Privacy::register_exporter( array() );
		$erasers   = WP_MCP_AI_Conversation_Import_Privacy::register_eraser( array() );

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
		$export = WP_MCP_AI_Conversation_Import_Privacy::export( 'nobody-' . uniqid() . '@example.com', 1 );
		$this->assertSame( array(), $export['data'] );
		$this->assertTrue( $export['done'] );

		$erase = WP_MCP_AI_Conversation_Import_Privacy::erase( 'nobody-' . uniqid() . '@example.com', 1 );
		$this->assertFalse( $erase['items_removed'] );
		$this->assertTrue( $erase['done'] );
	}

	/**
	 * Delete tool: unavailable without JetEngine and rejects missing platform.
	 *
	 * @return void
	 */
	public function test_delete_tool_errors_without_jetengine() {
		$tool = new WP_MCP_AI_Tool_Conversation_Import_Delete();

		$result = $tool->execute( array( 'platform' => 'chatgpt' ) );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_jetengine_missing', $result->get_error_code() );

		// Availability is checked before argument validation, so a missing
		// platform also reports the JetEngine gate in this environment.
		$result = $tool->execute( array() );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_jetengine_missing', $result->get_error_code() );
	}

	/**
	 * Admin: process_upload rejects invalid payloads.
	 *
	 * @return void
	 */
	public function test_admin_process_upload_rejects_invalid() {
		$admin = new WP_MCP_AI_Conversation_Import_Admin();

		$empty = $admin->process_upload( array() );
		$this->assertInstanceOf( 'WP_Error', $empty );

		$wrong_type = $admin->process_upload(
			array(
				'name'     => 'evil.exe',
				'tmp_name' => 'whatever',
				'size'     => 10,
			)
		);
		$this->assertInstanceOf( 'WP_Error', $wrong_type );
		$this->assertSame( 'wp_mcp_ai_import_upload_type', $wrong_type->get_error_code() );
	}

	/**
	 * Admin: error codes map to human-readable messages.
	 *
	 * @return void
	 */
	public function test_admin_error_messages() {
		$admin = new WP_MCP_AI_Conversation_Import_Admin();

		$reflection = new ReflectionClass( $admin );
		$method     = $reflection->getMethod( 'describe_error' );
		$method->setAccessible( true );

		$this->assertNotEmpty( $method->invoke( $admin, 'missing_file' ) );
		$this->assertNotEmpty( $method->invoke( $admin, 'wp_mcp_ai_import_unknown_format' ) );
		$this->assertNotEmpty( $method->invoke( $admin, 'something-unknown' ) );
	}

	/**
	 * Admin: the page renders the upload form for admins.
	 *
	 * @return void
	 */
	public function test_admin_render_page_shows_upload_form() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$admin = new WP_MCP_AI_Conversation_Import_Admin();

		ob_start();
		$admin->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'conversation_export', $output );
		$this->assertStringContainsString( 'wp_mcp_ai_conversation_import_upload', $output );
	}
}
