<?php
/**
 * Trait for executing Node.js scripts via subprocess.
 *
 * Provides helper methods for tools to execute Node.js scripts with proper
 * path resolution, error handling, and timeout management.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for executing Node.js subprocesses.
 *
 * This trait provides methods to:
 * - Locate Node.js executable
 * - Execute Node.js scripts with arguments
 * - Handle timeouts and errors
 * - Parse JSON output from scripts
 *
 * @since 1.1.0
 */
trait WP_MCP_AI_NodeJS_Subprocess {

	/**
	 * Execute a Node.js script and return the result.
	 *
	 * @param string $script_path  Absolute path to the Node.js script.
	 * @param array  $arguments    Arguments to pass to the script.
	 * @param array  $options      Optional execution options.
	 *                              - timeout: int, timeout in seconds (default: 30)
	 *                              - working_dir: string, working directory (default: script directory)
	 *                              - parse_json: bool, parse output as JSON (default: true).
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	protected function execute_nodejs_script( $script_path, array $arguments = array(), array $options = array() ) {
		// Validate script path.
		if ( ! file_exists( $script_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_script_not_found',
				sprintf(
					/* translators: %s: script path */
					__( 'Node.js script not found: %s', 'mcp-ai-wpoos' ),
					$script_path
				)
			);
		}

		// Get Node.js executable path.
		$node_path = $this->get_nodejs_executable();
		if ( is_wp_error( $node_path ) ) {
			return $node_path;
		}

		// Parse options.
		$timeout     = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 30;
		$working_dir = isset( $options['working_dir'] ) ? $options['working_dir'] : dirname( $script_path );
		$parse_json  = isset( $options['parse_json'] ) ? (bool) $options['parse_json'] : true;

		// Build command array.
		$command = array_merge( array( $node_path, $script_path ), $arguments );

		// Build process options.
		$process_options = array( 'timeout' => $timeout );
		if ( $working_dir && is_dir( $working_dir ) ) {
			$process_options['cwd'] = $working_dir;
		}

		// Execute via Process Service.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$result          = $process_service->run_silent( $command, $process_options );

		// Check for timeout.
		if ( isset( $result['timeout'] ) && $result['timeout'] ) {
			return new WP_Error(
				'wp_mcp_ai_script_timeout',
				sprintf(
					/* translators: %d: timeout in seconds */
					__( 'Node.js script execution timed out after %d seconds.', 'mcp-ai-wpoos' ),
					$timeout
				)
			);
		}

		$return_code   = $result['exit_code'];
		$output_string = trim( $result['output'] );

		// Check return code.
		if ( 0 !== $return_code ) {
			// Try to parse error as JSON.
			$error_data = null;
			if ( $parse_json ) {
				$decoded = json_decode( $output_string, true );
				if ( null !== $decoded && is_array( $decoded ) && isset( $decoded['error'] ) ) {
					$error_data = $decoded;
				}
			}

			$error_message = $error_data && isset( $error_data['error'] )
				? $error_data['error']
				: $output_string;

			return new WP_Error(
				'wp_mcp_ai_script_error',
				sprintf(
					/* translators: 1: return code, 2: error message */
					__( 'Node.js script failed with code %1$d: %2$s', 'mcp-ai-wpoos' ),
					$return_code,
					$error_message
				),
				array(
					'return_code' => $return_code,
					'output'      => $output_string,
					'error_data'  => $error_data,
				)
			);
		}

		// Parse JSON output if requested.
		if ( $parse_json ) {
			$decoded = json_decode( $output_string, true );
			if ( null === $decoded ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_json',
					sprintf(
						/* translators: %s: json error */
						__( 'Failed to parse Node.js script output as JSON: %s', 'mcp-ai-wpoos' ),
						json_last_error_msg()
					),
					array(
						'output' => $output_string,
					)
				);
			}

			return $decoded;
		}

		// Return raw output.
		return array(
			'output'      => $output_string,
			'return_code' => $return_code,
		);
	}

	/**
	 * Get the path to the Node.js executable.
	 *
	 * @return string|WP_Error Path to Node.js executable or WP_Error if not found.
	 */
	protected function get_nodejs_executable() {
		// Use Process Service to locate node via PATH.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$which_node      = $process_service->get_command_path( 'node' );
		if ( $which_node ) {
			return $which_node;
		}

		// Check for Node.js in common locations.
		$possible_paths = array(
			'/usr/bin/node',
			'/usr/local/bin/node',
			'/opt/homebrew/bin/node', // macOS Homebrew.
		);

		// Check possible paths.
		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path ) && is_executable( $path ) ) {
				return $path;
			}
		}

		// Allow filtering of Node.js path.
		$node_path = apply_filters( 'wp_mcp_ai_nodejs_executable_path', '' );
		if ( ! empty( $node_path ) && file_exists( $node_path ) && is_executable( $node_path ) ) {
			return $node_path;
		}

		return new WP_Error(
			'wp_mcp_ai_nodejs_not_found',
			__( 'Node.js executable not found. Please ensure Node.js is installed and accessible.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Check if Node.js is available.
	 *
	 * @return bool True if Node.js is available, false otherwise.
	 */
	protected function is_nodejs_available() {
		$node_path = $this->get_nodejs_executable();
		return ! is_wp_error( $node_path );
	}

	/**
	 * Get Node.js version.
	 *
	 * @return string|WP_Error Node.js version string or WP_Error on failure.
	 */
	protected function get_nodejs_version() {
		$node_path = $this->get_nodejs_executable();
		if ( is_wp_error( $node_path ) ) {
			return $node_path;
		}

		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$result          = $process_service->run_silent( array( $node_path, '--version' ), array( 'timeout' => 10 ) );

		if ( ! $result['success'] || empty( $result['output'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_nodejs_version_error',
				__( 'Failed to get Node.js version.', 'mcp-ai-wpoos' )
			);
		}

		$lines = explode( "\n", trim( $result['output'] ) );
		return trim( $lines[0] );
	}
}
