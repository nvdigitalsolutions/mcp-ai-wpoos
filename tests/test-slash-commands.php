<?php
/**
 * Test Slash Commands System
 *
 * PHPUnit tests for slash command parser, handler, and help command.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test slash commands functionality
 */
class Test_Slash_Commands extends WP_UnitTestCase {

	/**
	 * Slash command handler instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Handler
	 */
	private $handler;

	/**
	 * Slash command parser instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Parser
	 */
	private $parser;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load classes.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';

		$this->parser  = new WP_MCP_AI_Slash_Command_Parser();
		$this->handler = new WP_MCP_AI_Slash_Command_Handler();
	}

	/**
	 * Test parser validates slash prefix
	 */
	public function test_parser_requires_slash_prefix() {
		$result = $this->parser->parse( 'help' );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_command_syntax', $result->get_error_code() );
	}

	/**
	 * Test parser extracts command name
	 */
	public function test_parser_extracts_command_name() {
		$result = $this->parser->parse( '/help' );
		$this->assertIsArray( $result );
		$this->assertEquals( 'help', $result['command'] );
	}

	/**
	 * Test parser handles positional arguments
	 */
	public function test_parser_handles_positional_arguments() {
		$result = $this->parser->parse( '/ship 123 456' );
		$this->assertIsArray( $result );
		$this->assertEquals( 'ship', $result['command'] );
		$this->assertEquals( array( '123', '456' ), $result['args'] );
	}

	/**
	 * Test parser handles long flags
	 */
	public function test_parser_handles_long_flags() {
		$result = $this->parser->parse( '/ship --publish --date=2026-02-03' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['flags']['publish'] );
		$this->assertEquals( '2026-02-03', $result['flags']['date'] );
	}

	/**
	 * Test parser handles short flags
	 */
	public function test_parser_handles_short_flags() {
		$result = $this->parser->parse( '/ship -p -d 2026-02-03' );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['flags']['p'] );
		$this->assertEquals( '2026-02-03', $result['flags']['d'] );
	}

	/**
	 * Test parser handles quoted strings
	 */
	public function test_parser_handles_quoted_strings() {
		$result = $this->parser->parse( '/ship "My Post Title" --desc="A long description"' );
		$this->assertIsArray( $result );
		$this->assertEquals( array( 'My Post Title' ), $result['args'] );
		$this->assertEquals( 'A long description', $result['flags']['desc'] );
	}

	/**
	 * Test command registration
	 */
	public function test_command_registration() {
		$registered = $this->handler->register(
			'test',
			array(
				'handler'     => function () {
					return 'test result'; },
				'description' => 'Test command',
			)
		);

		$this->assertTrue( $registered );
		$this->assertTrue( $this->handler->command_exists( 'test' ) );
	}

	/**
	 * Test command registration with invalid name
	 */
	public function test_command_registration_invalid_name() {
		$registered = $this->handler->register(
			'invalid name',
			array(
				'handler' => function () {},
			)
		);

		$this->assertFalse( $registered );
	}

	/**
	 * Test command execution
	 */
	public function test_command_execution() {
		$this->handler->register(
			'test',
			array(
				'handler'    => function ( $args, $flags ) {
					return 'test result';
				},
				'capability' => 'read',
			)
		);

		$result = $this->handler->execute( '/test', array( 'user_id' => 1 ) );
		$this->assertEquals( 'test result', $result );
	}

	/**
	 * Test command not found error
	 */
	public function test_command_not_found() {
		$result = $this->handler->execute( '/nonexistent' );
		$this->assertWPError( $result );
		$this->assertEquals( 'command_not_found', $result->get_error_code() );
	}

	/**
	 * Test command aliases
	 */
	public function test_command_aliases() {
		$this->handler->register(
			'help',
			array(
				'handler'    => function () {
					return 'help text'; },
				'aliases'    => array( 'h', '?' ),
				'capability' => 'read',
			)
		);

		$result1 = $this->handler->execute( '/h', array( 'user_id' => 1 ) );
		$result2 = $this->handler->execute( '/?', array( 'user_id' => 1 ) );

		$this->assertEquals( 'help text', $result1 );
		$this->assertEquals( 'help text', $result2 );
	}

	/**
	 * Test authorization check
	 */
	public function test_authorization_check() {
		$this->handler->register(
			'admin_only',
			array(
				'handler'    => function () {
					return 'admin result'; },
				'capability' => 'manage_options',
			)
		);

		// Create regular user.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );

		$result = $this->handler->execute( '/admin_only', array( 'user_id' => $user_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'insufficient_capability', $result->get_error_code() );
	}

	/**
	 * Test rate limiting
	 */
	public function test_rate_limiting() {
		$this->handler->register(
			'limited',
			array(
				'handler'    => function () {
					return 'ok'; },
				'capability' => 'read',
			)
		);

		$user_id = 1;

		// Execute command 10 times (the limit).
		for ( $i = 0; $i < 10; $i++ ) {
			$result = $this->handler->execute( '/limited', array( 'user_id' => $user_id ) );
			$this->assertEquals( 'ok', $result );
		}

		// 11th execution should be rate limited.
		$result = $this->handler->execute( '/limited', array( 'user_id' => $user_id ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'rate_limit_exceeded', $result->get_error_code() );
	}

	/**
	 * Test command logging
	 */
	public function test_command_logging() {
		$this->handler->register(
			'logged',
			array(
				'handler'    => function () {
					return 'result'; },
				'capability' => 'read',
			)
		);

		// Clear existing logs.
		delete_option( 'wp_mcp_ai_slash_command_logs' );

		$this->handler->execute( '/logged', array( 'user_id' => 1 ) );

		$logs = get_option( 'wp_mcp_ai_slash_command_logs', array() );
		$this->assertNotEmpty( $logs );
		$this->assertEquals( 'logged', $logs[0]['command'] );
		$this->assertEquals( 'completed', $logs[0]['status'] );
	}

	/**
	 * Test get commands filtered by capability
	 */
	public function test_get_commands_filtered() {
		$this->handler->register(
			'public',
			array(
				'handler'    => function () {},
				'capability' => 'read',
			)
		);

		$this->handler->register(
			'admin',
			array(
				'handler'    => function () {},
				'capability' => 'manage_options',
			)
		);

		// Set current user to subscriber.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$commands = $this->handler->get_commands( true );

		// Should only see public command.
		$this->assertArrayHasKey( 'public', $commands );
		$this->assertArrayNotHasKey( 'admin', $commands );
	}
}
