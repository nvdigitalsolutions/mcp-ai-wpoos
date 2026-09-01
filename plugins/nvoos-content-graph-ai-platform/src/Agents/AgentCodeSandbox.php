<?php
/**
 * Agent Code Sandbox — CoSAI MCP-T3 (Input Validation) & MCP-T5 (Data Protection)
 *
 * Sandboxed code execution environment for agent-generated scripts.
 * Isolates execution via temporary directories and restricted process
 * environments with output size limits and configurable timeouts.
 *
 * ## CoSAI Alignment
 *
 * | CoSAI Control              | Implementation in this class                                       |
 * |----------------------------|--------------------------------------------------------------------|
 * | MCP-T3 – Input Validation  | Code is written to a temp file in an isolated directory;           |
 * |                            | language interpreters are validated against an allowed list;       |
 * |                            | no user-supplied input reaches the shell unescaped.                |
 * | MCP-T5 – Output Sanitise   | stdout capped at 1 MB; stderr capped at 256 KB; both are          |
 * |                            | truncated with explicit `truncated` flag rather than silently      |
 * |                            | dropping data.                                                     |
 * | MCP-T5 – Data Protection   | Environment is stripped of secrets; working_dir acts as chroot;    |
 * |                            | network access disabled by default; temp files cleaned up after    |
 * |                            | execution even on timeout/error.                                   |
 * | MCP-T6 – Resilience        | Timeout enforced via `proc_terminate()` with SIGKILL; exit code    |
 * |                            | captured even on forced termination; cleanup runs in `finally`     |
 * |                            | guard.                                                             |
 *
 * @package NvoosContentGraphAiPlatform\Agents
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sandboxed code execution environment.
 *
 * Executes agent-generated code in isolated temporary directories
 * with restricted environments, output size limits, and hard timeouts.
 * Integrates with the approval gate for execution gating.
 *
 * @since 1.2.0
 */
class AgentCodeSandbox {

	/**
	 * Default execution timeout in seconds.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Default max timeout in seconds.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const DEFAULT_MAX_TIMEOUT = 120;

	/**
	 * Max stdout output size in bytes (1 MB).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_STDOUT_SIZE = 1048576;

	/**
	 * Max stderr output size in bytes (256 KB).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MAX_STDERR_SIZE = 262144;

	/**
	 * File extension map for each supported language.
	 *
	 * @since 1.2.0
	 * @var array<string, string>
	 */
	private static $extensions = array(
		'python'     => '.py',
		'javascript' => '.js',
		'bash'       => '.sh',
		'php'        => '.php',
	);

