<?php
/**
 * Concurrency Guard for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-concurrency-guard.php` (behaviour-
 * preserving; base copy retained permanently — ecosystem port plan
 * D-NOBASE). Transient prefix, cache group, slot table name, TTL,
 * operation limits map, and the `wp_mcp_ai_concurrency_limits` filter
 * keep their base names and semantics.
 *
 * Decoupling (documented, additive):
 * - The subscriber (`ConcurrencyGuardSubscriber`) is registered
 *   standalone-only by `Plugin.php` — in monolith installs the base
 *   plugin owns the same tool-execution hooks and the same slot table;
 *   double registration would double-count slots.
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Limits concurrent resource-intensive operations.
 *
 * Uses atomic operations to prevent race conditions:
 * - Redis/Memcached: wp_cache_incr() / wp_cache_decr() (atomic).
 * - Database: INSERT ... ON DUPLICATE KEY UPDATE (InnoDB row-level lock).
 *
 * Usage:
 *   $slot = ConcurrencyGuard::acquire( 'image_generation' );
 *   if ( is_wp_error( $slot ) ) { return $slot; }
 *   // ... do work ...
 *   ConcurrencyGuard::release( 'image_generation' );
 *
 * @since 1.1.0
 */
class ConcurrencyGuard {

	/**
	 * Transient prefix for slot counters (legacy, retained for get_usage).
	 */
	const TRANSIENT_PREFIX = 'wp_mcp_ai_concurrency_';

	/**
	 * Cache group for atomic object-cache slot counters.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wp_mcp_ai_concurrency';

	/**
	 * Database table for atomic slot counters (no-object-cache fallback).
	 *
	 * @var string
	 */
	const SLOTS_TABLE = 'mcp_ai_concurrency_slots';

	/**
	 * Default TTL for concurrency locks (10 minutes).
	 */
	const LOCK_TTL = 600;

	/**
	 * Operation type → max concurrent slots.
	 *
	 * @var array<string, int>
	 */
	const LIMITS = array(
		'image_generation'    => 3,
		'video_generation'    => 1,
		'music_generation'    => 2,
		'deep_research'       => 2,
		'model_download'      => 1,
		'document_ocr'        => 2,
		'pdf_generation'      => 2,
		'embeddings_batch'    => 3,
		'video_frame_extract' => 1,
		'default'             => 5,
	);

	/**
	 * Acquire a concurrency slot for an operation type.
	 *
	 * When a persistent object cache (Redis/Memcached) is available,
	 * uses wp_cache_incr() which is atomic. Falls back to a database
	 * table with InnoDB row-level locking for sites without a
	 * persistent cache.
	 *
	 * @param string $operation_type Type from LIMITS (e.g. 'image_generation').
	 * @return true|WP_Error True if slot acquired, WP_Error if at capacity.
	 */
	public static function acquire( $operation_type ) {
		$max = self::get_limit( $operation_type );
		$key = self::TRANSIENT_PREFIX . sanitize_key( $operation_type );

		if ( wp_using_ext_object_cache() ) {
			return self::acquire_atomic_cache( $key, $max, $operation_type );
		}

		return self::acquire_atomic_db( $key, $max, $operation_type );
	}

	/**
	 * Release a concurrency slot after an operation completes.
	 *
	 * Always call this, even on failure paths (use try/finally or
	 * shutdown handler).
	 *
	 * @param string $operation_type Operation type.
	 * @return void
	 */
	public static function release( $operation_type ) {
		$key = self::TRANSIENT_PREFIX . sanitize_key( $operation_type );

		if ( wp_using_ext_object_cache() ) {
			$current = wp_cache_get( $key, self::CACHE_GROUP );
			if ( false !== $current && (int) $current > 0 ) {
				wp_cache_decr( $key, 1, self::CACHE_GROUP );
			}
			return;
		}

		self::release_atomic_db( $key );
	}

	// ─── Atomic cache path (Redis / Memcached) ──────────────────────

	/**
	 * Atomic acquire via persistent object cache.
	 *
	 * The wp_cache_incr() function is atomic when backed by Redis,
	 * Memcached, or any cache backend that supports atomic increment.
	 *
	 * @param string $key            Cache key.
	 * @param int    $max            Maximum concurrent slots.
	 * @param string $operation_type Operation name for error messages.
	 * @return true|WP_Error
	 */
	private static function acquire_atomic_cache( $key, $max, $operation_type ) {
		// Initialise if not set (wp_cache_incr returns false for
		// non-existent keys).
		$current = wp_cache_get( $key, self::CACHE_GROUP );
		if ( false === $current ) {
			wp_cache_set( $key, 0, self::CACHE_GROUP, self::LOCK_TTL );
		}

		$new_value = wp_cache_incr( $key, 1, self::CACHE_GROUP );

		if ( false === $new_value || (int) $new_value > $max ) {
			// Roll back the increment — we are over capacity.
			wp_cache_decr( $key, 1, self::CACHE_GROUP );
			return new \WP_Error(
				'concurrency_limit',
				sprintf(
					/* translators: 1=operation, 2=max count */
					__( 'Maximum %2$d concurrent %1$s operations reached. Please try again later.', 'nvoos-content-graph-ai' ),
					esc_html( $operation_type ),
					esc_html( (string) $max )
				)
			);
		}

		return true;
	}

	// ─── Atomic DB path (InnoDB row-level locking) ──────────────────

