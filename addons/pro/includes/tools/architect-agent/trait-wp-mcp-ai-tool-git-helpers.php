<?php
/**
 * Shared git helpers for the git_inspect and git_change sub-tools.
 *
 * Extracted from the monolithic WP_MCP_AI_Tool_Git_Operations class as part
 * of the P5 Part 2 action-split decomposition. Both sub-tools share:
 *   - Shell/process guards (WP_MCP_AI_ALLOW_SHELL_TOOLS + manage_options).
 *   - exec_git() — thin wrapper around wp_mcp_ai_run_shell().
 *   - is_git_available() / is_git_repository() — environment checks.
 *   - sanitize_options() — allow-list filter for extra git flags.
 *   - log_write_operation() — audit trail for state-changing operations.
 *   - git_precondition_check() — runs all preconditions and returns
 *     WP_Error on failure or null on success.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared git utility methods for git_inspect and git_change sub-tools.
 *
 * @since 1.3.0
 */
trait WP_MCP_AI_Tool_Git_Helpers {

	/**
	 * Run all preconditions common to every git operation.
	 *
	 * Checks that:
	 *  1. WP_MCP_AI_ALLOW_SHELL_TOOLS is enabled.
	 *  2. The current user holds the required capability (edit_plugins).
	 *  3. The git binary is reachable.
	 *  4. WP_MCP_AI_PATH is a git repository.
	 *
	 * @return WP_Error|null Null on success, WP_Error on the first failed check.
	 */
	protected function git_precondition_check() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			return new WP_Error(
				'shell_tools_disabled',
				__( "Shell tools are disabled. Set define( 'WP_MCP_AI_ALLOW_SHELL_TOOLS', true ) in wp-config.php to enable them.", 'mcp-ai-wpoos-pro' )
			);
		}

		// Both git sub-tools declare edit_plugins as their required capability.
		if ( ! current_user_can( 'edit_plugins' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to run git commands.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $this->is_git_available() ) {
			return new WP_Error(
				'git_not_found',
				__( 'Git is not available on this system.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $this->is_git_repository() ) {
			return new WP_Error(
				'not_a_git_repo',
				__( 'Plugin directory is not a git repository.', 'mcp-ai-wpoos-pro' )
			);
		}

		return null;
	}

	/**
	 * Check whether the git binary is present.
	 *
	 * @return bool
	 */
	protected function is_git_available() {
		return wp_mcp_ai_find_binary( 'git', '--version' );
	}

	/**
	 * Check whether WP_MCP_AI_PATH is inside a git repository.
	 *
	 * @return bool
	 */
	protected function is_git_repository() {
		$result = wp_mcp_ai_run_process( array( 'git', 'rev-parse', '--git-dir' ), WP_MCP_AI_PATH );
		return $result['success'];
	}

	/**
	 * Execute a git command via proc_open (no exec / shell_exec).
	 *
	 * All variable arguments passed by callers must already be wrapped in
	 * escapeshellarg(). wp_mcp_ai_run_shell() uses proc_open internally,
	 * satisfying the WPCS prohibition on exec()/shell_exec().
	 *
	 * @param string $command Pre-escaped git command string.
	 * @return array { 'output' => string, 'exit_code' => int, 'success' => bool }
	 */
	protected function exec_git( $command ) {
		$result = wp_mcp_ai_run_shell( $command . ' 2>&1', WP_MCP_AI_PATH );

		return array(
			'output'    => $result['stdout'],
			'exit_code' => $result['exit_code'],
			'success'   => $result['success'],
		);
	}

	/**
	 * Sanitize extra git flag arguments.
	 *
	 * Allow-list: only flags that start with -- or - followed by alphanumeric
	 * characters or hyphens (e.g. --staged, -p, --follow). Everything else is
	 * silently dropped.
	 *
	 * @param array $options Raw array of option strings from tool arguments.
	 * @return string Sanitized, space-joined options string (may be empty).
	 */
	protected function sanitize_options( $options ) {
		if ( empty( $options ) || ! is_array( $options ) ) {
			return '';
		}

		$sanitized = array();
		foreach ( $options as $opt ) {
			$opt = sanitize_text_field( $opt );
			if ( preg_match( '/^--?[a-zA-Z0-9-]+$/', $opt ) ) {
				$sanitized[] = $opt;
			}
		}

		return implode( ' ', $sanitized );
	}

	/**
	 * Emit an audit-log entry and the wp_mcp_ai_git_write_operation action.
	 *
	 * Called by every state-changing git operation in git_change.
	 *
	 * @param string $operation Short operation name (e.g. 'commit', 'stash_push').
	 * @param string $target    Human-readable target (file, message, branch, etc.).
	 * @param array  $result    Exec_git() result array.
	 * @param array  $context   Tool execution context.
	 */
	protected function log_write_operation( $operation, $target, $result, $context ) {
		$user_id      = $context['user_id'] ?? 0;
		$assistant_id = $context['assistant_id'] ?? 0;

		$log_entry = sprintf(
			'Git %s: %s (Success: %s, User: %d, Assistant: %d)',
			esc_html( $operation ),
			esc_html( $target ),
			$result['success'] ? 'yes' : 'no',
			(int) $user_id,
			(int) $assistant_id
		);

		WP_MCP_AI_Logger::info( $log_entry );

		/**
		 * Fires after a git write operation.
		 *
		 * @since 1.1.0
		 *
		 * @param string $operation Operation name.
		 * @param string $target    Operation target.
		 * @param array  $result    Exec_git() result array.
		 * @param array  $context   Tool execution context.
		 */
		do_action( 'wp_mcp_ai_git_write_operation', $operation, $target, $result, $context );
	}
}
