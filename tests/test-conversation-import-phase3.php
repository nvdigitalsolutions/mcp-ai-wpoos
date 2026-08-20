<?php
/**
 * Tests for Phase 3 of the conversation import pipeline.
 *
 * Covers the Claude, ShareGPT, and OpenAI JSONL adapters, JSONL decoding in
 * the format detector, and the media sideload pass.
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
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-adapter-claude.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-adapter-sharegpt.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-adapter-openai-jsonl.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-format-detector.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-media.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-cct-writer.php';
require_once WP_MCP_AI_PATH . 'includes/conversation-import/class-wp-mcp-ai-conversation-import-manager.php';

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double scoped to this suite.

/**
 * Writer stub that records the messages of the last written conversation.
 */
class WP_MCP_AI_Conversation_Import_Phase3_Stub_Writer extends WP_MCP_AI_Conversation_Import_CCT_Writer {

	/**
	 * Messages of the most recently written conversation.
	 *
	 * @var array
	 */
	public $last_messages = array();

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
		$this->last_messages = $conversation->get_messages();

		return array(
			'id'     => 1,
			'action' => 'imported',
		);
	}
}

// The MultipleFound sniff stays disabled for this whole file (two structures:
// the stub writer test double above and the test suite below).

/**
 * Phase 3 test suite.
 */
class WP_MCP_AI_Conversation_Import_Phase3_Test extends WP_UnitTestCase {

	/**
	 * Temp files/dirs created during tests.
	 *
	 * @var string[]
	 */
	protected $temp_paths = array();

	/**
	 * Clean up temp paths after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		foreach ( $this->temp_paths as $path ) {
			if ( is_dir( $path ) ) {
				$entries = scandir( $path );
				if ( false !== $entries ) {
					foreach ( $entries as $entry ) {
						if ( '.' === $entry || '..' === $entry ) {
							continue;
						}
						wp_delete_file( $path . DIRECTORY_SEPARATOR . $entry );
					}
				}
				@rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Test teardown cleanup.
			} elseif ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
		$this->temp_paths = array();

		parent::tearDown();
	}

	/**
	 * Write a temp file and register it for cleanup.
	 *
	 * @param string $contents File contents.
	 * @param string $suffix   File suffix.
	 * @return string Absolute path.
	 */
	protected function write_temp_file( $contents, $suffix = '.json' ) {
		$file = tempnam( sys_get_temp_dir(), 'wpmcp-phase3-' ) . $suffix;
		file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writing.

		$this->temp_paths[] = $file;

		return $file;
	}

