<?php
/**
 * WP_MCP_AI_Tool_Git_Helpers (ecosystem port - Wave F2, architect-agent toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/architect-agent/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `WP_MCP_AI_PATH` workspace-root refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` (the standalone
 * workspace is the addon); the base-owned proc helpers (`wp_mcp_ai_run_process|run_shell|find_binary`)
 * resolve from the addon's D8-compat `src/tools/architect-agent/helpers.php` copy.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architect_Agent
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




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
	 *  4. NVOOS_CONTENT_GRAPH_PRO_PATH is a git repository.
	 *
	 * @return WP_Error|null Null on success, WP_Error on the first failed check.
	 */
	protected function git_precondition_check() {
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			return new WP_Error(
				'shell_tools_disabled',
				__( "Shell tools are disabled. Set define( 'WP_MCP_AI_ALLOW_SHELL_TOOLS', true ) in wp-config.php to enable them.", 'nvoos-content-graph-pro' )
			);
		}

		// Both git sub-tools declare edit_plugins as their required capability.
		if ( ! current_user_can( 'edit_plugins' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to run git commands.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! $this->is_git_available() ) {
			return new WP_Error(
				'git_not_found',
				__( 'Git is not available on this system.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! $this->is_git_repository() ) {
			return new WP_Error(
				'not_a_git_repo',
				__( 'Plugin directory is not a git repository.', 'nvoos-content-graph-pro' )
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
	 * Check whether NVOOS_CONTENT_GRAPH_PRO_PATH is inside a git repository.
	 *
	 * @return bool
	 */
	protected function is_git_repository() {
		$result = wp_mcp_ai_run_process( array( 'git', 'rev-parse', '--git-dir' ), NVOOS_CONTENT_GRAPH_PRO_PATH );
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
		$result = wp_mcp_ai_run_shell( $command . ' 2>&1', NVOOS_CONTENT_GRAPH_PRO_PATH );

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
