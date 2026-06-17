<?php
/**
 * Git Inspect Tool — read-only git operations.
 *
 * Part of the P5 Part 2 action-split decomposition of WP_MCP_AI_Tool_Git_Operations.
 *
 * Handles all read-only git queries: status, diff, log, show, blame, and branch.
 * Write operations (commit, add, checkout, stash) are handled by git_change.
 *
 * Back-compat: the legacy `git_operations` slug resolves to this class via the
 * deprecated-alias registry. Callers using write operations (commit/add/checkout/
 * stash) must migrate to git_change before v1.4.0.
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
 * Read-only git inspection tool.
 *
 * Supported operations: status · diff · log · show · blame · branch
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Tool_Git_Inspect implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Git_Helpers;

	// ------------------------------------------------------------------ //
	// Identity                                                             //
	// ------------------------------------------------------------------ //

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'git_inspect';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Git Inspect', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read-only git inspection — query repository state without modifying it. Supports status (working-tree summary), diff (show changes), log (commit history), show (inspect a commit), blame (line-level history), and branch (list branches). For write operations (commit, add, checkout, stash) use git_change.', 'mcp-ai-wpoos-pro' );
	}

	// ------------------------------------------------------------------ //
	// Schema                                                               //
	// ------------------------------------------------------------------ //

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'operation'   => array(
					'type'        => 'string',
					'enum'        => array( 'status', 'diff', 'log', 'show', 'blame', 'branch' ),
					'description' => __( 'Read-only git operation: "status" (working-tree summary), "diff" (show changes), "log" (commit history), "show" (inspect a commit or HEAD), "blame" (line-level history for a file), "branch" (list local/remote branches).', 'mcp-ai-wpoos-pro' ),
				),
				'file_path'   => array(
					'type'        => 'string',
					'description' => __( 'Optional file path relative to plugin root. Used by: diff, log, blame.', 'mcp-ai-wpoos-pro' ),
				),
				'commit_hash' => array(
					'type'        => 'string',
					'description' => __( 'Commit hash for diff or show. Defaults to HEAD.', 'mcp-ai-wpoos-pro' ),
				),
				'branch_name' => array(
					'type'        => 'string',
					'description' => __( 'Branch name to create (branch operation only).', 'mcp-ai-wpoos-pro' ),
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of commits to return (log operation). Default: 10.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'options'     => array(
					'type'        => 'array',
					'description' => __( 'Extra git flags as an array of strings. Only --flag or -f style values are accepted. Example: ["--staged"].', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
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
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
			'local-only',
			'architect-agent',
			'version-control',
			'development-workflow',
			'requires-workspace-trust',
		);
	}

	// ------------------------------------------------------------------ //
	// Execution                                                            //
	// ------------------------------------------------------------------ //

	/**
	 * Execute a read-only git operation.
	 *
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Canonical success envelope or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Gate 1 — preconditions (shell tools enabled, capability, git, repo).
		$precondition_error = $this->git_precondition_check();
		if ( is_wp_error( $precondition_error ) ) {
			return $precondition_error;
		}

		// Gate 1 — sanitize all arguments at entry.
		$operation   = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : '';
		$file_path   = isset( $arguments['file_path'] ) ? sanitize_text_field( $arguments['file_path'] ) : '';
		$commit_hash = isset( $arguments['commit_hash'] ) ? sanitize_text_field( $arguments['commit_hash'] ) : '';
		$branch_name = isset( $arguments['branch_name'] ) ? sanitize_text_field( $arguments['branch_name'] ) : '';
		$limit       = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$options     = isset( $arguments['options'] ) && is_array( $arguments['options'] ) ? $arguments['options'] : array();

		if ( empty( $operation ) ) {
			return new WP_Error( 'missing_operation', __( 'Operation is required.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $operation ) {
			case 'status':
				return $this->do_git_status( $options );

			case 'diff':
				return $this->do_git_diff( $file_path, $commit_hash, $options );

			case 'log':
				return $this->do_git_log( $limit, $file_path, $options );

			case 'show':
				return $this->do_git_show( $commit_hash, $file_path, $options );

			case 'blame':
				return $this->do_git_blame( $file_path, $options );

			case 'branch':
				return $this->do_git_branch( $branch_name, $options );

			default:
				return new WP_Error(
					'unsupported_operation',
					sprintf(
						/* translators: %s: operation name */
						__( 'Unsupported read-only operation: %s. For write operations use git_change.', 'mcp-ai-wpoos-pro' ),
						esc_html( $operation )
					)
				);
		}
	}

	// ------------------------------------------------------------------ //
	// Private operation handlers                                           //.
	// ------------------------------------------------------------------ //

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git status — working-tree summary.
	 *
	 * @param array $options Extra flags.
	 * @return array|WP_Error
	 */
	private function do_git_status( $options ) {
		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git --no-pager status ' . $opts );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_status_failed', $result['output'] ? $result['output'] : __( 'git status failed.', 'mcp-ai-wpoos-pro' ) );
		}

		return $this->format_success_response(
			__( 'Git status retrieved.', 'mcp-ai-wpoos-pro' ),
			array(
				'operation' => 'status',
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git diff — show changes for a file or commit.
	 *
	 * @param string $file_path   Optional file path.
	 * @param string $commit_hash Optional commit hash.
	 * @param array  $options     Extra flags.
	 * @return array|WP_Error
	 */
	private function do_git_diff( $file_path, $commit_hash, $options ) {
		$opts = $this->sanitize_options( $options );
		$cmd  = 'git --no-pager diff ' . $opts;

		if ( ! empty( $commit_hash ) ) {
			$cmd .= ' ' . escapeshellarg( $commit_hash );
		}

		if ( ! empty( $file_path ) ) {
			$cmd .= ' -- ' . escapeshellarg( $file_path );
		}

		$result = $this->exec_git( $cmd );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_diff_failed', $result['output'] ? $result['output'] : __( 'git diff failed.', 'mcp-ai-wpoos-pro' ) );
		}

		return $this->format_success_response(
			__( 'Git diff retrieved.', 'mcp-ai-wpoos-pro' ),
			array(
				'operation'   => 'diff',
				'file_path'   => esc_html( $file_path ),
				'commit_hash' => esc_html( $commit_hash ),
				'output'      => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git log — commit history.
	 *
	 * @param int    $limit     Maximum commits.
	 * @param string $file_path Optional file path filter.
	 * @param array  $options   Extra flags.
	 * @return array|WP_Error
	 */
	private function do_git_log( $limit, $file_path, $options ) {
		$safe_limit = max( 1, min( 100, $limit ) );
		$opts       = $this->sanitize_options( $options );
		$cmd        = sprintf( 'git --no-pager log -n %d --oneline %s', $safe_limit, $opts );

		if ( ! empty( $file_path ) ) {
			$cmd .= ' -- ' . escapeshellarg( $file_path );
		}

		$result = $this->exec_git( $cmd );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_log_failed', $result['output'] ? $result['output'] : __( 'git log failed.', 'mcp-ai-wpoos-pro' ) );
		}

		return $this->format_success_response(
			__( 'Git log retrieved.', 'mcp-ai-wpoos-pro' ),
			array(
				'operation' => 'log',
				'limit'     => $safe_limit,
				'file_path' => esc_html( $file_path ),
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git show — inspect a commit (defaults to HEAD).
	 *
	 * @param string $commit_hash Commit hash or empty for HEAD.
	 * @param string $file_path   Optional file path.
	 * @param array  $options     Extra flags.
	 * @return array|WP_Error
	 */
	private function do_git_show( $commit_hash, $file_path, $options ) {
		$opts = $this->sanitize_options( $options );
		$hash = ! empty( $commit_hash ) ? escapeshellarg( $commit_hash ) : 'HEAD';
		$cmd  = "git --no-pager show {$hash} {$opts}";

		if ( ! empty( $file_path ) ) {
			$cmd .= ' -- ' . escapeshellarg( $file_path );
		}

		$result = $this->exec_git( $cmd );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_show_failed', $result['output'] ? $result['output'] : __( 'git show failed.', 'mcp-ai-wpoos-pro' ) );
		}

		return $this->format_success_response(
			__( 'Git show retrieved.', 'mcp-ai-wpoos-pro' ),
			array(
				'operation'   => 'show',
				'commit_hash' => esc_html( $commit_hash ),
				'file_path'   => esc_html( $file_path ),
				'output'      => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git blame — line-level history for a file.
	 *
	 * @param string $file_path File path (required).
	 * @param array  $options   Extra flags.
	 * @return array|WP_Error
	 */
	private function do_git_blame( $file_path, $options ) {
		if ( empty( $file_path ) ) {
			return new WP_Error( 'missing_file_path', __( 'file_path is required for the blame operation.', 'mcp-ai-wpoos-pro' ) );
		}

		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git --no-pager blame ' . $opts . ' ' . escapeshellarg( $file_path ) );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_blame_failed', $result['output'] ? $result['output'] : __( 'git blame failed.', 'mcp-ai-wpoos-pro' ) );
		}

		return $this->format_success_response(
			__( 'Git blame retrieved.', 'mcp-ai-wpoos-pro' ),
			array(
				'operation' => 'blame',
				'file_path' => esc_html( $file_path ),
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git branch — list or create branches.
	 *
	 * @param string $branch_name Optional branch name to create.
	 * @param array  $options     Extra flags.
	 * @return array|WP_Error
	 */
	private function do_git_branch( $branch_name, $options ) {
		$opts = $this->sanitize_options( $options );
		$cmd  = 'git --no-pager branch ' . $opts;

		if ( ! empty( $branch_name ) ) {
			$cmd .= ' ' . escapeshellarg( $branch_name );
		}

		$result = $this->exec_git( $cmd );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_branch_failed', $result['output'] ? $result['output'] : __( 'git branch failed.', 'mcp-ai-wpoos-pro' ) );
		}

		return $this->format_success_response(
			__( 'Git branch operation complete.', 'mcp-ai-wpoos-pro' ),
			array(
				'operation'   => 'branch',
				'branch_name' => esc_html( $branch_name ),
				'output'      => $result['output'],
			)
		);
	}
}
