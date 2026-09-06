<?php
/**
 * Harness Trace Store — unified execution artifact storage.
 *
 * Provides a filesystem-like artifact store that captures the complete
 * execution trace of every chat request when trace capture is enabled
 * for an assistant. Each run produces a directory containing structured
 * JSON artifacts (profile, scores, reasoning trace, retrieval results,
 * tool calls, self-refine iterations, cost, and model response).
 *
 * The directory-per-run layout is deliberately filesystem-oriented so
 * that a coding-agent proposer can inspect artifacts via standard
 * operations (grep, cat) rather than ingesting everything as a single
 * prompt — matching the Meta-Harness architecture (Lee et al. 2026).
 *
 * Hard caps prevent disk exhaustion:
 *   - Max 50 retained runs per assistant (FIFO pruning).
 *   - Max 500 files per run directory.
 *   - Max 10 MB per file.
 *
 * Storage root: wp-content/uploads/mcp-ai-harness-traces/
 *   {assistant_id}/{run_id}/
 *     meta.json
 *     profile.json
 *     score.json
 *     cost.json
 *     reasoning_trace.json
 *     retrieval.json
 *     tool_calls.jsonl
 *     self_refine.json
 *     model_response.txt
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Harness Trace Store.
 *
 * @since 1.9.0
 */
class HarnessTraceStore {

	/**
	 * Subdirectory under wp-content/uploads/ used for trace storage.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const BASE_DIR = 'mcp-ai-harness-traces';

	/**
	 * Maximum number of completed runs retained per assistant.
	 * When exceeded, the oldest run is deleted (FIFO).
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const MAX_RUNS_PER_ASSISTANT = 50;

	/**
	 * Maximum size in bytes for a single trace artifact file.
	 * Files exceeding this cap are truncated before write.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB.

	/**
	 * Maximum number of files allowed in a single run directory.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const MAX_FILES_PER_RUN = 500;

	/**
	 * Maximum number of lines in a JSONL artifact.
	 *
	 * @since 1.9.0
	 * @var int
	 */
	const MAX_JSONL_LINES = 10000;

	/**
	 * Cache group for the filesystem guard flag.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const CACHE_GROUP = 'wp_mcp_ai_harness_trace';

	/**
	 * Cache key for the "guards placed" flag.
	 *
	 * @since 1.9.0
	 * @var string
	 */
	const GUARDS_PLACED_KEY = 'trace_base_dir_guards_placed';

	/**
	 * Active runs keyed by run_id. Each entry holds the run directory
	 * path and metadata written so far. Cleared on finish_run().
	 *
	 * @since 1.9.0
	 * @var array<string,array{dir:string,meta:array,files:int}>
	 */
	private static $active_runs = array();

