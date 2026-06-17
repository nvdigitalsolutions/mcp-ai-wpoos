<?php
/**
 * Tests for WP_MCP_AI_Slash_Command_Help.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test class for the /help slash command.
 */
class Test_Slash_Command_Help extends WP_UnitTestCase {

	/**
	 * Stubbed slash-command handler.
	 *
	 * @var object
	 */
	private $handler;

	/**
	 * Command instance under test.
	 *
	 * @var WP_MCP_AI_Slash_Command_Help
	 */
	private $command;

	/**
	 * Set up: build a minimal handler that returns canned commands.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-help.php';

		$this->handler = new class() {
			public $commands = array(
				'cost'   => array(
					'description' => 'Token usage and cost summary',
					'usage'       => '/cost [--days=7]',
					'capability'  => 'edit_posts',
					'parameters'  => array(
						'days' => array(
							'required'    => false,
							'description' => 'Days back to summarise',
						),
					),
				),
				'status' => array(
					'description' => 'Aggregated system health report',
					'usage'       => '/status',
					'capability'  => 'manage_options',
				),
			);

			public function get_commands( $filter_by_cap = false ) {
				unset( $filter_by_cap );
				return $this->commands;
			}

			public function get_command( $name ) {
				return isset( $this->commands[ $name ] ) ? $this->commands[ $name ] : false;
			}
		};

		$this->command = new WP_MCP_AI_Slash_Command_Help( $this->handler );
	}

	/**
	 * No args, no flags → Markdown listing every command.
	 */
	public function test_lists_all_commands_alphabetically() {
		$out = $this->command->execute( array(), array(), array() );

		$this->assertIsString( $out );
		$this->assertStringContainsString( '# Available Slash Commands', $out );
		$this->assertStringContainsString( '/cost', $out );
		$this->assertStringContainsString( '/status', $out );
		$this->assertLessThan( strpos( $out, '/status' ), strpos( $out, '/cost' ) );
	}

	/**
	 * `/help cost` → focused help for that command.
	 */
	public function test_show_command_help_renders_known_command() {
		$out = $this->command->execute( array( 'cost' ), array(), array() );

		$this->assertStringContainsString( '## /cost', $out );
		$this->assertStringContainsString( 'Token usage and cost summary', $out );
		$this->assertStringContainsString( '/cost [--days=7]', $out );
		$this->assertStringContainsString( '`days`', $out );
		$this->assertStringContainsString( 'edit_posts', $out );
	}

	/**
	 * Unknown command → WP_Error('command_not_found').
	 */
	public function test_unknown_command_returns_wp_error() {
		$out = $this->command->execute( array( 'no-such-command' ), array(), array() );

		$this->assertWPError( $out );
		$this->assertSame( 'command_not_found', $out->get_error_code() );
	}

	/**
	 * `--detailed` flag adds usage + capability lines per entry.
	 */
	public function test_detailed_flag_adds_usage_and_capability() {
		$out = $this->command->execute( array(), array( 'detailed' => true ), array() );

		$this->assertStringContainsString( '**Usage:**', $out );
		$this->assertStringContainsString( '**Required:**', $out );
	}

	/**
	 * Short `-d` flag is an alias for `--detailed`.
	 */
	public function test_short_d_flag_is_alias_for_detailed() {
		$out = $this->command->execute( array(), array( 'd' => true ), array() );

		$this->assertStringContainsString( '**Usage:**', $out );
	}

	/**
	 * `--new` flag returns the curated v2.1.0 list.
	 */
	public function test_new_flag_returns_v2_changelog() {
		$out = $this->command->execute( array(), array( 'new' => true ), array() );

		$this->assertStringContainsString( 'New Commands (since v2.0)', $out );
		$this->assertStringContainsString( '/markup-stats', $out );
		$this->assertStringContainsString( '/diagnose', $out );
	}

	/**
	 * Empty registry → friendly translatable fallback message.
	 */
	public function test_empty_registry_returns_friendly_fallback() {
		$this->handler->commands = array();
		$out                     = $this->command->execute( array(), array(), array() );

		$this->assertSame( 'No commands available.', $out );
	}
}