	/**
	 * Database table name (without prefix) for slot tracking.
	 *
	 * @var string
	 */
	const SLOTS_TABLE_NAME = 'mcp_ai_concurrency_slots';

	/**
	 * Create the concurrency slots table.
	 *
	 * Idempotent — safe to call on every plugin activation.
	 * Uses dbDelta() for schema management.
	 *
	 * @return void
	 */
	public static function create_slots_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::SLOTS_TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table_name (
			slot_key VARCHAR(100) NOT NULL,
			current_count INT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY (slot_key),
			KEY expires_at (expires_at)
		) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/**
	 * Atomic acquire via database (InnoDB row-level locking).
	 *
	 * INSERT … ON DUPLICATE KEY UPDATE is atomic on InnoDB when the
	 * slot_key is a PRIMARY KEY.
	 *
	 * @param string $key            Slot key.
	 * @param int    $max            Maximum concurrent slots.
	 * @param string $operation_type Operation name for error messages.
	 * @return true|WP_Error
	 */
	private static function acquire_atomic_db( $key, $max, $operation_type ) {
		global $wpdb;

		// Ensure table exists (checked once per request lifetime).
		self::ensure_slots_table();

		$table = $wpdb->prefix . self::SLOTS_TABLE_NAME;

		// Atomic: INSERT with ON DUPLICATE KEY UPDATE checks
		// current_count < max and increments in a single statement.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name from constant; custom plugin table not covered by WP object cache.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (slot_key, current_count, expires_at)
			 VALUES (%s, 1, DATE_ADD(NOW(), INTERVAL %d SECOND))
			 ON DUPLICATE KEY UPDATE
				 current_count = IF(current_count < %d, current_count + 1, current_count),
				 expires_at = IF(current_count < %d,
					 DATE_ADD(NOW(), INTERVAL %d SECOND), expires_at)",
				$key,
				self::LOCK_TTL,
				$max,
				$max,
				self::LOCK_TTL
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $result ) {
			return new \WP_Error(
				'concurrency_db_error',
				__( 'Failed to acquire concurrency slot due to database error.', 'nvoos-content-graph-ai' )
			);
		}

		// Check whether we actually got the slot.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$current = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT current_count FROM {$table} WHERE slot_key = %s",
				$key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( (int) $current > $max ) {
			// We were the unlucky one — release immediately.
			self::release_atomic_db( $key );
			return new \WP_Error(
				'concurrency_limit',
				sprintf(
					/* translators: 1=operation, 2=max count */
					__( 'Maximum %2$d concurrent %1$s operations reached. Please try again later.', 'nvoos-content-graph-ai' ),
					esc_html( $operation_type ),
					esc_html( (string) $max )
				)
			);
		}

		return true;
	}

	/**
	 * Release a slot via database.
	 *
	 * @param string $key Slot key.
	 * @return void
	 */
	private static function release_atomic_db( $key ) {
		global $wpdb;

		// Ensure table exists.
		self::ensure_slots_table();

		$table = $wpdb->prefix . self::SLOTS_TABLE_NAME;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
			 SET current_count = GREATEST(current_count - 1, 0)
			 WHERE slot_key = %s AND current_count > 0",
				$key
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Ensure the concurrency slots table exists.
	 *
	 * Checked once per request lifetime. Uses dbDelta() for
	 * idempotent schema management.
	 *
	 * @return void
	 */
	private static function ensure_slots_table() {
		static $ensured = false;
		if ( $ensured ) {
			return;
		}

		self::create_slots_table();
		$ensured = true;
	}

	/**
	 * Clean up expired concurrency slots.
	 *
	 * Hooked to daily cron. Prevents orphaned slots from permanently
	 * consuming capacity if a process crashes without releasing.
	 *
	 * @return void
	 */
	public static function cleanup_expired_slots() {
		global $wpdb;

		$table = $wpdb->prefix . self::SLOTS_TABLE_NAME;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
		$wpdb->query(
			"DELETE FROM {$table} WHERE expires_at < NOW()"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get the concurrency limit for an operation type.
	 *
	 * @param string $operation_type Operation type.
	 * @return int Maximum concurrent operations allowed.
	 */
	public static function get_limit( $operation_type ) {
		$limits = apply_filters( 'wp_mcp_ai_concurrency_limits', self::LIMITS );

		return isset( $limits[ $operation_type ] )
			? absint( $limits[ $operation_type ] )
			: absint( $limits['default'] ?? 5 );
	}

	/**
	 * Get current usage for all operation types.
	 *
	 * @return array<string, array{current: int, max: int}>
	 */
	public static function get_usage() {
		$usage = array();

		foreach ( self::LIMITS as $type => $max ) {
			$key     = self::TRANSIENT_PREFIX . $type;
			$current = 0;

			// Try object cache first (atomic counters are stored here).
			if ( wp_using_ext_object_cache() ) {
				$val = wp_cache_get( $key, self::CACHE_GROUP );
				if ( false !== $val ) {
					$current = absint( $val );
				}
			} else {
				// Try the DB table, then fall back to transient.
				global $wpdb;
				$table = $wpdb->prefix . self::SLOTS_TABLE_NAME;
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin table.
				$db_val = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT current_count FROM {$table} WHERE slot_key = %s AND expires_at > NOW()",
						$key
					)
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( null !== $db_val ) {
					$current = absint( $db_val );
				} else {
					$current = absint( get_transient( $key ) );
				}
			}

			$usage[ $type ] = array(
				'current' => $current,
				'max'     => self::get_limit( $type ),
			);
		}

		return $usage;
	}
}