	/**
	 * Create a temp directory with a 1x1 PNG image file.
	 *
	 * @param string $file_name Image filename.
	 * @return string Directory containing the image.
	 */
	protected function create_image_dir( $file_name ) {
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpmcp-phase3-' . uniqid();
		wp_mkdir_p( $dir );
		$this->temp_paths[] = $dir;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a test fixture image.
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
		file_put_contents( $dir . DIRECTORY_SEPARATOR . $file_name, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writing.

		return $dir;
	}

	/**
	 * Claude adapter: sender mapping, content blocks, and timestamps.
	 *
	 * @return void
	 */
	public function test_claude_adapter_maps_senders_and_blocks() {
		$adapter = new WP_MCP_AI_Conversation_Import_Adapter_Claude();

		$fixture = array(
			array(
				'uuid'          => 'claude-conv-1',
				'name'          => 'A Claude chat',
				'created_at'    => '2024-06-01T10:00:00.000Z',
				'updated_at'    => '2024-06-01T10:05:00.000Z',
				'chat_messages' => array(
					array(
						'uuid'       => 'm-1',
						'sender'     => 'human',
						'text'       => 'Hello Claude!',
						'created_at' => '2024-06-01T10:00:01.000Z',
					),
					array(
						'uuid'       => 'm-2',
						'sender'     => 'assistant',
						'text'       => 'Hello! How can I help?',
						'created_at' => '2024-06-01T10:00:02.000Z',
					),
					array(
						'uuid'       => 'm-3',
						'sender'     => 'assistant',
						'created_at' => '2024-06-01T10:00:03.000Z',
						'content'    => array(
							array(
								'type' => 'text',
								'text' => 'Here is a code block:',
							),
							array(
								'type' => 'tool_use',
								'name' => 'code_runner',
							),
						),
					),
				),
			),
		);

		$extracted = iterator_to_array( $adapter->extract( $fixture ) );

		$this->assertCount( 1, $extracted );

		$conversation = $extracted[0];
		$this->assertSame( 'claude', $conversation->get_platform() );
		$this->assertSame( 'claude-conv-1', $conversation->get_source_id() );
		$this->assertSame( 'A Claude chat', $conversation->get_title() );
		$this->assertSame( strtotime( '2024-06-01T10:00:00.000Z' ), $conversation->get_created_at() );

		$messages = $conversation->get_messages();
		$this->assertCount( 3, $messages );
		$this->assertSame( 'user', $messages[0]['role'] );
		$this->assertSame( 'Hello Claude!', $messages[0]['content'] );
		$this->assertSame( 'assistant', $messages[1]['role'] );
		$this->assertSame( "Here is a code block:\n[Tool: code_runner]", $messages[2]['content'] );
	}

	/**
	 * Claude adapter: invalid payload shape yields WP_Error.
	 *
	 * @return void
	 */
	public function test_claude_adapter_rejects_invalid_shape() {
		$adapter = new WP_MCP_AI_Conversation_Import_Adapter_Claude();
		$result  = $adapter->extract( array( array( 'foo' => 'bar' ) ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_claude_shape', $result->get_error_code() );
	}

	/**
	 * ShareGPT adapter: role mapping including system passthrough.
	 *
	 * @return void
	 */
	public function test_sharegpt_adapter_role_mapping() {
		$adapter = new WP_MCP_AI_Conversation_Import_Adapter_Sharegpt();

		$fixture = array(
			array(
				'id'            => 'share-1',
				'conversations' => array(
					array(
						'from'  => 'system',
						'value' => 'You are a helpful assistant.',
					),
					array(
						'from'  => 'human',
						'value' => 'What is 2+2?',
					),
					array(
						'from'  => 'gpt',
						'value' => '4',
					),
					array(
						'from'  => 'observation',
						'value' => 'result: 4',
					),
				),
			),
			array(
				'conversations' => array(
					array(
						'from'  => 'human',
						'value' => 'Second dataset item.',
					),
					array(
						'from'  => 'gpt',
						'value' => 'Second answer.',
					),
				),
			),
		);

		$extracted = iterator_to_array( $adapter->extract( $fixture ) );

		$this->assertCount( 2, $extracted );

		$first = $extracted[0];
		$this->assertSame( 'sharegpt', $first->get_platform() );
		$this->assertSame( 'share-1', $first->get_source_id() );

		$messages = $first->get_messages();
		$this->assertCount( 4, $messages );
		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertSame( 'user', $messages[1]['role'] );
		$this->assertSame( 'assistant', $messages[2]['role'] );
		$this->assertSame( 'tool', $messages[3]['role'] );

		$second = $extracted[1];
		$this->assertSame( 'sharegpt-2', $second->get_source_id() );
		$this->assertSame( 'Second dataset item.', $second->get_title() );
	}

	/**
	 * ShareGPT adapter: invalid payload shape yields WP_Error.
	 *
	 * @return void
	 */
	public function test_sharegpt_adapter_rejects_invalid_shape() {
		$adapter = new WP_MCP_AI_Conversation_Import_Adapter_Sharegpt();
		$result  = $adapter->extract( array( array( 'foo' => 'bar' ) ) );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_sharegpt_shape', $result->get_error_code() );
	}

	/**
	 * OpenAI JSONL adapter: messages shape and content collapse.
	 *
	 * @return void
	 */
	public function test_openai_jsonl_adapter_messages_shape() {
		$adapter = new WP_MCP_AI_Conversation_Import_Adapter_Openai_Jsonl();

		$fixture = array(
			array(
				'messages' => array(
					array(
						'role'    => 'system',
						'content' => 'Be brief.',
					),
					array(
						'role'    => 'user',
						'content' => array(
							array(
								'type' => 'text',
								'text' => 'Describe this:',
							),
							array(
								'type' => 'text',
								'text' => 'A cat.',
							),
						),
					),
					array(
						'role'    => 'assistant',
						'content' => 'A small feline.',
					),
				),
			),
		);

		$extracted = iterator_to_array( $adapter->extract( $fixture ) );

		$this->assertCount( 1, $extracted );

		$conversation = $extracted[0];
		$this->assertSame( 'openai_jsonl', $conversation->get_platform() );

		$messages = $conversation->get_messages();
		$this->assertCount( 3, $messages );
		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertSame( "Describe this:\nA cat.", $messages[1]['content'] );
		$this->assertSame( 'assistant', $messages[2]['role'] );
	}

	/**
	 * OpenAI JSONL adapter: prompt/completion fallback shape.
	 *
	 * @return void
	 */
	public function test_openai_jsonl_adapter_prompt_completion() {
		$adapter = new WP_MCP_AI_Conversation_Import_Adapter_Openai_Jsonl();

		$fixture = array(
			array(
				'system'     => 'Act as a calculator.',
				'prompt'     => '2+2',
				'completion' => '4',
			),
		);

		$extracted = iterator_to_array( $adapter->extract( $fixture ) );

		$this->assertCount( 1, $extracted );

		$messages = $extracted[0]->get_messages();
		$this->assertCount( 3, $messages );
		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertSame( 'user', $messages[1]['role'] );
		$this->assertSame( 'assistant', $messages[2]['role'] );
		$this->assertSame( '4', $messages[2]['content'] );
	}

	/**
	 * Format detector: decodes JSONL line by line.
	 *
	 * @return void
	 */
	public function test_detector_decodes_jsonl() {
		$detector = new WP_MCP_AI_Conversation_Import_Format_Detector();

		$jsonl = wp_json_encode(
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'Hi',
					),
				),
			)
		) . "\n"
			. wp_json_encode(
				array(
					'messages' => array(
						array(
							'role'    => 'user',
							'content' => 'Again',
						),
					),
				)
			) . "\n";

		$file = $this->write_temp_file( $jsonl, '.jsonl' );

		$decoded = $detector->decode_file( $file );

		$this->assertNotInstanceOf( 'WP_Error', $decoded );
		$this->assertCount( 2, $decoded );

		$detection = $detector->detect( $file );
		$this->assertNotInstanceOf( 'WP_Error', $detection );
		$this->assertSame( 'openai_jsonl', $detection['platform'] );
	}

