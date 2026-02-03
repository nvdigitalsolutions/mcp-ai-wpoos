<?php
/**
 * Slash Commands Initialization
 *
 * Loads slash command infrastructure and registers default commands.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize slash commands system
 *
 * @since 1.2.0
 */
function wp_mcp_ai_init_slash_commands() {
	// Load parser.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php';

	// Load handler.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php';

	// Initialize global handler instance.
	global $wp_mcp_ai_slash_command_handler;
	$wp_mcp_ai_slash_command_handler = new WP_MCP_AI_Slash_Command_Handler();

	// Load default commands.
	wp_mcp_ai_load_default_slash_commands();

	/**
	 * Fires after slash commands are initialized
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_Slash_Command_Handler $handler Command handler instance.
	 */
	do_action( 'wp_mcp_ai_slash_commands_initialized', $wp_mcp_ai_slash_command_handler );
}
add_action( 'init', 'wp_mcp_ai_init_slash_commands', 20 );

/**
 * Load default slash commands
 *
 * @since 1.2.0
 */
function wp_mcp_ai_load_default_slash_commands() {
	global $wp_mcp_ai_slash_command_handler;

	if ( ! $wp_mcp_ai_slash_command_handler ) {
		return;
	}

	// Load help command.
	require_once WP_MCP_AI_PATH . 'includes/slash-commands/commands/class-wp-mcp-ai-slash-command-help.php';

	// Register /help command.
	$help_command = new WP_MCP_AI_Slash_Command_Help( $wp_mcp_ai_slash_command_handler );
	$wp_mcp_ai_slash_command_handler->register(
		'help',
		array(
			'handler'     => array( $help_command, 'execute' ),
			'description' => __( 'Display help information about available commands', 'mcp-ai-wpoos' ),
			'usage'       => '/help [command] [--detailed|-d]',
			'capability'  => 'read',
			'aliases'     => array( 'h', '?' ),
			'parameters'  => array(
				'command' => array(
					'description' => __( 'Specific command to get help for', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
				'--detailed' => array(
					'description' => __( 'Show detailed information for all commands', 'mcp-ai-wpoos' ),
					'required'    => false,
				),
			),
		)
	);

	/**
	 * Fires after default slash commands are loaded
	 *
	 * Allows plugins to register additional commands.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_Slash_Command_Handler $handler Command handler instance.
	 */
	do_action( 'wp_mcp_ai_default_slash_commands_loaded', $wp_mcp_ai_slash_command_handler );
}

/**
 * Get global slash command handler instance
 *
 * @since 1.2.0
 *
 * @return WP_MCP_AI_Slash_Command_Handler|null Handler instance or null if not initialized.
 */
function wp_mcp_ai_get_slash_command_handler() {
	global $wp_mcp_ai_slash_command_handler;
	return $wp_mcp_ai_slash_command_handler;
}

/**
 * Execute a slash command
 *
 * Helper function to execute slash commands from anywhere in the plugin.
 *
 * @since 1.2.0
 *
 * @param string $input   Command input (e.g., "/help").
 * @param array  $context Execution context.
 * @return mixed Command result or WP_Error.
 */
function wp_mcp_ai_execute_slash_command( $input, $context = array() ) {
	$handler = wp_mcp_ai_get_slash_command_handler();

	if ( ! $handler ) {
		return new WP_Error(
			'slash_commands_not_initialized',
			__( 'Slash commands system not initialized.', 'mcp-ai-wpoos' )
		);
	}

	return $handler->execute( $input, $context );
}

/**
 * Register a custom slash command
 *
 * Helper function for other plugins/themes to register commands.
 *
 * @since 1.2.0
 *
 * @param string $command Command name (without leading slash).
 * @param array  $config  Command configuration.
 * @return bool True on success, false on failure.
 */
function wp_mcp_ai_register_slash_command( $command, $config ) {
	$handler = wp_mcp_ai_get_slash_command_handler();

	if ( ! $handler ) {
		return false;
	}

	return $handler->register( $command, $config );
}

/**
 * Check if a slash command exists
 *
 * @since 1.2.0
 *
 * @param string $command Command name.
 * @return bool True if command exists.
 */
function wp_mcp_ai_slash_command_exists( $command ) {
	$handler = wp_mcp_ai_get_slash_command_handler();

	if ( ! $handler ) {
		return false;
	}

	return $handler->command_exists( $command );
}

/**
 * Get all registered slash commands
 *
 * @since 1.2.0
 *
 * @param bool $filter_by_capability Filter by current user capability.
 * @return array Registered commands.
 */
function wp_mcp_ai_get_slash_commands( $filter_by_capability = false ) {
	$handler = wp_mcp_ai_get_slash_command_handler();

	if ( ! $handler ) {
		return array();
	}

	return $handler->get_commands( $filter_by_capability );
}
