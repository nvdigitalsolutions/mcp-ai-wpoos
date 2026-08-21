<?php
/**
 * Tests for the conversation import pipeline.
 *
 * Covers the canonical model, ChatGPT and Gemini adapters, format detection,
 * archive safety, and manager orchestration (dry-run + policies). JetEngine
 * CCT write paths are exercised via the record-mapping test and skipped when
 * JetEngine is not available.
 *
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Load the conversation-import library classes explicitly: the main loader
 * only requires them on JetEngine-available environments, and the unit test
 * environment has no JetEngine.
 */
require_once WP_MCP_AI_PATH . 'includes/conversation-import/interface-wp-mcp-ai-conversation-import-adapter.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-conversation.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-adapter-chatgpt.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-adapter-gemini.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-archive.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-format-detector.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-cct-writer.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-manager.php';

/**
 * Stub writer for manager tests: records writes without a database.
 *
 * phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double scoped to this suite.
 */
class WP_MCP_AI_Conversation_Import_Stub_Writer extends WP_MCP_AI_Conversation_Import_CCT_Writer {

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
	 * @return array|\WP_Error
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
 * Conversation import test suite.
 */
class WP_MCP_AI_Conversation_Import_Test extends WP_UnitTestCase {

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
	 * Write a temp JSON file and register it for cleanup.
	 *
	 * @param string $contents File contents.
	 * @param string $suffix   Optional file suffix.
	 * @return string Absolute path.
	 */
	protected function write_temp_file( $contents, $suffix = '.json' ) {
		$file = tempnam( sys_get_temp_dir(), 'wpmcp-import-' );
		$file = $file . $suffix;

		file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writing.

		$this->temp_files[] = $file;

		return $file;
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
				'current_node'       => 'msg-3',
				'mapping'            => array(
					'root'   => array(
						'id'       => 'root',
						'message'  => null,
						'parent'   => null,
						'children' => array( 'msg-1' ),
					),
					'msg-1'  => array(
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
						'children' => array( 'msg-2a', 'msg-2b' ),
					),
					'msg-2a' => array(
						'id'       => 'msg-2a',
						'message'  => array(
							'id'          => 'msg-2a',
							'author'      => array( 'role' => 'assistant' ),
							'create_time' => 1700000002.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'First attempt.' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'msg-1',
						'children' => array(),
					),
					'msg-2b' => array(
						'id'       => 'msg-2b',
						'message'  => array(
							'id'          => 'msg-2b',
							'author'      => array( 'role' => 'assistant' ),
							'create_time' => 1700000003.0,
							'content'     => array(
								'content_type' => 'text',
								'parts'        => array( 'Regenerated answer.【cite】【turn1view3】' ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'msg-1',
						'children' => array( 'msg-3' ),
					),
					'msg-3'  => array(
						'id'       => 'msg-3',
						'message'  => array(
							'id'          => 'msg-3',
							'author'      => array( 'role' => 'user' ),
							'create_time' => 1700000004.0,
							'content'     => array(
								'content_type' => 'multimodal_text',
								'parts'        => array(
									array(
										'content_type'  => 'image_asset_pointer',
										'asset_pointer' => 'sediment://file_abc123',
									),
									'Thanks!',
								),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'msg-2b',
						'children' => array(),
					),
				),
			),
			array(
				'id'           => 'conv-2',
				'title'        => 'Hidden system message',
				'create_time'  => 1700000200.0,
				'update_time'  => 1700000200.0,
				'current_node' => 'h-2',
				'mapping'      => array(
					'h-root' => array(
						'id'       => 'h-root',
						'message'  => null,
						'parent'   => null,
						'children' => array( 'h-1' ),
					),
					'h-1'    => array(
						'id'       => 'h-1',
						'message'  => array(
							'id'       => 'h-1',
							'author'   => array( 'role' => 'system' ),
							'content'  => array(
								'content_type' => 'text',
								'parts'        => array( 'You are helpful.' ),
							),
							'weight'   => 0.0,
							'metadata' => array(),
						),
						'parent'   => 'h-root',
						'children' => array( 'h-2' ),
					),
					'h-2'    => array(
						'id'       => 'h-2',
						'message'  => array(
							'id'          => 'h-2',
							'author'      => array( 'role' => 'assistant' ),
							'create_time' => 1700000201.0,
							'content'     => array(
								'content_type' => 'code',
								'parts'        => array( "print('hi')" ),
							),
							'weight'      => 1.0,
							'metadata'    => array(),
						),
						'parent'   => 'h-1',
						'children' => array(),
					),
				),
			),
		);
	}

	/**
	 * Build a minimal Google Takeout Gemini activity fixture.
	 *
	 * @return array
	 */
	protected function gemini_fixture() {
		return array(
			array(
				'header'       => 'Gemini Apps',
				'title'        => 'What is the capital of France?',
				'titleUrl'     => 'https://gemini.google.com/app/abc123',
				'time'         => '2024-12-31T10:00:00.000Z',
				'products'     => array( 'Gemini' ),
				'subtitles'    => array(),
				'safeHtmlItem' => array(
					array( 'html' => '<p>The capital of France is <b>Paris</b>.</p>' ),
				),
			),
			array(
				'header'       => 'Gemini Apps',
				'title'        => 'What about Germany?',
				'titleUrl'     => 'https://gemini.google.com/app/abc123',
				'time'         => '2024-12-31T10:05:00.000Z',
				'products'     => array( 'Gemini' ),
				'subtitles'    => array(),
				'safeHtmlItem' => array(
					array( 'html' => '<p>The capital of Germany is <b>Berlin</b>.</p>' ),
				),
			),
			array(
				'header'   => 'Google Search',
				'title'    => 'weather today',
				'time'     => '2025-01-15T12:00:00.000Z',
				'products' => array( 'Search' ),
			),
			array(
				'header'       => 'Gemini Apps',
				'title'        => 'Tell me a joke',
				'titleUrl'     => 'https://gemini.google.com/app/def456',
				'time'         => '2024-12-31T14:00:00.000Z',
				'products'     => array( 'Gemini' ),
				'subtitles'    => array(),
				'safeHtmlItem' => array(
					array( 'html' => '<p>Why don&apos;t scientists trust atoms?</p><p>Because they make up <i>everything</i>!</p>' ),
				),
			),
		);
	}

	/**
	 * Canonical model: session key shape and dedupe hash stability.
	 *
	 * @return void
	 */
	public function test_canonical_model_session_key_and_hash() {
		$conversation = WP_MCP_AI_Conversation_Import_Conversation::create(
			'chatgpt',
			'conv-abc',
			'Test',
			1700000000,
			1700000100,
			'gpt-4',
			array(
				array(
					'role'      => 'user',
					'content'   => 'Hi',
					'timestamp' => 1700000000,
				),
				array(
					'role'      => 'assistant',
					'content'   => 'Hello',
					'timestamp' => 1700000005,
				),
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $conversation );
		$this->assertSame( 'import-chatgpt-' . substr( sha1( 'conv-abc' ), 0, 12 ), $conversation->get_session_key() );
		$this->assertLessThanOrEqual( 96, strlen( $conversation->get_session_key() ) );
		$this->assertSame( $conversation->compute_dedupe_hash(), $conversation->compute_dedupe_hash() );
		$this->assertSame( 'assistant', $conversation->get_final_assistant_message()['role'] );
		$this->assertArrayHasKey( 'messages', json_decode( $conversation->encode_request_payload(), true ) );
	}

	/**
	 * Canonical model: invalid input yields WP_Error.
	 *
	 * @return void
	 */
	public function test_canonical_model_rejects_invalid_input() {
		$invalid_platform = WP_MCP_AI_Conversation_Import_Conversation::create(
			'',
			'conv-abc',
			'Test',
			0,
			0,
			'',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hi',
				),
			)
		);
		$this->assertInstanceOf( 'WP_Error', $invalid_platform );

		$no_messages = WP_MCP_AI_Conversation_Import_Conversation::create(
			'chatgpt',
			'conv-abc',
			'Test',
			0,
			0,
			'',
			array()
		);
		$this->assertInstanceOf( 'WP_Error', $no_messages );
	}

	/**
	 * ChatGPT adapter: current_node branch resolution and message order.
	 *
	 * @return void
	 */
	public function test_chatgpt_adapter_follows_current_node_branch() {
		$adapter   = new WP_MCP_AI_Conversation_Import_Adapter_Chatgpt();
		$extracted = iterator_to_array( $adapter->extract( $this->chatgpt_fixture() ) );

		$this->assertCount( 2, $extracted );

		$first  = $extracted[0];
		$second = $extracted[1];

		$this->assertInstanceOf( 'WP_MCP_AI_Conversation_Import_Conversation', $first );
		$this->assertSame( 'chatgpt', $first->get_platform() );
		$this->assertSame( 'conv-1', $first->get_source_id() );
		$this->assertSame( 'Hello World', $first->get_title() );
		$this->assertSame( 'gpt-4', $first->get_model() );

		$messages = $first->get_messages();
		$this->assertCount( 3, $messages );

		// Branch resolution: the regenerated answer, not the first attempt.
		$this->assertSame( 'user', $messages[0]['role'] );
		$this->assertSame( 'Hello!', $messages[0]['content'] );
		$this->assertSame( 'assistant', $messages[1]['role'] );
		$this->assertStringNotContainsString( 'First attempt', $messages[1]['content'] );
		$this->assertStringNotContainsString( '【cite】', $messages[1]['content'] );
		$this->assertSame( 'user', $messages[2]['role'] );
		$this->assertStringContainsString( '[Image: sediment://file_abc123]', $messages[2]['content'] );
		$this->assertStringContainsString( 'Thanks!', $messages[2]['content'] );

		// Hidden system message filtered; code content collapsed to text.
		$this->assertCount( 1, $second->get_messages() );
		$this->assertSame( "print('hi')", $second->get_messages()[0]['content'] );
	}

	/**
	 * ChatGPT adapter: keep_hidden option retains hidden messages.
	 *
	 * @return void
	 */
	public function test_chatgpt_adapter_keep_hidden_option() {
		$adapter   = new WP_MCP_AI_Conversation_Import_Adapter_Chatgpt();
		$extracted = iterator_to_array( $adapter->extract( $this->chatgpt_fixture(), array( 'keep_hidden' => true ) ) );

		$second = $extracted[1];
		$this->assertCount( 2, $second->get_messages() );
		$this->assertSame( 'system', $second->get_messages()[0]['role'] );
	}

	/**
	 * ChatGPT adapter: invalid payload shape yields WP_Error.
	 *
	 * @return void
	 */
	public function test_chatgpt_adapter_rejects_invalid_shape() {
		$adapter = new WP_MCP_AI_Conversation_Import_Adapter_Chatgpt();
		$result  = $adapter->extract( array( array( 'foo' => 'bar' ) ) );

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Gemini adapter: grouping by conversation URL and HTML stripping.
	 *
	 * @return void
	 */
	public function test_gemini_adapter_groups_and_strips_html() {
		$adapter   = new WP_MCP_AI_Conversation_Import_Adapter_Gemini();
		$extracted = iterator_to_array( $adapter->extract( $this->gemini_fixture() ) );

		// Two Gemini conversations; the Google Search record is excluded.
		$this->assertCount( 2, $extracted );

		$first = $extracted[0];
		$this->assertSame( 'gemini', $first->get_platform() );
		$this->assertSame( 'abc123', $first->get_source_id() );
		$this->assertCount( 4, $first->get_messages() );

		$assistant = $first->get_messages()[1];
		$this->assertSame( 'assistant', $assistant['role'] );
		$this->assertSame( 'The capital of France is Paris.', $assistant['content'] );
		$this->assertStringNotContainsString( '<b>', $assistant['content'] );

		$second = $extracted[1];
		$this->assertSame( 'def456', $second->get_source_id() );
		$this->assertCount( 2, $second->get_messages() );
		$this->assertStringNotContainsString( '&apos;', $second->get_messages()[1]['content'] );
	}

	/**
	 * Format detector: routes ChatGPT and Gemini fixtures to the right adapter.
	 *
	 * @return void
	 */
	public function test_format_detector_routes_fixtures() {
		$detector = new WP_MCP_AI_Conversation_Import_Format_Detector();

		$chatgpt_file = $this->write_temp_file( wp_json_encode( $this->chatgpt_fixture() ) );
		$gemini_file  = $this->write_temp_file( wp_json_encode( $this->gemini_fixture() ) );

		$chatgpt_detection = $detector->detect( $chatgpt_file );
		$this->assertNotInstanceOf( 'WP_Error', $chatgpt_detection );
		$this->assertSame( 'chatgpt', $chatgpt_detection['platform'] );

		$gemini_detection = $detector->detect( $gemini_file );
		$this->assertNotInstanceOf( 'WP_Error', $gemini_detection );
		$this->assertSame( 'gemini', $gemini_detection['platform'] );
	}

	/**
	 * Format detector: unknown payloads yield an actionable WP_Error.
	 *
	 * @return void
	 */
	public function test_format_detector_rejects_unknown_format() {
		$detector = new WP_MCP_AI_Conversation_Import_Format_Detector();
		$file     = $this->write_temp_file( wp_json_encode( array( array( 'foo' => 'bar' ) ) ) );

		$result = $detector->detect( $file );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_unknown_format', $result->get_error_code() );
	}

	/**
	 * Format detector: invalid JSON yields a decode error.
	 *
	 * @return void
	 */
	public function test_format_detector_rejects_invalid_json() {
		$detector = new WP_MCP_AI_Conversation_Import_Format_Detector();
		$file     = $this->write_temp_file( '{"broken":' );

		$result = $detector->detect( $file );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_json_decode_failed', $result->get_error_code() );
	}

	/**
	 * Archive: rejects zip-slip entries.
	 *
	 * @return void
	 */
	public function test_archive_rejects_zip_slip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'ZipArchive extension is not available.' );
		}

		$zip_file = $this->write_temp_file( '', '.zip' );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_file, ZipArchive::CREATE ) );
		$zip->addFromString( '../evil.txt', 'payload' );
		$zip->close();

