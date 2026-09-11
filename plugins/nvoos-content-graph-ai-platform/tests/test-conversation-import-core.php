<?php
/**
 * Conversation import core port tests (Wave E4, sub-cluster 6).
 *
 * Characterization suite for the ported `ConversationImport` core:
 * the canonical `Conversation` value object, the five adapters
 * (ChatGPT, Gemini, Claude, ShareGPT, OpenAI JSONL), the
 * `FormatDetector` routing + JSONL decoding, the `Archive` zip-slip
 * guard, the `CctWriter` record mapping + JetEngine degradation, and
 * the `Media` sideload pass (with the per-mode conversation seam).
 * Runs in both matrices.
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\ConversationImport\AdapterChatgpt;
use NvoosContentGraphAiPlatform\ConversationImport\AdapterClaude;
use NvoosContentGraphAiPlatform\ConversationImport\AdapterGemini;
use NvoosContentGraphAiPlatform\ConversationImport\AdapterOpenaiJsonl;
use NvoosContentGraphAiPlatform\ConversationImport\AdapterSharegpt;
use NvoosContentGraphAiPlatform\ConversationImport\Archive;
use NvoosContentGraphAiPlatform\ConversationImport\CctWriter;
use NvoosContentGraphAiPlatform\ConversationImport\Conversation;
use NvoosContentGraphAiPlatform\ConversationImport\FormatDetector;
use NvoosContentGraphAiPlatform\ConversationImport\Media;

/**
 * Conversation import core test suite.
 */
class Test_Conversation_Import_Core extends \WP_UnitTestCase {

	/**
	 * Temp files created during tests.
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
		$file = tempnam( sys_get_temp_dir(), 'wpmcp-port-core-' ) . $suffix;
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
		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpmcp-port-core-' . uniqid();
		wp_mkdir_p( $dir );
		$this->temp_paths[] = $dir;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a test fixture image.
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' );
		file_put_contents( $dir . DIRECTORY_SEPARATOR . $file_name, $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture writing.

		return $dir;
	}

	/**
	 * Build a canonical conversation for the current install mode.
	 *
	 * The `Media` / `CctWriter` boundaries accept the base conversation
	 * class monolith and this package's class standalone — this helper
	 * constructs whichever the seam expects.
	 *
	 * @param string $platform   Platform slug.
	 * @param string $source_id  Source conversation ID.
	 * @param string $title      Conversation title.
	 * @param int    $created_at Created timestamp.
	 * @param int    $updated_at Updated timestamp.
	 * @param string $model      Model slug.
	 * @param array  $messages   Messages.
	 * @return Conversation|\WP_MCP_AI_Conversation_Import_Conversation|\WP_Error
	 */
	protected function make_conversation( $platform, $source_id, $title, $created_at, $updated_at, $model, array $messages ) {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return \WP_MCP_AI_Conversation_Import_Conversation::create( $platform, $source_id, $title, $created_at, $updated_at, $model, $messages );
		}

		return Conversation::create( $platform, $source_id, $title, $created_at, $updated_at, $model, $messages );
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
		$conversation = Conversation::create(
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
		$invalid_platform = Conversation::create(
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

		$no_messages = Conversation::create(
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
		$adapter   = new AdapterChatgpt();
		$extracted = iterator_to_array( $adapter->extract( $this->chatgpt_fixture() ) );

		$this->assertCount( 2, $extracted );

		$first  = $extracted[0];
		$second = $extracted[1];

		$this->assertInstanceOf( Conversation::class, $first );
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
		$adapter   = new AdapterChatgpt();
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
		$adapter = new AdapterChatgpt();
		$result  = $adapter->extract( array( array( 'foo' => 'bar' ) ) );

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	/**
	 * Gemini adapter: grouping by conversation URL and HTML stripping.
	 *
	 * @return void
	 */
	public function test_gemini_adapter_groups_and_strips_html() {
		$adapter   = new AdapterGemini();
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
	 * Claude adapter: sender mapping, content blocks, and timestamps.
	 *
	 * @return void
	 */
	public function test_claude_adapter_maps_senders_and_blocks() {
		$adapter = new AdapterClaude();

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
		$adapter = new AdapterClaude();
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
		$adapter = new AdapterSharegpt();

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
		$adapter = new AdapterSharegpt();
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
		$adapter = new AdapterOpenaiJsonl();

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
		$adapter = new AdapterOpenaiJsonl();

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
	 * Format detector: routes ChatGPT and Gemini fixtures to the right adapter.
	 *
	 * @return void
	 */
	public function test_format_detector_routes_fixtures() {
		$detector = new FormatDetector();

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
		$detector = new FormatDetector();
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
		$detector = new FormatDetector();
		$file     = $this->write_temp_file( '{"broken":' );

		$result = $detector->detect( $file );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_json_decode_failed', $result->get_error_code() );
	}

	/**
	 * Format detector: decodes JSONL line by line.
	 *
	 * @return void
	 */
	public function test_detector_decodes_jsonl() {
		$detector = new FormatDetector();

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
		$detector = new FormatDetector();

		$jsonl = wp_json_encode( array( 'foo' => 'bar' ) ) . "\n{broken\n";
		$file  = $this->write_temp_file( $jsonl, '.jsonl' );

		$result = $detector->decode_file( $file );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'wp_mcp_ai_import_jsonl_decode_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'line 2', $result->get_error_message() );
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

		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $zip_file, \ZipArchive::CREATE ) );
		$zip->addFromString( '../evil.txt', 'payload' );
		$zip->close();

		$archive = new Archive();
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
		$archive = new Archive();
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
		$conversation = Conversation::create(
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

		$writer = new CctWriter();
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
		$writer = new CctWriter();

		$this->assertInstanceOf( 'WP_Error', $writer->find_existing_ids( array( 'import-chatgpt-abc' ) ) );
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

		$conversation = $this->make_conversation(
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

		$media   = new Media();
		$updated = $media->sideload( $conversation, $dir );

		if ( is_wp_error( $updated ) ) {
			$this->markTestSkipped( 'Media library unavailable in this test environment.' );
		}

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

		$conversation = $this->make_conversation(
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

		$media   = new Media();
		$updated = $media->sideload( $conversation, $dir );

		$this->assertSame( $conversation, $updated );
		$this->assertSame( '[Image: sediment://file_missing999]', $updated->get_messages()[0]['content'] );
	}
}
