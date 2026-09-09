<?php
/**
 * Tool_Git_Change (ecosystem port - Wave F2, architect-agent toolkit).
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
 * State-changing git operations tool.
 *
 * Supported operations: commit · add · checkout · stash
 *
 * The stash operation accepts a stash_subcommand parameter:
 *   list · push · pop · apply · drop · clear · show · branch
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Tool_Git_Change implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Git_Helpers;

	// ------------------------------------------------------------------ //
	// Identity                                                             //
	// ------------------------------------------------------------------ //

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'git_change';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Git Change', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'State-changing git operations: commit (create a commit), add (stage files), checkout (switch branch or restore a file), and stash (save / apply / manage stashed changes). All write operations are logged. For read-only inspection use git_inspect.', 'nvoos-content-graph-pro' );
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
				'operation'         => array(
					'type'        => 'string',
					'enum'        => array( 'commit', 'add', 'checkout', 'stash' ),
					'description' => __( 'Write operation: "commit" (create a commit), "add" (stage a file), "checkout" (switch branch or restore file), "stash" (use stash_subcommand for the specific action).', 'nvoos-content-graph-pro' ),
				),
				'file_path'         => array(
					'type'        => 'string',
					'description' => __( 'File path relative to plugin root. Required for: add, checkout (file restore).', 'nvoos-content-graph-pro' ),
				),
				'branch_name'       => array(
					'type'        => 'string',
					'description' => __( 'Branch name. Used by: checkout (switch), stash branch (create branch from stash).', 'nvoos-content-graph-pro' ),
				),
				'message'           => array(
					'type'        => 'string',
					'description' => __( 'Commit message (commit operation) or stash label (stash push).', 'nvoos-content-graph-pro' ),
				),
				'stash_subcommand'  => array(
					'type'        => 'string',
					'enum'        => array( 'list', 'push', 'pop', 'apply', 'drop', 'clear', 'show', 'branch' ),
					'description' => __( 'Stash subcommand: list · push (save) · pop (apply+remove) · apply (apply only) · drop (delete) · clear (remove all) · show (diff) · branch (create branch from stash). Default: push.', 'nvoos-content-graph-pro' ),
					'default'     => 'push',
				),
				'stash_ref'         => array(
					'type'        => 'string',
					'description' => __( 'Stash reference, e.g. "stash@{0}". Used by: pop, apply, drop, show, branch.', 'nvoos-content-graph-pro' ),
				),
				'include_untracked' => array(
					'type'        => 'boolean',
					'description' => __( 'Include untracked files in stash push. Default: false.', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'keep_index'        => array(
					'type'        => 'boolean',
					'description' => __( 'Keep staged changes in the index when running stash push. Default: false.', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'options'           => array(
					'type'        => 'array',
					'description' => __( 'Extra git flags as an array of strings. Only --flag or -f style values are accepted.', 'nvoos-content-graph-pro' ),
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
			'requires-capability',
			'state-changing',
			'reversible',
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
	 * Execute a state-changing git operation.
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
		$operation         = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : '';
		$file_path         = isset( $arguments['file_path'] ) ? sanitize_text_field( $arguments['file_path'] ) : '';
		$branch_name       = isset( $arguments['branch_name'] ) ? sanitize_text_field( $arguments['branch_name'] ) : '';
		$message           = isset( $arguments['message'] ) ? sanitize_textarea_field( $arguments['message'] ) : '';
		$stash_subcommand  = isset( $arguments['stash_subcommand'] ) ? sanitize_text_field( $arguments['stash_subcommand'] ) : 'push';
		$stash_ref         = isset( $arguments['stash_ref'] ) ? sanitize_text_field( $arguments['stash_ref'] ) : '';
		$include_untracked = ! empty( $arguments['include_untracked'] );
		$keep_index        = ! empty( $arguments['keep_index'] );
		$options           = isset( $arguments['options'] ) && is_array( $arguments['options'] ) ? $arguments['options'] : array();

		if ( empty( $operation ) ) {
			return new WP_Error( 'missing_operation', __( 'Operation is required.', 'nvoos-content-graph-pro' ) );
		}

		switch ( $operation ) {
			case 'commit':
				return $this->do_git_commit( $message, $options, $context );

			case 'add':
				return $this->do_git_add( $file_path, $options, $context );

			case 'checkout':
				return $this->do_git_checkout( $branch_name, $file_path, $options, $context );

			case 'stash':
				return $this->do_git_stash( $stash_subcommand, $stash_ref, $message, $branch_name, $include_untracked, $keep_index, $options, $context );

			default:
				return new WP_Error(
					'unsupported_operation',
					sprintf(
						/* translators: %s: operation name */
						__( 'Unsupported write operation: %s. For read-only queries use git_inspect.', 'nvoos-content-graph-pro' ),
						esc_html( $operation )
					)
				);
		}
	}

	// ------------------------------------------------------------------ //
	// Commit / add / checkout                                              //.
	// ------------------------------------------------------------------ //

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git commit — create a commit with the staged changes.
	 *
	 * @param string $message Commit message (required).
	 * @param array  $options Extra flags.
	 * @param array  $context Execution context.
	 * @return array|WP_Error
	 */
	private function do_git_commit( $message, $options, $context ) {
		if ( empty( $message ) ) {
			return new WP_Error( 'missing_message', __( 'Commit message is required.', 'nvoos-content-graph-pro' ) );
		}

		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git commit ' . $opts . ' -m ' . escapeshellarg( $message ) );

		$this->log_write_operation( 'commit', $message, $result, $context );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_commit_failed', $result['output'] ? $result['output'] : __( 'git commit failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'Commit created.', 'nvoos-content-graph-pro' ),
			array(
				'operation' => 'commit',
				'message'   => esc_html( $message ),
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git add — stage a file.
	 *
	 * @param string $file_path File path to stage (required).
	 * @param array  $options   Extra flags.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	private function do_git_add( $file_path, $options, $context ) {
		if ( empty( $file_path ) ) {
			return new WP_Error( 'missing_file_path', __( 'file_path is required for the add operation.', 'nvoos-content-graph-pro' ) );
		}

		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git add ' . $opts . ' ' . escapeshellarg( $file_path ) );

		$this->log_write_operation( 'add', $file_path, $result, $context );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_add_failed', $result['output'] ? $result['output'] : __( 'git add failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'File staged.', 'nvoos-content-graph-pro' ),
			array(
				'operation' => 'add',
				'file_path' => esc_html( $file_path ),
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git checkout — switch branch or restore a file.
	 *
	 * @param string $branch_name Branch to switch to.
	 * @param string $file_path   File to restore (mutually exclusive with branch_name).
	 * @param array  $options     Extra flags.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error
	 */
	private function do_git_checkout( $branch_name, $file_path, $options, $context ) {
		if ( empty( $branch_name ) && empty( $file_path ) ) {
			return new WP_Error(
				'missing_target',
				__( 'Either branch_name or file_path is required for the checkout operation.', 'nvoos-content-graph-pro' )
			);
		}

		$opts = $this->sanitize_options( $options );
		$cmd  = 'git checkout ' . $opts;

		if ( ! empty( $branch_name ) ) {
			$cmd .= ' ' . escapeshellarg( $branch_name );
		}

		if ( ! empty( $file_path ) ) {
			$cmd .= ' -- ' . escapeshellarg( $file_path );
		}

		$result = $this->exec_git( $cmd );
		$target = ! empty( $branch_name ) ? $branch_name : $file_path;

		$this->log_write_operation( 'checkout', $target, $result, $context );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_checkout_failed', $result['output'] ? $result['output'] : __( 'git checkout failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'Checkout complete.', 'nvoos-content-graph-pro' ),
			array(
				'operation'   => 'checkout',
				'branch_name' => esc_html( $branch_name ),
				'file_path'   => esc_html( $file_path ),
				'output'      => $result['output'],
			)
		);
	}

	// ------------------------------------------------------------------ //
	// Stash dispatcher                                                     //.
	// ------------------------------------------------------------------ //

	/**
	 * Route a stash operation to the appropriate private handler.
	 *
	 * @param string $subcommand       Stash subcommand.
	 * @param string $stash_ref        Stash reference (e.g. stash@{0}).
	 * @param string $message          Label for stash push.
	 * @param string $branch_name      Branch name for stash branch.
	 * @param bool   $include_untracked Include untracked files in stash push.
	 * @param bool   $keep_index        Keep index during stash push.
	 * @param array  $options          Extra flags.
	 * @param array  $context          Execution context.
	 * @return array|WP_Error
	 */
	private function do_git_stash( $subcommand, $stash_ref, $message, $branch_name, $include_untracked, $keep_index, $options, $context ) {
		if ( ! empty( $stash_ref ) && ! preg_match( '/^stash@\{[0-9]+\}$/', $stash_ref ) ) {
			return new WP_Error(
				'invalid_stash_ref',
				__( 'Invalid stash reference. Use stash@{N} where N is a non-negative integer.', 'nvoos-content-graph-pro' )
			);
		}

		switch ( $subcommand ) {
			case 'list':
				return $this->stash_list( $options );

			case 'push':
				return $this->stash_push( $message, $include_untracked, $keep_index, $options, $context );

			case 'pop':
				return $this->stash_pop( $stash_ref, $options, $context );

			case 'apply':
				return $this->stash_apply( $stash_ref, $options, $context );

			case 'drop':
				return $this->stash_drop( $stash_ref, $options, $context );

			case 'clear':
				return $this->stash_clear( $options, $context );

			case 'show':
				return $this->stash_show( $stash_ref, $options );

			case 'branch':
				return $this->stash_branch( $branch_name, $stash_ref, $options, $context );

			default:
				return new WP_Error(
					'unsupported_stash_subcommand',
					sprintf(
						/* translators: %s: subcommand name */
						__( 'Unsupported stash subcommand: %s', 'nvoos-content-graph-pro' ),
						esc_html( $subcommand )
					)
				);
		}
	}

	// ------------------------------------------------------------------ //
	// Stash subcommand handlers                                            //.
	// ------------------------------------------------------------------ //

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git stash list — list all stashed changes.
	 *
	 * @param array $options Extra flags.
	 * @return array|WP_Error
	 */
	private function stash_list( $options ) {
		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git stash list ' . $opts );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_stash_list_failed', $result['output'] ? $result['output'] : __( 'git stash list failed.', 'nvoos-content-graph-pro' ) );
		}

		// Parse stash list into structured entries.
		$entries = array();
		if ( ! empty( $result['output'] ) ) {
			foreach ( explode( "\n", trim( $result['output'] ) ) as $line ) {
				if ( preg_match( '/^(stash@\{(\d+)\}):\s+(.+)$/', $line, $m ) ) {
					$entries[] = array(
						'ref'     => $m[1],
						'index'   => (int) $m[2],
						'message' => $m[3],
					);
				}
			}
		}

		return $this->format_success_response(
			__( 'Stash list retrieved.', 'nvoos-content-graph-pro' ),
			array(
				'operation'     => 'stash_list',
				'stash_count'   => count( $entries ),
				'stash_entries' => $entries,
				'output'        => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git stash push — save current changes to a new stash entry.
	 *
	 * @param string $message          Optional stash label.
	 * @param bool   $include_untracked Include untracked files.
	 * @param bool   $keep_index        Keep index.
	 * @param array  $options          Extra flags.
	 * @param array  $context          Execution context.
	 * @return array|WP_Error
	 */
	private function stash_push( $message, $include_untracked, $keep_index, $options, $context ) {
		$opts = $this->sanitize_options( $options );
		$cmd  = 'git stash push ' . $opts;

		if ( $include_untracked ) {
			$cmd .= ' --include-untracked';
		}

		if ( $keep_index ) {
			$cmd .= ' --keep-index';
		}

		if ( ! empty( $message ) ) {
			$cmd .= ' -m ' . escapeshellarg( $message );
		}

		$result = $this->exec_git( $cmd );
		$this->log_write_operation( 'stash_push', $message, $result, $context );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_stash_push_failed', $result['output'] ? $result['output'] : __( 'git stash push failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'Changes stashed.', 'nvoos-content-graph-pro' ),
			array(
				'operation'         => 'stash_push',
				'message'           => esc_html( $message ),
				'include_untracked' => $include_untracked,
				'keep_index'        => $keep_index,
				'output'            => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git stash pop — apply stash and remove it from the stash list.
	 *
	 * @param string $stash_ref Optional stash reference (defaults to latest).
	 * @param array  $options   Extra flags.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	private function stash_pop( $stash_ref, $options, $context ) {
		$opts = $this->sanitize_options( $options );
		$cmd  = 'git stash pop ' . $opts;

		if ( ! empty( $stash_ref ) ) {
			$cmd .= ' ' . escapeshellarg( $stash_ref );
		}

		$result = $this->exec_git( $cmd );
		$this->log_write_operation( 'stash_pop', $stash_ref, $result, $context );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_stash_pop_failed', $result['output'] ? $result['output'] : __( 'git stash pop failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'Stash applied and removed.', 'nvoos-content-graph-pro' ),
			array(
				'operation' => 'stash_pop',
				'stash_ref' => esc_html( $stash_ref ),
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git stash apply — apply stash without removing it.
	 *
	 * @param string $stash_ref Optional stash reference.
	 * @param array  $options   Extra flags.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	private function stash_apply( $stash_ref, $options, $context ) {
		$opts = $this->sanitize_options( $options );
		$cmd  = 'git stash apply ' . $opts;

		if ( ! empty( $stash_ref ) ) {
			$cmd .= ' ' . escapeshellarg( $stash_ref );
		}

		$result = $this->exec_git( $cmd );
		$this->log_write_operation( 'stash_apply', $stash_ref, $result, $context );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_stash_apply_failed', $result['output'] ? $result['output'] : __( 'git stash apply failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'Stash applied.', 'nvoos-content-graph-pro' ),
			array(
				'operation' => 'stash_apply',
				'stash_ref' => esc_html( $stash_ref ),
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git stash drop — delete a stash entry.
	 *
	 * @param string $stash_ref Stash reference (required).
	 * @param array  $options   Extra flags.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error
	 */
	private function stash_drop( $stash_ref, $options, $context ) {
		if ( empty( $stash_ref ) ) {
			return new WP_Error( 'missing_stash_ref', __( 'stash_ref is required for the drop operation.', 'nvoos-content-graph-pro' ) );
		}

		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git stash drop ' . $opts . ' ' . escapeshellarg( $stash_ref ) );

		$this->log_write_operation( 'stash_drop', $stash_ref, $result, $context );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_stash_drop_failed', $result['output'] ? $result['output'] : __( 'git stash drop failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'Stash entry deleted.', 'nvoos-content-graph-pro' ),
			array(
				'operation' => 'stash_drop',
				'stash_ref' => esc_html( $stash_ref ),
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git stash clear — remove all stash entries.
	 *
	 * @param array $options Extra flags.
	 * @param array $context Execution context.
	 * @return array|WP_Error
	 */
	private function stash_clear( $options, $context ) {
		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git stash clear ' . $opts );

		$this->log_write_operation( 'stash_clear', 'all stashes', $result, $context );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_stash_clear_failed', $result['output'] ? $result['output'] : __( 'git stash clear failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'All stash entries cleared.', 'nvoos-content-graph-pro' ),
			array(
				'operation' => 'stash_clear',
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git stash show — display the diff for a stash entry.
	 *
	 * @param string $stash_ref Optional stash reference (defaults to latest).
	 * @param array  $options   Extra flags.
	 * @return array|WP_Error
	 */
	private function stash_show( $stash_ref, $options ) {
		$opts = $this->sanitize_options( $options );
		$cmd  = 'git --no-pager stash show ' . $opts;

		if ( ! empty( $stash_ref ) ) {
			$cmd .= ' ' . escapeshellarg( $stash_ref );
		}

		$result = $this->exec_git( $cmd );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_stash_show_failed', $result['output'] ? $result['output'] : __( 'git stash show failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'Stash diff retrieved.', 'nvoos-content-graph-pro' ),
			array(
				'operation' => 'stash_show',
				'stash_ref' => esc_html( $stash_ref ),
				'output'    => $result['output'],
			)
		);
	}

	/**
	 * Performs the operation.
	 // phpcs:ignore Generic.Commenting.DocComment.ShortNotCapital
	 * git stash branch — create a new branch from a stash entry.
	 *
	 * @param string $branch_name Branch name (required).
	 * @param string $stash_ref   Optional stash reference.
	 * @param array  $options     Extra flags.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error
	 */
	private function stash_branch( $branch_name, $stash_ref, $options, $context ) {
		if ( empty( $branch_name ) ) {
			return new WP_Error( 'missing_branch_name', __( 'branch_name is required for the stash branch operation.', 'nvoos-content-graph-pro' ) );
		}

		$opts = $this->sanitize_options( $options );
		$cmd  = 'git stash branch ' . $opts . ' ' . escapeshellarg( $branch_name );

		if ( ! empty( $stash_ref ) ) {
			$cmd .= ' ' . escapeshellarg( $stash_ref );
		}

		$result = $this->exec_git( $cmd );
		$target = $branch_name . ( $stash_ref ? " from {$stash_ref}" : '' );

		$this->log_write_operation( 'stash_branch', $target, $result, $context );

		if ( ! $result['success'] ) {
			return new WP_Error( 'git_stash_branch_failed', $result['output'] ? $result['output'] : __( 'git stash branch failed.', 'nvoos-content-graph-pro' ) );
		}

		return $this->format_success_response(
			__( 'Branch created from stash.', 'nvoos-content-graph-pro' ),
			array(
				'operation'   => 'stash_branch',
				'branch_name' => esc_html( $branch_name ),
				'stash_ref'   => esc_html( $stash_ref ),
				'output'      => $result['output'],
			)
		);
	}
}
