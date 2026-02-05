<?php
/**
 * Test Slash Command Error Handling
 *
 * PHPUnit tests for slash command error and exception handling.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test slash command error handling functionality
 */
class Test_Slash_Command_Error_Handling extends WP_UnitTestCase {

	/**
	 * Slash command handler instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Handler
	 */
	private $handler;

	/**
	 * Setup test environment
	 */
	public function setUp(): void {
		parent::setUp();

		// Load classes.
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';

		$this->handler = new WP_MCP_AI_Slash_Command_Handler();
	}

	/**
	 * Test that Exception is caught and converted to WP_Error
	 */
	public function test_exception_is_caught() {
		$this->handler->register(
			'throw_exception',
			array(
				'handler'    => function() {
					throw new Exception( 'Test exception message' );
				},
				'capability' => 'read',
			)
		);

		$result = $this->handler->execute( '/throw_exception', array( 'user_id' => 1 ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'command_execution_error', $result->get_error_code() );
		$this->assertStringContainsString( 'Test exception message', $result->get_error_message() );
	}

	/**
	 * Test that PHP Error is caught and converted to WP_Error
	 *
	 * This test verifies that the fix using Throwable instead of Exception works.
	 */
	public function test_error_is_caught() {
		$this->handler->register(
			'throw_error',
			array(
				'handler'    => function() {
					throw new Error( 'Test PHP Error message' );
				},
				'capability' => 'read',
			)
		);

		$result = $this->handler->execute( '/throw_error', array( 'user_id' => 1 ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'command_execution_error', $result->get_error_code() );
		$this->assertStringContainsString( 'Test PHP Error message', $result->get_error_message() );
	}

	/**
	 * Test that TypeError is caught and converted to WP_Error
	 */
	public function test_type_error_is_caught() {
		$this->handler->register(
			'throw_type_error',
			array(
				'handler'    => function() {
					// Intentionally call a function with wrong argument type to trigger TypeError.
					// This simulates what might happen with undefined functions or type mismatches.
					$func = function( int $required ) {
						return $required;
					};
					$func( 'string instead of int' );
				},
				'capability' => 'read',
			)
		);

		$result = $this->handler->execute( '/throw_type_error', array( 'user_id' => 1 ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'command_execution_error', $result->get_error_code() );
		// Error message should contain details about the type error.
		$this->assertNotEmpty( $result->get_error_message() );
	}

	/**
	 * Test that the error message is descriptive, not generic "error"
	 */
	public function test_error_message_is_descriptive() {
		$this->handler->register(
			'descriptive_error',
			array(
				'handler'    => function() {
					throw new Exception( 'This is a very specific error about X failing' );
				},
				'capability' => 'read',
			)
		);

		$result = $this->handler->execute( '/descriptive_error', array( 'user_id' => 1 ) );

		$this->assertWPError( $result );
		$error_message = $result->get_error_message();
		
		// Should NOT be just "error".
		$this->assertNotEquals( 'error', $error_message );
		$this->assertNotEquals( 'Error: error', $error_message );
		
		// Should contain the actual error details.
		$this->assertStringContainsString( 'specific error about X failing', $error_message );
	}

	/**
	 * Test that WP_Error returned from command is passed through correctly
	 */
	public function test_wp_error_passthrough() {
		$this->handler->register(
			'return_wp_error',
			array(
				'handler'    => function() {
					return new WP_Error( 'custom_error', 'Custom error message' );
				},
				'capability' => 'read',
			)
		);

		$result = $this->handler->execute( '/return_wp_error', array( 'user_id' => 1 ) );

		$this->assertWPError( $result );
		$this->assertEquals( 'custom_error', $result->get_error_code() );
		$this->assertEquals( 'Custom error message', $result->get_error_message() );
	}
}
