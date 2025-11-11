<?php
/**
 * Tool for installing npm packages.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs npm packages in a WordPress installation.
 */
class WP_MCP_AI_Tool_NPM_Install_Package implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'npm_install_package';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'NPM Install Package', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Installs one or more npm packages in the WordPress installation. Supports optional version specifications and can install as dev dependencies.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'packages'    => array(
					'type'        => 'array',
					'description' => __( 'Array of package names to install. Can include version specifiers (e.g., "lodash@4.17.21" or "react@^18.0.0").', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
					'minItems'    => 1,
				),
				'save_dev'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to save packages as devDependencies instead of dependencies.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'working_dir' => array(
					'type'        => 'string',
					'description' => __( 'Working directory path relative to WordPress root. Defaults to WordPress root.', 'wp-mcp-ai' ),
					'default'     => '',
				),
			),
			'required'   => array( 'packages' ),
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

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to install npm packages.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate packages parameter.
		if ( empty( $arguments['packages'] ) || ! is_array( $arguments['packages'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_packages', __( 'The packages parameter must be a non-empty array.', 'wp-mcp-ai' ) );
		}

		$packages    = array_map( 'sanitize_text_field', $arguments['packages'] );
		$save_dev    = ! empty( $arguments['save_dev'] );
		$working_dir = isset( $arguments['working_dir'] ) ? sanitize_text_field( $arguments['working_dir'] ) : '';

		// Validate packages format.
		foreach ( $packages as $package ) {
			if ( ! $this->is_valid_package_spec( $package ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_package_spec',
					sprintf(
						/* translators: %s: package specification */
						__( 'Invalid package specification: %s', 'wp-mcp-ai' ),
						$package
					)
				);
			}
		}

		// Determine working directory.
		$work_dir = $this->resolve_working_directory( $working_dir );
		if ( is_wp_error( $work_dir ) ) {
			return $work_dir;
		}

		// Check if npm is available.
		$npm_binary = $this->get_npm_binary();
		if ( is_wp_error( $npm_binary ) ) {
			return $npm_binary;
		}

		// Check if process execution is available.
		if ( ! $this->can_execute_processes() ) {
			return new WP_Error( 'wp_mcp_ai_process_disabled', __( 'Server configuration prevents executing external processes.', 'wp-mcp-ai' ) );
		}

		// Check if package.json exists.
		$package_json_path = trailingslashit( $work_dir ) . 'package.json';
		if ( ! file_exists( $package_json_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_package_json',
				sprintf(
					/* translators: %s: directory path */
					__( 'No package.json found in %s. Please initialize npm first.', 'wp-mcp-ai' ),
					$work_dir
				)
			);
		}

		// Build npm install command.
		$install_args = array();
		if ( $save_dev ) {
			$install_args[] = '--save-dev';
		}

		foreach ( $packages as $package ) {
			$install_args[] = escapeshellarg( $package );
		}

		$command = sprintf(
			'cd %s && %s install %s 2>&1',
			escapeshellarg( $work_dir ),
			escapeshellcmd( $npm_binary ),
			implode( ' ', $install_args )
		);

		// Execute the command.
		$result = $this->run_process( $command );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Parse the output.
		$success       = 0 === $result['exit_code'];
		$installed_pkg = array();

		if ( $success ) {
			// Extract installed package info from output.
			foreach ( $packages as $package ) {
				// Remove version specification for package name.
				$pkg_name        = preg_replace( '/@[^@]+$/', '', $package );
				$installed_pkg[] = $pkg_name;
			}
		}

		return array(
			'success'            => $success,
			'packages_requested' => $packages,
			'packages_installed' => $installed_pkg,
			'save_dev'           => $save_dev,
			'working_directory'  => $work_dir,
			'exit_code'          => $result['exit_code'],
			'output'             => $result['stdout'],
			'npm_version'        => $this->get_npm_version(),
		);
	}

	/**
	 * Validate package specification format.
	 *
	 * @param string $package Package specification.
	 * @return bool
	 */
	protected function is_valid_package_spec( $package ) {
		// Allow package names with optional scope (@scope/package) and version specs.
		// Valid examples: lodash, @babel/core, react@^18.0.0, typescript@latest.
		return (bool) preg_match( '/^(@[a-z0-9-~][a-z0-9-._~]*\/)?[a-z0-9-~][a-z0-9-._~]*(@[a-z0-9-~^.>=<*|]+)?$/i', $package );
	}

	/**
	 * Resolve the working directory path.
	 *
	 * @param string $relative_path Relative path from WordPress root.
	 * @return string|WP_Error Absolute path or error.
	 */
	protected function resolve_working_directory( $relative_path ) {
		$wp_root = untrailingslashit( ABSPATH );

		if ( empty( $relative_path ) ) {
			return $wp_root;
		}

		// Normalize and validate path.
		$relative_path = wp_normalize_path( $relative_path );
		$relative_path = ltrim( $relative_path, '/' );

		// Prevent directory traversal.
		if ( false !== strpos( $relative_path, '..' ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_path', __( 'Invalid working directory path.', 'wp-mcp-ai' ) );
		}

		$absolute_path = wp_normalize_path( $wp_root . '/' . $relative_path );

		// Ensure the path is within WordPress root.
		if ( 0 !== strpos( $absolute_path, $wp_root ) ) {
			return new WP_Error( 'wp_mcp_ai_path_outside_root', __( 'Working directory must be within WordPress root.', 'wp-mcp-ai' ) );
		}

		if ( ! is_dir( $absolute_path ) ) {
			return new WP_Error( 'wp_mcp_ai_dir_not_found', __( 'Working directory does not exist.', 'wp-mcp-ai' ) );
		}

		return $absolute_path;
	}

	/**
	 * Get the npm binary path.
	 *
	 * @return string|WP_Error
	 */
	protected function get_npm_binary() {
		$candidates = $this->get_npm_candidate_paths();

		foreach ( $candidates as $candidate ) {
			if ( $this->is_executable_file( $candidate ) ) {
				return $candidate;
			}
		}

		return new WP_Error( 'wp_mcp_ai_npm_not_found', __( 'npm binary not found. Please ensure Node.js and npm are installed.', 'wp-mcp-ai' ) );
	}

	/**
	 * Get candidate paths for npm binary.
	 *
	 * @return array
	 */
	protected function get_npm_candidate_paths() {
		$candidates = array();

		// Check environment variable.
		$npm_path = getenv( 'NPM_PATH' );
		if ( is_string( $npm_path ) && '' !== trim( $npm_path ) ) {
			$candidates[] = $npm_path;
		}

		// Check common installation paths.
		$common_paths = array(
			'/usr/local/bin/npm',
			'/usr/bin/npm',
			'/opt/homebrew/bin/npm',
		);

		foreach ( $common_paths as $path ) {
			$candidates[] = $path;
		}

		// Check PATH environment variable.
		$path_env = getenv( 'PATH' );
		if ( is_string( $path_env ) && '' !== trim( $path_env ) ) {
			$paths = explode( PATH_SEPARATOR, $path_env );

			foreach ( $paths as $dir ) {
				$dir = trim( $dir );
				if ( '' !== $dir ) {
					$candidates[] = rtrim( $dir, '\\/' ) . '/npm';
				}
			}
		}

		/**
		 * Filter the list of candidate npm paths.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $candidates Candidate binary paths.
		 */
		return apply_filters( 'wp_mcp_ai_npm_candidate_paths', array_unique( $candidates ) );
	}

	/**
	 * Check if a file exists and is executable.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	protected function is_executable_file( $path ) {
		if ( empty( $path ) ) {
			return false;
		}

		return file_exists( $path ) && is_file( $path ) && is_executable( $path );
	}

	/**
	 * Check if process execution is available.
	 *
	 * @return bool
	 */
	protected function can_execute_processes() {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$disabled = ini_get( 'disable_functions' );
		if ( ! $disabled ) {
			return true;
		}

		$disabled_functions = array_map( 'trim', explode( ',', (string) $disabled ) );
		return ! in_array( 'proc_open', $disabled_functions, true );
	}

	/**
	 * Run a shell command and capture output.
	 *
	 * @param string $command Command to execute.
	 * @return array{stdout:string,exit_code:int}|WP_Error
	 */
	protected function run_process( $command ) {
		if ( ! $this->can_execute_processes() ) {
			return new WP_Error( 'wp_mcp_ai_process_disabled', __( 'Server configuration prevents executing external processes.', 'wp-mcp-ai' ) );
		}

		$descriptor_spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptor_spec, $pipes );

		if ( ! is_resource( $process ) ) {
			return new WP_Error( 'wp_mcp_ai_process_failure', __( 'Failed to open a process for the requested command.', 'wp-mcp-ai' ) );
		}

		fclose( $pipes[0] );

		$stdout = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );

		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );

		// Combine stdout and stderr for npm output.
		$output = trim( (string) $stdout );
		if ( ! empty( $stderr ) ) {
			$output .= "\n" . trim( (string) $stderr );
		}

		return array(
			'stdout'    => trim( $output ),
			'exit_code' => (int) $exit_code,
		);
	}

	/**
	 * Get npm version.
	 *
	 * @return string
	 */
	protected function get_npm_version() {
		$npm_binary = $this->get_npm_binary();
		if ( is_wp_error( $npm_binary ) ) {
			return '';
		}

		$command = sprintf( '%s --version 2>&1', escapeshellcmd( $npm_binary ) );
		$result  = $this->run_process( $command );

		if ( is_wp_error( $result ) || 0 !== $result['exit_code'] ) {
			return '';
		}

		return trim( $result['stdout'] );
	}
}
