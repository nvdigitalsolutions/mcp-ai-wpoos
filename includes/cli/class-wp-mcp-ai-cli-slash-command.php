<?php
/**
 * WP-CLI Command for Slash Commands
 *
 * Provides WP-CLI interface for executing slash commands.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Slash Command WP-CLI Command
 *
 * Execute and manage slash commands via WP-CLI.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_CLI_Slash_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Execute a slash command.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : The slash command to execute (with or without leading slash)
	 *
	 * [--user=<id>]
	 * : User ID to execute command as (default: 1)
	 *
	 * [--format=<format>]
	 * : Output format: text, json, yaml, table (default: text)
	 *
	 * ## EXAMPLES
	 *
	 *     # Execute help command
	 *     wp mcp-ai slash execute /help
	 *
	 *     # Execute command as specific user
	 *     wp mcp-ai slash execute "/help --detailed" --user=2
	 *
	 *     # Get output as JSON
	 *     wp mcp-ai slash execute /help --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @when after_wp_load
	 */
	public function execute( $args, $assoc_args ) {
		$command = $args[0];
		$user_id = isset( $assoc_args['user'] ) ? absint( $assoc_args['user'] ) : 1;
		$format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'text';

		// Add leading slash if missing.
		if ( '/' !== $command[0] ) {
			$command = '/' . $command;
		}

		// Get handler.
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			WP_CLI::error( 'Slash command handler not initialized' );
		}

		// Execute command.
		$context = array(
			'user_id' => $user_id,
			'source'  => 'wp-cli',
		);

		$result = $handler->execute( $command, $context );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		// Format output.
		$this->format_output( $result, $format );

		WP_CLI::success( 'Command executed successfully' );
	}

	/**
	 * List all available slash commands.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format: table, json, yaml, csv (default: table)
	 *
	 * [--user=<id>]
	 * : Filter commands by user capability (default: current user)
	 *
	 * ## EXAMPLES
	 *
	 *     # List all commands
	 *     wp mcp-ai slash list
	 *
	 *     # List commands as JSON
	 *     wp mcp-ai slash list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$user_id = isset( $assoc_args['user'] ) ? absint( $assoc_args['user'] ) : get_current_user_id();

		// Get handler.
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			WP_CLI::error( 'Slash command handler not initialized' );
		}

		// Set current user for capability filtering.
		if ( $user_id ) {
			wp_set_current_user( $user_id );
		}

		// Get commands.
		$commands = $handler->get_commands( true );

		if ( empty( $commands ) ) {
			WP_CLI::warning( 'No commands available' );
			return;
		}

		// Format for table output.
		$formatted = array();
		foreach ( $commands as $name => $config ) {
			$formatted[] = array(
				'command'     => '/' . $name,
				'description' => $config['description'] ?? '',
				'capability'  => $config['capability'] ?? 'edit_posts',
				'aliases'     => ! empty( $config['aliases'] ) ? implode( ', ', $config['aliases'] ) : '-',
			);
		}

		WP_CLI\Utils\format_items( $format, $formatted, array( 'command', 'description', 'capability', 'aliases' ) );
	}

	/**
	 * Get help for a specific command.
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : The command to get help for (with or without leading slash)
	 *
	 * ## EXAMPLES
	 *
	 *     # Get help for help command
	 *     wp mcp-ai slash help help
	 *
	 *     # Get help with slash
	 *     wp mcp-ai slash help /help
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @when after_wp_load
	 */
	public function help( $args, $assoc_args ) {
		$command = $args[0];

		// Remove leading slash if present.
		$command = ltrim( $command, '/' );

		// Get handler.
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			WP_CLI::error( 'Slash command handler not initialized' );
		}

		// Get command config.
		$config = $handler->get_command( $command );
		if ( ! $config ) {
			WP_CLI::error( "Command '/{$command}' not found" );
		}

		// Display help.
		WP_CLI::line( '' );
		WP_CLI::line( WP_CLI::colorize( "%G/{$command}%n" ) );
		WP_CLI::line( '' );

		if ( ! empty( $config['description'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YDescription:%n' ) );
			WP_CLI::line( '  ' . $config['description'] );
			WP_CLI::line( '' );
		}

		if ( ! empty( $config['usage'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YUsage:%n' ) );
			WP_CLI::line( '  ' . $config['usage'] );
			WP_CLI::line( '' );
		}

		if ( ! empty( $config['capability'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YRequired Capability:%n' ) );
			WP_CLI::line( '  ' . $config['capability'] );
			WP_CLI::line( '' );
		}

		if ( ! empty( $config['aliases'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YAliases:%n' ) );
			WP_CLI::line( '  /' . implode( ', /', $config['aliases'] ) );
			WP_CLI::line( '' );
		}

		if ( ! empty( $config['parameters'] ) ) {
			WP_CLI::line( WP_CLI::colorize( '%YParameters:%n' ) );
			foreach ( $config['parameters'] as $param => $info ) {
				$required = isset( $info['required'] ) && $info['required'] ? ' (required)' : ' (optional)';
				$desc     = isset( $info['description'] ) ? $info['description'] : '';
				WP_CLI::line( "  {$param}{$required}: {$desc}" );
			}
			WP_CLI::line( '' );
		}
	}

	/**
	 * Format command output
	 *
	 * @param mixed  $result Command result.
	 * @param string $format Output format.
	 */
	private function format_output( $result, $format ) {
		switch ( $format ) {
			case 'json':
				WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
				break;

			case 'yaml':
				if ( is_array( $result ) || is_object( $result ) ) {
					WP_CLI::line( WP_CLI\Utils\mustache_render( '{{#.}}{{key}}: {{value}}' . "\n" . '{{/.}}', $result ) );
				} else {
					WP_CLI::line( $result );
				}
				break;

			case 'table':
				if ( is_array( $result ) || is_object( $result ) ) {
					$items = array();
					foreach ( (array) $result as $key => $value ) {
						$items[] = array(
							'key'   => $key,
							'value' => is_scalar( $value ) ? $value : wp_json_encode( $value ),
						);
					}
					WP_CLI\Utils\format_items( 'table', $items, array( 'key', 'value' ) );
				} else {
					WP_CLI::line( $result );
				}
				break;

			case 'text':
			default:
				if ( is_string( $result ) ) {
					WP_CLI::line( $result );
				} else {
					WP_CLI::line( print_r( $result, true ) );
				}
				break;
		}
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai slash', 'WP_MCP_AI_CLI_Slash_Command' );
}
