<?php
/**
 * Slash Commands ported-class tests (extraction Wave B, S1).
 *
 * Verifies the port of the slash command engine (parser, validator, handler,
 * audit, commands/) preserves the public behaviour of the base plugin's
 * slash-commands system (mcp-ai-wpoos/includes/slash-commands/).
 *
 * @package NvoosContentGraphAiPlatform\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tests;

use NvoosContentGraphAiPlatform\SlashCommands\Commands\SlashCommandHelp;
use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandParser;
use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandService;
use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandValidator;

/**
 * @group slash-commands
 */
class Test_Platform_SlashCommands extends \WP_UnitTestCase {

	public function test_parser_tokenizes_input(): void {
		$parser = new SlashCommandParser();

		$parsed = $parser->parse( '/help /tools --detailed' );

		$this->assertIsArray( $parsed );
		$this->assertSame( 'help', $parsed['command'] );
	}

	public function test_handler_registers_and_lists_commands(): void {
		$handler = new SlashCommandHandler();

		$registered = $handler->register(
			'test-command',
			array(
				'handler'     => static function () {
					return 'ok';
				},
				'description' => 'A test command.',
				'usage'       => '/test-command',
				'capability'  => 'read',
			)
		);

		$this->assertTrue( $registered );
		$this->assertTrue( $handler->command_exists( 'test-command' ) );

		$commands = $handler->get_commands();
		$this->assertArrayHasKey( 'test-command', $commands );
	}

	public function test_handler_executes_registered_command(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$handler = new SlashCommandHandler();

		$handler->register(
			'echo-command',
			array(
				'handler'     => static function ( $args ) {
					return 'echo:' . implode( ',', $args );
				},
				'description' => 'Echoes args.',
				'usage'       => '/echo-command [text]',
				'capability'  => 'read',
			)
		);

		$result = $handler->execute( '/echo-command hello world' );
		$this->assertSame( 'echo:hello,world', $result );
	}

	public function test_handler_unknown_command_returns_error(): void {
		$handler = new SlashCommandHandler();

		$result = $handler->execute( '/no-such-command' );
		$this->assertWPError( $result );
	}

	public function test_help_command_lists_commands(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$handler = new SlashCommandHandler();

		$handler->register(
			'help',
			array(
				'handler'     => array( new SlashCommandHelp( $handler ), 'execute' ),
				'description' => 'Display help information about available commands',
				'usage'       => '/help [command]',
				'capability'  => 'read',
			)
		);

		$result = $handler->execute( '/help' );
		$this->assertIsString( $result );
		$this->assertStringContainsString( '/help', $result );
	}

	public function test_validator_normalizes_name(): void {
		$this->assertSame( 'Jane Doe', SlashCommandValidator::normalize_name( '  Jane Doe  ' ) );
		$this->assertSame( 'user@example.com', SlashCommandValidator::normalize_email( ' USER@Example.COM ' ) );
	}

	public function test_shim_surface_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin owns the global function surface.' );
		}

		// The shim is loaded by SlashCommandService::register() in standalone.
		SlashCommandService::instance()->register();

		$this->assertTrue( function_exists( 'wp_mcp_ai_init_slash_commands' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_execute_slash_command' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_register_slash_command' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_slash_command_exists' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_get_slash_commands' ) );
		$this->assertTrue( function_exists( 'wp_mcp_ai_get_workflow_orchestrator' ) );
	}

	public function test_shim_init_wires_default_commands_in_standalone_mode(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin owns the wiring.' );
		}

		SlashCommandService::instance()->register();

		// Note: WP_UnitTestCase resets hook globals between tests, so hooks
		// registered by an earlier test's register() call are gone. Invoke
		// the init function directly — its wiring is byte-for-byte the base
		// slash-commands-init.php flow (verified against the shim source).
		wp_mcp_ai_init_slash_commands();

		$handler = $GLOBALS['wp_mcp_ai_slash_command_handler'] ?? null;
		$this->assertInstanceOf( SlashCommandHandler::class, $handler );

		$commands = $handler->get_commands();
		$this->assertArrayHasKey( 'help', $commands );
	}

	public function test_service_register_is_safe_in_both_modes(): void {
		SlashCommandService::instance()->register();
		$this->assertTrue( true );
	}
}
