<?php
/**
 * Git Operations Tool - Perform git operations on the plugin repository.
 *
 * Inspired by GitHub Copilot CLI's git integration. Allows AI agents to perform
 * common git operations like status, diff, commit, branch management, etc.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Git Operations tool for version control management.
 *
 * This tool enables an "Architect Agent" to perform git operations, similar
 * to GitHub Copilot CLI's git integration.
 *
 * Security features:
 * - Requires edit_plugins capability
 * - Restricted to plugin directory
 * - Read-only operations allowed by default
 * - Write operations require explicit permission
 * - Logs all modifications
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Git_Operations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'git_operations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Git Operations', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Perform git version control operations on the plugin repository. Supports status, diff, log, branch, commit, and more. Read operations are unrestricted; write operations require approval. Similar to GitHub Copilot CLI git integration.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'operation'   => array(
					'type'        => 'string',
					'enum'        => array( 'status', 'diff', 'log', 'branch', 'show', 'blame', 'commit', 'add', 'checkout', 'stash' ),
					'description' => __( 'Git operation to perform: "status" (working tree status), "diff" (show changes), "log" (commit history), "branch" (list/create branches), "show" (show commit), "blame" (file line history), "commit" (create commit), "add" (stage changes), "checkout" (switch branch/restore), "stash" (stash changes).', 'mcp-ai-wpoos' ),
				),
				'file_path'   => array(
					'type'        => 'string',
					'description' => __( 'Optional file path for file-specific operations (diff, blame, add, checkout). Relative to plugin root.', 'mcp-ai-wpoos' ),
				),
				'commit_hash' => array(
					'type'        => 'string',
					'description' => __( 'Commit hash for operations like "show" or "diff". Use "HEAD" for latest commit.', 'mcp-ai-wpoos' ),
				),
				'branch_name' => array(
					'type'        => 'string',
					'description' => __( 'Branch name for branch operations (create, checkout, etc.).', 'mcp-ai-wpoos' ),
				),
				'message'     => array(
					'type'        => 'string',
					'description' => __( 'Commit message for commit operation.', 'mcp-ai-wpoos' ),
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Limit number of results for log operation. Default: 10.', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'options'     => array(
					'type'        => 'array',
					'description' => __( 'Additional git command options as array of strings. Example: ["--staged", "--cached"].', 'mcp-ai-wpoos' ),
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
	public function get_capability_flags() {
		return array(
			'pro',                   // Pro tier feature.
			'requires-capability',   // Requires edit_plugins capability.
			'state-changing',        // Some operations modify git state.
			'local-only',            // Works locally, no external APIs.
			'reversible',            // Git operations are reversible.
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
	public function execute( array $arguments = array(), array $context = array() ) {
		// Extract arguments.
		$operation   = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : '';
		$file_path   = isset( $arguments['file_path'] ) ? sanitize_text_field( $arguments['file_path'] ) : '';
		$commit_hash = isset( $arguments['commit_hash'] ) ? sanitize_text_field( $arguments['commit_hash'] ) : '';
		$branch_name = isset( $arguments['branch_name'] ) ? sanitize_text_field( $arguments['branch_name'] ) : '';
		$message     = isset( $arguments['message'] ) ? sanitize_textarea_field( $arguments['message'] ) : '';
		$limit       = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$options     = isset( $arguments['options'] ) && is_array( $arguments['options'] ) ? $arguments['options'] : array();

		// Validate operation.
		if ( empty( $operation ) ) {
			return $this->error_response( __( 'Operation is required.', 'mcp-ai-wpoos' ) );
		}

		// Check if git is available.
		if ( ! $this->is_git_available() ) {
			return $this->error_response( __( 'Git is not available on this system.', 'mcp-ai-wpoos' ) );
		}

		// Check if plugin directory is a git repository.
		if ( ! $this->is_git_repository() ) {
			return $this->error_response( __( 'Plugin directory is not a git repository.', 'mcp-ai-wpoos' ) );
		}

		// Execute the operation.
		switch ( $operation ) {
			case 'status':
				return $this->git_status( $options );

			case 'diff':
				return $this->git_diff( $file_path, $commit_hash, $options );

			case 'log':
				return $this->git_log( $limit, $file_path, $options );

			case 'branch':
				return $this->git_branch( $branch_name, $options );

			case 'show':
				return $this->git_show( $commit_hash, $file_path, $options );

			case 'blame':
				return $this->git_blame( $file_path, $options );

			case 'commit':
				return $this->git_commit( $message, $options, $context );

			case 'add':
				return $this->git_add( $file_path, $options, $context );

			case 'checkout':
				return $this->git_checkout( $branch_name, $file_path, $options, $context );

			case 'stash':
				return $this->git_stash( $options, $context );

			default:
				return $this->error_response(
					sprintf(
						/* translators: %s: operation name */
						__( 'Unsupported operation: %s', 'mcp-ai-wpoos' ),
						esc_html( $operation )
					)
				);
		}
	}

	/**
	 * Check if git is available.
	 *
	 * @return bool True if git is available.
	 */
	private function is_git_available() {
		exec( 'git --version 2>&1', $output, $return_var );
		return 0 === $return_var;
	}

	/**
	 * Check if directory is a git repository.
	 *
	 * @return bool True if directory is a git repository.
	 */
	private function is_git_repository() {
		$original_dir = getcwd();
		chdir( WP_MCP_AI_PATH );

		exec( 'git rev-parse --git-dir 2>&1', $output, $return_var );

		chdir( $original_dir );

		return 0 === $return_var;
	}

	/**
	 * Execute git command.
	 *
	 * @param string $command Git command.
	 * @return array Command result.
	 */
	private function exec_git( $command ) {
		$original_dir = getcwd();
		chdir( WP_MCP_AI_PATH );

		$output     = array();
		$return_var = 0;

		exec( $command . ' 2>&1', $output, $return_var );

		chdir( $original_dir );

		return array(
			'output'    => implode( "\n", $output ),
			'exit_code' => $return_var,
			'success'   => 0 === $return_var,
		);
	}

	/**
	 * Git status operation.
	 *
	 * @param array $options Command options.
	 * @return array Operation result.
	 */
	private function git_status( $options ) {
		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git --no-pager status ' . $opts );

		return array(
			'operation' => 'status',
			'output'    => $result['output'],
			'success'   => $result['success'],
		);
	}

	/**
	 * Git diff operation.
	 *
	 * @param string $file_path   File path.
	 * @param string $commit_hash Commit hash.
	 * @param array  $options     Command options.
	 * @return array Operation result.
	 */
	private function git_diff( $file_path, $commit_hash, $options ) {
		$opts = $this->sanitize_options( $options );
		$cmd  = 'git --no-pager diff ' . $opts;

		if ( ! empty( $commit_hash ) ) {
			$cmd .= ' ' . escapeshellarg( $commit_hash );
		}

		if ( ! empty( $file_path ) ) {
			$cmd .= ' -- ' . escapeshellarg( $file_path );
		}

		$result = $this->exec_git( $cmd );

		return array(
			'operation'   => 'diff',
			'file_path'   => $file_path,
			'commit_hash' => $commit_hash,
			'output'      => $result['output'],
			'success'     => $result['success'],
		);
	}

	/**
	 * Git log operation.
	 *
	 * @param int    $limit     Number of commits.
	 * @param string $file_path File path.
	 * @param array  $options   Command options.
	 * @return array Operation result.
	 */
	private function git_log( $limit, $file_path, $options ) {
		$opts = $this->sanitize_options( $options );
		$cmd  = sprintf( 'git --no-pager log -n %d --oneline %s', max( 1, min( 100, $limit ) ), $opts );

		if ( ! empty( $file_path ) ) {
			$cmd .= ' -- ' . escapeshellarg( $file_path );
		}

		$result = $this->exec_git( $cmd );

		return array(
			'operation' => 'log',
			'limit'     => $limit,
			'file_path' => $file_path,
			'output'    => $result['output'],
			'success'   => $result['success'],
		);
	}

	/**
	 * Git branch operation.
	 *
	 * @param string $branch_name Branch name.
	 * @param array  $options     Command options.
	 * @return array Operation result.
	 */
	private function git_branch( $branch_name, $options ) {
		$opts = $this->sanitize_options( $options );
		$cmd  = 'git --no-pager branch ' . $opts;

		if ( ! empty( $branch_name ) ) {
			$cmd .= ' ' . escapeshellarg( $branch_name );
		}

		$result = $this->exec_git( $cmd );

		return array(
			'operation'   => 'branch',
			'branch_name' => $branch_name,
			'output'      => $result['output'],
			'success'     => $result['success'],
		);
	}

	/**
	 * Git show operation.
	 *
	 * @param string $commit_hash Commit hash.
	 * @param string $file_path   File path.
	 * @param array  $options     Command options.
	 * @return array Operation result.
	 */
	private function git_show( $commit_hash, $file_path, $options ) {
		$opts = $this->sanitize_options( $options );
		$hash = ! empty( $commit_hash ) ? escapeshellarg( $commit_hash ) : 'HEAD';
		$cmd  = "git --no-pager show {$hash} {$opts}";

		if ( ! empty( $file_path ) ) {
			$cmd .= ' -- ' . escapeshellarg( $file_path );
		}

		$result = $this->exec_git( $cmd );

		return array(
			'operation'   => 'show',
			'commit_hash' => $commit_hash,
			'file_path'   => $file_path,
			'output'      => $result['output'],
			'success'     => $result['success'],
		);
	}

	/**
	 * Git blame operation.
	 *
	 * @param string $file_path File path.
	 * @param array  $options   Command options.
	 * @return array Operation result.
	 */
	private function git_blame( $file_path, $options ) {
		if ( empty( $file_path ) ) {
			return $this->error_response( __( 'File path is required for blame operation.', 'mcp-ai-wpoos' ) );
		}

		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git --no-pager blame ' . $opts . ' ' . escapeshellarg( $file_path ) );

		return array(
			'operation' => 'blame',
			'file_path' => $file_path,
			'output'    => $result['output'],
			'success'   => $result['success'],
		);
	}

	/**
	 * Git commit operation (write operation - requires logging).
	 *
	 * @param string $message Commit message.
	 * @param array  $options Command options.
	 * @param array  $context Execution context.
	 * @return array Operation result.
	 */
	private function git_commit( $message, $options, $context ) {
		if ( empty( $message ) ) {
			return $this->error_response( __( 'Commit message is required.', 'mcp-ai-wpoos' ) );
		}

		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git commit ' . $opts . ' -m ' . escapeshellarg( $message ) );

		// Log the operation.
		$this->log_write_operation( 'commit', $message, $result, $context );

		return array(
			'operation' => 'commit',
			'message'   => $message,
			'output'    => $result['output'],
			'success'   => $result['success'],
		);
	}

	/**
	 * Git add operation (write operation - requires logging).
	 *
	 * @param string $file_path File path.
	 * @param array  $options   Command options.
	 * @param array  $context   Execution context.
	 * @return array Operation result.
	 */
	private function git_add( $file_path, $options, $context ) {
		if ( empty( $file_path ) ) {
			return $this->error_response( __( 'File path is required for add operation.', 'mcp-ai-wpoos' ) );
		}

		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git add ' . $opts . ' ' . escapeshellarg( $file_path ) );

		// Log the operation.
		$this->log_write_operation( 'add', $file_path, $result, $context );

		return array(
			'operation' => 'add',
			'file_path' => $file_path,
			'output'    => $result['output'],
			'success'   => $result['success'],
		);
	}

	/**
	 * Git checkout operation (write operation - requires logging).
	 *
	 * @param string $branch_name Branch name.
	 * @param string $file_path   File path.
	 * @param array  $options     Command options.
	 * @param array  $context     Execution context.
	 * @return array Operation result.
	 */
	private function git_checkout( $branch_name, $file_path, $options, $context ) {
		if ( empty( $branch_name ) && empty( $file_path ) ) {
			return $this->error_response( __( 'Either branch_name or file_path is required for checkout operation.', 'mcp-ai-wpoos' ) );
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

		// Log the operation.
		$target = ! empty( $branch_name ) ? $branch_name : $file_path;
		$this->log_write_operation( 'checkout', $target, $result, $context );

		return array(
			'operation'   => 'checkout',
			'branch_name' => $branch_name,
			'file_path'   => $file_path,
			'output'      => $result['output'],
			'success'     => $result['success'],
		);
	}

	/**
	 * Git stash operation (write operation - requires logging).
	 *
	 * @param array $options Command options.
	 * @param array $context Execution context.
	 * @return array Operation result.
	 */
	private function git_stash( $options, $context ) {
		$opts   = $this->sanitize_options( $options );
		$result = $this->exec_git( 'git stash ' . $opts );

		// Log the operation.
		$this->log_write_operation( 'stash', '', $result, $context );

		return array(
			'operation' => 'stash',
			'output'    => $result['output'],
			'success'   => $result['success'],
		);
	}

	/**
	 * Sanitize git command options.
	 *
	 * @param array $options Raw options.
	 * @return string Sanitized options string.
	 */
	private function sanitize_options( $options ) {
		if ( empty( $options ) || ! is_array( $options ) ) {
			return '';
		}

		$sanitized = array();
		foreach ( $options as $opt ) {
			$opt = sanitize_text_field( $opt );
			// Only allow options that start with -- or -.
			if ( preg_match( '/^--?[a-zA-Z0-9-]+$/', $opt ) ) {
				$sanitized[] = $opt;
			}
		}

		return implode( ' ', $sanitized );
	}

	/**
	 * Log write operation.
	 *
	 * @param string $operation Operation name.
	 * @param string $target    Operation target.
	 * @param array  $result    Operation result.
	 * @param array  $context   Execution context.
	 */
	private function log_write_operation( $operation, $target, $result, $context ) {
		$user_id      = $context['user_id'] ?? 0;
		$assistant_id = $context['assistant_id'] ?? 0;

		$log_entry = sprintf(
			'Git %s: %s (Success: %s, User: %d, Assistant: %d)',
			esc_html( $operation ),
			esc_html( $target ),
			$result['success'] ? 'yes' : 'no',
			$user_id,
			$assistant_id
		);

		WP_MCP_AI_Logger::info( $log_entry );

		/**
		 * Fires after a git write operation.
		 *
		 * @param string $operation Operation name.
		 * @param string $target    Operation target.
		 * @param array  $result    Operation result.
		 * @param array  $context   Execution context.
		 */
		do_action( 'wp_mcp_ai_git_write_operation', $operation, $target, $result, $context );
	}

	/**
	 * Generate error response.
	 *
	 * @param string $message Error message.
	 * @return array Error response.
	 */
	private function error_response( $message ) {
		return array(
			'operation' => 'error',
			'message'   => $message,
			'success'   => false,
		);
	}
}
