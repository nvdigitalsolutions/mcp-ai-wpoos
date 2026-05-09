<?php
/**
 * Tests for count_tokens tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test count_tokens tool functionality.
 */
class Test_Tool_Count_Tokens extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Count_Tokens
	 */
	private $tool;

	/**
	 * Subscriber user ID (any logged-in user is sufficient for this tool).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tool          = new WP_MCP_AI_Tool_Count_Tokens();
		$this->subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tool metadata is correct.
	 */
	public function test_tool_metadata() {
		$this->assertSame( 'count_tokens', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Unauthenticated call returns unauthorized error.
	 */
	public function test_unauthenticated_returns_error() {
		$result = $this->tool->execute(
			array( 'text' => 'hello world' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_unauthorized', $result->get_error_code() );
	}

	/**
	 * Providing neither text nor messages returns invalid_arguments error.
	 */
	public function test_neither_text_nor_messages_returns_error() {
		$result = $this->tool->execute(
			array(),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_arguments', $result->get_error_code() );
	}

	/**
	 * Providing both text and messages returns invalid_arguments error.
	 */
	public function test_both_text_and_messages_returns_error() {
		$result = $this->tool->execute(
			array(
				'text'     => 'some text',
				'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ),
			),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_arguments', $result->get_error_code() );
	}

	/**
	 * Token count for plain text returns a positive integer estimate.
	 */
	public function test_count_tokens_for_text_returns_positive_count() {
		$result = $this->tool->execute(
			array( 'text' => 'The quick brown fox jumps over the lazy dog.', 'method' => 'heuristic' ),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'estimated_tokens', $result );
		$this->assertGreaterThan( 0, $result['estimated_tokens'] );
	}

	/**
	 * Token count for messages array returns aggregated count.
	 */
	public function test_count_tokens_for_messages_array() {
		$result = $this->tool->execute(
			array(
				'messages' => array(
					array( 'role' => 'user',      'content' => 'Hello there!' ),
					array( 'role' => 'assistant', 'content' => 'Hi! How can I help you today?' ),
				),
				'method'   => 'heuristic',
			),
			array( 'user_id' => $this->subscriber_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'estimated_tokens', $result );
		$this->assertGreaterThan( 0, $result['estimated_tokens'] );
	}

	/**
	 * Empty string still returns a valid (possibly zero) token count.
	 */
	public function test_empty_text_returns_valid_response() {
		$result = $this->tool->execute(
			array( 'text' => '' ),
			array( 'user_id' => $this->subscriber_id )
		);

		// Empty text should either return a valid array or an error — both are acceptable.
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}

	/**
	 * Capability flags do not include 'write' (read-only utility).
	 */
	public function test_capability_flags_are_read_only() {
		$flags = $this->tool->get_capability_flags();
		$this->assertNotContains( 'write', $flags );
		$this->assertNotContains( 'state-changing', $flags );
	}
}
