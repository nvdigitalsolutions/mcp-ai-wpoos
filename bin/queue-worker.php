#!/usr/bin/env php
<?php
/**
 * NV oOS Queue Worker — Processes async jobs from the job queue.
 *
 * Consumes jobs from the custom DB table (wp_mcp_ai_concurrent_jobs) or
 * RabbitMQ (when available) and executes them outside the WordPress
 * request lifecycle.
 *
 * ## Usage
 *
 *   # Process one batch and exit
 *   php bin/queue-worker.php
 *
 *   # Run as daemon (continuous loop)
 *   php bin/queue-worker.php --daemon
 *
 *   # Limit memory usage
 *   php bin/queue-worker.php --memory-limit=256M
 *
 *   # Process a maximum number of jobs then exit
 *   php bin/queue-worker.php --max-jobs=50
 *
 *   # Run for a maximum time then exit
 *   php bin/queue-worker.php --timeout=300
 *
 * ## Deployment
 *
 * ### Cloudways Flexible (VPS)
 * Add to Cloudways Cron (every 1 minute):
 *   php /home/master/applications/{app}/public_html/wp-content/plugins/mcp-ai-wpoos/bin/queue-worker.php --timeout=55
 * The script acquires an exclusive lock, so overlapping cron invocations are safe.
 *
 * ### Cloudways Autonomous (Kubernetes)
 * Run as a long-lived daemon process:
 *   php bin/queue-worker.php --daemon --memory-limit=256M
 *
 * ### Systemd (self-hosted)
 * See docs/operations/queue-worker-systemd.md for a systemd unit file example.
 *
 * ## Signal Handling
 *
 * SIGTERM / SIGINT: Graceful shutdown — finish current job, acknowledge, exit.
 * SIGHUP: Reload configuration (not yet implemented).
 *
 * @package   WP_MCP_AI
 * @since     1.1.37
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions -- CLI script, not a web request.

// ─── Parse CLI arguments ─────────────────────────────────────────────
$options = getopt(
	'',
	array(
		'daemon',
		'memory-limit:',
		'max-jobs:',
		'timeout:',
		'help',
	)
);

if ( isset( $options['help'] ) ) {
	echo "NV oOS Queue Worker\n\n";
	echo "Usage: php bin/queue-worker.php [options]\n\n";
	echo "Options:\n";
	echo "  --daemon            Run continuously (default: process one batch and exit)\n";
	echo "  --memory-limit=N    Set memory limit (e.g., 256M)\n";
	echo "  --max-jobs=N        Exit after processing N jobs\n";
	echo "  --timeout=N         Exit after N seconds\n";
	echo "  --help              Show this help\n";
	exit( 0 );
}

$is_daemon    = isset( $options['daemon'] );
$max_jobs     = isset( $options['max-jobs'] ) ? absint( $options['max-jobs'] ) : 0;
$timeout      = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : 0;
$memory_limit = isset( $options['memory-limit'] ) ? $options['memory-limit'] : '256M';

// ─── Bootstrap WordPress ─────────────────────────────────────────────
// Find WordPress root. This script lives in bin/ under the plugin dir.
$plugin_dir = dirname( __DIR__ );

// Try standard WordPress directory structures.
$wp_roots = array(
	dirname( $plugin_dir, 3 ),          // wp-content/plugins/mcp-ai-wpoos/bin → ABSPATH
	dirname( $plugin_dir, 4 ),          // Nested: wp-content/plugins/vendor/mcp-ai-wpoos/bin
	dirname( dirname( $plugin_dir ) ),  // One level up from plugin dir
);

$wp_root = null;
foreach ( $wp_roots as $candidate ) {
	if ( file_exists( $candidate . '/wp-load.php' ) ) {
		$wp_root = $candidate;
		break;
	}
}

if ( null === $wp_root ) {
	fwrite( STDERR, "Error: Could not find WordPress installation.\n" );
	fwrite( STDERR, "Searched: " . implode( ', ', $wp_roots ) . "\n" );
	exit( 1 );
}

// Minimal WordPress load.
define( 'WP_USE_THEMES', false );
define( 'WP_ADMIN', false );

// Suppress debug output in CLI mode.
if ( ! defined( 'WP_DEBUG_DISPLAY' ) ) {
	define( 'WP_DEBUG_DISPLAY', false );
}

require_once $wp_root . '/wp-load.php';

// ─── Verify plugin is loaded ─────────────────────────────────────────
if ( ! class_exists( 'WP_MCP_AI_Job_Queue_Manager' ) ) {
	fwrite( STDERR, "Error: NV oOS plugin is not loaded.\n" );
	exit( 1 );
}

// ─── Set memory limit ─────────────────────────────────────────────────
ini_set( 'memory_limit', $memory_limit );

// ─── File lock to prevent overlapping workers ────────────────────────
$lock_file = sys_get_temp_dir() . '/nvoos-queue-worker.lock';
$lock_fh   = fopen( $lock_file, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

if ( ! $lock_fh ) {
	fwrite( STDERR, "Error: Could not open lock file: $lock_file\n" );
	exit( 1 );
}

if ( ! flock( $lock_fh, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
	// Another worker is already running.
	fwrite( STDERR, "Another queue worker is already running. Exiting.\n" );
	fclose( $lock_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit( 0 );
}

// ─── Signal handling for graceful shutdown ───────────────────────────
$should_exit = false;

if ( function_exists( 'pcntl_signal' ) ) {
	pcntl_signal( SIGTERM, function () use ( &$should_exit ) {
		fwrite( STDOUT, "Received SIGTERM, shutting down gracefully...\n" );
		$should_exit = true;
	} );

	pcntl_signal( SIGINT, function () use ( &$should_exit ) {
		fwrite( STDOUT, "Received SIGINT, shutting down gracefully...\n" );
		$should_exit = true;
	} );
}

// ─── Worker loop ─────────────────────────────────────────────────────
$start_time   = time();
$jobs_processed = 0;
$sleep_seconds  = $is_daemon ? 1 : 0; // Daemon polls every 1s; one-shot exits after processing.

fwrite( STDOUT, sprintf(
	"[%s] NV oOS Queue Worker started. Daemon: %s, Memory limit: %s, Max jobs: %s, Timeout: %s\n",
	gmdate( 'Y-m-d H:i:s' ),
	$is_daemon ? 'yes' : 'no',
	$memory_limit,
	$max_jobs > 0 ? $max_jobs : 'unlimited',
	$timeout > 0 ? "${timeout}s" : 'none'
) );

do {
	// Check exit conditions.
	if ( $should_exit ) {
		fwrite( STDOUT, "Shutting down after signal.\n" );
		break;
	}

	if ( $timeout > 0 && ( time() - $start_time ) >= $timeout ) {
		fwrite( STDOUT, sprintf( "Timeout reached (%ds). Exiting.\n", $timeout ) );
		break;
	}

	if ( $max_jobs > 0 && $jobs_processed >= $max_jobs ) {
		fwrite( STDOUT, sprintf( "Max jobs reached (%d). Exiting.\n", $max_jobs ) );
		break;
	}

	// Memory watchdog: exit at 90% of memory limit.
	$mem_usage = memory_get_usage( true );
	$mem_limit = self::parse_memory_limit( $memory_limit );

	if ( $mem_limit > 0 && $mem_usage > ( $mem_limit * 0.9 ) ) {
		fwrite( STDERR, sprintf(
			"Memory limit approaching: %s / %s. Exiting.\n",
			self::format_bytes( $mem_usage ),
			self::format_bytes( $mem_limit )
		) );
		break;
	}

	// Process a batch of jobs.
	try {
		$result = WP_MCP_AI_Job_Queue_Manager::process_queue( 3 );
		$batch_processed = isset( $result['processed'] ) ? (int) $result['processed'] : 0;

		if ( $batch_processed > 0 ) {
			$jobs_processed += $batch_processed;
			fwrite( STDOUT, sprintf(
				"[%s] Processed %d job(s). Total: %d. Queue stats: %s\n",
				gmdate( 'Y-m-d H:i:s' ),
				$batch_processed,
				$jobs_processed,
				wp_json_encode( isset( $result ) ? array_diff_key( $result, array( 'processed' => 0 ) ) : array() )
			) );
		}

		// Reset memory after each batch to help with PHP's internal memory management.
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}
	} catch ( Exception $e ) {
		fwrite( STDERR, sprintf( "[%s] Worker error: %s\n", gmdate( 'Y-m-d H:i:s' ), $e->getMessage() ) );
	}

	// Sleep between iterations (daemon mode only).
	if ( $is_daemon && ! $should_exit && $batch_processed < 1 ) {
		sleep( $sleep_seconds );
	}

	// Check signals.
	if ( function_exists( 'pcntl_signal_dispatch' ) ) {
		pcntl_signal_dispatch();
	}
} while ( $is_daemon && ! $should_exit );

// ─── Cleanup ─────────────────────────────────────────────────────────
flock( $lock_fh, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
fclose( $lock_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

fwrite( STDOUT, sprintf(
	"[%s] Queue worker exiting. Total jobs processed: %d. Runtime: %ds.\n",
	gmdate( 'Y-m-d H:i:s' ),
	$jobs_processed,
	time() - $start_time
) );

exit( 0 );

// ─── Helper functions ─────────────────────────────────────────────────

/**
 * Parse a PHP memory limit string to bytes.
 *
 * @param string $limit Memory limit string (e.g., "256M").
 * @return int Bytes.
 */
function parse_memory_limit( $limit ) {
	$value = absint( $limit );
	$unit  = strtoupper( substr( $limit, -1 ) );

	switch ( $unit ) {
		case 'G':
			$value *= 1024;
			// Fall through.
		case 'M':
			$value *= 1024;
			// Fall through.
		case 'K':
			$value *= 1024;
			break;
	}

	return $value;
}

/**
 * Format bytes to human-readable string.
 *
 * @param int $bytes Number of bytes.
 * @return string Formatted string.
 */
function format_bytes( $bytes ) {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$i     = 0;

	while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
		$bytes /= 1024;
		++$i;
	}

	return round( $bytes, 2 ) . ' ' . $units[ $i ];
}

// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions
