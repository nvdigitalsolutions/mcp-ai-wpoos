<?php
/**
 * Proc helpers (D8-compat copy — ecosystem port, Wave F2 architect-agent toolkit).
 *
 * Base-owned in `includes/bootstrap/helpers.php` (bootstrap-globals, classmap-served), so the
 * standalone addon serves these byte-identical copies from `src/tools/architect-agent/` (the
 * architect-agent tools need them at execute time; the upstream function_exists guards stay).
 *
 * Documented deviations: `declare(strict_types=1)` added.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! function_exists( 'wp_mcp_ai_run_process' ) ) {
	/**
	 * Run an external process using proc_open with array-form argv (no shell expansion).
	 *
	 * This is the only approved way to spawn external processes inside the plugin.
	 * Using array-form argv with proc_open bypasses the shell entirely: the OS
	 * executes the binary directly, so special characters in arguments cannot be
	 * interpreted as shell metacharacters — even without escapeshellarg().
	 *
	 * Security notes:
	 * - The caller is responsible for ensuring $args[0] is an absolute or
	 *   whitelisted binary path.
	 * - The working directory should be set to the plugin directory or a temp dir,
	 *   never to an attacker-controlled path.
	 * - Do NOT call this function unless WP_MCP_AI_ALLOW_SHELL_TOOLS is true AND
	 *   the current user has `manage_options` capability.
	 *
	 * @since 1.1.9
	 *
	 * @param array       $args     Command and arguments as an array, e.g. array('git', 'status').
	 *                              $args[0] must be the binary; subsequent entries are arguments.
	 * @param string|null $cwd      Working directory or null to keep the current directory.
	 * @param int         $timeout  Seconds before the child process is killed. Default 30.
	 * @return array {
	 *     @type string $stdout    Captured standard output.
	 *     @type string $stderr    Captured standard error.
	 *     @type int    $exit_code Process exit status (0 = success).
	 *     @type bool   $success   True when exit_code is 0.
	 *     @type bool   $timed_out True when the process was killed due to timeout.
	 * }
	 */
	function wp_mcp_ai_run_process( array $args, $cwd = null, $timeout = 30 ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ), // stdin.
			1 => array( 'pipe', 'w' ), // stdout.
			2 => array( 'pipe', 'w' ), // stderr.
		);

		// proc_open() accepts an array for $command only on PHP 7.4+ (the minimum
		// for this plugin), which avoids shell expansion entirely.
		$process = proc_open( $args, $descriptors, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
				// phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope.SuccessFalseArray -- Not a tool execute() return; process-result utility function.
				return array(
					'stdout'    => '',
					'stderr'    => 'Failed to start process.',
					'exit_code' => -1,
					'success'   => false,
					'timed_out' => false,
				);
		}

			// Close stdin immediately — we don't need it.
			fclose( $pipes[0] );

			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );

			$stdout    = '';
			$stderr    = '';
			$start     = microtime( true );
			$timed_out = false;

		while ( true ) {
			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}

			if ( ( microtime( true ) - $start ) >= $timeout ) {
				$timed_out = true;
				proc_terminate( $process );
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}

			// Read partial output to prevent pipe buffer deadlock.
			$chunk = fread( $pipes[1], 8192 );
			if ( false !== $chunk ) {
				$stdout .= $chunk;
			}
			$chunk = fread( $pipes[2], 8192 );
			if ( false !== $chunk ) {
				$stderr .= $chunk;
			}

			usleep( 10000 ); // 10 ms.
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		return array(
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'exit_code' => $exit_code,
			'success'   => 0 === $exit_code && ! $timed_out,
			'timed_out' => $timed_out,
		);
	}
}

if ( ! function_exists( 'wp_mcp_ai_run_shell' ) ) {
	/**
	 * Run a pre-built shell command string using proc_open (not exec/shell_exec).
	 *
	 * Use this ONLY when the command string has already been assembled with
	 * escapeshellarg() / escapeshellcmd() for all variable parts. Prefer the
	 * array-form wp_mcp_ai_run_process() for new code.
	 *
	 * @since 1.1.9
	 *
	 * @param string      $command  Shell command string (must be pre-escaped).
	 * @param string|null $cwd      Working directory or null for current directory.
	 * @param int         $timeout  Seconds before the child process is killed. Default 30.
	 * @return array Same keys as wp_mcp_ai_run_process().
	 */
	function wp_mcp_ai_run_shell( $command, $cwd = null, $timeout = 30 ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $pipes, $cwd );

		if ( ! is_resource( $process ) ) {
				// phpcs:ignore WPMCPAI.Tools.CanonicalReturnEnvelope.SuccessFalseArray -- Not a tool execute() return; process-result utility function.
				return array(
					'stdout'    => '',
					'stderr'    => 'Failed to start process.',
					'exit_code' => -1,
					'success'   => false,
					'timed_out' => false,
				);
		}

			fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout    = '';
		$stderr    = '';
		$start     = microtime( true );
		$timed_out = false;

		while ( true ) {
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}
			if ( ( microtime( true ) - $start ) >= $timeout ) {
				$timed_out = true;
				proc_terminate( $process );
				$stdout .= stream_get_contents( $pipes[1] );
				$stderr .= stream_get_contents( $pipes[2] );
				break;
			}
			$chunk = fread( $pipes[1], 8192 );
			if ( false !== $chunk ) {
				$stdout .= $chunk;
			}
			$chunk = fread( $pipes[2], 8192 );
			if ( false !== $chunk ) {
				$stderr .= $chunk;
			}
			usleep( 10000 );
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$exit_code = proc_close( $process );

		return array(
			'stdout'    => $stdout,
			'stderr'    => $stderr,
			'exit_code' => $exit_code,
			'success'   => 0 === $exit_code && ! $timed_out,
			'timed_out' => $timed_out,
		);
	}
}

if ( ! function_exists( 'wp_mcp_ai_find_binary' ) ) {
	/**
	 * Probe whether a system binary exists by running `binary --version` (or the
	 * caller-supplied probe argument) through proc_open — no shell involved.
	 *
	 * Replaces the pattern `shell_exec('which binary 2>/dev/null')` that several
	 * Pro tools used for binary detection.
	 *
	 * @since 1.1.9
	 *
	 * @param string $binary       Binary name (e.g. 'wkhtmltopdf', 'pdftk', 'git').
	 * @param string $probe_arg    Argument to pass to the binary to test it exits 0.
	 *                             Defaults to '--version'.
	 * @return bool True when the binary can be found and executed.
	 */
	function wp_mcp_ai_find_binary( $binary, $probe_arg = '--version' ) {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$result = wp_mcp_ai_run_process( array( $binary, $probe_arg ), null, 5 );
		return $result['ok'];
	}
}
