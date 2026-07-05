<?php
/**
 * NV oOS Docs Hub — Rebuild Pipeline
 *
 * Implements the chunked, self-rescheduling rebuild pipeline used by the
 * async path. The synchronous path in NV_oOS_Docs_Hub_Rebuild_Job::run()
 * remains intact for tests and CLI; this class handles everything that
 * needs to span multiple PHP requests.
 *
 * Phases:
 *   1. scan     — gather entries (cheap), build provisional manifest, write
 *                 it to the staging cache. Read-only on the live cache.
 *   2. pages    — open N entries per tick, write payloads to staging.
 *   3. links    — read each staged page payload, run broken-link detection,
 *                 accumulate the list on the state.
 *   4. search   — build the search index from staged pages, append in chunks.
 *   5. finalize — atomic swap from staging into the live cache.
 *
 * Each tick respects a wall-clock budget (default ~15s) and a memory budget
 * (default 80% of memory_limit). When either is approached, the tick stops
 * mid-chunk and reschedules itself so the next request picks up where it
 * left off.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the inline-async-tick trait from the base plugin when available so the
// rebuild pipeline can fire its first chunk immediately on the request that
// triggered the rebuild, rather than waiting for the next WP-Cron loopback.
if ( defined( 'WP_MCP_AI_PATH' ) && ! trait_exists( 'WP_MCP_AI_Inline_Async_Tick_Trait' ) ) {
	$nvoos_docs_hub_trait_path = WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-inline-async-tick.php';
	if ( file_exists( $nvoos_docs_hub_trait_path ) ) {
		require_once $nvoos_docs_hub_trait_path;
	}
	unset( $nvoos_docs_hub_trait_path );
}

// Stub: lets the class load cleanly on bare envs (e.g. unit tests running
// without the base plugin) while silently disabling the inline kick.
if ( ! trait_exists( 'WP_MCP_AI_Inline_Async_Tick_Trait' ) ) {
	// phpcs:ignore Generic.Files.OneClassPerFile.MultipleFound -- intentional stub trait.
	trait WP_MCP_AI_Inline_Async_Tick_Trait {
		// phpcs:ignore Squiz.Commenting.FunctionComment.Missing,Universal.NamingConventions.NoReservedKeywordParameterNames,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		protected static function inline_async_kick_enabled( $job_id, $class ) {
			return false;
		}
		// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		protected static function inline_async_detach_worker_from_client() {}
		// phpcs:ignore Squiz.Commenting.FunctionComment.Missing,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		protected static function inline_async_acquire_tick_lock( $lock_key, $cache_group, $ttl_seconds = 60 ) {
			return true;
		}
		// phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		protected static function inline_async_release_tick_lock( $lock_key, $cache_group ) {}
		// phpcs:ignore Squiz.Commenting.FunctionComment.Missing,Universal.NamingConventions.NoReservedKeywordParameterNames,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		protected static function inline_async_run_kick( $class, $job_id, $callable ) {}
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- file intentionally contains a stub trait and a class.
/**
 * Chunked rebuild pipeline.
 *
 * Adopts {@see WP_MCP_AI_Inline_Async_Tick_Trait} (Slice 4 of the inline-async-tick
 * campaign) when the base NV oOS plugin is active. The inline kick fires the first
 * chunk of the rebuild on the shutdown of the request that calls {@see enqueue()},
 * eliminating the latency between "rebuild triggered" and "first chunk processed".
 * The cooperative tick lock prevents two concurrent tick executions (e.g. an inline
 * kick racing a WP-Cron loopback) from processing the same chunk twice.
 *
 * @since 1.2.0
 */
class NV_oOS_Docs_Hub_Rebuild_Pipeline {

	use WP_MCP_AI_Inline_Async_Tick_Trait;

	/**
	 * Cron / single-event hook fired between chunks.
	 *
	 * @var string
	 */
	const TICK_HOOK = 'nvoos_docs_hub_rebuild_tick';