		$archive = new WP_MCP_AI_Conversation_Import_Archive();
		$result  = $archive->prepare( $zip_file );
		$archive->cleanup();

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_zip_unsafe_entry', $result->get_error_code() );
	}

	/**
	 * Archive: passes plain JSON files straight through.
	 *
	 * @return void
	 */
	public function test_archive_passes_plain_json_through() {
		$file    = $this->write_temp_file( wp_json_encode( $this->chatgpt_fixture() ) );
		$archive = new WP_MCP_AI_Conversation_Import_Archive();
		$result  = $archive->prepare( $file );

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertContains( wp_normalize_path( $file ), $result );
	}

	/**
	 * CCT writer: record mapping mirrors the native transcript recorder shape.
	 *
	 * @return void
	 */
	public function test_cct_writer_build_record_mapping() {
		$conversation = WP_MCP_AI_Conversation_Import_Conversation::create(
			'chatgpt',
			'conv-abc',
			'Test',
			1700000000,
			1700000100,
			'gpt-4',
			array(
				array(
					'role'      => 'user',
					'content'   => 'Hi',
					'timestamp' => 1700000000,
				),
				array(
					'role'      => 'assistant',
					'content'   => 'Hello',
					'timestamp' => 1700000005,
				),
			)
		);

		$writer = new WP_MCP_AI_Conversation_Import_CCT_Writer();
		$record = $writer->build_record( $conversation, 7 );

		$this->assertSame( $conversation->get_session_key(), $record['session_key'] );
		$this->assertSame( 7, $record['user_id'] );
		$this->assertSame( 'import-chatgpt', $record['assistant_id'] );
		$this->assertSame( 'gpt-4', $record['assistant_model'] );
		$this->assertSame( 1700000000, $record['request_started_at'] );
		$this->assertSame( 1700000100, $record['response_completed_at'] );
		$this->assertArrayHasKey( 'metadata', $record );

		$metadata = json_decode( $record['metadata'], true );
		$this->assertSame( 'chatgpt', $metadata['import']['platform'] );
		$this->assertSame( 'conv-abc', $metadata['import']['source_id'] );
		$this->assertSame( $conversation->compute_dedupe_hash(), $metadata['import']['dedupe_hash'] );
	}

