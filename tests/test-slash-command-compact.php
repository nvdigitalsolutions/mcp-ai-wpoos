<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Compact.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /compact slash command.
 */
class Test_Slash_Command_Compact extends WP_UnitTestCase {

	/**
	 * Command instance under test.
	 *
	 * @var WP_MCP_AI_Slash_Command_Compact
	 */
	private $command;

	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-compact.php';
		$this->command = new WP_MCP_AI_Slash_Command_Compact();
	}

	/**
	 * Build a synthetic conversation for compaction tests.
	 *
	 * @param int $n_user_assistant_pairs Number of user/assistant turn pairs.
	 * @return array
	 */
	private function build_conversation( $n_user_assistant_pairs = 5 ) {
		$messages = array();
		for ( $i = 0; $i < $n_user_assistant_pairs; $i++ ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => 'Question about topic ' . $i . ' that has enough text to be measurable.',
			);
			$messages[] = array(
				'role'       => 'assistant',
				'content'    => 'I think the answer for topic ' . $i . ' is fairly straightforward to explain in detail.',
				'tool_calls' => array(
					array(
						'id'   => 'call_' . $i,
						'type' => 'function',
					),
				),
			);
			$messages[] = array(
				'role'    => 'tool',
				'name'    => 'search_tool',
				'content' => str_repeat( 'tool result payload ', 20 ),
			);
		}
		return $messages;
	}

	/**
	 * Unknown strategy → WP_Error.
	 */
	public function test_invalid_strategy_returns_wp_error() {
		$result = $this->command->execute(
			array(),
			array( 'strategy' => 'totally-bogus' ),
			array( 'messages' => $this->build_conversation() )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_strategy', $result->get_error_code() );
	}

	/**
	 * Tiny conversation (<3 messages) is reported as already compact.
	 */
	public function test_short_conversation_short_circuits() {
		$result = $this->command->execute(
			array(),
			array(),
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'hi',
					),
					array(
						'role'    => 'assistant',
						'content' => 'hello',
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'none', $result['data']['strategy'] );
		$this->assertSame( 0, $result['data']['tokens_saved'] );
	}

	/**
	 * keep-recent drops everything but the most recent N.
	 */
	public function test_keep_recent_drops_older_messages() {
		$messages = $this->build_conversation( 5 ); // 15 messages total.

		$result = $this->command->execute(
			array(),
			array(
				'strategy' => 'keep-recent',
				'keep'     => 4,
			),
			array( 'messages' => $messages )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'keep-recent', $result['data']['strategy'] );
		$this->assertSame( 15, $result['data']['messages_before'] );
		$this->assertSame( 4, $result['data']['messages_after'] );
		$this->assertCount( 4, $result['data']['compacted_messages'] );
		$this->assertGreaterThan( 0, $result['data']['tokens_saved'] );
	}

	/**
	 * trim-tools removes role=tool messages and strips tool_calls from older
	 * assistant messages while preserving the kept tail.
	 */
	public function test_trim_tools_removes_tool_messages_from_older_history() {
		$messages = $this->build_conversation( 5 );

		$result = $this->command->execute(
			array(),
			array(
				'strategy' => 'trim-tools',
				'keep'     => 3,
			),
			array( 'messages' => $messages )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'trim-tools', $result['data']['strategy'] );
		$this->assertGreaterThan( 0, $result['data']['tokens_saved'] );

		$compacted   = $result['data']['compacted_messages'];
		$older_slice = array_slice( $compacted, 0, count( $compacted ) - 3 );
		foreach ( $older_slice as $msg ) {
			$this->assertNotSame( 'tool', $msg['role'] );
			if ( 'assistant' === $msg['role'] ) {
				$this->assertArrayNotHasKey( 'tool_calls', $msg );
			}
		}
	}

	/**
	 * Default summarize strategy injects a single system summary in front of
	 * the kept tail.
	 */
	public function test_summarize_default_prepends_summary() {
		$messages = $this->build_conversation( 5 );

		$result = $this->command->execute( array(), array(), array( 'messages' => $messages ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'summarize', $result['data']['strategy'] );
		$this->assertArrayHasKey( 'summary', $result['data'] );
		$this->assertNotEmpty( $result['data']['summary'] );

		$compacted = $result['data']['compacted_messages'];
		$this->assertSame( 'system', $compacted[0]['role'] );
		$this->assertStringContainsString( '[Context Summary]', $compacted[0]['content'] );
		$this->assertLessThan( count( $messages ), count( $compacted ) );
	}

	/**
	 * full strategy runs without error and returns a structured result.
	 */
	public function test_full_strategy_runs_without_error() {
		$messages = $this->build_conversation( 4 );

		$result = $this->command->execute(
			array(),
			array( 'strategy' => 'full' ),
			array( 'messages' => $messages )
		);

		$this->assertNotInstanceOf( 'WP_Error', $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
	}
}