	/**
	 * Start a new trace run. Creates the run directory and writes meta.json.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $meta         Optional. Run metadata (model, task_class, search_run_id, etc.).
	 * @return string|WP_Error Run ID on success, WP_Error on failure.
	 */
	public static function start_run( $assistant_id, array $meta = array() ) {
		$assistant_id = (int) $assistant_id;
		if ( $assistant_id <= 0 ) {
			return new \WP_Error(
				'wp_mcp_ai_trace_store_invalid_assistant',
				__( 'A valid assistant ID is required to start a trace run.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$run_id = self::generate_run_id( $assistant_id );
		$dir    = self::get_run_dir( $assistant_id, $run_id );

		if ( ! self::ensure_directory( $dir ) ) {
			return new \WP_Error(
				'wp_mcp_ai_trace_store_mkdir_failed',
				__( 'Could not create trace run directory.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$default_meta = array(
			'schema_version' => '1.0',
			'run_id'         => $run_id,
			'assistant_id'   => $assistant_id,
			'started_at'     => time(),
			'finished_at'    => null,
			'duration_ms'    => null,
			'model'          => isset( $meta['model'] ) ? (string) $meta['model'] : '',
			'task_class'     => isset( $meta['task_class'] ) ? sanitize_key( (string) $meta['task_class'] ) : 'general',
			'profile_hash'   => '',
			'provider'       => isset( $meta['provider'] ) ? sanitize_key( (string) $meta['provider'] ) : '',
			'search_run_id'  => isset( $meta['search_run_id'] ) ? sanitize_key( (string) $meta['search_run_id'] ) : '',
		);

		$merged = array_merge( $default_meta, array_intersect_key( $meta, $default_meta ) );
		$merged = array_intersect_key( $merged, $default_meta );

		$written = self::atomic_write( $dir . '/meta.json', wp_json_encode( $merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( ! $written ) {
			return new \WP_Error(
				'wp_mcp_ai_trace_store_write_failed',
				__( 'Could not write run metadata.', 'nvoos-content-graph-ai-platform' )
			);
		}

		self::$active_runs[ $run_id ] = array(
			'dir'   => $dir,
			'meta'  => $merged,
			'files' => 1,
		);

		return $run_id;
	}

	/**
	 * Write a single JSON artifact to the run directory.
	 *
	 * @since 1.9.0
	 *
	 * @param string $run_id   Run ID returned by start_run().
	 * @param string $filename Artifact filename (e.g. 'profile.json').
	 * @param mixed  $data     Data to JSON-encode and write.
	 * @return bool True on success, false on failure.
	 */
	public static function write_artifact( $run_id, $filename, $data ) {
		if ( ! isset( self::$active_runs[ $run_id ] ) ) {
			return false;
		}

		$run  = &self::$active_runs[ $run_id ];
		$path = $run['dir'] . '/' . self::sanitize_filename( $filename );

		if ( $run['files'] >= self::MAX_FILES_PER_RUN ) {
			return false;
		}

		$encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			return false;
		}

		$written = self::atomic_write( $path, $encoded );
		if ( $written ) {
			++$run['files'];
		}
		return $written;
	}

	/**
	 * Write plain text to the run directory.
	 *
	 * @since 1.9.0
	 *
	 * @param string $run_id   Run ID returned by start_run().
	 * @param string $filename Artifact filename (e.g. 'model_response.txt').
	 * @param string $text     Text content to write.
	 * @return bool True on success, false on failure.
	 */
	public static function write_text( $run_id, $filename, $text ) {
		if ( ! isset( self::$active_runs[ $run_id ] ) ) {
			return false;
		}

		$run  = &self::$active_runs[ $run_id ];
		$path = $run['dir'] . '/' . self::sanitize_filename( $filename );

		if ( $run['files'] >= self::MAX_FILES_PER_RUN ) {
			return false;
		}

		$written = self::atomic_write( $path, (string) $text );
		if ( $written ) {
			++$run['files'];
		}
		return $written;
	}

	/**
	 * Append a JSON record to a JSONL artifact.
	 *
	 * JSONL format: one JSON object per line, no trailing commas, no outer array.
	 * Each line is a self-contained JSON object. Hard-capped at MAX_JSONL_LINES.
	 *
	 * @since 1.9.0
	 *
	 * @param string $run_id   Run ID returned by start_run().
	 * @param string $filename Artifact filename (e.g. 'tool_calls.jsonl').
	 * @param array  $record   Associative array to JSON-encode as one line.
	 * @return bool True on success, false on failure.
	 */
	public static function append_jsonl( $run_id, $filename, array $record ) {
		if ( ! isset( self::$active_runs[ $run_id ] ) ) {
			return false;
		}

		$run  = &self::$active_runs[ $run_id ];
		$path = $run['dir'] . '/' . self::sanitize_filename( $filename );

		$line = wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $line ) {
			return false;
		}

		// Count existing lines to enforce cap.
		$existing = 0;
		if ( file_exists( $path ) ) {
			$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( $handle ) {
				while ( ! feof( $handle ) ) {
					$line_content = fgets( $handle );
					if ( false !== $line_content && '' !== trim( $line_content ) ) {
						++$existing;
					}
				}
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}

		if ( $existing >= self::MAX_JSONL_LINES ) {
			return false;
		}

		$written = self::atomic_append( $path, $line . "\n" );
		return (bool) $written;
	}

	/**
	 * Mark a run as complete. Writes final metadata (finished_at, duration_ms)
	 * and triggers pruning of old runs for this assistant.
	 *
	 * @since 1.9.0
	 *
	 * @param string $run_id Run ID returned by start_run().
	 * @param array  $score  Optional. Final score data to write as score.json.
	 * @return bool True on success, false if run not found.
	 */
	public static function finish_run( $run_id, array $score = array() ) {
		if ( ! isset( self::$active_runs[ $run_id ] ) ) {
			return false;
		}

		$run = self::$active_runs[ $run_id ];

		// Update meta.json with finish timestamp.
		$meta                 = $run['meta'];
		$meta['finished_at']  = time();
		$meta['duration_ms']  = (int) round( ( $meta['finished_at'] - $meta['started_at'] ) * 1000 );
		$meta['profile_hash'] = isset( $meta['profile_hash'] ) ? $meta['profile_hash'] : '';
		self::atomic_write( $run['dir'] . '/meta.json', wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		// Write score if provided.
		if ( ! empty( $score ) ) {
			self::atomic_write( $run['dir'] . '/score.json', wp_json_encode( $score, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}

		$assistant_id = (int) $meta['assistant_id'];
		unset( self::$active_runs[ $run_id ] );

		// Prune old runs for this assistant.
		self::prune_old_runs( $assistant_id );

		return true;
	}

	/**
	 * Get the absolute filesystem path to a run directory.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $run_id       Run ID.
	 * @return string Absolute path.
	 */
	public static function get_run_dir( $assistant_id, $run_id = '' ) {
		$base = self::get_base_dir();
		if ( '' === $run_id ) {
			return $base . '/' . (int) $assistant_id;
		}
		return $base . '/' . (int) $assistant_id . '/' . self::sanitize_run_id( $run_id );
	}

	/**
	 * List completed runs for an assistant, ordered by start time descending.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @param int $limit        Maximum runs to return (default 20, max 100).
	 * @return array<int,array> List of run metadata arrays.
	 */
	public static function list_runs( $assistant_id, $limit = 20 ) {
		$assistant_id = (int) $assistant_id;
		$limit        = max( 1, min( 100, (int) $limit ) );
		$dir          = self::get_run_dir( $assistant_id );

		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$runs    = array();
		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$run_path = $dir . '/' . $entry;
			if ( ! is_dir( $run_path ) ) {
				continue;
			}
			$meta_path = $run_path . '/meta.json';
			if ( ! file_exists( $meta_path ) ) {
				continue;
			}
			$json = self::safe_file_get_contents( $meta_path );
			if ( empty( $json ) ) {
				continue;
			}
			$meta = json_decode( $json, true );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$meta['run_id'] = $entry;
			$runs[]         = $meta;
		}

		// Sort by started_at descending.
		usort(
			$runs,
			static function ( $a, $b ) {
				$a_time = isset( $a['started_at'] ) ? (int) $a['started_at'] : 0;
				$b_time = isset( $b['started_at'] ) ? (int) $b['started_at'] : 0;
				return $b_time - $a_time;
			}
		);

		return array_slice( $runs, 0, $limit );
	}

	/**
	 * Get the manifest (list of files with sizes) for a run.
	 *
	 * @since 1.9.0
	 *
	 * @param string $run_id       Run ID.
	 * @param int    $assistant_id Assistant post ID (optional; auto-detected from run_id prefix).
	 * @return array{files:array<int,array{name:string,size:int}>,total_size:int}|WP_Error
	 */
	public static function get_run_manifest( $run_id, $assistant_id = 0 ) {
		$run_id       = self::sanitize_run_id( $run_id );
		$assistant_id = (int) $assistant_id;

		if ( $assistant_id <= 0 ) {
			// Try to extract from run_id (format: assistant_{id}_run_...).
			if ( preg_match( '/^assistant_(\d+)_run_/', $run_id, $m ) ) {
				$assistant_id = (int) $m[1];
			}
		}

		if ( $assistant_id <= 0 ) {
			return new \WP_Error(
				'wp_mcp_ai_trace_store_invalid_id',
				__( 'Could not determine assistant ID for run.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$dir = self::get_run_dir( $assistant_id, $run_id );
		if ( ! is_dir( $dir ) ) {
			return new \WP_Error(
				'wp_mcp_ai_trace_store_not_found',
				__( 'Run directory not found.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$files      = array();
		$total_size = 0;
		$entries    = scandir( $dir );

		if ( ! is_array( $entries ) ) {
			return array(
				'files'      => array(),
				'total_size' => 0,
			);
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_file( $path ) ) {
				$size        = (int) filesize( $path );
				$files[]     = array(
					'name' => $entry,
					'size' => $size,
				);
				$total_size += $size;
			}
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'files'      => $files,
			'total_size' => $total_size,
		);
	}

	/**
	 * Read a specific artifact from a run.
	 *
	 * @since 1.9.0
	 *
	 * @param string $run_id       Run ID.
	 * @param string $filename     Artifact filename.
	 * @param int    $assistant_id Assistant post ID (auto-detected if 0).
	 * @return string|array|null File contents (decoded if JSON), or null if not found.
	 */
	public static function read_artifact( $run_id, $filename, $assistant_id = 0 ) {
		$run_id       = self::sanitize_run_id( $run_id );
		$assistant_id = (int) $assistant_id;

		if ( $assistant_id <= 0 && preg_match( '/^assistant_(\d+)_run_/', $run_id, $m ) ) {
			$assistant_id = (int) $m[1];
		}

		if ( $assistant_id <= 0 ) {
			return null;
		}

		$filename = self::sanitize_filename( $filename );
		$path     = self::get_run_dir( $assistant_id, $run_id ) . '/' . $filename;

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$content = self::safe_file_get_contents( $path );
		if ( false === $content ) {
			return null;
		}

		// Auto-decode JSON files.
		if ( '.json' === substr( $filename, -5 ) ) {
			$decoded = json_decode( $content, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// For JSONL, return as array of lines.
		if ( '.jsonl' === substr( $filename, -6 ) ) {
			$lines = array_filter( explode( "\n", $content ) );
			$out   = array();
			foreach ( $lines as $line ) {
				$decoded = json_decode( trim( $line ), true );
				if ( is_array( $decoded ) ) {
					$out[] = $decoded;
				}
			}
			return $out;
		}

		return $content;
	}

	/**
	 * Remove old runs exceeding the per-assistant retention cap.
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return int Number of runs pruned.
	 */
	public static function prune_old_runs( $assistant_id ) {
		$assistant_id = (int) $assistant_id;
		$runs         = self::list_runs( $assistant_id, self::MAX_RUNS_PER_ASSISTANT + 50 );

		if ( count( $runs ) <= self::MAX_RUNS_PER_ASSISTANT ) {
			return 0;
		}

		$pruned   = 0;
		$to_keep  = self::MAX_RUNS_PER_ASSISTANT;
		$dir_base = self::get_run_dir( $assistant_id );

		foreach ( $runs as $i => $run ) {
			if ( $i < $to_keep ) {
				continue;
			}
			$run_path = $dir_base . '/' . ( isset( $run['run_id'] ) ? self::sanitize_run_id( $run['run_id'] ) : '' );
			if ( '' !== $run_path && is_dir( $run_path ) ) {
				self::recursive_rmdir( $run_path );
				++$pruned;
			}
		}

		return $pruned;
	}

	/**
	 * Delete all trace data for an assistant (used on assistant deletion).
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return bool True on success.
	 */
	public static function delete_all_for_assistant( $assistant_id ) {
		$assistant_id = (int) $assistant_id;
		$dir          = self::get_run_dir( $assistant_id );

		if ( is_dir( $dir ) ) {
			self::recursive_rmdir( $dir );
		}

		return true;
	}

	/**
	 * Check whether trace capture is active for a given run_id.
	 *
	 * @since 1.9.0
	 *
	 * @param string $run_id Run ID.
	 * @return bool
	 */
	public static function is_active( $run_id ) {
		return isset( self::$active_runs[ $run_id ] );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Generate a unique run ID.
	 *
	 * Format: assistant_{id}_run_{timestamp}_{random_hex}
	 *
	 * @since 1.9.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return string
	 */
	private static function generate_run_id( $assistant_id ) {
		return sprintf(
			'assistant_%d_run_%d_%s',
			(int) $assistant_id,
			time(),
			bin2hex( random_bytes( 4 ) )
		);
	}

	/**
	 * Get the absolute base directory for all trace storage.
	 *
	 * @since 1.9.0
	 *
	 * @return string
	 */
	private static function get_base_dir() {
		$upload_dir = wp_upload_dir();
		$base       = $upload_dir['basedir'] . '/' . self::BASE_DIR;

		// Place security guards once per request (cached).
		$guards_placed = wp_cache_get( self::GUARDS_PLACED_KEY, self::CACHE_GROUP );
		if ( ! $guards_placed ) {
			self::place_security_guards( $base );
			wp_cache_set( self::GUARDS_PLACED_KEY, true, self::CACHE_GROUP );
		}

		return $base;
	}

	/**
	 * Ensure a directory exists with proper permissions.
	 *
	 * @since 1.9.0
	 *
	 * @param string $dir Absolute path.
	 * @return bool
	 */
	private static function ensure_directory( $dir ) {
		if ( is_dir( $dir ) ) {
			return true;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Place guards in the new assistant-level directory too.
		$assistant_dir = dirname( $dir );
		self::place_security_guards_in_dir( $assistant_dir );

		return true;
	}

	/**
	 * Place .htaccess and index.php security guards in the base directory.
	 *
	 * @since 1.9.0
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private static function place_security_guards( $dir ) {
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return;
			}
		}
		self::place_security_guards_in_dir( $dir );
	}

	/**
	 * Place security guard files in a specific directory.
	 *
	 * @since 1.9.0
	 *
	 * @param string $dir Absolute path.
	 * @return void
	 */
	private static function place_security_guards_in_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		// .htaccess — deny directory listing and direct access.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			self::atomic_write( $htaccess, "Deny from all\n" );
		}

		// index.php — silence is golden.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			self::atomic_write( $index, '<?php' . "\n" . '// Silence is golden.' . "\n" );
		}
	}

	/**
	 * Write content to a file atomically (write to temp file, then rename).
	 *
	 * @since 1.9.0
	 *
	 * @param string $path    Target path.
	 * @param string $content Content to write (truncated to MAX_FILE_SIZE).
	 * @return bool True on success.
	 */
	private static function atomic_write( $path, $content ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Enforce file size cap.
		$content = self::truncate_content( $content );

		$tmp = $path . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';

		$written = file_put_contents( $tmp, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $written ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- Atomic file replacement on same filesystem; WP_Filesystem::move() requires initialized context and doesn't guarantee atomicity; @ suppresses error on non-existent temp file.
		$renamed = @rename( $tmp, $path );
		if ( ! $renamed ) {
			// Clean up temp file on failure.
			@unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Temp file cleanup; WP_Filesystem has no temp-cleanup method; @ suppresses errors on non-existent temp files.
			return false;
		}

		return true;
	}

	/**
	 * Append content to a file atomically.
	 *
	 * @since 1.9.0
	 *
	 * @param string $path    Target path.
	 * @param string $content Content to append.
	 * @return bool True on success.
	 */
	private static function atomic_append( $path, $content ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Check total file size before appending.
		if ( file_exists( $path ) && filesize( $path ) >= self::MAX_FILE_SIZE ) {
			return false;
		}

		$written = file_put_contents( $path, $content, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== $written;
	}

	/**
	 * Truncate content to the max file size, preserving JSON validity
	 * where possible by keeping a trailing indicator.
	 *
	 * @since 1.9.0
	 *
	 * @param string $content Raw content.
	 * @return string Truncated content.
	 */
	private static function truncate_content( $content ) {
		if ( strlen( $content ) <= self::MAX_FILE_SIZE ) {
			return $content;
		}

		// For JSON: truncate and add a truncation marker as a JSON-safe comment.
		$truncated  = substr( $content, 0, self::MAX_FILE_SIZE - 100 );
		$truncated .= "\n\n/* Content truncated at " . self::MAX_FILE_SIZE . ' bytes */';
		return $truncated;
	}

	/**
	 * Safely read file contents.
	 *
	 * @since 1.9.0
	 *
	 * @param string $path File path.
	 * @return string|false File contents or false on failure.
	 */
	private static function safe_file_get_contents( $path ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $path );
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @since 1.9.0
	 *
	 * @param string $dir Absolute path.
	 * @return bool
	 */
	private static function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return false;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				self::recursive_rmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- Recursive directory cleanup; WP_Filesystem has no recursive-delete method; @ suppresses errors on non-existent files.
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Recursive directory cleanup; WP_Filesystem has no recursive-delete method; @ suppresses errors on non-existent dirs.
		return @rmdir( $dir );
	}

	/**
	 * Sanitize a run ID for use in a directory name.
	 *
	 * @since 1.9.0
	 *
	 * @param string $run_id Raw run ID.
	 * @return string Sanitized (alphanumeric + underscores + hyphens).
	 */
	private static function sanitize_run_id( $run_id ) {
		return preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $run_id );
	}

	/**
	 * Sanitize a filename for use in the trace directory.
	 *
	 * @since 1.9.0
	 *
	 * @param string $filename Raw filename.
	 * @return string Sanitized (alphanumeric + dots + underscores + hyphens).
	 */
	private static function sanitize_filename( $filename ) {
		$sanitized = preg_replace( '/[^a-zA-Z0-9._\-]/', '_', (string) $filename );
		// Prevent directory traversal.
		$sanitized = basename( $sanitized );
		return $sanitized;
	}
}