	/**
	 * Default per-tick wall-clock budget in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_WALLCLOCK_BUDGET = 15;

	/**
	 * Default per-tick memory budget as a fraction of the PHP memory_limit.
	 *
	 * @var float
	 */
	const DEFAULT_MEMORY_BUDGET = 0.8;

	/**
	 * Lock key for the cooperative tick lock.
	 *
	 * There is only one rebuild at a time (guarded by {@see is_running()}),
	 * so a fixed key is sufficient. The key is passed directly to
	 * {@see inline_async_acquire_tick_lock()} /
	 * {@see inline_async_release_tick_lock()}.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const TICK_LOCK_KEY = 'nvoos_docs_hub_rebuild_tick_lock';

	/**
	 * Object-cache group used by the tick-lock entries.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const TICK_LOCK_CACHE_GROUP = 'nvoos_docs_hub';

	/**
	 * Tick-lock TTL in seconds.
	 *
	 * Should be longer than the per-tick wall-clock budget (15 s) plus some
	 * headroom for worst-case ticks. 45 s is chosen to be safely above the
	 * default 15 s budget while releasing the lock quickly.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const TICK_LOCK_TTL = 45;

	/**
	 * Tick start timestamp (microtime).
	 *
	 * @var float
	 */
	private $tick_start = 0.0;

	/**
	 * Wall-clock budget for the current tick (seconds).
	 *
	 * @var int
	 */
	private $wallclock_budget = self::DEFAULT_WALLCLOCK_BUDGET;

