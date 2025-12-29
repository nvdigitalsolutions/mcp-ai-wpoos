<?php
/**
 * Process Service
 *
 * Provides Symfony Process integration for WP oOS.
 * Wraps external command execution with better error handling, timeout management, and security.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Services;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Class WP_MCP_AI_Process_Service
 *
 * Wraps Symfony Process for WordPress plugin use.
 * Provides better process execution with timeout handling, error management, and logging.
 */
class WP_MCP_AI_Process_Service {

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Process_Service|null
	 */
	private static $instance = null;

	/**
	 * Default timeout in seconds.
	 *
	 * @var int
	 */
	private $default_timeout = 60;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Process_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Private constructor for singleton.
	}

	/**
	 * Execute a command and return the result.
	 *
	 * @param string|array $command Command to execute. Can be a string or array of arguments.
	 * @param array        $options Options for process execution.
	 *        - timeout: int - Timeout in seconds (default: 60).
	 *        - cwd: string - Working directory (default: null).
	 *        - env: array - Environment variables (default: null).
	 *        - input: string - Input to send to process (default: null).
	 * @return array|WP_Error Array with 'output', 'error', 'exit_code' on success, WP_Error on failure.
	 */
	public function run( $command, array $options = array() ) {
		// Parse options.
		$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : $this->default_timeout;
		$cwd     = isset( $options['cwd'] ) ? $options['cwd'] : null;
		$env     = isset( $options['env'] ) ? $options['env'] : null;
		$input   = isset( $options['input'] ) ? $options['input'] : null;

		// Create process.
		if ( is_array( $command ) ) {
			$process = new Process( $command, $cwd, $env, $input, $timeout );
		} else {
			$process = Process::fromShellCommandline( $command, $cwd, $env, $input, $timeout );
		}

		// Run process.
		try {
			$process->mustRun();

			return array(
				'output'    => $process->getOutput(),
				'error'     => $process->getErrorOutput(),
				'exit_code' => $process->getExitCode(),
				'success'   => $process->isSuccessful(),
			);
		} catch ( ProcessTimedOutException $e ) {
			return new \WP_Error(
				'process_timeout',
				sprintf(
					/* translators: %d: timeout in seconds */
					__( 'Process timed out after %d seconds', 'mcp-ai-wpoos' ),
					$timeout
				),
				array(
					'timeout' => $timeout,
					'output'  => $e->getProcess()->getOutput(),
					'error'   => $e->getProcess()->getErrorOutput(),
				)
			);
		} catch ( ProcessFailedException $e ) {
			return new \WP_Error(
				'process_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Process failed: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				),
				array(
					'exit_code' => $e->getProcess()->getExitCode(),
					'output'    => $e->getProcess()->getOutput(),
					'error'     => $e->getProcess()->getErrorOutput(),
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'process_exception',
				sprintf(
					/* translators: %s: exception message */
					__( 'Process exception: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Execute a command without throwing exceptions on failure.
	 * Returns output even if exit code is non-zero.
	 *
	 * @param string|array $command Command to execute.
	 * @param array        $options Options for process execution.
	 * @return array Array with 'output', 'error', 'exit_code', 'success'.
	 */
	public function run_silent( $command, array $options = array() ) {
		// Parse options.
		$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : $this->default_timeout;
		$cwd     = isset( $options['cwd'] ) ? $options['cwd'] : null;
		$env     = isset( $options['env'] ) ? $options['env'] : null;
		$input   = isset( $options['input'] ) ? $options['input'] : null;

		// Create process.
		if ( is_array( $command ) ) {
			$process = new Process( $command, $cwd, $env, $input, $timeout );
		} else {
			$process = Process::fromShellCommandline( $command, $cwd, $env, $input, $timeout );
		}

		// Run process without exceptions.
		try {
			$process->run();

			return array(
				'output'    => $process->getOutput(),
				'error'     => $process->getErrorOutput(),
				'exit_code' => $process->getExitCode(),
				'success'   => $process->isSuccessful(),
			);
		} catch ( ProcessTimedOutException $e ) {
			return array(
				'output'    => $e->getProcess()->getOutput(),
				'error'     => $e->getProcess()->getErrorOutput(),
				'exit_code' => -1,
				'success'   => false,
				'timeout'   => true,
			);
		} catch ( \Exception $e ) {
			return array(
				'output'    => '',
				'error'     => $e->getMessage(),
				'exit_code' => -1,
				'success'   => false,
			);
		}
	}

	/**
	 * Check if a command is available on the system.
	 *
	 * @param string $command Command to check (e.g., 'ffmpeg', 'python3').
	 * @return bool True if command is available, false otherwise.
	 */
	public function is_command_available( $command ) {
		// Use 'which' on Unix-like systems, 'where' on Windows.
		$check_command = stripos( PHP_OS, 'WIN' ) === 0 ? 'where' : 'which';

		$result = $this->run_silent( array( $check_command, $command ), array( 'timeout' => 5 ) );

		return $result['success'] && ! empty( $result['output'] );
	}

	/**
	 * Get the full path to a command.
	 *
	 * @param string $command Command to locate (e.g., 'ffmpeg').
	 * @return string|false Full path to command on success, false on failure.
	 */
	public function get_command_path( $command ) {
		$check_command = stripos( PHP_OS, 'WIN' ) === 0 ? 'where' : 'which';

		$result = $this->run_silent( array( $check_command, $command ), array( 'timeout' => 5 ) );

		if ( $result['success'] && ! empty( $result['output'] ) ) {
			$paths = explode( "\n", trim( $result['output'] ) );
			return trim( $paths[0] );
		}

		return false;
	}

	/**
	 * Set default timeout for process execution.
	 *
	 * @param int $timeout Timeout in seconds.
	 */
	public function set_default_timeout( $timeout ) {
		$this->default_timeout = absint( $timeout );
	}

	/**
	 * Get default timeout.
	 *
	 * @return int Timeout in seconds.
	 */
	public function get_default_timeout() {
		return $this->default_timeout;
	}

	/**
	 * Execute a command and stream output in real-time.
	 *
	 * @param string|array $command  Command to execute.
	 * @param callable     $callback Callback function to receive output chunks.
	 * @param array        $options  Options for process execution.
	 * @return array|WP_Error Result array or WP_Error on failure.
	 */
	public function run_with_callback( $command, callable $callback, array $options = array() ) {
		// Parse options.
		$timeout = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : $this->default_timeout;
		$cwd     = isset( $options['cwd'] ) ? $options['cwd'] : null;
		$env     = isset( $options['env'] ) ? $options['env'] : null;
		$input   = isset( $options['input'] ) ? $options['input'] : null;

		// Create process.
		if ( is_array( $command ) ) {
			$process = new Process( $command, $cwd, $env, $input, $timeout );
		} else {
			$process = Process::fromShellCommandline( $command, $cwd, $env, $input, $timeout );
		}

		// Run process with callback.
		try {
			$process->run( $callback );

			return array(
				'output'    => $process->getOutput(),
				'error'     => $process->getErrorOutput(),
				'exit_code' => $process->getExitCode(),
				'success'   => $process->isSuccessful(),
			);
		} catch ( ProcessTimedOutException $e ) {
			return new \WP_Error(
				'process_timeout',
				sprintf(
					/* translators: %d: timeout in seconds */
					__( 'Process timed out after %d seconds', 'mcp-ai-wpoos' ),
					$timeout
				),
				array(
					'timeout' => $timeout,
					'output'  => $e->getProcess()->getOutput(),
					'error'   => $e->getProcess()->getErrorOutput(),
				)
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'process_exception',
				sprintf(
					/* translators: %s: exception message */
					__( 'Process exception: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}
}
