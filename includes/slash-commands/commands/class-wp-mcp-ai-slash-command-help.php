<?php
/**
 * Help Slash Command
 *
 * Lists all available slash commands with descriptions and usage examples.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Help Command Class
 *
 * Provides help information about available slash commands.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Slash_Command_Help {

	/**
	 * Command handler instance
	 *
	 * @var WP_MCP_AI_Slash_Command_Handler
	 */
	private $handler;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Slash_Command_Handler $handler Command handler instance.
	 */
	public function __construct( $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Execute help command
	 *
	 * @param array $args  Positional arguments.
	 * @param array $flags Command flags.
	 * @param array $context Execution context.
	 * @return string Help text.
	 */
	public function execute( $args, $flags, $context ) {
		// Check if specific command requested.
		if ( ! empty( $args[0] ) ) {
			return $this->show_command_help( $args[0] );
		}

		// Show all commands.
		return $this->show_all_commands( $flags );
	}

	/**
	 * Show help for specific command
	 *
	 * @param string $command_name Command name.
	 * @return string|WP_Error Help text or error.
	 */
	private function show_command_help( $command_name ) {
		$config = $this->handler->get_command( $command_name );

		if ( false === $config ) {
			return new WP_Error(
				'command_not_found',
				sprintf(
					/* translators: %s: command name */
					__( 'Command "/%s" not found.', 'mcp-ai-wpoos' ),
					esc_html( $command_name )
				)
			);
		}

		$help = "## /{$command_name}\n\n";

		if ( ! empty( $config['description'] ) ) {
			$help .= "**Description:** {$config['description']}\n\n";
		}

		if ( ! empty( $config['usage'] ) ) {
			$help .= "**Usage:** `{$config['usage']}`\n\n";
		}

		if ( ! empty( $config['parameters'] ) ) {
			$help .= "**Parameters:**\n";
			foreach ( $config['parameters'] as $param => $info ) {
				$required = isset( $info['required'] ) && $info['required'] ? ' (required)' : ' (optional)';
				$desc     = isset( $info['description'] ) ? $info['description'] : '';
				$help    .= "- `{$param}`{$required}: {$desc}\n";
			}
			$help .= "\n";
		}

		if ( ! empty( $config['capability'] ) ) {
			$help .= "**Required Capability:** `{$config['capability']}`\n\n";
		}

		return $help;
	}

	/**
	 * Show all available commands
	 *
	 * @param array $flags Command flags.
	 * @return string Help text.
	 */
	private function show_all_commands( $flags ) {
		// Get commands filtered by user capability.
		$commands = $this->handler->get_commands( true );

		if ( empty( $commands ) ) {
			return __( 'No commands available.', 'mcp-ai-wpoos' );
		}

		$help  = "# Available Slash Commands\n\n";
		$help .= sprintf(
			/* translators: %d: number of commands */
			__( 'You have access to %d command(s). Use `/help <command>` for detailed information.', 'mcp-ai-wpoos' ),
			count( $commands )
		);
		$help .= "\n\n";

		// Sort commands alphabetically.
		ksort( $commands );

		// Check if detailed view requested.
		$detailed = isset( $flags['detailed'] ) || isset( $flags['d'] );

		foreach ( $commands as $command => $config ) {
			$help .= "### /{$command}\n";

			if ( ! empty( $config['description'] ) ) {
				$help .= "{$config['description']}\n";
			}

			if ( $detailed ) {
				if ( ! empty( $config['usage'] ) ) {
					$help .= "**Usage:** `{$config['usage']}`\n";
				}

				if ( ! empty( $config['capability'] ) ) {
					$help .= "**Required:** `{$config['capability']}`\n";
				}
			}

			$help .= "\n";
		}

		// Add usage tips.
		$help .= "---\n\n";
		$help .= "**Tips:**\n";
		$help .= "- Use `/help <command>` for detailed help on a specific command\n";
		$help .= "- Use `/help --detailed` or `/help -d` to show detailed information for all commands\n";
		$help .= "- Commands support flags: `--flag=value` or `-f value`\n";
		$help .= "- Use quotes for values with spaces: `\"value with spaces\"`\n";

		return $help;
	}
}