	/**
	 * Memory budget for the current tick (bytes).
	 *
	 * @var int
	 */
	private $memory_budget = 0;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Register the chunk hook.
	 *
	 * Called from the plugin bootstrap so cron and single-event ticks can
	 * route into the pipeline without a hard dependency on this class
	 * being loaded eagerly.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function register() {
		add_action( self::TICK_HOOK, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Enqueue an async rebuild.
	 *
	 * Resets state, kicks off the scan phase synchronously (it's cheap),
	 * then schedules the first page-processing tick. Returns the new
	 * state summary.
	 *
	 * @since 1.2.0
	 *
	 * @return array Summary of the queued job.
	 */
	public static function enqueue() {
		$current = NV_oOS_Docs_Hub_Rebuild_State::get();

		// Auto-cancel a rebuild that has been stuck for > 30 minutes
		// (cron stopped, server restart, etc.) so the next attempt
		// doesn't silently no-op.
		$stale_timeout = (int) apply_filters( 'nvoos_docs_hub_rebuild_stale_seconds', 30 * MINUTE_IN_SECONDS );
		if ( NV_oOS_Docs_Hub_Rebuild_State::is_running( $current )
			&& ! empty( $current['started_at'] )
			&& ( time() - (int) $current['started_at'] ) > $stale_timeout
		) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic for stale-job auto-cancel
			error_log(
				sprintf(
					'[NV oOS Docs Hub] Auto-cancelling stale rebuild job %s (stuck in phase %s since %s).',
					(string) $current['job_id'],
					(string) $current['phase'],
					gmdate( 'c', (int) $current['started_at'] )
				)
			);
			self::cancel();
			$current = NV_oOS_Docs_Hub_Rebuild_State::get();
		}

		// If a job is currently running, return its summary instead of
		// stomping on it. The caller can cancel + re-enqueue if needed.
		if ( NV_oOS_Docs_Hub_Rebuild_State::is_running( $current ) ) {
			return NV_oOS_Docs_Hub_Rebuild_State::to_summary( $current );
		}

		$job_id = NV_oOS_Docs_Hub_Rebuild_State::generate_job_id();
		$now    = time();

		NV_oOS_Docs_Hub_Rebuild_State::set(
			array(
				'job_id'        => $job_id,
				'phase'         => NV_oOS_Docs_Hub_Rebuild_State::PHASE_SCAN,
				'cursor'        => 0,
				'total'         => 0,
				'processed'     => 0,
				'errors'        => array(),
				'warnings'      => array(),
				'started_at'    => $now,
				'updated_at'    => $now,
				'finished_at'   => 0,
				'phase_timings' => array(),
				'sync'          => false,
				'cap_hit'       => false,
				'last_error'    => '',
				'duration_ms'   => 0,
				'pages'         => 0,
				'broken_links'  => 0,
			)
		);

		// Run scan synchronously (cheap), then schedule the first tick.
		$pipeline = new self();
		$pipeline->run_scan_phase();

		self::schedule_next_tick();

		// Inline-async-tick: fire the first processing chunk on the shutdown of
		// the current request so the rebuild begins work immediately instead of
		// waiting for the next WP-Cron loopback (which on busy sites may be 1+
		// minutes away, or may never fire when DISABLE_WP_CRON is true).
		if ( self::inline_async_kick_enabled( $job_id, __CLASS__ ) ) {
			add_action(
				'shutdown',
				function () use ( $job_id ) {
					self::inline_async_detach_worker_from_client();
					self::inline_async_run_kick(
						__CLASS__,
						$job_id,
						function () {
							self::tick();
						}
					);
				},
				22
			);
		}

		return NV_oOS_Docs_Hub_Rebuild_State::to_summary();
	}

	/**
	 * Resume a stalled or failed rebuild from its last cursor.
	 *
	 * Handles: FAILED, CANCELED (restart at PAGES), and stuck active
	 * phases (e.g. cron died mid-PAGES — schedule a tick and let it
	 * pick up where it left off).
	 *
	 * @since 1.2.0
	 *
	 * @return array Summary.
	 */
	public static function resume() {
		$state = NV_oOS_Docs_Hub_Rebuild_State::get();

		// Stuck mid-phase (cron died, server restart): just reschedule
		// the tick and let the pipeline pick up at its cursor.
		if ( NV_oOS_Docs_Hub_Rebuild_State::is_running( $state ) ) {
			self::schedule_next_tick();
			return NV_oOS_Docs_Hub_Rebuild_State::to_summary();
		}

		if ( in_array(
			$state['phase'],
			array(
				NV_oOS_Docs_Hub_Rebuild_State::PHASE_FAILED,
				NV_oOS_Docs_Hub_Rebuild_State::PHASE_CANCELED,
			),
			true
		) ) {
			// Restart from the most appropriate phase. If we never got
			// past scan we have nothing to resume from — re-enqueue.
			if ( empty( $state['total'] ) ) {
				NV_oOS_Docs_Hub_Rebuild_State::reset();
				return self::enqueue();
			}
			NV_oOS_Docs_Hub_Rebuild_State::update(
				array(
					'phase'      => NV_oOS_Docs_Hub_Rebuild_State::PHASE_PAGES,
					'last_error' => '',
				)
			);
		}
		self::schedule_next_tick();
		return NV_oOS_Docs_Hub_Rebuild_State::to_summary();
	}

	/**
	 * Cancel an in-flight rebuild.
	 *
	 * Live cache is left untouched; staging is cleared so the next
	 * rebuild starts fresh.
	 *
	 * @since 1.2.0
	 *
	 * @return array Summary.
	 */
	public static function cancel() {
		wp_clear_scheduled_hook( self::TICK_HOOK );
		( new NV_oOS_Docs_Hub_Cache() )->clear_staging();
		return NV_oOS_Docs_Hub_Rebuild_State::update(
			array(
				'phase'       => NV_oOS_Docs_Hub_Rebuild_State::PHASE_CANCELED,
				'finished_at' => time(),
			)
		);
	}

	/**
	 * Tick callback — driven by wp_schedule_single_event.
	 *
	 * Picks up the current phase from the rebuild state and runs as
	 * much work as the per-tick budget allows. Reschedules itself
	 * when more work remains. Protected by the cooperative tick lock
	 * ({@see WP_MCP_AI_Inline_Async_Tick_Trait}) to prevent the inline
	 * kick fired by {@see enqueue()} from racing a concurrent WP-Cron
	 * loopback tick.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function tick() {
		$state = NV_oOS_Docs_Hub_Rebuild_State::get();
		if ( ! NV_oOS_Docs_Hub_Rebuild_State::is_running( $state ) ) {
			return;
		}

		// Cooperative lock — one tick at a time.
		if ( ! self::inline_async_acquire_tick_lock( self::TICK_LOCK_KEY, self::TICK_LOCK_CACHE_GROUP, self::TICK_LOCK_TTL ) ) {
			return;
		}

		try {
			self::do_tick();
		} finally {
			self::inline_async_release_tick_lock( self::TICK_LOCK_KEY, self::TICK_LOCK_CACHE_GROUP );
		}
	}

	/**
	 * Inner tick logic — executes while the tick lock is held.
	 *
	 * Extracted from {@see tick()} so unit tests can run chunks without
	 * going through the lock machinery.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function do_tick() {
		$state = NV_oOS_Docs_Hub_Rebuild_State::get();
		if ( ! NV_oOS_Docs_Hub_Rebuild_State::is_running( $state ) ) {
			return;
		}

		$pipeline = new self();
		$pipeline->begin_tick();

		try {
			$keep_going = true;
			$phase      = $state['phase'];
			while ( $keep_going && $pipeline->has_budget() ) {
				switch ( $phase ) {
					case NV_oOS_Docs_Hub_Rebuild_State::PHASE_SCAN:
						$pipeline->run_scan_phase();
						$phase = NV_oOS_Docs_Hub_Rebuild_State::get()['phase'];
						break;
					case NV_oOS_Docs_Hub_Rebuild_State::PHASE_PAGES:
						$keep_going = $pipeline->run_pages_chunk();
						$phase      = NV_oOS_Docs_Hub_Rebuild_State::get()['phase'];
						break;
					case NV_oOS_Docs_Hub_Rebuild_State::PHASE_LINKS:
						$keep_going = $pipeline->run_links_chunk();
						$phase      = NV_oOS_Docs_Hub_Rebuild_State::get()['phase'];
						break;
					case NV_oOS_Docs_Hub_Rebuild_State::PHASE_SEARCH:
						$keep_going = $pipeline->run_search_chunk();
						$phase      = NV_oOS_Docs_Hub_Rebuild_State::get()['phase'];
						break;
					case NV_oOS_Docs_Hub_Rebuild_State::PHASE_FINALIZE:
						$pipeline->run_finalize_phase();
						$keep_going = false;
						break;
					default:
						$keep_going = false;
						break;
				}
			}
		} catch ( Throwable $e ) {
			$pipeline->fail( $e->getMessage() );
			return;
		}

		// Reschedule unless we landed on a terminal phase.
		$state = NV_oOS_Docs_Hub_Rebuild_State::get();
		if ( NV_oOS_Docs_Hub_Rebuild_State::is_running( $state ) ) {
			self::schedule_next_tick();
		}
	}

	// -------------------------------------------------------------------------
	// Phase: scan
	// -------------------------------------------------------------------------

	/**
	 * Phase 1 — scan + provisional manifest into staging.
	 *
	 * Reads every file once for frontmatter+title (cheap), writes the
	 * manifest+slug_map to the staging cache, and parks the entry list
	 * onto the rebuild state for subsequent phases.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function run_scan_phase() {
		$phase_started = microtime( true );

		$scanner = new NV_oOS_Docs_Hub_Scanner();
		$entries = $scanner->scan();

		// Hard cap on total indexed files — protects against runaway scans.
		/**
		 * Filter the maximum number of files indexed in a single rebuild.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max Default 5000.
		 */
		$max_files = (int) apply_filters(
			'nvoos_docs_hub_max_files_total',
			NV_oOS_Docs_Hub_Rebuild_State::DEFAULT_MAX_FILES_TOTAL
		);
		$cap_hit   = false;
		if ( count( $entries ) > $max_files ) {
			$entries = array_slice( $entries, 0, $max_files );
			$cap_hit = true;
		}

		$indexer  = new NV_oOS_Docs_Hub_Indexer();
		$manifest = $indexer->build_manifest( $entries, false ); // skip broken-link pass.

		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->use_staging( true );
		$cache->clear_staging();
		$cache->set_manifest( $manifest );
		$cache->use_staging( false );

		// Park the slug list (just the slugs in build order) so subsequent
		// phases can iterate cheaply without holding the entire slug_map
		// in the option payload.
		$slug_map = $indexer->get_slug_map();
		$slugs    = array_keys( $slug_map );

		// Record per-phase timing.
		$state                                  = NV_oOS_Docs_Hub_Rebuild_State::get();
		$state['phase_timings']['scan_ms']      = (int) round( ( microtime( true ) - $phase_started ) * 1000 );
		$state['phase_timings']['scan_entries'] = count( $entries );

		NV_oOS_Docs_Hub_Rebuild_State::set(
			array_merge(
				$state,
				array(
					'phase'   => NV_oOS_Docs_Hub_Rebuild_State::PHASE_PAGES,
					'cursor'  => 0,
					'total'   => count( $slugs ),
					'cap_hit' => $cap_hit || $state['cap_hit'],
					'_slugs'  => $slugs,
				)
			)
		);

		do_action( 'nvoos_docs_hub_rebuild_phase', NV_oOS_Docs_Hub_Rebuild_State::PHASE_PAGES, NV_oOS_Docs_Hub_Rebuild_State::get() );
	}

	// -------------------------------------------------------------------------
	// Phase: pages
	// -------------------------------------------------------------------------

	/**
	 * Phase 2 — process up to chunk_size page payloads into staging.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when more chunks remain in this tick window.
	 */
	public function run_pages_chunk() {
		$state = NV_oOS_Docs_Hub_Rebuild_State::get();
		$slugs = isset( $state['_slugs'] ) ? (array) $state['_slugs'] : array();
		if ( empty( $slugs ) || $state['cursor'] >= count( $slugs ) ) {
			return $this->advance_to( NV_oOS_Docs_Hub_Rebuild_State::PHASE_LINKS );
		}

		$indexer = $this->load_indexer_from_staging();
		$cache   = new NV_oOS_Docs_Hub_Cache();
		$cache->use_staging( true );

		$chunk_size = $this->chunk_size();
		$cursor     = (int) $state['cursor'];
		$end        = min( $cursor + $chunk_size, count( $slugs ) );

		for ( $i = $cursor; $i < $end; $i++ ) {
			$slug    = $slugs[ $i ];
			$payload = $indexer->build_page_payload( $slug );
			if ( is_array( $payload ) ) {
				$cache->set_page( $slug, $payload );
			}
		}

		$state['cursor']    = $end;
		$state['processed'] = $end;
		$cache->use_staging( false );
		NV_oOS_Docs_Hub_Rebuild_State::set( $state );

		if ( $end >= count( $slugs ) ) {
			return $this->advance_to( NV_oOS_Docs_Hub_Rebuild_State::PHASE_LINKS );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Phase: links
	// -------------------------------------------------------------------------

	/**
	 * Phase 3 — broken-link detection (chunked).
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function run_links_chunk() {
		$state = NV_oOS_Docs_Hub_Rebuild_State::get();

		// Reset cursor when entering this phase.
		if ( NV_oOS_Docs_Hub_Rebuild_State::PHASE_LINKS === $state['phase'] && $state['cursor'] >= $state['total'] ) {
			$state['cursor'] = 0;
			NV_oOS_Docs_Hub_Rebuild_State::set( $state );
		}

		$slugs = isset( $state['_slugs'] ) ? (array) $state['_slugs'] : array();
		if ( empty( $slugs ) || $state['cursor'] >= count( $slugs ) ) {
			return $this->advance_to( NV_oOS_Docs_Hub_Rebuild_State::PHASE_SEARCH );
		}

		$indexer    = $this->load_indexer_from_staging();
		$slug_map   = $indexer->get_slug_map();
		$chunk_size = $this->chunk_size();
		$cursor     = (int) $state['cursor'];
		$end        = min( $cursor + $chunk_size, count( $slugs ) );

		$broken = array();
		for ( $i = $cursor; $i < $end; $i++ ) {
			$slug = $slugs[ $i ];
			if ( ! isset( $slug_map[ $slug ] ) ) {
				continue;
			}
			$data    = $slug_map[ $slug ];
			$content = $indexer->read_file( $data['path'] );
			$broken  = array_merge(
				$broken,
				$indexer->detect_broken_links( $content, $data['path'], $data['relative_path'] )
			);
		}

		// Persist broken links into staged manifest.
		if ( ! empty( $broken ) ) {
			$cache = new NV_oOS_Docs_Hub_Cache();
			$cache->use_staging( true );
			$manifest = $cache->get_manifest();
			if ( is_array( $manifest ) ) {
				$existing                 = isset( $manifest['broken_links'] ) ? (array) $manifest['broken_links'] : array();
				$manifest['broken_links'] = array_merge( $existing, $broken );
				$cache->set_manifest( $manifest );
			}
			$cache->use_staging( false );
		}

		$state['cursor']       = $end;
		$state['broken_links'] = ( isset( $state['broken_links'] ) ? (int) $state['broken_links'] : 0 ) + count( $broken );
		NV_oOS_Docs_Hub_Rebuild_State::set( $state );

		if ( $end >= count( $slugs ) ) {
			return $this->advance_to( NV_oOS_Docs_Hub_Rebuild_State::PHASE_SEARCH );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Phase: search
	// -------------------------------------------------------------------------

	/**
	 * Phase 4 — build search index from already-staged page payloads.
	 *
	 * Reads each staged page (cheap — they're local JSON now) and appends
	 * to the staged search-index.json. No file re-reads from the source
	 * Markdown trees.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function run_search_chunk() {
		$state = NV_oOS_Docs_Hub_Rebuild_State::get();

		// Reset cursor on phase entry.
		if ( NV_oOS_Docs_Hub_Rebuild_State::PHASE_SEARCH === $state['phase'] && $state['cursor'] >= $state['total'] ) {
			$state['cursor'] = 0;
			NV_oOS_Docs_Hub_Rebuild_State::set( $state );
		}

		$slugs = isset( $state['_slugs'] ) ? (array) $state['_slugs'] : array();
		if ( empty( $slugs ) || $state['cursor'] >= count( $slugs ) ) {
			return $this->advance_to( NV_oOS_Docs_Hub_Rebuild_State::PHASE_FINALIZE );
		}

		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->use_staging( true );

		$existing = $cache->get_search_index();
		$index    = is_array( $existing ) ? $existing : array();

		$chunk_size = $this->chunk_size();
		$cursor     = (int) $state['cursor'];
		$end        = min( $cursor + $chunk_size, count( $slugs ) );

		for ( $i = $cursor; $i < $end; $i++ ) {
			$slug    = $slugs[ $i ];
			$payload = $cache->get_page( $slug );
			if ( ! is_array( $payload ) ) {
				continue;
			}

			$plain = preg_replace( '/[#*`\[\]_~>]/', '', (string) ( $payload['content'] ?? '' ) );
			$plain = preg_replace( '/\s+/', ' ', $plain );
			$plain = trim( $plain );

			$index[] = array(
				'slug'        => $slug,
				'title'       => $payload['title'] ?? '',
				'excerpt'     => substr( $plain, 0, 500 ),
				'plugin_name' => $payload['plugin_name'] ?? '',
				'source'      => $payload['source'] ?? '',
			);
		}

		$cache->set_search_index( $index );
		$cache->use_staging( false );

		$state['cursor'] = $end;
		NV_oOS_Docs_Hub_Rebuild_State::set( $state );

		if ( $end >= count( $slugs ) ) {
			return $this->advance_to( NV_oOS_Docs_Hub_Rebuild_State::PHASE_FINALIZE );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Phase: finalize
	// -------------------------------------------------------------------------

	/**
	 * Phase 5 — atomic swap into the live cache.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function run_finalize_phase() {
		$cache = new NV_oOS_Docs_Hub_Cache();
		$ok    = $cache->promote_staging();

		$state = NV_oOS_Docs_Hub_Rebuild_State::get();
		if ( ! $ok ) {
			$this->fail( __( 'Atomic swap failed: staging cache empty or unwritable.', 'nvoos-docs-hub' ) );
			return;
		}

		// Pull final counts out of the now-live manifest for the summary.
		$manifest = $cache->get_manifest();
		$pages    = is_array( $manifest ) ? (int) ( $manifest['total_pages'] ?? 0 ) : 0;
		$broken   = is_array( $manifest ) && isset( $manifest['broken_links'] ) ? count( (array) $manifest['broken_links'] ) : 0;

		$started_at  = (int) $state['started_at'];
		$duration_ms = $started_at > 0 ? (int) ( ( time() - $started_at ) * 1000 ) : 0;

		$state['phase']        = NV_oOS_Docs_Hub_Rebuild_State::PHASE_DONE;
		$state['finished_at']  = time();
		$state['duration_ms']  = $duration_ms;
		$state['pages']        = $pages;
		$state['broken_links'] = $broken;
		// Drop the bulky entry list from the option once we're done.
		unset( $state['_slugs'] );

		NV_oOS_Docs_Hub_Rebuild_State::set( $state );

		do_action( 'nvoos_docs_hub_rebuild_phase', NV_oOS_Docs_Hub_Rebuild_State::PHASE_DONE, $state );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Begin tick: capture timing baselines and budgets.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function begin_tick() {
		$this->tick_start = microtime( true );

		/**
		 * Filter the per-tick wall-clock budget (seconds).
		 *
		 * @since 1.2.0
		 *
		 * @param int $seconds Default 15.
		 */
		$this->wallclock_budget = (int) apply_filters( 'nvoos_docs_hub_rebuild_tick_budget', self::DEFAULT_WALLCLOCK_BUDGET );

		// Memory budget = ratio * memory_limit.
		$limit_bytes = $this->parse_memory_limit( ini_get( 'memory_limit' ) );
		/**
		 * Filter the per-tick memory budget as a fraction of memory_limit.
		 *
		 * @since 1.2.0
		 *
		 * @param float $fraction Default 0.8.
		 */
		$ratio               = (float) apply_filters( 'nvoos_docs_hub_rebuild_memory_ratio', self::DEFAULT_MEMORY_BUDGET );
		$this->memory_budget = $limit_bytes > 0 ? (int) ( $limit_bytes * max( 0.1, min( 0.95, $ratio ) ) ) : 0;
	}

	/**
	 * Whether the current tick still has budget for more work.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	private function has_budget() {
		if ( $this->wallclock_budget > 0 && ( microtime( true ) - $this->tick_start ) > $this->wallclock_budget ) {
			return false;
		}
		if ( $this->memory_budget > 0 && memory_get_usage( true ) >= $this->memory_budget ) {
			return false;
		}
		return true;
	}

	/**
	 * Parse a php.ini memory limit string ("128M", "1G", "-1") into bytes.
	 *
	 * @since 1.2.0
	 *
	 * @param string $value memory_limit value.
	 * @return int Bytes; 0 when unlimited or unparseable.
	 */
	private function parse_memory_limit( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return 0;
		}
		$value = trim( $value );
		if ( '-1' === $value ) {
			return 0;
		}
		$last  = strtolower( substr( $value, -1 ) );
		$bytes = (int) $value;
		switch ( $last ) {
			case 'g':
				$bytes *= 1024 * 1024 * 1024;
				break;
			case 'm':
				$bytes *= 1024 * 1024;
				break;
			case 'k':
				$bytes *= 1024;
				break;
		}
		return max( 0, $bytes );
	}

	/**
	 * Resolve the configured per-tick chunk size.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	private function chunk_size() {
		/**
		 * Filter the per-chunk size (entries processed per tick).
		 *
		 * @since 1.2.0
		 *
		 * @param int $size Default 25.
		 */
		$size = (int) apply_filters(
			'nvoos_docs_hub_rebuild_chunk_size',
			NV_oOS_Docs_Hub_Rebuild_State::DEFAULT_CHUNK_SIZE
		);
		return max( 1, $size );
	}

