<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Context.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /context slash command.
 */
class Test_Slash_Command_Context extends WP_UnitTestCase {

	/**
	 * Command instance under test.
	 *
	 * @var WP_MCP_AI_Slash_Command_Context
	 */
	private $command;

	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-context.php';
		$this->command = new WP_MCP_AI_Slash_Command_Context();
	}

	/**
	 * Empty conversation → 0% used, OK status, message_count=0.
	 */
	public function test_empty_messages_reports_zero_usage() {
		$result = $this->command->execute( array(), array(), array( 'messages' => array() ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['data']['estimated_tokens'] );
		$this->assertSame( 0, $result['data']['message_count'] );
		$this->assertSame( 0.0, $result['data']['usage_percentage'] );
		$this->assertSame( 'OK', $result['data']['status'] );
		$this->assertStringContainsString( '🟢', $result['message'] );
	}

	/**
	 * Counts roles correctly across mixed conversation.
	 */
	public function test_role_counts_accumulate() {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'hello there',
			),
			array(
				'role'    => 'assistant',
				'content' => 'hi!',
			),
			array(
				'role'    => 'user',
				'content' => 'second turn',
			),
			array(
				'role'    => 'tool',
				'name'    => 'search',
				'content' => 'tool output payload',
			),
		);

		$result = $this->command->execute( array(), array(), array( 'messages' => $messages ) );

		$this->assertSame( 5, $result['data']['message_count'] );
		$this->assertSame( 1, $result['data']['role_counts']['system'] );
		$this->assertSame( 2, $result['data']['role_counts']['user'] );
		$this->assertSame( 1, $result['data']['role_counts']['assistant'] );
		$this->assertSame( 1, $result['data']['role_counts']['tool'] );
		$this->assertGreaterThan( 0, $result['data']['estimated_tokens'] );
		$this->assertGreaterThan( 0, $result['data']['tool_tokens'] );
	}

	/**
	 * tool_calls payloads inflate tool_call_count and tool_tokens.
	 */
	public function test_tool_calls_are_counted_and_tokenised() {
		$messages = array(
			array(
				'role'       => 'assistant',
				'content'    => 'invoking tools…',
				'tool_calls' => array(
					array(
						'id'   => 'call_1',
						'type' => 'function',
					),
					array(
						'id'   => 'call_2',
						'type' => 'function',
					),
				),
			),
		);

		$result = $this->command->execute( array(), array(), array( 'messages' => $messages ) );

		$this->assertSame( 2, $result['data']['tool_call_count'] );
		$this->assertGreaterThan( 0, $result['data']['tool_tokens'] );
	}

	/**
	 * The token-limit filter overrides the model fallback.
	 */
	public function test_token_limit_filter_is_honoured() {
		add_filter(
			'wp_mcp_ai_chat_request_token_limit',
			static function () {
				return 1000;
			}
		);

		$messages = array(
			array(
				'role'    => 'user',
				// 4000 chars / 4 = ~1000 tokens, fully consuming the 1000-token cap.
				'content' => str_repeat( 'a', 4000 ),
			),
		);

		$result = $this->command->execute( array(), array(), array( 'messages' => $messages ) );

		remove_all_filters( 'wp_mcp_ai_chat_request_token_limit' );

		$this->assertSame( 1000, $result['data']['max_tokens'] );
		$this->assertSame( 'CRITICAL', $result['data']['status'] );
		$this->assertStringContainsString( '🔴', $result['message'] );
		$this->assertStringContainsString( 'Recommendation', $result['message'] );
	}

	/**
	 * --verbose flag adds the breakdown section.
	 */
	public function test_verbose_flag_renders_breakdown_section() {
		$result = $this->command->execute(
			array(),
			array( 'verbose' => true ),
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'hi',
					),
				),
			)
		);

		$this->assertStringContainsString( '**Detailed Breakdown:**', $result['message'] );
		$this->assertStringContainsString( 'Total characters', $result['message'] );
	}

	/**
	 * Model name is sanitised and surfaced in data.
	 */
	public function test_model_is_sanitised_and_surfaced() {
		$result = $this->command->execute(
			array(),
			array(),
			array(
				'messages' => array(),
				'model'    => 'gpt-4o',
			)
		);

		$this->assertSame( 'gpt-4o', $result['data']['model'] );
		$this->assertGreaterThanOrEqual( 128000, $result['data']['max_tokens'] );
	}
}
