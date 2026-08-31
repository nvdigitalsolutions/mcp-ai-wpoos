<?php
/**
 * Help Slash Command
 *
 * Lists all available slash commands with descriptions and usage examples.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
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
class SlashCommandHelp {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Command handler instance
	 *
	 * @var SlashCommandHandler
	 */
	private $handler;

	/**
	 * Constructor
	 *
	 * @param SlashCommandHandler $handler Command handler instance.
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
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Signature contract: not every slash-command consumes every execute() parameter.
	public function execute( $args, $flags, $context ) {
		// --new: list recently added commands.
		if ( isset( $flags['new'] ) ) {
			return $this->show_new_commands();
		}

		// Check if specific command requested.
		if ( ! empty( $args[0] ) ) {
			return $this->show_command_help( $args[0] );
		}

		// Show all commands.
		return $this->show_all_commands( $flags );
	}

	/**
	 * Show commands added since v2.0.
	 *
	 * @return string
	 */
	private function show_new_commands() {
		$out  = "## New Commands (since v2.0)\n\n";
		$out .= "The following commands were added in **v2.1.0**:\n\n";
		$out .= "- `/markup-stats` — Show aggregate markup telemetry counters\n";
		$out .= "- `/jobs` — List and cancel async background jobs\n";
		$out .= "- `/status` — Aggregated system health report\n";
		$out .= "- `/cost` — Token usage and cost summary\n";
		$out .= "- `/tools` — Browse and inspect registered tools\n";
		$out .= "- `/skills` — List and install agent skill packs\n";
		$out .= "- `/preset` — Manage orchestration presets\n";
		$out .= "- `/model` — List or set AI models for assistants\n";
		$out .= "- `/clear` — Clear the chat window\n";
		$out .= "- `/reset` — Reset the current session\n";
		$out .= "- `/resume` — Resume the last saved session\n";
		$out .= "- `/diagnose` — Generate a diagnostic bundle for support\n\n";
		$out .= "_Use `/help <command>` for detailed information on any command._\n";
		return $out;
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
			return new \WP_Error(
				'command_not_found',
				sprintf(
					/* translators: %s: command name */
					__( 'Command "/%s" not found.', 'nvoos-content-graph-ai-platform' ),
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
			return __( 'No commands available.', 'nvoos-content-graph-ai-platform' );
		}

		$help  = "# Available Slash Commands\n\n";
		$help .= sprintf(
			/* translators: %d: number of commands */
			__( 'You have access to %d command(s). Use `/help <command>` for detailed information.', 'nvoos-content-graph-ai-platform' ),
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
