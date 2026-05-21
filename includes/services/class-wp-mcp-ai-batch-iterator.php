<?php
/**
 * Batch iterator for massive-data operations.
 *
 * Provides two complementary iteration modes — paged (WP_Query / get_posts)
 * and seek-based (WHERE id > $last_id ORDER BY id ASC LIMIT $n) — together
 * with built-in memory hygiene, throttling, checkpointing, and Dead Letter
 * Queue integration.
 *
 * @link    https://deliciousbrains.com/building-custom-wp-cli-commands-massive-data-migrations/
 * @credit  Pattern derived from Delicious Brains / WP-CLI / VIP large-data guides
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch iterator with checkpointing + DLQ failure isolation.
 *
 * Typical usage (paged):
 *
 *     $iterator = new WP_MCP_AI_Batch_Iterator( 'rebuild-thumbnails' );
 *     foreach ( $iterator->paged_iterate( array( 'post_type' => 'attachment' ), 100 ) as $batch ) {
 *         foreach ( $batch as $post ) {
 *             $iterator->process_item( $post->ID, function () use ( $post ) {
 *                 // ... do work ...
 *             } );
 *         }
 *     }
 *     $iterator->complete();
 *
 * Typical usage (seek):
 *
 *     foreach ( $iterator->seek_iterate( $wpdb->posts, 'ID', "post_type = 'attachment'", 200 ) as $rows ) {
 *         foreach ( $rows as $row ) {
 *             $iterator->process_item( (int) $row->ID, $callback );
 *         }
 *     }
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Batch_Iterator {

	/**
	 * Option prefix for checkpoints.
	 */
	const CHECKPOINT_OPTION_PREFIX = 'wp_mcp_ai_migration_checkpoint_';

	/**
	 * Default batch size when not specified.
	 */
	const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Run identifier (slug + uuid).
	 *
	 * @var string
	 */
	protected $run_id;

	/**
	 * Cached checkpoint state.
	 *
	 * @var array
	 */
	protected $checkpoint = array(
		'run_id'    => '',
		'last_id'   => 0,
		'processed' => 0,
		'errors'    => 0,
		'started'   => 0,
		'updated'   => 0,
	);

	/**
	 * Whether to write per-row failures to the Dead Letter Queue.
	 *
	 * @var bool
	 */
	protected $dlq_enabled = true;

	/**
	 * Memory threshold percentage to honour between batches.
	 *
	 * @var int
	 */
	protected $memory_threshold_pct;

	/**
	 * Microseconds to sleep between batches.
	 *
	 * @var int
	 */
	protected $batch_sleep_us;

	/**
	 * Hard ceiling on items processed in this run (0 = unlimited).
	 *
	 * @var int
	 */
	protected $max_items = 0;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param string $run_id  Logical identifier for this run (used for checkpoint key).
	 *                        If empty, a random one is generated.
	 * @param array  $options {
	 *     Optional. Behaviour overrides.
	 *
	 *     @type bool $dlq_enabled            Default true.
	 *     @type int  $memory_threshold_pct   Default WP_MCP_AI_Memory_Manager::DEFAULT_THRESHOLD_PCT.
	 *     @type int  $batch_sleep_us         Default 0.
	 *     @type int  $max_items              Default 0 (unlimited).
	 *     @type bool $resume                 Whether to rehydrate any existing checkpoint. Default true.
	 * }
	 */
	public function __construct( $run_id = '', $options = array() ) {
		if ( ! is_string( $run_id ) || '' === $run_id ) {
			$run_id = self::generate_run_id();
		}
		$this->run_id = sanitize_key( $run_id );

		$options = wp_parse_args(
			$options,
			array(
				'dlq_enabled'          => true,
				'memory_threshold_pct' => WP_MCP_AI_Memory_Manager::DEFAULT_THRESHOLD_PCT,
				'batch_sleep_us'       => 0,
				'max_items'            => 0,
				'resume'               => true,
			)
		);

		$this->dlq_enabled = (bool) $options['dlq_enabled'];

		/** This filter is documented in includes/services/class-wp-mcp-ai-batch-iterator.php */
		$this->memory_threshold_pct = (int) apply_filters(
			'wp_mcp_ai_memory_threshold',
			(int) $options['memory_threshold_pct']
		);

		/**
		 * Filters microseconds to sleep between batches (CPU/IO throttling).
		 *
		 * @since 1.2.0
		 *
		 * @param int    $us     Microseconds. Default 0.
		 * @param string $run_id Run identifier.
		 */
		$this->batch_sleep_us = (int) apply_filters(
			'wp_mcp_ai_batch_sleep_us',
			(int) $options['batch_sleep_us'],
			$this->run_id
		);

		/**
		 * Filters the per-run hard ceiling on processed items.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $max    Maximum items (0 = unlimited).
		 * @param string $run_id Run identifier.
		 */
		$this->max_items = (int) apply_filters(
			'wp_mcp_ai_iterator_max_items',
			(int) $options['max_items'],
			$this->run_id
		);

		if ( $options['resume'] ) {
			$this->load_checkpoint();
		} else {
			$this->checkpoint['run_id']  = $this->run_id;
			$this->checkpoint['started'] = time();
		}
	}

	/**
	 * Generate a random run identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function generate_run_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'run_' . str_replace( '-', '', wp_generate_uuid4() );
		}
		return 'run_' . md5( uniqid( (string) wp_rand(), true ) );
	}

	/**
	 * Get the run identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_run_id() {
		return $this->run_id;
	}

	/**
	 * Get the current checkpoint snapshot.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function get_checkpoint() {
		return $this->checkpoint;
	}

	/**
	 * Load checkpoint from options (or initialise a fresh one).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function load_checkpoint() {
		$option = get_option( self::CHECKPOINT_OPTION_PREFIX . $this->run_id, false );

		if ( is_array( $option ) && isset( $option['run_id'] ) ) {
			$this->checkpoint = wp_parse_args(
				$option,
				array(
					'run_id'    => $this->run_id,
					'last_id'   => 0,
					'processed' => 0,
					'errors'    => 0,
					'started'   => time(),
					'updated'   => time(),
				)
			);
		} else {
			$this->checkpoint = array(
				'run_id'    => $this->run_id,
				'last_id'   => 0,
				'processed' => 0,
				'errors'    => 0,
				'started'   => time(),
				'updated'   => time(),
			);
		}
	}

	/**
	 * Persist the current checkpoint to wp_options.
	 *
	 * Uses autoload=no to keep these out of the main options bootstrap.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function save_checkpoint() {
		$this->checkpoint['updated'] = time();
		update_option(
			self::CHECKPOINT_OPTION_PREFIX . $this->run_id,
			$this->checkpoint,
			false
		);
	}

	/**
	 * Delete the checkpoint (call when the run completes successfully).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function complete() {
		delete_option( self::CHECKPOINT_OPTION_PREFIX . $this->run_id );
	}

	/**
	 * Static helper to rehydrate state from a stored checkpoint.
	 *
	 * @since 1.2.0
	 *
	 * @param string $run_id Run identifier.
	 * @return self
	 */
	public static function resume( $run_id ) {
		return new self( $run_id, array( 'resume' => true ) );
	}

	/**
	 * Set the seek cursor manually (e.g. when --start-id is provided).
	 *
	 * @since 1.2.0
	 *
	 * @param int $last_id Cursor value.
	 * @return void
	 */
	public function set_last_id( $last_id ) {
		$this->checkpoint['last_id'] = max( 0, (int) $last_id );
	}

	/**
	 * Iterate a WP_Query / get_posts query in pages, yielding chunks.
	 *
	 * Generator yielding arrays of WP_Post objects (or whatever fields=ids
	 * returns). Resets caches between batches and honours throttling.
	 *
	 * @since 1.2.0
	 *
	 * @param array $query_args Query args (without paged/posts_per_page).
	 * @param int   $batch_size Items per page.
	 *
	 * @return Generator|array[]
	 */
	public function paged_iterate( $query_args, $batch_size = self::DEFAULT_BATCH_SIZE ) {
		$batch_size = $this->resolve_batch_size( $batch_size );

		$paged     = isset( $query_args['paged'] ) ? max( 1, (int) $query_args['paged'] ) : 1;
		$processed = 0;

		// Strip args we control.
		unset( $query_args['nopaging'] );

		while ( true ) {
			$args                     = $query_args;
			$args['posts_per_page']   = $batch_size;
			$args['paged']            = $paged;
			$args['no_found_rows']    = true;
			$args['suppress_filters'] = isset( $query_args['suppress_filters'] ) ? $query_args['suppress_filters'] : false;

			$posts = get_posts( $args );

			if ( empty( $posts ) || ! is_array( $posts ) ) {
				break;
			}

			yield $posts;

			$processed += count( $posts );

			$this->after_batch();

			if ( count( $posts ) < $batch_size ) {
				break;
			}
			if ( $this->max_items > 0 && $processed >= $this->max_items ) {
				break;
			}

			++$paged;
		}
	}

	/**
	 * Iterate a table using seek-based pagination (WHERE id > $last_id).
	 *
	 * The recommended pattern for very large tables: avoids the COUNT(*)
	 * cost of OFFSET and remains stable when rows are inserted/deleted
	 * during the run.
	 *
	 * @since 1.2.0
	 *
	 * @param string $table        Fully qualified table name (e.g. $wpdb->posts).
	 *                             MUST be a developer-supplied identifier — it is
	 *                             validated against `[A-Za-z0-9_$.]+` but never
	 *                             escaped by `$wpdb->prepare()`.
	 * @param string $id_column    Primary-key column to seek on. Validated against
	 *                             `[A-Za-z0-9_]+`.
	 * @param string $where        Optional SQL WHERE fragment (without the WHERE keyword).
	 *                             **The caller is responsible for ensuring this is safe**:
	 *                             it must be either a static string, or already escaped /
	 *                             prepared via `$wpdb->prepare()`. Never pass raw user
	 *                             input here.
	 * @param int    $batch_size   Rows per batch.
	 * @param array  $extra_select Optional extra columns to SELECT (alongside the id column).
	 *                             Each column is validated against `[A-Za-z0-9_]+`.
	 *
	 * @return Generator|array[]
	 */
	public function seek_iterate( $table, $id_column, $where = '', $batch_size = self::DEFAULT_BATCH_SIZE, $extra_select = array() ) {
		global $wpdb;

		$batch_size = $this->resolve_batch_size( $batch_size );

		// Validate identifiers — these are NEVER user-supplied in practice
		// (callers pass $wpdb->posts etc.), but guard anyway.
		if ( ! preg_match( '/^[A-Za-z0-9_$.]+$/', (string) $table ) ) {
			return;
		}
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $id_column ) ) {
			return;
		}

		$select_cols = array( $id_column );
		foreach ( (array) $extra_select as $col ) {
			if ( preg_match( '/^[A-Za-z0-9_]+$/', (string) $col ) ) {
				$select_cols[] = $col;
			}
		}
		$select_sql = implode( ', ', array_unique( $select_cols ) );

		$where_sql = '';
		if ( '' !== trim( (string) $where ) ) {
			$where_sql = ' AND ( ' . $where . ' )';
		}

		$processed = 0;

		while ( true ) {
			$last_id = (int) $this->checkpoint['last_id'];

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table name from plugin constant; cannot be parameterised. Direct read on custom plugin batch-tracking table; no WP Core API for this schema.
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from plugin constant; cannot be parameterised.
				"SELECT {$select_sql} FROM {$table} WHERE {$id_column} > %d {$where_sql} ORDER BY {$id_column} ASC LIMIT %d",
				$last_id,
				$batch_size
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Direct read on custom plugin batch-tracking table; no WP Core API for this schema. Table name is a hardcoded plugin constant in a CREATE TABLE IF NOT EXISTS statement.
			$rows = $wpdb->get_results( $sql );

			if ( empty( $rows ) ) {
				break;
			}

			yield $rows;

			// Advance cursor to last seen id.
			$last_row = end( $rows );
			if ( is_object( $last_row ) && isset( $last_row->{$id_column} ) ) {
				$this->checkpoint['last_id'] = (int) $last_row->{$id_column};
			}

			$processed += count( $rows );

			$this->after_batch();

			if ( count( $rows ) < $batch_size ) {
				break;
			}
			if ( $this->max_items > 0 && $processed >= $this->max_items ) {
				break;
			}
		}
	}

	/**
	 * Run a callback against a single item with error isolation + DLQ logging.
	 *
	 * @since 1.2.0
	 *
	 * @param int|string $item_id Identifier for the item (used in DLQ + checkpoint).
	 * @param callable   $callback Callback receiving no arguments. Should return
	 *                             truthy on success, or throw / return WP_Error on failure.
	 * @param array      $context  Optional context data persisted in DLQ entries.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function process_item( $item_id, $callback, $context = array() ) {
		try {
			$result = call_user_func( $callback );

			if ( is_wp_error( $result ) ) {
				$this->record_failure( $item_id, $result->get_error_message(), $context );
				return false;
			}

			++$this->checkpoint['processed'];
			return true;
		} catch ( Throwable $e ) {
			// Catch both Exception (which implements Throwable) and engine errors.
			$this->record_failure( $item_id, $e->getMessage(), $context );
			return false;
		}
	}

	/**
	 * Record a per-item failure to the Dead Letter Queue (failure isolation).
	 *
	 * @since 1.2.0
	 *
	 * @param int|string $item_id Item identifier.
	 * @param string     $reason  Failure reason / exception message.
	 * @param array      $context Additional context data.
	 *
	 * @return void
	 */
	protected function record_failure( $item_id, $reason, $context = array() ) {
		++$this->checkpoint['errors'];

		if ( ! $this->dlq_enabled ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			return;
		}

		$identifier = $this->run_id . ':' . (string) $item_id;
		$payload    = array(
			'run_id'    => $this->run_id,
			'item_id'   => $item_id,
			'context'   => $context,
			'args_hash' => md5( wp_json_encode( $context ) ),
		);

		WP_MCP_AI_Dead_Letter_Queue::add(
			WP_MCP_AI_Dead_Letter_Queue::TYPE_JOB_QUEUE,
			$identifier,
			$payload,
			(string) $reason
		);
	}

	/**
	 * Run end-of-batch hygiene: stop_the_insanity, throttle, persist checkpoint.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	protected function after_batch() {
		WP_MCP_AI_Memory_Manager::stop_the_insanity();
		$this->save_checkpoint();

		if ( $this->batch_sleep_us > 0 ) {
			usleep( $this->batch_sleep_us );
		}

		// If we're approaching the memory limit, sleep + recheck.
		WP_MCP_AI_Memory_Manager::throttle_or_abort( $this->memory_threshold_pct );
	}

	/**
	 * Resolve the effective batch size (filter + sane bounds).
	 *
	 * @since 1.2.0
	 *
	 * @param int $batch_size Requested batch size.
	 * @return int
	 */
	protected function resolve_batch_size( $batch_size ) {
		$batch_size = (int) $batch_size;
		if ( $batch_size <= 0 ) {
			$batch_size = self::DEFAULT_BATCH_SIZE;
		}

		/**
		 * Filters the batch size used by the iterator.
		 *
		 * @since 1.2.0
		 *
		 * @param int    $batch_size Batch size.
		 * @param string $run_id     Run identifier.
		 */
		$batch_size = (int) apply_filters( 'wp_mcp_ai_batch_size', $batch_size, $this->run_id );

		// Clamp to safe range.
		if ( $batch_size < 1 ) {
			$batch_size = 1;
		}
		if ( $batch_size > 5000 ) {
			$batch_size = 5000;
		}
		return $batch_size;
	}
}
