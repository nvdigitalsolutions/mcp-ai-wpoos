<?php
/**
 * Test Command Parser Hyphen Handling
 *
 * Tests that the command parser correctly handles command names with hyphens.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Command Parser Hyphen Test Class
 */
class Test_Command_Parser_Hyphen_Fix extends WP_UnitTestCase {

	/**
	 * Parser instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Parser
	 */
	private $parser;

	/**
	 * Set up test environment
	 */
	public function setUp(): void {
		parent::setUp();
		
		// Load parser class.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';
		
		$this->parser = new WP_MCP_AI_Slash_Command_Parser();
	}

	/**
	 * Test parsing command with single hyphen
	 */
	public function test_parse_command_with_single_hyphen() {
		$result = $this->parser->parse( '/optimize-perf' );

		$this->assertNotWPError( $result, 'Parser should not return error for hyphenated command' );
		$this->assertEquals( 'optimize-perf', $result['command'], 'Command name should include hyphen' );
		$this->assertEmpty( $result['args'], 'Should have no positional arguments' );
		$this->assertEmpty( $result['flags'], 'Should have no flags' );
	}

	/**
	 * Test parsing command with multiple hyphens
	 */
	public function test_parse_command_with_multiple_hyphens() {
		$result = $this->parser->parse( '/my-multi-word-command' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'my-multi-word-command', $result['command'] );
	}

	/**
	 * Test parsing hyphenated command with arguments
	 */
	public function test_parse_hyphenated_command_with_arguments() {
		$result = $this->parser->parse( '/optimize-perf --dry-run --phases=1,2,3' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'optimize-perf', $result['command'], 'Command should be optimize-perf' );
		$this->assertTrue( $result['flags']['dry-run'], 'Should have dry-run flag' );
		$this->assertEquals( '1,2,3', $result['flags']['phases'], 'Should have phases flag' );
	}

	/**
	 * Test parsing clean-content command
	 */
	public function test_parse_clean_content_command() {
		$result = $this->parser->parse( '/clean-content --post-id=123' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'clean-content', $result['command'] );
		$this->assertEquals( '123', $result['flags']['post-id'] );
	}

	/**
	 * Test parsing sync-docs command
	 */
	public function test_parse_sync_docs_command() {
		$result = $this->parser->parse( '/sync-docs' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'sync-docs', $result['command'] );
	}

	/**
	 * Test parsing next-task command
	 */
	public function test_parse_next_task_command() {
		$result = $this->parser->parse( '/next-task --filter=drafts' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'next-task', $result['command'] );
		$this->assertEquals( 'drafts', $result['flags']['filter'] );
	}

	/**
	 * Test that commands without hyphens still work
	 */
	public function test_parse_command_without_hyphen() {
		$result = $this->parser->parse( '/help' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'help', $result['command'] );
	}

	/**
	 * Test command with underscore still works
	 */
	public function test_parse_command_with_underscore() {
		$result = $this->parser->parse( '/test_command' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'test_command', $result['command'] );
	}

	/**
	 * Test mixed alphanumeric with hyphens
	 */
	public function test_parse_command_mixed_alphanumeric_hyphens() {
		$result = $this->parser->parse( '/test-123-command' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'test-123-command', $result['command'] );
	}

	/**
	 * Test that the old bug is fixed
	 *
	 * Previously /optimize-perf would be parsed as command="optimize" with args="-perf"
	 * This test ensures that bug is fixed.
	 */
	public function test_hyphen_not_treated_as_flag() {
		$result = $this->parser->parse( '/optimize-perf' );

		$this->assertNotWPError( $result );
		$this->assertEquals( 'optimize-perf', $result['command'], 'Entire command name should be captured' );
		
		// Ensure -perf is NOT treated as a flag
		$this->assertArrayNotHasKey( 'p', $result['flags'], 'Should not have -p flag' );
		$this->assertArrayNotHasKey( 'perf', $result['flags'], 'Should not have -perf flag' );
		
		// Ensure -perf is NOT treated as a positional argument
		$this->assertEmpty( $result['args'], 'Should not have any positional arguments' );
	}

	/**
	 * Test get_command_name method with hyphenated command
	 */
	public function test_get_command_name_with_hyphen() {
		$command_name = $this->parser->get_command_name( '/optimize-perf' );

		$this->assertEquals( 'optimize-perf', $command_name );
	}

	/**
	 * Test is_valid_syntax with hyphenated commands
	 */
	public function test_is_valid_syntax_with_hyphens() {
		$this->assertTrue( $this->parser->is_valid_syntax( '/optimize-perf' ) );
		$this->assertTrue( $this->parser->is_valid_syntax( '/clean-content' ) );
		$this->assertTrue( $this->parser->is_valid_syntax( '/sync-docs' ) );
		$this->assertTrue( $this->parser->is_valid_syntax( '/next-task' ) );
	}
}
