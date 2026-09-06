<?php
/**
 * Slash Command Handler
 *
 * Central handler for slash command registration, routing, and execution.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slash Command Handler Class
 *
 * Manages slash command lifecycle:
 * - Command registration
 * - Routing to handlers
 * - Authorization checks
 * - Rate limiting
 * - Execution logging
 *
 * @since 1.2.0
 */
class SlashCommandHandler {
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
	 * Registered commands
	 *
	 * @var array
	 */
	private $commands = array();

	/**
	 * Command aliases
	 *
	 * @var array
	 */
	private $aliases = array();

	/**
	 * Parser instance
	 *
	 * @var SlashCommandParser
	 */
	private $parser;

	/**
	 * Rate limiter instance
	 *
	 * @var WP_MCP_AI_Rate_Limit_Manager
	 */
	private $rate_limiter;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->parser = new SlashCommandParser();

		// Initialize rate limiter if available.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Rate_Limit_Manager' ) ) {
			$this->rate_limiter = new \WP_MCP_AI_Rate_Limit_Manager();
		}
	}

	/**
	 * Register a slash command
	 *
	 * @param string $command Command name (without leading /).
	 * @param array  $config {
	 *     Command configuration.
	 *
	 *     @type callable $handler       Command handler function.
	 *     @type string   $description   Command description.
	 *     @type string   $usage         Usage example.
	 *     @type string   $capability    Required capability (default: 'edit_posts').
	 *     @type array    $aliases       Alternative command names.
	 *     @type array    $parameters    Parameter definitions.
	 * }
	 * @return bool True on success, false on failure.
	 */
	public function register( $command, $config ) {
		// Validate command name.
		if ( empty( $command ) || ! preg_match( '/^[a-z0-9_-]+$/i', $command ) ) {
			return false;
		}

		// Validate handler.
		if ( empty( $config['handler'] ) || ! is_callable( $config['handler'] ) ) {
			return false;
		}

		// Set defaults.
		$config = wp_parse_args(
			$config,
			array(
				'description' => '',
				'usage'       => "/{$command}",
				'capability'  => 'edit_posts',
				'aliases'     => array(),
				'parameters'  => array(),
			)
		);

		// Store command.
		$this->commands[ $command ] = $config;

		// Register aliases.
		if ( ! empty( $config['aliases'] ) ) {
			foreach ( $config['aliases'] as $alias ) {
				$this->aliases[ $alias ] = $command;
			}
		}

		/**
		 * Fires after a slash command is registered
		 *
		 * @since 1.2.0
		 *
		 * @param string $command Command name.
		 * @param array  $config  Command configuration.
		 */
		do_action( 'wp_mcp_ai_slash_command_registered', $command, $config );

		return true;
	}

	/**
	 * Execute a slash command
	 *
	 * @param string $input   Raw command input.
	 * @param array  $context Execution context (user_id, assistant_id, etc.).
	 * @return mixed Command result or WP_Error on failure.
	 */
	public function execute( $input, $context = array() ) {
		// Parse command.
		$parsed = $this->parser->parse( $input );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$command = $parsed['command'];

		// Resolve alias.
		if ( isset( $this->aliases[ $command ] ) ) {
			$command = $this->aliases[ $command ];
		}

		// Check if command exists.
		if ( ! isset( $this->commands[ $command ] ) ) {
			return new \WP_Error(
				'command_not_found',
				sprintf(
					/* translators: %s: command name */
					__( 'Command "/%s" not found. Use /help to see available commands.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $parsed['command'] )
				)
			);
		}

		$config = $this->commands[ $command ];

		// Check authorization.
		$auth_check = $this->check_authorization( $command, $config, $context );
		if ( is_wp_error( $auth_check ) ) {
			return $auth_check;
		}

		// Check rate limits.
		$rate_check = $this->check_rate_limits( $command, $context );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Log execution start.
		$this->log_execution( $command, $parsed, $context, 'started' );

		// Execute command.
		try {
			// Merge named flags into positional args so command handlers
			// that expect a single $args array (2-param signature) receive
			// all parameters in the first argument. The raw flags are still
			// passed as the second argument for handlers with a 3-param
			// ($args, $flags, $context) signature.
			$merged_args = array_merge( $parsed['flags'], $parsed['args'] );
			$result      = call_user_func(
				$config['handler'],
				$merged_args,
				$parsed['flags'],
				$context
			);

			// Log execution success.
			$this->log_execution( $command, $parsed, $context, 'completed', $result );

			return $result;

		} catch ( Throwable $e ) {
			$error = new \WP_Error(
				'command_execution_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Command execution failed: %s', 'nvoos-content-graph-ai-platform' ),
					$e->getMessage()
				)
			);

			// Log execution failure.
			$this->log_execution( $command, $parsed, $context, 'failed', $error );

			return $error;
		}
	}

	/**
	 * Check if user is authorized to execute command
	 *
	 * @param string $command Command name.
	 * @param array  $config  Command configuration.
	 * @param array  $context Execution context.
	 * @return true|WP_Error True if authorized, WP_Error otherwise.
	 */
	private function check_authorization( $command, $config, $context ) {
		$user_id = isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id();

		// Check required capability.
		if ( ! user_can( $user_id, $config['capability'] ) ) {
			return new \WP_Error(
				'insufficient_capability',
				sprintf(
					/* translators: 1: command name, 2: required capability */
					__( 'You do not have permission to execute /%1$s (requires %2$s capability).', 'nvoos-content-graph-ai-platform' ),
					esc_html( $command ),
					esc_html( $config['capability'] )
				)
			);
		}

		/**
		 * Filter command authorization
		 *
		 * @since 1.2.0
		 *
		 * @param bool   $authorized True if authorized.
		 * @param string $command    Command name.
		 * @param int    $user_id    User ID.
		 * @param array  $context    Execution context.
		 */
		$authorized = apply_filters(
			'wp_mcp_ai_slash_command_authorized',
			true,
			$command,
			$user_id,
			$context
		);

		if ( ! $authorized ) {
			return new \WP_Error(
				'command_not_authorized',
				__( 'You are not authorized to execute this command.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return true;
	}

	/**
	 * Check rate limits for command execution
	 *
	 * @param string $command Command name.
	 * @param array  $context Execution context.
	 * @return true|WP_Error True if within limits, WP_Error otherwise.
	 */
	private function check_rate_limits( $command, $context ) {
		$user_id = isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id();
		$key     = "slash_command_{$user_id}";

		// Get current count.
		$count = get_transient( $key );
		if ( false === $count ) {
			$count = 0;
		}

		// Allow 10 commands per minute.
		$limit = 10;

		/**
		 * Filter rate limit for slash commands
		 *
		 * @since 1.2.0
		 *
		 * @param int    $limit   Rate limit (commands per minute).
		 * @param string $command Command name.
		 * @param int    $user_id User ID.
		 */
		$limit = apply_filters( 'wp_mcp_ai_slash_command_rate_limit', $limit, $command, $user_id );

		if ( $count >= $limit ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: %d: rate limit */
					__( 'Rate limit exceeded. Maximum %d commands per minute allowed.', 'nvoos-content-graph-ai-platform' ),
					$limit
				)
			);
		}

		// Increment count.
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Log command execution
	 *
	 * @param string $command Command name.
	 * @param array  $parsed  Parsed command data.
	 * @param array  $context Execution context.
	 * @param string $status  Execution status (started, completed, failed).
	 * @param mixed  $result  Execution result (optional).
	 */
	private function log_execution( $command, $parsed, $context, $status, $result = null ) {
		$user_id = isset( $context['user_id'] ) ? $context['user_id'] : get_current_user_id();

		$log_entry = array(
			'command'   => $command,
			'status'    => $status,
			'user_id'   => $user_id,
			'timestamp' => current_time( 'mysql' ),
			'input'     => $parsed['raw_input'],
			'context'   => $context,
		);

		if ( null !== $result ) {
			if ( is_wp_error( $result ) ) {
				$log_entry['error'] = $result->get_error_message();
			} else {
				$log_entry['result_type'] = gettype( $result );
			}
		}

		/**
		 * Fires when command execution is logged
		 *
		 * @since 1.2.0
		 *
		 * @param array $log_entry Log entry data.
		 */
		do_action( 'wp_mcp_ai_slash_command_logged', $log_entry );

		// Store in WordPress options for recent activity.
		$recent_logs = get_option( 'wp_mcp_ai_slash_command_logs', array() );
		array_unshift( $recent_logs, $log_entry );

		// Keep only last 100 entries.
		$recent_logs = array_slice( $recent_logs, 0, 100 );

		update_option( 'wp_mcp_ai_slash_command_logs', $recent_logs, false );
	}

	/**
	 * Get all registered commands
	 *
	 * @param bool $filter_by_capability Whether to filter by current user capability.
	 * @return array Registered commands.
	 */
	public function get_commands( $filter_by_capability = false ) {
		if ( ! $filter_by_capability ) {
			return $this->commands;
		}

		$user_id  = get_current_user_id();
		$filtered = array();

		foreach ( $this->commands as $command => $config ) {
			if ( user_can( $user_id, $config['capability'] ) ) {
				$filtered[ $command ] = $config;
			}
		}

		return $filtered;
	}

	/**
	 * Get command configuration
	 *
	 * @param string $command Command name.
	 * @return array|false Command configuration or false if not found.
	 */
	public function get_command( $command ) {
		// Resolve alias.
		if ( isset( $this->aliases[ $command ] ) ) {
			$command = $this->aliases[ $command ];
		}

		return isset( $this->commands[ $command ] ) ? $this->commands[ $command ] : false;
	}

	/**
	 * Check if command exists
	 *
	 * @param string $command Command name.
	 * @return bool True if command exists.
	 */
	public function command_exists( $command ) {
		return isset( $this->commands[ $command ] ) || isset( $this->aliases[ $command ] );
	}
}