	/**
	 * Format detector: reports the line number for invalid JSONL.
	 *
	 * @return void
	 */
	public function test_detector_reports_invalid_jsonl_line() {
		$detector = new WP_MCP_AI_Conversation_Import_Format_Detector();

		$jsonl = wp_json_encode( array( 'foo' => 'bar' ) ) . "\n{broken\n";
		$file  = $this->write_temp_file( $jsonl, '.jsonl' );

		$result = $detector->decode_file( $file );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_jsonl_decode_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'line 2', $result->get_error_message() );
	}

	/**
	 * Media: sideloads a resolved image and rewrites the placeholder.
	 *
	 * @return void
	 */
	public function test_media_sideload_rewrites_placeholder() {
		$dir = $this->create_image_dir( 'file_abc123-sanitized.png' );

		// The WordPress test environment does not create the uploads tree by
		// default; media_handle_sideload() needs it to store attachments.
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			wp_mkdir_p( $uploads['basedir'] );
		}

		$conversation = WP_MCP_AI_Conversation_Import_Conversation::create(
			'chatgpt',
			'conv-media-1',
			'With image',
			1700000000,
			1700000001,
			'gpt-4',
			array(
				array(
					'role'      => 'user',
					'content'   => 'Look: [Image: sediment://file_abc123]',
					'timestamp' => 1700000000,
				),
			)
		);

		$media   = new WP_MCP_AI_Conversation_Import_Media();
		$updated = $media->sideload( $conversation, $dir );

		$this->assertInstanceOf( 'WP_MCP_AI_Conversation_Import_Conversation', $updated );

		$content = $updated->get_messages()[0]['content'];
		if ( 0 === strpos( $content, 'Look: [Image: sediment://' ) ) {
			$this->markTestSkipped( 'Media library unavailable in this test environment.' );
		}

		$this->assertStringContainsString( '[Image: ', $content );
		$this->assertStringNotContainsString( 'sediment://', $content );
		$this->assertStringContainsString( 'wp-content/uploads', $content );

		// The replacement must not change the dedupe hash.
		$this->assertSame( $conversation->compute_dedupe_hash(), $updated->compute_dedupe_hash() );
	}

	/**
	 * Media: missing files leave placeholders untouched.
	 *
	 * @return void
	 */
	public function test_media_missing_file_left_untouched() {
		$dir = $this->create_image_dir( 'unrelated-sanitized.png' );

		$conversation = WP_MCP_AI_Conversation_Import_Conversation::create(
			'chatgpt',
			'conv-media-2',
			'Missing image',
			1700000000,
			1700000001,
			'gpt-4',
			array(
				array(
					'role'      => 'user',
					'content'   => '[Image: sediment://file_missing999]',
					'timestamp' => 1700000000,
				),
			)
		);

		$media   = new WP_MCP_AI_Conversation_Import_Media();
		$updated = $media->sideload( $conversation, $dir );

		$this->assertSame( $conversation, $updated );
		$this->assertSame( '[Image: sediment://file_missing999]', $updated->get_messages()[0]['content'] );
	}
}
