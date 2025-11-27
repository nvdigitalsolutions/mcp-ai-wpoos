<?php
/**
 * Tool that manages GitHub repository operations for custom tool development.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/integrations/class-wp-mcp-ai-github-client.php';

/**
 * Provides an assistant tool for GitHub repository operations focused on custom tool development.
 */
class WP_MCP_AI_Tool_Github_Repository_Operations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'github_repository_operations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GitHub Repository Operations', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Perform GitHub repository operations such as creating branches and managing files in the custom-tools directory.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list_branches, create_branch, get_file, or update_file.', 'wp-mcp-ai' ),
					'enum'        => array( 'list_branches', 'create_branch', 'get_file', 'update_file' ),
				),
				'owner'       => array(
					'type'        => 'string',
					'description' => __( 'Repository owner.', 'wp-mcp-ai' ),
				),
				'repo'        => array(
					'type'        => 'string',
					'description' => __( 'Repository name.', 'wp-mcp-ai' ),
				),
				'branch_name' => array(
					'type'        => 'string',
					'description' => __( 'Branch name for create_branch action.', 'wp-mcp-ai' ),
				),
				'source_branch' => array(
					'type'        => 'string',
					'description' => __( 'Source branch to branch from (default: main).', 'wp-mcp-ai' ),
					'default'     => 'main',
				),
				'file_path'   => array(
					'type'        => 'string',
					'description' => __( 'File path (must be within custom-tools/ directory for safety).', 'wp-mcp-ai' ),
				),
				'file_content' => array(
					'type'        => 'string',
					'description' => __( 'File content for update_file action.', 'wp-mcp-ai' ),
				),
				'commit_message' => array(
					'type'        => 'string',
					'description' => __( 'Commit message for update_file action.', 'wp-mcp-ai' ),
				),
				'branch'      => array(
					'type'        => 'string',
					'description' => __( 'Branch to operate on (default: main).', 'wp-mcp-ai' ),
					'default'     => 'main',
				),
			),
			'required'             => array( 'action', 'owner', 'repo' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters( 'wp_mcp_ai_github_operations_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_github_forbidden', __( 'You do not have permission to perform GitHub operations.', 'wp-mcp-ai' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$owner  = isset( $arguments['owner'] ) ? sanitize_text_field( $arguments['owner'] ) : '';
		$repo   = isset( $arguments['repo'] ) ? sanitize_text_field( $arguments['repo'] ) : '';

		if ( empty( $action ) || empty( $owner ) || empty( $repo ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_params', __( 'Action, owner, and repo parameters are required.', 'wp-mcp-ai' ) );
		}

		$client = new WP_MCP_AI_Github_Client();

		switch ( $action ) {
			case 'list_branches':
				return $this->handle_list_branches( $client, $owner, $repo );

			case 'create_branch':
				return $this->handle_create_branch( $client, $owner, $repo, $arguments );

			case 'get_file':
				return $this->handle_get_file( $client, $owner, $repo, $arguments );

			case 'update_file':
				return $this->handle_update_file( $client, $owner, $repo, $arguments );

			default:
				return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action specified.', 'wp-mcp-ai' ) );
		}
	}

	/**
	 * Handle list_branches action.
	 *
	 * @param WP_MCP_AI_Github_Client $client GitHub client.
	 * @param string                  $owner  Repository owner.
	 * @param string                  $repo   Repository name.
	 * @return array|WP_Error
	 */
	private function handle_list_branches( $client, $owner, $repo ) {
		$branches = $client->list_branches( $owner, $repo );

		if ( is_wp_error( $branches ) ) {
			return $branches;
		}

		$formatted = array_map(
			function ( $branch ) {
				return array(
					'name'   => $branch['name'],
					'sha'    => $branch['commit']['sha'] ?? '',
					'protected' => $branch['protected'] ?? false,
				);
			},
			$branches
		);

		return array(
			'branches' => $formatted,
			'count'    => count( $formatted ),
		);
	}

	/**
	 * Handle create_branch action.
	 *
	 * @param WP_MCP_AI_Github_Client $client    GitHub client.
	 * @param string                  $owner     Repository owner.
	 * @param string                  $repo      Repository name.
	 * @param array                   $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_create_branch( $client, $owner, $repo, $arguments ) {
		$branch_name   = isset( $arguments['branch_name'] ) ? sanitize_text_field( $arguments['branch_name'] ) : '';
		$source_branch = isset( $arguments['source_branch'] ) ? sanitize_text_field( $arguments['source_branch'] ) : 'main';

		if ( empty( $branch_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_branch_name', __( 'Branch name is required.', 'wp-mcp-ai' ) );
		}

		// Get the SHA of the source branch.
		$source_sha = $client->get_branch_sha( $owner, $repo, $source_branch );

		if ( is_wp_error( $source_sha ) ) {
			return $source_sha;
		}

		$result = $client->create_branch( $owner, $repo, $branch_name, $source_sha );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: branch name */
				__( 'Branch "%s" created successfully.', 'wp-mcp-ai' ),
				$branch_name
			),
			'branch'  => array(
				'name' => $branch_name,
				'sha'  => $source_sha,
			),
		);
	}

	/**
	 * Handle get_file action with safety restrictions.
	 *
	 * @param WP_MCP_AI_Github_Client $client    GitHub client.
	 * @param string                  $owner     Repository owner.
	 * @param string                  $repo      Repository name.
	 * @param array                   $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_get_file( $client, $owner, $repo, $arguments ) {
		$file_path = isset( $arguments['file_path'] ) ? sanitize_text_field( $arguments['file_path'] ) : '';
		$branch    = isset( $arguments['branch'] ) ? sanitize_text_field( $arguments['branch'] ) : 'main';

		if ( empty( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_file_path', __( 'File path is required.', 'wp-mcp-ai' ) );
		}

		// Safety check: Only allow access to custom-tools directory.
		if ( ! $this->is_safe_path( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_unsafe_path',
				__( 'For safety, only files in the custom-tools/ directory can be accessed.', 'wp-mcp-ai' )
			);
		}

		$contents = $client->get_contents( $owner, $repo, $file_path, $branch );

		if ( is_wp_error( $contents ) ) {
			return $contents;
		}

		// Decode base64 content with validation.
		$decoded_content = '';
		if ( isset( $contents['content'] ) ) {
			$content_clean = str_replace( "\n", '', $contents['content'] );
			// Validate base64 before decoding.
			if ( preg_match( '/^[A-Za-z0-9+\/=]+$/', $content_clean ) ) {
				$decoded_content = base64_decode( $content_clean ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			}
		}

		return array(
			'file_path' => $file_path,
			'content'   => $decoded_content,
			'sha'       => $contents['sha'] ?? '',
			'size'      => $contents['size'] ?? 0,
		);
	}

	/**
	 * Handle update_file action with safety restrictions.
	 *
	 * @param WP_MCP_AI_Github_Client $client    GitHub client.
	 * @param string                  $owner     Repository owner.
	 * @param string                  $repo      Repository name.
	 * @param array                   $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function handle_update_file( $client, $owner, $repo, $arguments ) {
		$file_path      = isset( $arguments['file_path'] ) ? sanitize_text_field( $arguments['file_path'] ) : '';
		$file_content   = isset( $arguments['file_content'] ) ? $arguments['file_content'] : '';
		$commit_message = isset( $arguments['commit_message'] ) ? sanitize_text_field( $arguments['commit_message'] ) : 'Update custom tool';
		$branch         = isset( $arguments['branch'] ) ? sanitize_text_field( $arguments['branch'] ) : 'main';

		if ( empty( $file_path ) || empty( $file_content ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_params', __( 'File path and content are required.', 'wp-mcp-ai' ) );
		}

		// Safety check: Only allow modifications to custom-tools directory.
		if ( ! $this->is_safe_path( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_unsafe_path',
				__( 'For safety, only files in the custom-tools/ directory can be modified.', 'wp-mcp-ai' )
			);
		}

		// Validate PHP syntax if it's a PHP file.
		if ( preg_match( '/\.php$/i', $file_path ) ) {
			$syntax_check = $this->validate_php_syntax( $file_content );
			if ( is_wp_error( $syntax_check ) ) {
				return $syntax_check;
			}
		}

		// Get existing file SHA if it exists.
		$sha             = '';
		$existing_file = $client->get_contents( $owner, $repo, $file_path, $branch );
		if ( ! is_wp_error( $existing_file ) && isset( $existing_file['sha'] ) ) {
			$sha = $existing_file['sha'];
		}

		$result = $client->create_or_update_file( $owner, $repo, $file_path, $commit_message, $file_content, $branch, $sha );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: %s: file path */
				__( 'File "%s" updated successfully.', 'wp-mcp-ai' ),
				$file_path
			),
			'commit'  => array(
				'sha'     => $result['commit']['sha'] ?? '',
				'message' => $commit_message,
			),
		);
	}

	/**
	 * Check if the file path is within the safe custom-tools directory.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_safe_path( $path ) {
		// Normalize path.
		$path = trim( $path, '/' );

		// Must start with custom-tools/.
		if ( 0 !== strpos( $path, 'custom-tools/' ) ) {
			return false;
		}

		// Prevent directory traversal.
		if ( strpos( $path, '..' ) !== false ) {
			return false;
		}

		// Only allow PHP files.
		if ( ! preg_match( '/\.php$/i', $path ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate PHP syntax without executing the code.
	 *
	 * @param string $code PHP code to validate.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	private function validate_php_syntax( $code ) {
		// Basic validation: Check for opening PHP tag.
		if ( 0 !== strpos( trim( $code ), '<?php' ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_php',
				__( 'PHP files must start with <?php tag.', 'wp-mcp-ai' )
			);
		}

		// Check for dangerous functions.
		$dangerous_functions = array( 'eval', 'exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen' );
		foreach ( $dangerous_functions as $func ) {
			if ( preg_match( '/\b' . preg_quote( $func, '/' ) . '\s*\(/i', $code ) ) {
				return new WP_Error(
					'wp_mcp_ai_dangerous_function',
					sprintf(
						/* translators: %s: function name */
						__( 'Dangerous function "%s" is not allowed in custom tools.', 'wp-mcp-ai' ),
						$func
					)
				);
			}
		}

		// Try to lint the PHP code using php -l if available and safe.
		// Only use proc_open if explicitly enabled via filter.
		$allow_proc_open = apply_filters( 'wp_mcp_ai_allow_php_lint', function_exists( 'proc_open' ) );

		if ( $allow_proc_open ) {
			$temp_file = wp_tempnam();
			file_put_contents( $temp_file, $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			// Run PHP linter in safe mode.
			$descriptors = array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);

			// Use escapeshellarg for security and limit execution time.
			$cmd     = 'timeout 5 php -l ' . escapeshellarg( $temp_file ) . ' 2>&1';
			$process = proc_open( $cmd, $descriptors, $pipes );

			if ( is_resource( $process ) ) {
				$output = stream_get_contents( $pipes[1] );
				$errors = stream_get_contents( $pipes[2] );
				fclose( $pipes[1] );
				fclose( $pipes[2] );
				$return_code = proc_close( $process );

				unlink( $temp_file );

				if ( 0 !== $return_code ) {
					return new WP_Error(
						'wp_mcp_ai_syntax_error',
						__( 'PHP syntax error: ', 'wp-mcp-ai' ) . ( $errors ? $errors : $output )
					);
				}
			} else {
				unlink( $temp_file );
			}
		}

		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array();
	}
}