	/**
	 * Schedule the next tick, defaulting to "as soon as cron runs again".
	 *
	 * Tests / sync paths can short-circuit by running tick() inline.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private static function schedule_next_tick() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			$scheduled = wp_schedule_single_event( time() + 1, self::TICK_HOOK );
			if ( false === $scheduled ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic for failed tick scheduling
				error_log(
					'[NV oOS Docs Hub] Failed to schedule next async rebuild tick. ' .
					'If WP-Cron is disabled (DISABLE_WP_CRON), the rebuild will stall. ' .
					'Use the synchronous "Rebuild now" button to complete it.'
				);
			}
		}
	}

	/**
	 * Move to a new phase, resetting the cursor to 0.
	 *
	 * Returns true so the tick loop continues into the new phase
	 * within the same wall-clock budget when possible.
	 *
	 * @since 1.2.0
	 *
	 * @param string $next_phase Phase identifier.
	 * @return bool
	 */
	private function advance_to( $next_phase ) {
		NV_oOS_Docs_Hub_Rebuild_State::update(
			array(
				'phase'  => $next_phase,
				'cursor' => 0,
			)
		);
		do_action( 'nvoos_docs_hub_rebuild_phase', $next_phase, NV_oOS_Docs_Hub_Rebuild_State::get() );
		return true;
	}

