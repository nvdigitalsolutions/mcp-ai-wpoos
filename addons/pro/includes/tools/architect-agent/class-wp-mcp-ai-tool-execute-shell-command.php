<?php
/**
 * Execute Shell Command Tool - Run shell commands with safety controls.
 *
 * Inspired by GitHub Copilot CLI's shell integration. Allows AI agents to execute
 * shell commands with user approval and safety checks.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Execute Shell Command tool for running terminal commands.
 *
 * This tool enables an "Architect Agent" to execute shell commands within the
 * plugin directory, similar to GitHub Copilot CLI's command execution.
 *
 * ## Security model
 *
 * The primary security boundaries are:
 *  1. `WP_MCP_AI_ALLOW_SHELL_TOOLS` must be `true` in wp-config.php (default: false).
 *  2. The executing user must have `manage_options` capability.
 *  3. The command is launched via `proc_open` with a timeout (no shell interpolation).
 *
 * The regex-based denylist of dangerous command patterns is a UX speed-bump,
 * not a security boundary.  Operators should run the WordPress/PHP process
 * under a low-privilege OS user and consider a binary-allowlist model for
 * production deployments.
 *
 * ## Features
 * - Command preview before execution (returned for user approval)
 * - Restricted to plugin directory as working directory
 * - Logs all executions
 * - Timeout protection
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Execute_Shell_Command implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Dangerous command patterns to block.
	 *
	 * @var array
	 */
	private $dangerous_patterns = array(
		'/rm\s+-rf\s*\//',              // rm -rf / (with or without space).
		'/rm\s+-rf\s*\*/',              // rm -rf * (with or without space).
		'/dd\s+if=/',                   // dd operations.
		'/mkfs/',                       // filesystem formatting.
		'/:\(\)\{.*\};:/',              // fork bomb.
		'/chmod\s+-R\s+777/',           // dangerous permissions.
		'/chown\s+-R/',                 // ownership changes.
		'/\>\s*\/dev\/sd/',             // writing to disk.
		'/curl.*\|\s*(sh|bash|dash|python\d*|perl|ruby|node)/',  // piping to interpreter.
		'/wget.*\|\s*(sh|bash|dash|python\d*|perl|ruby|node)/',  // piping to interpreter.
		'/curl\s+.*-[oO]\s/',           // curl writing output to file (potential webshell).
		'/wget\s+.*-O\s/',              // wget writing output to file (potential webshell).
		'/sudo\s/',                     // privilege escalation.
		'/\beval\b/',                   // code injection.
		'/killall\s/',                  // kill all processes.
		'/pkill\s/',                    // kill processes by name.
		'/\bsu\s/',                     // switch user.
		'/rm\s+-rf.*\.git/',            // destructive git operations.
		'/&&.*rm\s+-rf/',               // chained dangerous commands.
		'/;\s*rm\s+-rf/',               // chained dangerous commands.
		'/\|\|.*rm\s+-rf/',             // chained dangerous commands.
		'/`[^`]+`/',                    // backtick command substitution.
		'/\$\([^)]+\)/',               // $(...) command substitution.
		'/\bnohup\s/',                  // detaching persistent background processes.
		'/\bcrontab\s/',                // installing persistent cron jobs.
	);

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'execute_shell_command';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Execute Shell Command', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Execute shell commands within the plugin directory with safety controls. Supports git operations, file operations, build commands, and more. Commands are previewed before execution and dangerous operations are blocked. Similar to GitHub Copilot CLI shell integration.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'command'     => array(
					'type'        => 'string',
					'description' => __( 'Shell command to execute. Will be run in the plugin directory. Use standard shell syntax (pipes, redirects, etc. are supported).', 'mcp-ai-wpoos' ),
				),
				'preview'     => array(
					'type'        => 'boolean',
					'description' => __( 'If true, returns the command for review without executing. Use this to show the user what will be executed. Default: false.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'timeout'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum execution time in seconds. Default: 30 seconds. Maximum: 300 seconds.', 'mcp-ai-wpoos' ),
					'default'     => 30,
					'minimum'     => 1,
					'maximum'     => 300,
				),
				'explanation' => array(
					'type'        => 'string',
					'description' => __( 'Optional explanation of what the command does and why it\'s needed. Helps with logging and user understanding.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'command' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                     // Pro tier feature.
			'requires-capability',     // Requires edit_plugins capability.
			'state-changing',          // Executes commands that can modify state.
			'local-only',              // Works locally, no external APIs.
			'reversible',              // Many operations can be undone.
			'architect-agent',         // Core Architect Agent capability.
			'shell-execution',         // Can execute shell commands.
			'requires-workspace-trust', // Requires workspace trust (security).
			'development-workflow',    // Part of development lifecycle.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_plugins';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Shell-tools constant and capability gate (F-EXEC-01 / R-S-02).
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			return $this->error_response( __( 'Shell tools are disabled. Set define( \'WP_MCP_AI_ALLOW_SHELL_TOOLS\', true ) in wp-config.php to enable them.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response( __( 'You do not have permission to run shell commands.', 'mcp-ai-wpoos-pro' ) );
		}

		// Extract arguments.
		$command     = isset( $arguments['command'] ) ? trim( $arguments['command'] ) : '';
		$preview     = isset( $arguments['preview'] ) ? (bool) $arguments['preview'] : false;
		$timeout     = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 30;
		$explanation = isset( $arguments['explanation'] ) ? sanitize_text_field( $arguments['explanation'] ) : '';

		// Validate command.
		if ( empty( $command ) ) {
			return $this->error_response( __( 'Command is required.', 'mcp-ai-wpoos' ) );
		}

		// Check for dangerous patterns.
		foreach ( $this->dangerous_patterns as $pattern ) {
			if ( preg_match( $pattern, $command ) ) {
				return $this->error_response(
					sprintf(
						/* translators: %s: command */
						__( 'Command blocked for safety: %s. This command pattern is considered dangerous.', 'mcp-ai-wpoos' ),
						esc_html( $command )
					)
				);
			}
		}

		// Validate timeout.
		$timeout = max( 1, min( 300, $timeout ) );

		// Preview mode - return without executing.
		if ( $preview ) {
			return array(
				'status'      => 'preview',
				'command'     => $command,
				'explanation' => $explanation,
				'working_dir' => WP_MCP_AI_PATH,
				'timeout'     => $timeout,
				'message'     => __( 'Command preview. Set preview=false to execute.', 'mcp-ai-wpoos' ),
			);
		}

		// Execute the command.
		$result = $this->execute_command( $command, $timeout );

		// Log execution.
		$this->log_execution( $command, $explanation, $result, $context );

		return $result;
	}

	/**
	 * Execute a shell command safely.
	 *
	 * @param string $command Command to execute.
	 * @param int    $timeout Timeout in seconds.
	 * @return array Execution result.
	 */
	private function execute_command( $command, $timeout ) {
		// Change to plugin directory.
		$original_dir = getcwd();
		if ( ! chdir( WP_MCP_AI_PATH ) ) {
			return $this->error_response( __( 'Failed to set working directory. Cannot execute command.', 'mcp-ai-wpoos' ) );
		}

		if ( ! function_exists( 'proc_open' ) ) {
			if ( $original_dir ) {
				chdir( $original_dir );
			}
			return $this->error_response( __( 'proc_open() is not available on this server. Shell tool execution requires proc_open.', 'mcp-ai-wpoos' ) );
		}

		$result = $this->execute_with_proc_open( $command, $timeout );

		// Restore original directory.
		if ( $original_dir ) {
			chdir( $original_dir );
		}

		return $result;
	}

	/**
	 * Execute command using proc_open (preferred method with timeout support).
	 *
	 * @param string $command Command to execute.
	 * @param int    $timeout Timeout in seconds.
	 * @return array Execution result.
	 */
	private function execute_with_proc_open( $command, $timeout ) {
		$descriptorspec = array(
			0 => array( 'pipe', 'r' ),  // stdin.
			1 => array( 'pipe', 'w' ),  // stdout.
			2 => array( 'pipe', 'w' ),  // stderr.
		);

		$process = proc_open( $command, $descriptorspec, $pipes );

		if ( ! is_resource( $process ) ) {
			return $this->error_response( __( 'Failed to execute command.', 'mcp-ai-wpoos' ) );
		}

		// Close stdin.
		fclose( $pipes[0] );

		// Set non-blocking mode.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		// Read output with timeout.
		$start_time = time();
		$stdout     = '';
		$stderr     = '';

		while ( true ) {
			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				// Process finished.
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}

			if ( time() - $start_time > $timeout ) {
				// Timeout reached - kill process.
				proc_terminate( $process, 9 );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				proc_close( $process );

				return array(
					'status'      => 'timeout',
					'command'     => $command,
					'stdout'      => $stdout,
					'stderr'      => $stderr . "\n[Process killed after {$timeout} seconds]",
					'exit_code'   => -1,
					'working_dir' => WP_MCP_AI_PATH,
				);
			}

			// Read available output.
			$stdout .= stream_get_contents( $pipes[1] );
			$stderr .= stream_get_contents( $pipes[2] );

			// Sleep briefly to avoid busy loop.
			usleep( 100000 ); // 0.1 seconds.
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		return array(
			'status'      => 'completed',
			'command'     => $command,
			'stdout'      => $stdout,
			'stderr'      => $stderr,
			'exit_code'   => $exit_code,
			'working_dir' => WP_MCP_AI_PATH,
		);
	}

	/**
	 * Execute command using exec (fallback method, no timeout support).
	 *
	 * Retained for backward compatibility only. The execute_command() method
	 * now requires proc_open and will never reach this method. It will be
	 * removed in a future version.
	 *
	 * @deprecated 1.1.9 Use execute_with_proc_open() instead.
	 *
	 * @param string $command Command to execute.
	 * @return array Execution result.
	 */
	private function execute_with_exec( $command ) {
		return $this->error_response( __( 'exec() fallback has been removed. proc_open() is required.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Log command execution.
	 *
	 * @param string $command     Executed command.
	 * @param string $explanation Explanation of command.
	 * @param array  $result      Execution result.
	 * @param array  $context     Execution context.
	 */
	private function log_execution( $command, $explanation, $result, $context ) {
		$user_id      = $context['user_id'] ?? 0;
		$assistant_id = $context['assistant_id'] ?? 0;

		$log_entry = sprintf(
			'Shell command executed: %s (Exit: %d, User: %d, Assistant: %d)',
			esc_html( $command ),
			$result['exit_code'] ?? -1,
			$user_id,
			$assistant_id
		);

		if ( ! empty( $explanation ) ) {
			$log_entry .= ' - ' . esc_html( $explanation );
		}

		WP_MCP_AI_Logger::info( $log_entry );

		/**
		 * Fires after a shell command is executed.
		 *
		 * @param string $command     Executed command.
		 * @param array  $result      Execution result.
		 * @param string $explanation Command explanation.
		 * @param array  $context     Execution context.
		 */
		do_action( 'wp_mcp_ai_shell_command_executed', $command, $result, $explanation, $context );
	}

	/**
	 * Generate error response.
	 *
	 * @param string $message Error message.
	 * @return array Error response.
	 */
	private function error_response( $message ) {
		return array(
			'status'  => 'error',
			'message' => $message,
		);
	}
}
