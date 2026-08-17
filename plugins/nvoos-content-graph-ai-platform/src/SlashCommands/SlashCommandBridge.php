<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands;

/**
 * Static bridge to the base plugin's slash commands procedural API.
 *
 * The slash command system (14.5K lines) lives in the base plugin's
 * `includes/slash-commands/`. This bridge provides namespace-friendly
 * static accessors for Platform code.
 */
final class SlashCommandBridge {

	/**
	 * Execute a slash command.
	 *
	 * @param string $input   Raw command input (e.g. "/help").
	 * @param array  $context Execution context.
	 * @return array|\WP_Error
	 */
	public static function execute( string $input, array $context = array() ) {
		if ( ! function_exists( 'wp_mcp_ai_execute_slash_command' ) ) {
			return new \WP_Error( 'not_available', __( 'Slash commands not available.', 'nvoos-content-graph-ai-platform' ) );
		}
		return wp_mcp_ai_execute_slash_command( $input, $context );
	}

	/**
	 * Register a new slash command.
	 *
	 * @param string $command Command name (without leading /).
	 * @param array  $config  Command configuration.
	 * @return bool
	 */
	public static function register( string $command, array $config ): bool {
		if ( ! function_exists( 'wp_mcp_ai_register_slash_command' ) ) {
			return false;
		}
		return wp_mcp_ai_register_slash_command( $command, $config );
	}

	/**
	 * Check if a command exists.
	 *
	 * @param string $command Command name.
	 * @return bool
	 */
	public static function exists( string $command ): bool {
		if ( ! function_exists( 'wp_mcp_ai_slash_command_exists' ) ) {
			return false;
		}
		return wp_mcp_ai_slash_command_exists( $command );
	}

	/**
	 * Get all registered commands.
	 *
	 * @param bool $filter_by_capability Filter by current user capability.
	 * @return array<string, array>
	 */
	public static function getAll( bool $filter_by_capability = false ): array {
		if ( ! function_exists( 'wp_mcp_ai_get_slash_commands' ) ) {
			return array();
		}
		return wp_mcp_ai_get_slash_commands( $filter_by_capability );
	}

	/**
	 * Execute a named workflow.
	 *
	 * @param string $workflow_name Workflow name.
	 * @param array  $params        Workflow parameters.
	 * @param array  $context       Execution context.
	 * @return array|\WP_Error
	 */
	public static function executeWorkflow( string $workflow_name, array $params = array(), array $context = array() ) {
		if ( ! function_exists( 'wp_mcp_ai_execute_workflow' ) ) {
			return new \WP_Error( 'not_available', __( 'Workflow orchestrator not available.', 'nvoos-content-graph-ai-platform' ) );
		}
		return wp_mcp_ai_execute_workflow( $workflow_name, $params, $context );
	}

	private function __construct() {}
}
