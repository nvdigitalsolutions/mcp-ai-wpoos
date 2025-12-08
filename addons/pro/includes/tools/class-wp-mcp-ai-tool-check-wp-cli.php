<?php
/**
 * Tool that inspects the availability of the WP-CLI binary.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports on the current WP-CLI environment.
 */
class WP_MCP_AI_Tool_Check_WP_CLI implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_wp_cli';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check WP-CLI Status', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Inspects the server for a WP-CLI binary and reports path, version, and execution support.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
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
		// Check if site creator and WP-CLI tools are enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator'] ) || empty( $settings['site_creator_allow_wp_cli_tools'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The check_wp_cli tool is disabled. Enable it in WP oOS → Tools & Features → Site Creator settings.', 'wp-mcp-ai' )
			);
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to inspect the WP-CLI environment.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$candidates  = $this->get_candidate_paths();
		$binary_info = $this->locate_binary( $candidates );
		$result      = array(
			'summary'       => $binary_info ? __( 'WP-CLI check complete', 'wp-mcp-ai' ) : __( 'WP-CLI not found', 'wp-mcp-ai' ),
			'available'     => (bool) $binary_info,
			'binary_path'   => $binary_info ? $binary_info['path'] : '',
			'binary_type'   => $binary_info ? $binary_info['type'] : '',
			'can_execute'   => $this->can_execute_processes(),
			'version'       => '',
			'checked_paths' => $this->summarise_checked_paths( $candidates, $binary_info ? $binary_info['path'] : '' ),
			'notes'         => array(),
		);

		if ( ! $binary_info ) {
			$result['notes'][] = __( 'Unable to locate a wp binary in the expected paths or the current PATH environment.', 'wp-mcp-ai' );
			return $result;
		}

		if ( 'phar' === $binary_info['type'] ) {
			$result['notes'][] = __( 'A wp-cli.phar archive was discovered. Ensure the PHP binary can execute it (for example: php wp-cli.phar --version).', 'wp-mcp-ai' );
		}

		if ( ! $result['can_execute'] ) {
			$result['notes'][] = __( 'Process execution functions (proc_open) are disabled, so the wp binary could not be interrogated for its version.', 'wp-mcp-ai' );
			return $result;
		}

		$version_data = $this->resolve_version( $binary_info['path'], $binary_info['type'] );

		if ( is_wp_error( $version_data ) ) {
			$result['notes'][] = $version_data->get_error_message();

			$data = $version_data->get_error_data();
			if ( is_array( $data ) && ! empty( $data['stderr'] ) ) {
				$result['notes'][] = sprintf(
					/* translators: %s: WP-CLI stderr output. */
					__( 'WP-CLI reported: %s', 'wp-mcp-ai' ),
					$data['stderr']
				);
			}

			return $result;
		}

		$result['version']        = $version_data['version'];
		$result['command']        = $version_data['command'];
		$result['version_output'] = $version_data['stdout'];

		if ( ! empty( $version_data['stderr'] ) ) {
			$result['notes'][] = sprintf(
				/* translators: %s: WP-CLI stderr output. */
				__( 'WP-CLI emitted warnings: %s', 'wp-mcp-ai' ),
				$version_data['stderr']
			);
		}

		return $result;
	}

	/**
	 * Attempt to resolve the WP-CLI version for the provided binary.
	 *
	 * @param string $path Binary path.
	 * @param string $type Binary type (binary|phar).
	 * @return array|WP_Error
	 */
	protected function resolve_version( $path, $type ) {
		$command = '';

		if ( 'phar' === $type ) {
			$php_binary = $this->get_php_binary();

			if ( ! $php_binary ) {
				return new WP_Error( 'wp_mcp_ai_wp_cli_php_missing', __( 'Unable to locate the PHP binary required to execute wp-cli.phar.', 'wp-mcp-ai' ) );
			}

			$command = sprintf(
				'%s %s --version',
				escapeshellcmd( $php_binary ),
				escapeshellarg( $path )
			);
		} else {
			$command = sprintf( '%s --version', escapeshellcmd( $path ) );
		}

		$run = $this->run_process( $command );

		if ( is_wp_error( $run ) ) {
			return $run;
		}

		if ( 0 !== $run['exit_code'] ) {
			return new WP_Error(
				'wp_mcp_ai_wp_cli_non_zero_exit',
				sprintf(
					/* translators: 1: exit code, 2: command. */
					__( 'WP-CLI exited with code %1$s when executing %2$s.', 'wp-mcp-ai' ),
					$run['exit_code'],
					$command
				),
				$run
			);
		}

		$version = $this->extract_version( $run['stdout'] );

		if ( empty( $version ) ) {
			return new WP_Error(
				'wp_mcp_ai_wp_cli_unknown_version',
				__( 'WP-CLI executed successfully but the version string could not be determined.', 'wp-mcp-ai' ),
				$run
			);
		}

		return array(
			'version' => $version,
			'stdout'  => $run['stdout'],
			'stderr'  => $run['stderr'],
			'command' => $command,
		);
	}

	/**
	 * Run a shell command and capture the output.
	 *
	 * @param string $command Command to execute.
	 * @return array{stdout:string,stderr:string,exit_code:int}|WP_Error
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

		return array(
			'stdout'    => trim( (string) $stdout ),
			'stderr'    => trim( (string) $stderr ),
			'exit_code' => (int) $exit_code,
		);
	}

	/**
	 * Attempt to extract the WP-CLI version from the provided output.
	 *
	 * @param string $output Raw stdout output.
	 * @return string
	 */
	protected function extract_version( $output ) {
		$lines = preg_split( '/\r?\n/', (string) $output );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/WP-CLI\s+([0-9\.]+)/i', $line, $matches ) ) {
				return $matches[1];
			}

			if ( preg_match( '/version\s+([0-9\.]+)/i', $line, $matches ) ) {
				return $matches[1];
			}

			return $line;
		}

		return '';
	}

	/**
	 * Retrieve possible WP-CLI binary locations.
	 *
	 * @return string[]
	 */
	protected function get_candidate_paths() {
		$candidates = array();

		$env_keys = array( 'WP_CLI_BIN', 'WP_CLI_BINARY', 'WP_CLI_PATH', 'WP_CLI_PHAR' );
		foreach ( $env_keys as $env_key ) {
			$value = getenv( $env_key );

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$candidates[] = $value;
			}
		}

		$wp_root      = untrailingslashit( ABSPATH );
		$candidates[] = $wp_root . '/wp';
		$candidates[] = $wp_root . '/wp-cli.phar';

		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$content_root = untrailingslashit( WP_CONTENT_DIR );
			$candidates[] = $content_root . '/../wp';
			$candidates[] = $content_root . '/../wp-cli.phar';
		}

		$plugin_root  = untrailingslashit( WP_MCP_AI_PATH );
		$candidates[] = $plugin_root . '../vendor/bin/wp';

		// Cloudways environments ship a preinstalled WP-CLI binary outside the default PATH.
		$cloudways_paths = array(
			'/usr/local/bin/wp',
			'/home/master/bin/wp',
			'/home/master/.wp-cli/wp-cli.phar',
		);

		foreach ( $cloudways_paths as $cloudways_path ) {
			$candidates[] = $cloudways_path;
		}

		$path_env = getenv( 'PATH' );

		if ( is_string( $path_env ) && '' !== trim( $path_env ) ) {
			$paths = explode( PATH_SEPARATOR, $path_env );

			foreach ( $paths as $dir ) {
				$dir = trim( $dir );

				if ( '' === $dir ) {
					continue;
				}

				$candidates[] = rtrim( $dir, '\\/' ) . '/wp';
				$candidates[] = rtrim( $dir, '\\/' ) . '/wp-cli.phar';
			}
		}

		/**
		 * Filter the list of candidate WP-CLI paths inspected by the tool.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $candidates Candidate binary paths.
		 */
		$candidates = apply_filters( 'wp_mcp_ai_wp_cli_candidate_paths', $candidates );

		$normalised = array();

		foreach ( (array) $candidates as $candidate ) {
			$candidate = $this->normalise_path( $candidate );

			if ( $candidate ) {
				$normalised[ $candidate ] = true;
			}
		}

		return array_keys( $normalised );
	}

	/**
	 * Locate the first usable binary from the candidate list.
	 *
	 * @param string[] $candidates Candidate paths.
	 * @return array{path:string,type:string}|null
	 */
	protected function locate_binary( array $candidates ) {
		foreach ( $candidates as $candidate ) {
			if ( $this->is_executable_file( $candidate ) ) {
				return array(
					'path' => $candidate,
					'type' => 'binary',
				);
			}

			if ( $this->is_readable_phar( $candidate ) ) {
				return array(
					'path' => $candidate,
					'type' => 'phar',
				);
			}
		}

		return null;
	}

	/**
	 * Determine whether an executable file exists at the provided path.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	protected function is_executable_file( $path ) {
		if ( ! $path ) {
			return false;
		}

		return file_exists( $path ) && is_file( $path ) && is_executable( $path );
	}

	/**
	 * Determine whether a readable wp-cli.phar archive exists at the provided path.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	protected function is_readable_phar( $path ) {
		if ( ! $path ) {
			return false;
		}

		if ( false === stripos( $path, 'wp-cli.phar' ) ) {
			return false;
		}

		return file_exists( $path ) && is_file( $path ) && is_readable( $path );
	}

	/**
	 * Determine whether external processes can be executed.
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
	 * Retrieve the PHP binary path used for executing wp-cli.phar archives.
	 *
	 * @return string
	 */
	protected function get_php_binary() {
		if ( defined( 'PHP_BINARY' ) && PHP_BINARY ) {
			return PHP_BINARY;
		}

		$php_binary = getenv( 'PHP_BINARY' );
		if ( is_string( $php_binary ) && '' !== trim( $php_binary ) ) {
			return trim( $php_binary );
		}

		return 'php';
	}

	/**
	 * Summarise the inspected paths for reporting purposes.
	 *
	 * @param string[] $candidates Candidate paths.
	 * @param string   $match      Resolved binary path.
	 * @return array[]
	 */
	protected function summarise_checked_paths( array $candidates, $match ) {
		$summary = array();
		$match   = $match ? $this->normalise_path( $match ) : '';

		foreach ( array_slice( $candidates, 0, 15 ) as $candidate ) {
			$exists     = file_exists( $candidate );
			$executable = $exists ? is_executable( $candidate ) : false;
			$is_match   = $match && $candidate === $match;
			$summary[]  = array(
				'path'       => $candidate,
				'exists'     => $exists,
				'executable' => $executable,
				'match'      => $is_match,
			);
		}

		return $summary;
	}

	/**
	 * Normalise a filesystem path for consistent comparisons.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	protected function normalise_path( $path ) {
		$path = (string) $path;

		if ( '' === trim( $path ) ) {
			return '';
		}

		$path = wp_normalize_path( $path );

		return rtrim( $path, '/' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro feature.
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires 'manage_options' capability.
			'cacheable',            // Results can be cached.
		);
	}
}
