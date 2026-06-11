<?php
/**
 * NV oOS LibreChat — Code Interpreter Service
 *
 * Sandboxed code execution via Docker containers, dispatched through WP-Cron.
 * Supports 8 languages: Python, JavaScript, TypeScript, Go, C++, Java, PHP, Rust.
 *
 * @package NV_oOS_LibreChat
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Code interpreter service.
 *
 * @since 0.1.0
 */
class NV_oOS_LibreChat_Code_Interpreter {

	/**
	 * Hook name for cron processing.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'nvoos_librechat_process_code_execution';

	/**
	 * Transient prefix for job storage.
	 *
	 * @var string
	 */
	const JOB_TRANSIENT_PREFIX = 'nvoos_librechat_code_job_';

	/**
	 * Register cron handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'process_job' ), 10, 1 );
	}

	/**
	 * Process a code execution job via WP-Cron.
	 *
	 * @param string $job_id Unique job identifier.
	 * @return void
	 */
	public static function process_job( $job_id ) {
		$job = get_transient( self::JOB_TRANSIENT_PREFIX . $job_id );

		if ( false === $job || ! is_array( $job ) ) {
			return;
		}

		// Update status to running.
		$job['status']  = 'running';
		$job['started'] = time();
		set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job, HOUR_IN_SECONDS );

		$language = isset( $job['language'] ) ? sanitize_key( $job['language'] ) : 'python';
		$code     = isset( $job['code'] ) ? $job['code'] : '';

		$settings = NV_oOS_LibreChat_Plugin::get_settings();
		$timeout  = absint( $settings['code_interpreter_timeout'] );

		$result = self::execute_in_sandbox( $language, $code, $timeout );

		if ( is_wp_error( $result ) ) {
			$job['status'] = 'error';
			$job['error']  = $result->get_error_message();
			$job['stderr'] = $result->get_error_message();
		} else {
			$job['status']    = 'completed';
			$job['stdout']    = $result['stdout'];
			$job['stderr']    = $result['stderr'];
			$job['exit_code'] = $result['exit_code'];
		}

		$job['finished'] = time();
		set_transient( self::JOB_TRANSIENT_PREFIX . $job_id, $job, HOUR_IN_SECONDS );