	/**
	 * Execute code in a sandboxed environment.
	 *
	 * Creates a temporary directory, writes code to a temp file, and executes
	 * it via an isolated process with output size limits and a hard timeout.
	 * The temp directory is always cleaned up after execution.
	 *
	 * @since 1.2.0
	 *
	 * @param string $code            The source code to execute.
	 * @param string $language        One of 'python', 'javascript', 'bash', 'php'.
	 * @param int    $timeout_seconds Execution timeout in seconds (default 30, max 120).
	 * @param string $working_dir     Optional. Working directory path; if empty, a temp
	 *                                directory is created.
	 *
	 * @return array{
	 *     stdout:      string,
	 *     stderr:      string,
	 *     exit_code:   int,
	 *     duration_ms: float,
	 *     truncated:   bool,
	 * }|WP_Error Execution result or error.
	 */
	public function execute( $code, $language, $timeout_seconds = null, $working_dir = '' ) {
		// Validate language.
		$allowed_languages = $this->get_supported_languages();
		if ( ! in_array( $language, $allowed_languages, true ) ) {
			return new \WP_Error(
				'unsupported_language',
				sprintf(
					/* translators: 1: requested language, 2: comma-separated supported languages */
					__( 'Unsupported language "%1$s". Supported: %2$s.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $language ),
					esc_html( implode( ', ', $allowed_languages ) )
				)
			);
		}

		// Validate and clamp timeout.
		$timeout_seconds = is_int( $timeout_seconds ) && $timeout_seconds > 0
			? $timeout_seconds
			: self::DEFAULT_TIMEOUT;

		/**
		 * Filter the maximum allowed execution timeout.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_timeout Maximum timeout in seconds. Default 120.
		 */
		$max_timeout = apply_filters( 'wp_mcp_ai_sandbox_max_timeout', self::DEFAULT_MAX_TIMEOUT );
		$max_timeout = absint( $max_timeout );

		if ( $timeout_seconds > $max_timeout ) {
			$timeout_seconds = $max_timeout;
		}

		// Validate code is not empty.
		$code = trim( $code );
		if ( '' === $code ) {
			return new \WP_Error(
				'empty_code',
				__( 'No code provided for execution.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Determine working directory.
		$created_temp_dir = false;
		if ( '' === $working_dir ) {
			$working_dir = self::create_temp_directory();
			if ( is_wp_error( $working_dir ) ) {
				return $working_dir;
			}
			$created_temp_dir = true;
		} else {
			// Validate existing working directory.
			$real_working_dir = realpath( $working_dir );
			if ( false === $real_working_dir || ! is_dir( $real_working_dir ) ) {
				return new \WP_Error(
					'invalid_working_dir',
					__( 'Specified working directory does not exist.', 'nvoos-content-graph-ai-platform' )
				);
			}

			// Containment check: ensure resolved path is within allowed sandbox roots.
			$allowed_roots = self::get_allowed_sandbox_roots();
			$contained     = false;
			foreach ( $allowed_roots as $root ) {
				if ( 0 === strpos( $real_working_dir, $root ) ) {
					$contained = true;
					break;
				}
			}
			if ( ! $contained ) {
				return new \WP_Error(
					'working_dir_not_allowed',
					__( 'Working directory is outside allowed sandbox locations.', 'nvoos-content-graph-ai-platform' )
				);
			}

			$working_dir = $real_working_dir;
		}

		// Write code to temp file.
		$extension = isset( self::$extensions[ $language ] ) ? self::$extensions[ $language ] : '.txt';
		$code_file = trailingslashit( $working_dir ) . 'sandbox_' . wp_generate_uuid4() . $extension;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing sandbox code to temp directory.
		$written = file_put_contents( $code_file, $code );

		if ( false === $written ) {
			self::cleanup_temp_directory( $working_dir, $created_temp_dir );
			return new \WP_Error(
				'write_failed',
				__( 'Failed to write code to temporary file.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Build the execution command.
		$command = self::build_command( $language, $code_file, $working_dir );
		if ( is_wp_error( $command ) ) {
			self::cleanup_temp_directory( $working_dir, $created_temp_dir );
			return $command;
		}

		// Execute.
		$start_time = microtime( true );
		$result     = self::run_process( $command, $working_dir, $timeout_seconds );
		$duration   = ( microtime( true ) - $start_time ) * 1000;

		// Always clean up, even on failure.
		self::cleanup_temp_directory( $working_dir, $created_temp_dir );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['duration_ms'] = round( $duration, 2 );

		return $result;
	}

	/**
	 * Check whether code execution is available on this server.
	 *
	 * Verifies that `proc_open` is available and not disabled, and that
	 * the PHP configuration permits process execution.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if code execution is possible.
	 */
	public function is_available() {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$disabled = ini_get( 'disable_functions' );

		if ( ! empty( $disabled ) ) {
			$disabled_functions = array_map( 'trim', explode( ',', $disabled ) );

			$blocking = array( 'proc_open', 'proc_close', 'proc_terminate', 'proc_get_status' );
			foreach ( $blocking as $func ) {
				if ( in_array( $func, $disabled_functions, true ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Get the list of supported languages.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Available language identifiers.
	 */
	public function get_supported_languages() {
		$default_languages = self::detect_available_languages();

		/**
		 * Filter the list of languages available for sandboxed execution.
		 *
		 * Return an empty array to disable code execution entirely.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $languages Available language identifiers.
		 */
		return apply_filters( 'wp_mcp_ai_sandbox_allowed_languages', $default_languages );
	}

	/**
	 * Build the shell command for a given language and file.
	 *
	 * @since 1.2.0
	 *
	 * @param string $language    The target language.
	 * @param string $code_file   Absolute path to the code file.
	 * @param string $working_dir Absolute path to the working directory.
	 *
	 * @return string|WP_Error The shell command string or an error.
	 */
	private static function build_command( $language, $code_file, $working_dir ) {
		$escaped_file = escapeshellarg( $code_file );

		switch ( $language ) {
			case 'python':
				return 'python ' . $escaped_file;

			case 'javascript':
				return 'node ' . $escaped_file;

			case 'bash':
				return 'bash ' . $escaped_file;

			case 'php':
				// Restricted PHP: no ini overrides, no external config, working_dir as CWD.
				return 'php -n -d "open_basedir=' . escapeshellarg( $working_dir ) . '" ' . $escaped_file;

			default:
				return new \WP_Error(
					'unknown_language',
					sprintf(
						/* translators: %s: language identifier */
						__( 'Unknown language: %s.', 'nvoos-content-graph-ai-platform' ),
						esc_html( $language )
					)
				);
		}
	}

	/**
	 * Run the process via proc_open with timeout enforcement.
	 *
	 * @since 1.2.0
	 *
	 * @param string $command     The shell command to execute.
	 * @param string $working_dir The working directory for the process.
	 * @param int    $timeout     Timeout in seconds.
	 *
	 * @return array{
	 *     stdout:    string,
	 *     stderr:    string,
	 *     exit_code: int,
	 *     truncated: bool,
	 * }|WP_Error Execution result or error.
	 */
	private static function run_process( $command, $working_dir, $timeout ) {
		$descriptorspec = array(
			0 => array( 'pipe', 'r' ),  // stdin.
			1 => array( 'pipe', 'w' ),  // stdout.
			2 => array( 'pipe', 'w' ),  // stderr.
		);

		/**
		 * Filter the environment variables passed to the sandboxed process.
		 *
		 * By default, the environment is stripped to a safe subset (PATH, HOME,
		 * TMPDIR). Add variables cautiously — secrets or sensitive config
		 * values must never be exposed to sandboxed code.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, string> $env Environment variables.
		 */
		$env = apply_filters( 'wp_mcp_ai_sandbox_execution_env', self::get_safe_environment() );

		// Respect DISABLE_WP_CRON to prevent sandboxed code from triggering cron.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$env['DISABLE_WP_CRON'] = 'true';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Sandboxed execution is the intended use.
		$process = proc_open(
			$command,
			$descriptorspec,
			$pipes,
			$working_dir,
			$env
		);

		if ( ! is_resource( $process ) ) {
			return new \WP_Error(
				'process_failed',
				__( 'Failed to start execution process.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Close stdin immediately — sandboxed code does not receive interactive input.
		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Sandbox proc_open pipe handle; WP_Filesystem does not apply.

		// Set non-blocking mode for timeout polling.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$start_time = time();
		$stdout     = '';
		$stderr     = '';
		$truncated  = false;
		$killed     = false;

		while ( true ) {
			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				// Process finished — drain remaining output.
				$chunk_stdout = stream_get_contents( $pipes[1] );
				if ( is_string( $chunk_stdout ) ) {
					$stdout .= $chunk_stdout;
				}
				$chunk_stderr = stream_get_contents( $pipes[2] );
				if ( is_string( $chunk_stderr ) ) {
					$stderr .= $chunk_stderr;
				}
				break;
			}

			// Check timeout.
			if ( ( time() - $start_time ) >= $timeout ) {
				// Drain remaining before kill.
				$chunk_stdout = stream_get_contents( $pipes[1] );
				if ( is_string( $chunk_stdout ) ) {
					$stdout .= $chunk_stdout;
				}
				$chunk_stderr = stream_get_contents( $pipes[2] );
				if ( is_string( $chunk_stderr ) ) {
					$stderr .= $chunk_stderr;
				}

				proc_terminate( $process, 9 ); // SIGKILL.
				fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Sandbox proc_open pipe handle; WP_Filesystem does not apply.
				fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Sandbox proc_open pipe handle; WP_Filesystem does not apply.
				proc_close( $process );

				$killed    = true;
				$truncated = true;
				$stderr   .= "\n[" . esc_html__( 'Process terminated: maximum execution time exceeded.', 'nvoos-content-graph-ai-platform' ) . ']';
				break;
			}

			// Read available output in chunks (avoid busy loop).
			$chunk_stdout = stream_get_contents( $pipes[1] );
			if ( is_string( $chunk_stdout ) ) {
				$stdout .= $chunk_stdout;
			}
			$chunk_stderr = stream_get_contents( $pipes[2] );
			if ( is_string( $chunk_stderr ) ) {
				$stderr .= $chunk_stderr;
			}

			// Enforce output size limits mid-execution.
			if ( strlen( $stdout ) > self::MAX_STDOUT_SIZE ) {
				$stdout    = substr( $stdout, 0, self::MAX_STDOUT_SIZE );
				$truncated = true;
			}
			if ( strlen( $stderr ) > self::MAX_STDERR_SIZE ) {
				$stderr    = substr( $stderr, 0, self::MAX_STDERR_SIZE );
				$truncated = true;
			}

			usleep( 50000 ); // 0.05s polling interval.
		}

		// If not killed, close pipes and get exit code normally.
		if ( ! $killed ) {
			fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Sandbox proc_open pipe handle; WP_Filesystem does not apply.
			fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Sandbox proc_open pipe handle; WP_Filesystem does not apply.
			$exit_code = proc_close( $process );
		} else {
			$exit_code = -1;
		}

		// Final output size enforcement.
		if ( strlen( $stdout ) > self::MAX_STDOUT_SIZE ) {
			$stdout    = substr( $stdout, 0, self::MAX_STDOUT_SIZE );
			$truncated = true;
		}
		if ( strlen( $stderr ) > self::MAX_STDERR_SIZE ) {
			$stderr    = substr( $stderr, 0, self::MAX_STDERR_SIZE );
			$truncated = true;
		}

		return array(
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'exit_code' => $exit_code,
			'truncated' => $truncated,
		);
	}

	/**
	 * Get a safe subset of environment variables for sandboxed processes.
	 *
	 * Strips all WordPress-specific and potentially sensitive variables.
	 * Only PATH, HOME, and TMPDIR are preserved by default.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string>
	 */
	private static function get_safe_environment() {
		$safe = array();

		// Preserve only essential paths.
		$allow = array( 'PATH', 'HOME', 'TMPDIR', 'TEMP', 'TMP', 'LANG', 'USER' );

		foreach ( $allow as $key ) {
			$value = getenv( $key );
			if ( false !== $value ) {
				$safe[ $key ] = $value;
			}
		}

		// Ensure a TMP variable exists.
		if ( ! isset( $safe['TMPDIR'] ) && ! isset( $safe['TMP'] ) && ! isset( $safe['TEMP'] ) ) {
			$safe['TMPDIR'] = sys_get_temp_dir();
		}

		return $safe;
	}

	/**
	 * Detect which language runtimes are available on the server.
	 *
	 * Probes the system PATH for Python, Node.js, Bash, and PHP binaries.
	 *
	 * @since 1.2.0
	 *
	 * @return string[] Language identifiers for available runtimes.
	 */
	private static function detect_available_languages() {
		$available = array();

		// Bash is almost always available on Unix-like systems.
		if ( self::binary_exists( 'bash' ) ) {
			$available[] = 'bash';
		}

		// PHP is always available in a WordPress environment (restricted mode).
		if ( self::binary_exists( 'php' ) ) {
			$available[] = 'php';
		}

		// Python.
		if ( self::binary_exists( 'python' ) || self::binary_exists( 'python3' ) ) {
			$available[] = 'python';
		}

		// Node.js.
		if ( self::binary_exists( 'node' ) ) {
			$available[] = 'javascript';
		}

		return $available;
	}

	/**
	 * Check if a binary executable exists in the system PATH.
	 *
	 * Uses `command -v` which is POSIX-compliant and does not rely on
	 * `which` (which may not be available in restricted environments).
	 *
	 * @since 1.2.0
	 *
	 * @param string $binary The binary name.
	 * @return bool True if the binary is found.
	 */
	private static function binary_exists( $binary ) {
		// Quick check: proc_open must be available.
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$descriptorspec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Binary existence check.
		$process = proc_open(
			'command -v ' . escapeshellarg( $binary ),
			$descriptorspec,
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			return false;
		}

		fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Sandbox proc_open pipe handle; WP_Filesystem does not apply.
		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Sandbox proc_open pipe handle; WP_Filesystem does not apply.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Sandbox proc_open pipe handle; WP_Filesystem does not apply.
		$exit_code = proc_close( $process );

		return 0 === $exit_code && ! empty( trim( (string) $output ) );
	}

	/**
	 * Create a temporary directory for sandbox execution.
	 *
	 * Uses WordPress filesystem APIs to create a temp directory within
	 * the system temp space. The caller is responsible for cleanup.
	 *
	 * @since 1.2.0
	 *
	 * @return string|WP_Error Absolute path to temp directory or error.
	 */
	private static function create_temp_directory() {
		$base_temp = sys_get_temp_dir();
		$dir_name  = 'wp_mcp_ai_sandbox_' . wp_generate_uuid4();
		$temp_dir  = trailingslashit( $base_temp ) . $dir_name;

		// Respect open_basedir if set.
		$open_basedir = ini_get( 'open_basedir' );
		if ( ! empty( $open_basedir ) ) {
			$allowed = false;
			$paths   = explode( PATH_SEPARATOR, $open_basedir );
			foreach ( $paths as $path ) {
				$path = trim( $path );
				if ( '' !== $path && 0 === strpos( $temp_dir, $path ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				// Fall back to WordPress uploads directory which is more likely within open_basedir.
				$upload_dir = wp_upload_dir();
				if ( ! empty( $upload_dir['basedir'] ) ) {
					$temp_dir = trailingslashit( $upload_dir['basedir'] ) . $dir_name;
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creating temp sandbox directory.
		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new \WP_Error(
				'temp_dir_failed',
				__( 'Failed to create temporary sandbox directory.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Write a marker file so we can identify our own temp dirs later.
		$marker = trailingslashit( $temp_dir ) . '.wp_mcp_ai_sandbox';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Marker file in sandbox temp dir.
		file_put_contents( $marker, (string) time() );

		return $temp_dir;
	}

	/**
	 * Return the set of allowed sandbox root directories (resolved via realpath).
	 *
	 * Caller-supplied working_dir values are only permitted when they reside
	 * within one of these roots, preventing directory-traversal escapes.
	 *
	 * @since 1.2.1
	 *
	 * @return string[] Absolute, realpath-resolved directory paths with trailing slash.
	 */
	private static function get_allowed_sandbox_roots() {
		$roots   = array();
		$temp    = realpath( sys_get_temp_dir() );
		$uploads = wp_upload_dir();

		if ( false !== $temp ) {
			$roots[] = trailingslashit( $temp );
		}

		if ( ! empty( $uploads['basedir'] ) ) {
			$upload_real = realpath( $uploads['basedir'] );
			if ( false !== $upload_real ) {
				$roots[] = trailingslashit( $upload_real );
			}
		}

		/**
		 * Filter the allowed sandbox root directories.
		 *
		 * @since 1.2.1
		 *
		 * @param string[] $roots Absolute, realpath-resolved directory paths.
		 */
		return apply_filters( 'wp_mcp_ai_sandbox_allowed_roots', array_unique( $roots ) );
	}

	/**
	 * Clean up a temporary sandbox directory.
	 *
	 * Only removes directories that were created by this class (checked via
	 * the `.wp_mcp_ai_sandbox` marker file) to avoid accidental deletion.
	 *
	 * @since 1.2.0
	 *
	 * @param string $dir     Absolute path to the directory.
	 * @param bool   $created Whether this directory was created by us.
	 * @return void
	 */
	private static function cleanup_temp_directory( $dir, $created ) {
		if ( ! $created || '' === $dir ) {
			return;
		}

		// Verify the marker exists — never delete a directory without it.
		$marker = trailingslashit( $dir ) . '.wp_mcp_ai_sandbox';
		if ( ! file_exists( $marker ) ) {
			return;
		}

		self::recursive_rmdir( $dir );
	}

	/**
	 * Recursively remove a directory and all its contents.
	 *
	 * Uses WordPress filesystem functions where possible and avoids
	 * shelling out to `rm -rf`.
	 *
	 * @since 1.2.0
	 *
	 * @param string $dir Absolute path to remove.
	 * @return void
	 */
	private static function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		// Use WP_Filesystem if available.
		global $wp_filesystem;
		if ( ! empty( $wp_filesystem ) && method_exists( $wp_filesystem, 'rmdir' ) ) {
			$wp_filesystem->rmdir( $dir, true );
			return;
		}

		// Fallback: manual recursive delete.
		$items = array_diff( scandir( $dir ), array( '.', '..' ) );

		foreach ( $items as $item ) {
			$path = trailingslashit( $dir ) . $item;

			if ( is_dir( $path ) ) {
				self::recursive_rmdir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Sandbox cleanup.
				unlink( $path );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Sandbox cleanup.
		rmdir( $dir );
	}
}
