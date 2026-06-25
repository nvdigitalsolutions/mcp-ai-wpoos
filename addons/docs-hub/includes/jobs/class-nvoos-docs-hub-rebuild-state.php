<?php
/**
 * NV oOS Docs Hub — Rebuild State
 *
 * Persists progress for the chunked async rebuild pipeline.
 * Lives in a single WordPress option so partial progress survives
 * across separate PHP requests (cron ticks, REST polls).
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value object + persistence helper for the chunked rebuild pipeline.
 *
 * @since 1.2.0
 */
class NV_oOS_Docs_Hub_Rebuild_State {

	/**
	 * Option key the state persists to.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_docs_hub_rebuild_state';

	/**
	 * Phase identifiers in execution order.
	 */
	const PHASE_IDLE     = 'idle';
	const PHASE_SCAN     = 'scan';
	const PHASE_PAGES    = 'pages';
	const PHASE_LINKS    = 'links';
	const PHASE_SEARCH   = 'search';
	const PHASE_FINALIZE = 'finalize';
	const PHASE_DONE     = 'done';
	const PHASE_FAILED   = 'failed';
	const PHASE_CANCELED = 'canceled';

	/**
	 * Default chunk size for page processing (filterable per-tick).
	 *
	 * @var int
	 */
	const DEFAULT_CHUNK_SIZE = 25;

	/**
	 * Hard cap on total indexed files per rebuild.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_FILES_TOTAL = 5000;

	/**
	 * Get the current state. Returns a normalized array even when no
	 * rebuild has ever been started.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public static function get() {
		$raw  = get_option( self::OPTION_KEY, array() );
		$base = array(
			'job_id'        => '',
			'phase'         => self::PHASE_IDLE,
			'cursor'        => 0,
			'total'         => 0,
			'processed'     => 0,
			'errors'        => array(),
			'warnings'      => array(),
			'started_at'    => 0,
			'updated_at'    => 0,
			'finished_at'   => 0,
			'phase_timings' => array(),
			'sync'          => false,
			'cap_hit'       => false,
			'last_error'    => '',
			'duration_ms'   => 0,
			'pages'         => 0,
			'broken_links'  => 0,
		);

		if ( ! is_array( $raw ) ) {
			return $base;
		}

		return array_merge( $base, $raw );
	}

	/**
	 * Persist a partial update to the state.
	 *
	 * @since 1.2.0
	 *
	 * @param array $patch Fields to merge into the current state.
	 * @return array The new state.
	 */
	public static function update( $patch ) {
		$current            = self::get();
		$next               = array_merge( $current, (array) $patch );
		$next['updated_at'] = time();
		// Keep autoload off — the _slugs payload can exceed 200 KB.
		update_option( self::OPTION_KEY, $next, 'no' );
		return $next;
	}

	/**
	 * Replace the entire state.
	 *
	 * @since 1.2.0
	 *
	 * @param array $state Full state array.
	 * @return void
	 */
	public static function set( $state ) {
		$state['updated_at'] = time();
		// Do not autoload — this option can carry a bulky _slugs list (up to
		// ~5 000 entries at ~40 chars each ≈ 200 KB) that should never be
		// loaded on every frontend request.
		update_option( self::OPTION_KEY, $state, 'no' );
	}

	/**
	 * Wipe the state back to a clean idle baseline.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Generate a fresh job ID.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function generate_job_id() {
		return wp_generate_uuid4();
	}

	/**
	 * Whether a rebuild is currently in progress (any non-terminal phase).
	 *
	 * @since 1.2.0
	 *
	 * @param array|null $state Optional pre-fetched state.
	 * @return bool
	 */
	public static function is_running( $state = null ) {
		$state = null === $state ? self::get() : $state;
		return in_array(
			$state['phase'],
			array( self::PHASE_SCAN, self::PHASE_PAGES, self::PHASE_LINKS, self::PHASE_SEARCH, self::PHASE_FINALIZE ),
			true
		);
	}

	/**
	 * Compute a human-friendly summary suitable for REST responses.
	 *
	 * @since 1.2.0
	 *
	 * @param array|null $state Optional pre-fetched state.
	 * @return array
	 */
	public static function to_summary( $state = null ) {
		$state      = null === $state ? self::get() : $state;
		$total      = (int) $state['total'];
		$processed  = (int) $state['processed'];
		$percentage = $total > 0 ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : 0;

		return array(
			'job_id'        => (string) $state['job_id'],
			'phase'         => (string) $state['phase'],
			'cursor'        => (int) $state['cursor'],
			'total'         => $total,
			'processed'     => $processed,
			'percentage'    => $percentage,
			'errors'        => array_values( (array) $state['errors'] ),
			'warnings'      => array_values( (array) $state['warnings'] ),
			'last_error'    => (string) $state['last_error'],
			'started_at'    => (int) $state['started_at'],
			'updated_at'    => (int) $state['updated_at'],
			'finished_at'   => (int) $state['finished_at'],
			'duration_ms'   => (int) $state['duration_ms'],
			'pages'         => (int) $state['pages'],
			'broken_links'  => (int) $state['broken_links'],
			'cap_hit'       => (bool) $state['cap_hit'],
			'phase_timings' => (array) $state['phase_timings'],
			'is_running'    => self::is_running( $state ),
		);
	}
}