		/**
		 * Fires when a code execution job completes.
		 *
		 * @param string $job_id Job identifier.
		 * @param array  $job    Job data including status, stdout, stderr.
		 */
		do_action( 'nvoos_librechat_code_execution_completed', $job_id, $job );
	}

	/**
	 * Execute code in a Docker sandbox.
	 *
	 * @param string $language Programming language.
	 * @param string $code     Source code to execute.
	 * @param int    $timeout  Execution timeout in seconds.
	 * @return array|WP_Error Result with stdout, stderr, exit_code or WP_Error.
	 */
	protected static function execute_in_sandbox( $language, $code, $timeout ) {
		$docker_images = array(
			'python'     => 'python:3.12-slim',
			'javascript' => 'node:20-slim',
			'typescript' => 'node:20-slim',
			'go'         => 'golang:1.22-alpine',
			'cpp'        => 'gcc:14',
			'java'       => 'openjdk:21-slim',
			'php'        => 'php:8.3-cli',
			'rust'       => 'rust:1.84-slim',
		);

		$image = isset( $docker_images[ $language ] ) ? $docker_images[ $language ] : $docker_images['python'];

		// Build the command based on language.
		$command = self::build_docker_command( $language, $code, $image, $timeout );

		if ( ! $command ) {
			return new WP_Error(
				'nvoos_librechat_unsupported_language',
				sprintf(
					/* translators: %s: language name */
					__( 'Unsupported language: %s', 'nvoos-librechat' ),
					$language
				)
			);
		}

		// Execute via shell. Docker must be installed and the web server user must
		// have permission to run `docker` commands.
		$descriptors = array(
			1 => array( 'pipe', 'w' ), // stdout.
			2 => array( 'pipe', 'w' ), // stderr.
		);

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Intentional: Docker sandbox requires proc_open and fclose for pipe I/O.
		$process = proc_open(
			$command,
			$descriptors,
			$pipes,
			null,
			null,
			array( 'suppress_errors' => true )
		);

		if ( ! is_resource( $process ) ) {
			return new WP_Error(
				'nvoos_librechat_proc_open_failed',
				__( 'Failed to create sandbox process. Ensure Docker is installed and the web server user has docker permissions.', 'nvoos-librechat' )
			);
		}

		$stdout = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );

		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		// phpcs:enable

		return array(
			'stdout'    => self::sanitize_output( $stdout ),
			'stderr'    => self::sanitize_output( $stderr ),
			'exit_code' => $exit_code,
		);
	}

	/**
	 * Build the Docker run command for a given language.
	 *
	 * @param string $language Programming language.
	 * @param string $code     Source code.
	 * @param string $image    Docker image to use.
	 * @param int    $timeout  Execution timeout.
	 * @return string|false Command string or false if unsupported language.
	 */
	protected static function build_docker_command( $language, $code, $image, $timeout ) {
		// Escape the code for safe shell embedding.
		$escaped_code = escapeshellarg( $code );

		switch ( $language ) {
			case 'python':
				return sprintf(
					'docker run --rm --network none --memory=256m --cpus=1 --timeout=%d %s python -c %s 2>&1',
					(int) $timeout,
					escapeshellcmd( $image ),
					$escaped_code
				);

			case 'javascript':
				return sprintf(
					'docker run --rm --network none --memory=256m --cpus=1 --timeout=%d %s node -e %s 2>&1',
					(int) $timeout,
					escapeshellcmd( $image ),
					$escaped_code
				);

			case 'typescript':
				return sprintf(
					'docker run --rm --network none --memory=256m --cpus=1 --timeout=%d %s sh -c "echo %s | npx tsx --eval-file /dev/stdin" 2>&1',
					(int) $timeout,
					escapeshellcmd( $image ),
					$escaped_code
				);

			case 'go':
				return sprintf(
					'docker run --rm --network none --memory=256m --cpus=1 --timeout=%d %s sh -c "cat > /tmp/main.go << EOF\n%s\nEOF\ngo run /tmp/main.go" 2>&1',
					(int) $timeout,
					escapeshellcmd( $image ),
					$code
				);

			case 'cpp':
				return sprintf(
					'docker run --rm --network none --memory=256m --cpus=1 --timeout=%d %s sh -c "cat > /tmp/main.cpp << EOF\n%s\nEOF\ng++ -std=c++17 -O2 /tmp/main.cpp -o /tmp/a.out && /tmp/a.out" 2>&1',
					(int) $timeout,
					escapeshellcmd( $image ),
					$code
				);

			case 'java':
				return sprintf(
					'docker run --rm --network none --memory=512m --cpus=1 --timeout=%d %s sh -c "cat > /tmp/Main.java << EOF\n%s\nEOF\njavac /tmp/Main.java -d /tmp && java -cp /tmp Main" 2>&1',
					(int) $timeout,
					escapeshellcmd( $image ),
					$code
				);

			case 'php':
				return sprintf(
					'docker run --rm --network none --memory=256m --cpus=1 --timeout=%d %s php -r %s 2>&1',
					(int) $timeout,
					escapeshellcmd( $image ),
					$escaped_code
				);

			case 'rust':
				return sprintf(
					'docker run --rm --network none --memory=512m --cpus=1 --timeout=%d %s sh -c "cat > /tmp/main.rs << EOF\nfn main() {\n%s\n}\nEOF\nrustc /tmp/main.rs -o /tmp/a.out && /tmp/a.out" 2>&1',
					(int) $timeout,
					escapeshellcmd( $image ),
					$code
				);

			default:
				return false;
		}
	}

	/**
	 * Sanitize command output for safe transmission.
	 *
	 * @param string|false $output Raw output.
	 * @return string Sanitized output.
	 */
	protected static function sanitize_output( $output ) {
		if ( ! is_string( $output ) ) {
			return '';
		}

		// Strip ANSI escape codes.
		$output = preg_replace( '/\x1B\[[0-?]*[ -\/]*[@-~]/', '', $output );

		// Ensure valid UTF-8.
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$output = wp_check_invalid_utf8( $output, true );
		} else {
			$output = iconv( 'UTF-8', 'UTF-8//IGNORE', $output );
			if ( false === $output ) {
				$output = '';
			}
		}

		return trim( $output );
	}
}
