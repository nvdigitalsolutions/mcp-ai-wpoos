<?php
/**
 * Tool for managing npm packages in WordPress environment.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages npm package operations (list, install, update, remove).
 */
class WP_MCP_AI_Tool_Manage_NPM_Packages implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_npm_packages';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage NPM Packages', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manage npm packages for the WordPress plugin development environment. List installed packages, install new ones, update existing packages, or remove packages.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'       => array(
					'type'        => 'string',
					'description' => 'The action to perform: list, install, update, or remove',
					'enum'        => array( 'list', 'install', 'update', 'remove' ),
				),
				'package_name' => array(
					'type'        => 'string',
					'description' => 'The npm package name (required for install, update, remove)',
				),
				'version'      => array(
					'type'        => 'string',
					'description' => 'Specific version to install (optional for install action)',
				),
				'dev'          => array(
					'type'        => 'boolean',
					'description' => 'Install as dev dependency (for install action)',
					'default'     => false,
				),
			),
			'required'   => array( 'action' ),
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
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage npm packages.', 'wp-mcp-ai' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		if ( ! in_array( $action, array( 'list', 'install', 'update', 'remove' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_action',
				__( 'Invalid action. Must be one of: list, install, update, remove.', 'wp-mcp-ai' )
			);
		}

		// Check if npm is available.
		$npm_check = $this->check_npm_available();
		if ( is_wp_error( $npm_check ) ) {
			return $npm_check;
		}

		switch ( $action ) {
			case 'list':
				return $this->list_packages();
			case 'install':
				return $this->install_package( $arguments );
			case 'update':
				return $this->update_package( $arguments );
			case 'remove':
				return $this->remove_package( $arguments );
		}

		return new WP_Error(
			'wp_mcp_ai_unknown_action',
			__( 'Unknown action specified.', 'wp-mcp-ai' )
		);
	}

	/**
	 * Check if npm is available on the system.
	 *
	 * @return true|WP_Error True if available, WP_Error otherwise.
	 */
	private function check_npm_available() {
		if ( ! function_exists( 'proc_open' ) ) {
			return new WP_Error(
				'wp_mcp_ai_proc_disabled',
				__( 'Process execution functions are disabled. Cannot execute npm commands.', 'wp-mcp-ai' )
			);
		}

		$result = $this->execute_npm_command( '--version' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['output'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_npm_not_found',
				__( 'npm is not available on this system.', 'wp-mcp-ai' )
			);
		}

		return true;
	}

	/**
	 * List installed npm packages.
	 *
	 * @return array|WP_Error Package list or error.
	 */
	private function list_packages() {
		$result = $this->execute_npm_command( 'list --json --depth=0' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['output'], true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'wp_mcp_ai_json_error',
				__( 'Failed to parse npm output.', 'wp-mcp-ai' )
			);
		}

		$dependencies     = isset( $data['dependencies'] ) ? $data['dependencies'] : array();
		$dev_dependencies = isset( $data['devDependencies'] ) ? $data['devDependencies'] : array();

		return array(
			'summary'          => sprintf(
				/* translators: 1: number of dependencies, 2: number of dev dependencies */
				__( 'Found %1$d dependencies and %2$d dev dependencies', 'wp-mcp-ai' ),
				count( $dependencies ),
				count( $dev_dependencies )
			),
			'dependencies'     => $this->format_package_list( $dependencies ),
			'devDependencies'  => $this->format_package_list( $dev_dependencies ),
			'package_json_dir' => $this->get_package_json_dir(),
		);
	}

	/**
	 * Install an npm package.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Installation result or error.
	 */
	private function install_package( $arguments ) {
		$package_name = isset( $arguments['package_name'] ) ? sanitize_text_field( $arguments['package_name'] ) : '';

		if ( empty( $package_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_package',
				__( 'Package name is required for install action.', 'wp-mcp-ai' )
			);
		}

		$version = isset( $arguments['version'] ) ? sanitize_text_field( $arguments['version'] ) : '';
		$is_dev  = isset( $arguments['dev'] ) ? (bool) $arguments['dev'] : false;

		$package_spec = $package_name;
		if ( ! empty( $version ) ) {
			$package_spec .= '@' . $version;
		}

		$command = 'install ' . escapeshellarg( $package_spec );
		if ( $is_dev ) {
			$command .= ' --save-dev';
		}

		$result = $this->execute_npm_command( $command );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => sprintf(
				/* translators: 1: package name */
				__( 'Successfully installed %s', 'wp-mcp-ai' ),
				$package_spec
			),
			'package' => $package_spec,
			'dev'     => $is_dev,
			'output'  => $result['output'],
		);
	}

	/**
	 * Update an npm package.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Update result or error.
	 */
	private function update_package( $arguments ) {
		$package_name = isset( $arguments['package_name'] ) ? sanitize_text_field( $arguments['package_name'] ) : '';

		if ( empty( $package_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_package',
				__( 'Package name is required for update action.', 'wp-mcp-ai' )
			);
		}

		$command = 'update ' . escapeshellarg( $package_name );
		$result  = $this->execute_npm_command( $command );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => sprintf(
				/* translators: 1: package name */
				__( 'Successfully updated %s', 'wp-mcp-ai' ),
				$package_name
			),
			'package' => $package_name,
			'output'  => $result['output'],
		);
	}

	/**
	 * Remove an npm package.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Removal result or error.
	 */
	private function remove_package( $arguments ) {
		$package_name = isset( $arguments['package_name'] ) ? sanitize_text_field( $arguments['package_name'] ) : '';

		if ( empty( $package_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_package',
				__( 'Package name is required for remove action.', 'wp-mcp-ai' )
			);
		}

		$command = 'uninstall ' . escapeshellarg( $package_name );
		$result  = $this->execute_npm_command( $command );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'summary' => sprintf(
				/* translators: 1: package name */
				__( 'Successfully removed %s', 'wp-mcp-ai' ),
				$package_name
			),
			'package' => $package_name,
			'output'  => $result['output'],
		);
	}

	/**
	 * Execute an npm command.
	 *
	 * @param string $command The npm command arguments.
	 * @return array|WP_Error Command output or error.
	 */
	private function execute_npm_command( $command ) {
		$package_dir = $this->get_package_json_dir();

		if ( ! is_dir( $package_dir ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_dir',
				__( 'Plugin directory not found.', 'wp-mcp-ai' )
			);
		}

		$full_command = sprintf(
			'cd %s && npm %s 2>&1',
			escapeshellarg( $package_dir ),
			$command
		);

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $full_command, $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			return new WP_Error(
				'wp_mcp_ai_proc_failed',
				__( 'Failed to execute npm command.', 'wp-mcp-ai' )
			);
		}

		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$errors = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$return_code = proc_close( $process );

		if ( 0 !== $return_code && ! empty( $errors ) ) {
			return new WP_Error(
				'wp_mcp_ai_npm_error',
				sprintf(
					/* translators: 1: error message */
					__( 'npm command failed: %s', 'wp-mcp-ai' ),
					$errors
				)
			);
		}

		return array(
			'output'      => $output,
			'errors'      => $errors,
			'return_code' => $return_code,
		);
	}

	/**
	 * Get the directory containing package.json.
	 *
	 * @return string Directory path.
	 */
	private function get_package_json_dir() {
		return defined( 'WP_MCP_AI_PATH' ) ? WP_MCP_AI_PATH : plugin_dir_path( dirname( dirname( __FILE__ ) ) );
	}

	/**
	 * Format package list for output.
	 *
	 * @param array $packages Raw package data.
	 * @return array Formatted package list.
	 */
	private function format_package_list( $packages ) {
		$formatted = array();

		foreach ( $packages as $name => $info ) {
			$formatted[] = array(
				'name'    => $name,
				'version' => isset( $info['version'] ) ? $info['version'] : 'unknown',
			);
		}

		return $formatted;
	}
}
