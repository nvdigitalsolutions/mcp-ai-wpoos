<?php
/**
 * Manage Files Tool - Self-editing capability for Architect Agent.
 *
 * Allows AI agents to read, write, and list files within the plugin directory
 * for self-editing and self-improvement capabilities. This tool is restricted
 * to users with edit_plugins capability and operates only within the plugin
 * directory to ensure security.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage Files tool for self-editing capabilities.
 *
 * This tool enables an "Architect Agent" to read, write, and list files
 * within the plugin directory, allowing for self-modification, self-healing,
 * and self-improvement capabilities.
 *
 * Security features:
 * - Requires edit_plugins capability
 * - Restricted to plugin directory only (WP_MCP_AI_PATH)
 * - Prevents directory traversal attacks
 * - Validates all file paths
 * - Logs all write operations
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Manage_Files implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Files', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read, write, and list files within the plugin directory. Enables self-editing capabilities for Architect Agent. Restricted to users with edit_plugins capability and confined to the plugin directory for security.', 'mcp-ai-wpoos' );
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
					'enum'        => array( 'read', 'write', 'list' ),
					'description' => __( 'Action to perform: "read" (read file contents), "write" (write/update file contents), "list" (list files in directory).', 'mcp-ai-wpoos' ),
				),
				'path'        => array(
					'type'        => 'string',
					'description' => __( 'Relative path to file or directory within the plugin directory. For read/write, this should be a file path. For list, this should be a directory path. Do not include absolute paths or ".." traversal.', 'mcp-ai-wpoos' ),
				),
				'content'     => array(
					'type'        => 'string',
					'description' => __( 'File content to write (required for write action). Will create or overwrite the file at the specified path.', 'mcp-ai-wpoos' ),
				),
				'create_dirs' => array(
					'type'        => 'boolean',
					'description' => __( 'For write action: automatically create parent directories if they don\'t exist. Default: true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'action', 'path' ),
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
			'write',                   // Can modify files.
			'state-changing',          // Modifies plugin code/files.
			'local-only',              // Works locally, no external APIs.
			'reversible',              // Changes can be undone via version control.
			'architect-agent',         // Core Architect Agent capability.
			'code-modification',       // Can modify source code files.
			'requires-workspace-trust', // Requires workspace trust (security).
			'development-workflow',    // Part of development lifecycle.
		);
	}

	/**
	 * Get tool definition for LLM payload.
	 *
	 * @return array Tool definition including name, description, parameters, and required capability.
	 */
	public function get_definition() {
		return array(
			'name'                => $this->get_name(),
			'description'         => $this->get_description(),
			'parameters'          => $this->get_parameters_schema(),
			'required_capability' => 'edit_plugins',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id, assistant_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Verify user is logged in.
		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_unauthorized',
				__( 'You must be logged in to use the Manage Files tool.', 'mcp-ai-wpoos' )
			);
		}

		// Check user has required capability (edit_plugins for security).
		if ( ! user_can( $user_id, 'edit_plugins' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the Manage Files tool. The edit_plugins capability is required.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Validate action parameter.
		if ( empty( $arguments['action'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_action',
				__( 'The "action" parameter is required.', 'mcp-ai-wpoos' )
			);
		}

		$action        = sanitize_text_field( $arguments['action'] );
		$valid_actions = array( 'read', 'write', 'list' );

		if ( ! in_array( $action, $valid_actions, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_action',
				sprintf(
					/* translators: %s: comma-separated list of valid actions */
					__( 'Invalid action. Must be one of: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $valid_actions )
				)
			);
		}

		// Validate path parameter.
		if ( empty( $arguments['path'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_path',
				__( 'The "path" parameter is required.', 'mcp-ai-wpoos' )
			);
		}

		$path = $arguments['path'];

		// Validate and resolve the path.
		$resolved_path = $this->validate_and_resolve_path( $path );
		if ( is_wp_error( $resolved_path ) ) {
			return $resolved_path;
		}

		// Route to appropriate handler based on action.
		switch ( $action ) {
			case 'read':
				return $this->handle_read_action( $resolved_path, $context );

			case 'write':
				return $this->handle_write_action( $resolved_path, $arguments, $context );

			case 'list':
				return $this->handle_list_action( $resolved_path, $context );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					__( 'Invalid action specified.', 'mcp-ai-wpoos' )
				);
		}
	}

	/**
	 * Validate and resolve file path.
	 *
	 * Ensures the path is within the plugin directory and prevents directory traversal.
	 *
	 * @param string $path Relative path within plugin directory.
	 * @return string|WP_Error Resolved absolute path or error.
	 */
	protected function validate_and_resolve_path( $path ) {
		// Remove any leading/trailing slashes.
		$path = trim( $path, '/' );

		// Prevent directory traversal attempts.
		if ( strpos( $path, '..' ) !== false ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_path',
				__( 'Path contains invalid directory traversal. The ".." sequence is not allowed.', 'mcp-ai-wpoos' )
			);
		}

		// Build absolute path within plugin directory.
		$plugin_dir    = rtrim( WP_MCP_AI_PATH, '/' );
		$absolute_path = $plugin_dir . '/' . $path;

		// Normalize the path to resolve any remaining traversal attempts.
		$real_plugin_dir = realpath( $plugin_dir );
		if ( false === $real_plugin_dir ) {
			return new WP_Error(
				'wp_mcp_ai_plugin_dir_error',
				__( 'Unable to resolve plugin directory path.', 'mcp-ai-wpoos' )
			);
		}

		// For new files that don't exist yet, we can't use realpath.
		// Check parent directory instead.
		$parent_dir = dirname( $absolute_path );
		if ( file_exists( $absolute_path ) ) {
			$real_path = realpath( $absolute_path );
		} elseif ( file_exists( $parent_dir ) ) {
			$real_parent = realpath( $parent_dir );
			if ( false === $real_parent ) {
				return new WP_Error(
					'wp_mcp_ai_path_error',
					__( 'Unable to resolve file path.', 'mcp-ai-wpoos' )
				);
			}
			$real_path = $real_parent . '/' . basename( $absolute_path );
		} else {
			$real_path = $absolute_path;
		}

		// Ensure the resolved path is within the plugin directory.
		if ( strpos( $real_path, $real_plugin_dir ) !== 0 ) {
			return new WP_Error(
				'wp_mcp_ai_path_outside_plugin',
				__( 'Access denied. Path must be within the plugin directory.', 'mcp-ai-wpoos' )
			);
		}

		return $absolute_path;
	}

	/**
	 * Handle read action.
	 *
	 * @param string $path Absolute path to file.
	 * @param array  $context Execution context.
	 * @return array|WP_Error File contents or error.
	 */
	protected function handle_read_action( $path, $context ) {
		if ( ! file_exists( $path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				sprintf(
					/* translators: %s: file path */
					__( 'File not found: %s', 'mcp-ai-wpoos' ),
					str_replace( WP_MCP_AI_PATH, '', $path )
				)
			);
		}

		if ( ! is_file( $path ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_a_file',
				__( 'The specified path is not a file. Use the "list" action to view directory contents.', 'mcp-ai-wpoos' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading plugin files for self-editing.
		$content = file_get_contents( $path );

		if ( false === $content ) {
			return new WP_Error(
				'wp_mcp_ai_read_error',
				__( 'Unable to read file contents.', 'mcp-ai-wpoos' )
			);
		}

		$relative_path = str_replace( WP_MCP_AI_PATH, '', $path );

		return array(
			'success' => true,
			'action'  => 'read',
			'path'    => $relative_path,
			'content' => $content,
			'size'    => strlen( $content ),
			'message' => sprintf(
				/* translators: %s: file path */
				__( 'Successfully read file: %s', 'mcp-ai-wpoos' ),
				$relative_path
			),
		);
	}

	/**
	 * Handle write action.
	 *
	 * @param string $path Absolute path to file.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Write result or error.
	 */
	protected function handle_write_action( $path, $arguments, $context ) {
		if ( empty( $arguments['content'] ) && ! isset( $arguments['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_content',
				__( 'The "content" parameter is required for write action.', 'mcp-ai-wpoos' )
			);
		}

		$content     = $arguments['content'];
		$create_dirs = isset( $arguments['create_dirs'] ) ? (bool) $arguments['create_dirs'] : true;

		// Check if parent directory exists.
		$parent_dir = dirname( $path );
		if ( ! file_exists( $parent_dir ) ) {
			if ( ! $create_dirs ) {
				return new WP_Error(
					'wp_mcp_ai_dir_not_found',
					__( 'Parent directory does not exist and create_dirs is false.', 'mcp-ai-wpoos' )
				);
			}

			// Create parent directories.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating directories for plugin self-editing.
			if ( ! wp_mkdir_p( $parent_dir ) ) {
				return new WP_Error(
					'wp_mcp_ai_mkdir_error',
					__( 'Unable to create parent directories.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Write file content.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing plugin files for self-editing.
		$result = file_put_contents( $path, $content );

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_write_error',
				__( 'Unable to write file contents.', 'mcp-ai-wpoos' )
			);
		}

		$relative_path = str_replace( WP_MCP_AI_PATH, '', $path );

		// Log the write operation.
		$this->log_write_operation( $relative_path, $context );

		return array(
			'success' => true,
			'action'  => 'write',
			'path'    => $relative_path,
			'bytes'   => $result,
			'message' => sprintf(
				/* translators: 1: number of bytes, 2: file path */
				__( 'Successfully wrote %1$d bytes to file: %2$s', 'mcp-ai-wpoos' ),
				$result,
				$relative_path
			),
		);
	}

	/**
	 * Handle list action.
	 *
	 * @param string $path Absolute path to directory.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Directory listing or error.
	 */
	protected function handle_list_action( $path, $context ) {
		if ( ! file_exists( $path ) ) {
			return new WP_Error(
				'wp_mcp_ai_dir_not_found',
				sprintf(
					/* translators: %s: directory path */
					__( 'Directory not found: %s', 'mcp-ai-wpoos' ),
					str_replace( WP_MCP_AI_PATH, '', $path )
				)
			);
		}

		if ( ! is_dir( $path ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_a_directory',
				__( 'The specified path is not a directory. Use the "read" action to read file contents.', 'mcp-ai-wpoos' )
			);
		}

		$files       = array();
		$directories = array();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir -- Reading directory for self-editing.
		$handle = opendir( $path );
		if ( false === $handle ) {
			return new WP_Error(
				'wp_mcp_ai_list_error',
				__( 'Unable to list directory contents.', 'mcp-ai-wpoos' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readdir -- Reading directory for self-editing.
		while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Standard PHP pattern for directory iteration.
			// Skip . and ..
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$full_path = $path . '/' . $entry;

			if ( is_dir( $full_path ) ) {
				$directories[] = array(
					'name' => $entry,
					'type' => 'directory',
					'path' => str_replace( WP_MCP_AI_PATH, '', $full_path ),
				);
			} else {
				$files[] = array(
					'name' => $entry,
					'type' => 'file',
					'path' => str_replace( WP_MCP_AI_PATH, '', $full_path ),
					'size' => filesize( $full_path ),
				);
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_closedir -- Closing directory handle.
		closedir( $handle );

		// Sort alphabetically.
		usort( $directories, fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );
		usort( $files, fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

		$relative_path = str_replace( WP_MCP_AI_PATH, '', $path );

		return array(
			'success'     => true,
			'action'      => 'list',
			'path'        => $relative_path,
			'directories' => $directories,
			'files'       => $files,
			'total'       => count( $directories ) + count( $files ),
			'message'     => sprintf(
				/* translators: 1: number of items, 2: directory path */
				__( 'Found %1$d items in directory: %2$s', 'mcp-ai-wpoos' ),
				count( $directories ) + count( $files ),
				$relative_path
			),
		);
	}

	/**
	 * Log write operation for audit trail.
	 *
	 * @param string $path Relative file path.
	 * @param array  $context Execution context.
	 */
	protected function log_write_operation( $path, $context ) {
		if ( ! function_exists( 'wp_mcp_ai_log_activity' ) ) {
			return;
		}

		$user_id      = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		wp_mcp_ai_log_activity(
			sprintf(
				'Architect Agent wrote file: %s (User: %d, Assistant: %d)',
				$path,
				$user_id,
				$assistant_id
			)
		);
	}
}