	/**
	 * CCT writer: write and lookup fail cleanly without JetEngine.
	 *
	 * @return void
	 */
	public function test_cct_writer_fails_cleanly_without_jetengine() {
		$writer = new WP_MCP_AI_Conversation_Import_CCT_Writer();

		$this->assertInstanceOf( 'WP_Error', $writer->find_existing_ids( array( 'import-chatgpt-abc' ) ) );
	}

	/**
	 * Manager: dry-run counts without writing.
	 *
	 * @return void
	 */
	public function test_manager_dry_run_counts_without_writing() {
		$file = $this->write_temp_file( wp_json_encode( $this->chatgpt_fixture() ) );

		$stub           = new WP_MCP_AI_Conversation_Import_Stub_Writer();
		$stub->existing = array();

		$manager = new WP_MCP_AI_Conversation_Import_Manager( $stub );
		$report  = $manager->run(
			array(
				'source'  => $file,
				'dry_run' => true,
				'user_id' => 1,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertSame( 'completed', $report['status'] );
		$this->assertSame( 2, $report['totals']['detected'] );
		$this->assertSame( 2, $report['totals']['imported'] );
		$this->assertSame( 0, $report['totals']['failed'] );
		$this->assertEmpty( $stub->written );
	}

	/**
	 * Manager: skip policy leaves existing rows untouched.
	 *
	 * @return void
	 */
	public function test_manager_skip_policy() {
		$file = $this->write_temp_file( wp_json_encode( $this->chatgpt_fixture() ) );

		$stub           = new WP_MCP_AI_Conversation_Import_Stub_Writer();
		$stub->existing = array(
			'import-chatgpt-' . substr( sha1( 'conv-1' ), 0, 12 ) => 42,
		);

		$manager = new WP_MCP_AI_Conversation_Import_Manager( $stub );
		$report  = $manager->run(
			array(
				'source'  => $file,
				'policy'  => 'skip',
				'user_id' => 1,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertSame( 1, $report['totals']['imported'] );
		$this->assertSame( 1, $report['totals']['skipped'] );
		$this->assertCount( 1, $stub->written );
	}

	/**
	 * Manager: refresh policy updates existing rows.
	 *
	 * @return void
	 */
	public function test_manager_refresh_policy() {
		$file = $this->write_temp_file( wp_json_encode( $this->chatgpt_fixture() ) );

		$stub           = new WP_MCP_AI_Conversation_Import_Stub_Writer();
		$stub->existing = array(
			'import-chatgpt-' . substr( sha1( 'conv-1' ), 0, 12 ) => 42,
		);

		$manager = new WP_MCP_AI_Conversation_Import_Manager( $stub );
		$report  = $manager->run(
			array(
				'source'  => $file,
				'policy'  => 'refresh',
				'user_id' => 1,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertSame( 1, $report['totals']['updated'] );
		$this->assertSame( 1, $report['totals']['imported'] );
		$this->assertCount( 2, $stub->written );
	}

	/**
	 * Manager: limit caps the number of processed conversations.
	 *
	 * @return void
	 */
	public function test_manager_limit_caps_processing() {
		$file = $this->write_temp_file( wp_json_encode( $this->chatgpt_fixture() ) );

		$stub    = new WP_MCP_AI_Conversation_Import_Stub_Writer();
		$manager = new WP_MCP_AI_Conversation_Import_Manager( $stub );
		$report  = $manager->run(
			array(
				'source'  => $file,
				'limit'   => 1,
				'user_id' => 1,
			)
		);

		$this->assertNotInstanceOf( 'WP_Error', $report );
		$this->assertSame( 1, $report['totals']['processed'] );
		$this->assertCount( 1, $stub->written );
	}

	/**
	 * Manager: unknown resume token yields WP_Error.
	 *
	 * @return void
	 */
	public function test_manager_rejects_unknown_resume_token() {
		$file = $this->write_temp_file( wp_json_encode( $this->chatgpt_fixture() ) );

		$stub    = new WP_MCP_AI_Conversation_Import_Stub_Writer();
		$manager = new WP_MCP_AI_Conversation_Import_Manager( $stub );
		$result  = $manager->run(
			array(
				'source'       => $file,
				'user_id'      => 1,
				'resume_token' => 'import-00000000000000-deadbeef0000',
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_resume_not_found', $result->get_error_code() );
	}
}