	/**
	 * Mark the rebuild as failed and stop scheduling ticks.
	 *
	 * Live cache is left untouched; staging is preserved so the
	 * resume path can pick up where the failure happened.
	 *
	 * @since 1.2.0
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function fail( $message ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic for rebuild failure
		error_log(
			sprintf(
				'[NV oOS Docs Hub] Rebuild failed (phase %s, cursor %d): %s',
				(string) NV_oOS_Docs_Hub_Rebuild_State::get()['phase'],
				(int) NV_oOS_Docs_Hub_Rebuild_State::get()['cursor'],
				(string) $message
			)
		);

		wp_clear_scheduled_hook( self::TICK_HOOK );
		$state                = NV_oOS_Docs_Hub_Rebuild_State::get();
		$errors               = (array) $state['errors'];
		$errors[]             = array(
			'phase'   => $state['phase'],
			'cursor'  => $state['cursor'],
			'message' => (string) $message,
			'time'    => time(),
		);
		$state['phase']       = NV_oOS_Docs_Hub_Rebuild_State::PHASE_FAILED;
		$state['errors']      = $errors;
		$state['last_error']  = (string) $message;
		$state['finished_at'] = time();
		NV_oOS_Docs_Hub_Rebuild_State::set( $state );

		do_action( 'nvoos_docs_hub_rebuild_phase', NV_oOS_Docs_Hub_Rebuild_State::PHASE_FAILED, $state );
	}

	/**
	 * Re-hydrate an indexer instance from the staged manifest so subsequent
	 * phases can call build_page_payload(), detect_broken_links(), etc.
	 *
	 * @since 1.2.0
	 *
	 * @return NV_oOS_Docs_Hub_Indexer
	 */
	private function load_indexer_from_staging() {
		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->use_staging( true );
		$manifest = $cache->get_manifest();
		$cache->use_staging( false );

		$indexer = new NV_oOS_Docs_Hub_Indexer();
		if ( is_array( $manifest ) ) {
			$indexer->set_slug_map( isset( $manifest['slug_map'] ) ? (array) $manifest['slug_map'] : array() );
			$indexer->set_tree( isset( $manifest['tree'] ) ? (array) $manifest['tree'] : array() );
			$indexer->set_broken_links( isset( $manifest['broken_links'] ) ? (array) $manifest['broken_links'] : array() );
			$indexer->set_content_hashes( isset( $manifest['content_hashes'] ) ? (array) $manifest['content_hashes'] : array() );
		}
		return $indexer;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
