<?php
/**
 * NV oOS Docs Hub — Rebuild Job
 *
 * Handles cron scheduling, on-demand rebuilds, and cache invalidation
 * when plugins are activated, deactivated, or updated.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuild job coordinator for the Docs Hub addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_Rebuild_Job {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'nvoos_docs_hub_rebuild_cron';

	/**
	 * Schedule the daily rebuild cron event if not already scheduled.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the rebuild cron event.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Run a full documentation rebuild SYNCHRONOUSLY: scan, index, and cache.
	 *
	 * Preserves the original single-request contract for tests and
	 * `wp nvoos-docs sync`. The async / chunked path used by the
	 * REST API and the admin UI lives in
	 * NV_oOS_Docs_Hub_Rebuild_Pipeline. This sync path now also does
	 * an atomic swap (build into staging, swap into live) so a partial
	 * failure no longer leaves the site with a blank index.
	 *
	 * @since 1.0.0
	 *
	 * @return array { success: bool, pages: int, broken_links: int, duration_ms: int }
	 * @throws \Exception When the staging cache cannot be promoted to the live cache.
	 */
	public static function run() {
		$start = microtime( true );

		try {
			$scanner = new NV_oOS_Docs_Hub_Scanner();
			$entries = $scanner->scan();

			// Honour the same aggregate file cap as the chunked pipeline so
			// the sync path cannot OOM on very large repos — and so suites
			// and hosts can bound the workload deterministically via the
			// nvoos_docs_hub_max_files_total filter.
			$max_files = (int) apply_filters(
				'nvoos_docs_hub_max_files_total',
				NV_oOS_Docs_Hub_Rebuild_State::DEFAULT_MAX_FILES_TOTAL
			);
			if ( count( $entries ) > $max_files ) {
				$entries = array_slice( $entries, 0, $max_files );
			}

			$indexer  = new NV_oOS_Docs_Hub_Indexer();
			$manifest = $indexer->build_manifest( $entries );

			$cache = new NV_oOS_Docs_Hub_Cache();

			// Build into staging first so a fault leaves the live cache intact.
			$cache->use_staging( true );
			$cache->clear_staging();
			$cache->set_manifest( $manifest );

			$slug_map = $indexer->get_slug_map();
			foreach ( array_keys( $slug_map ) as $slug ) {
				$payload = $indexer->build_page_payload( $slug );
				if ( is_array( $payload ) ) {
					$cache->set_page( $slug, $payload );
				}
			}

			$search_index = self::build_search_index( $slug_map, $indexer );
			$cache->set_search_index( $search_index );
			$cache->use_staging( false );

			// Atomic swap into the live cache. Fail loudly when the swap
			// cannot be performed (e.g. uploads directory is unwritable) —
			// previously the in-memory counts were reported as success even
			// though nothing was persisted, leaving the site with an empty
			// index and a misleading "Rebuilt N pages" message.
			if ( ! $cache->promote_staging() ) {
				throw new \Exception(
					__( 'Atomic swap failed: staging cache empty or unwritable.', 'nvoos-docs-hub' )
				);
			}

			$duration_ms  = (int) round( ( microtime( true ) - $start ) * 1000 );
			$broken_count = count( $indexer->get_broken_links() );

			// Mirror result onto rebuild state so the admin status panel
			// can display a "last run" summary even when sync mode was used.
			NV_oOS_Docs_Hub_Rebuild_State::set(
				array(
					'job_id'        => NV_oOS_Docs_Hub_Rebuild_State::generate_job_id(),
					'phase'         => NV_oOS_Docs_Hub_Rebuild_State::PHASE_DONE,
					'cursor'        => count( $slug_map ),
					'total'         => count( $slug_map ),
					'processed'     => count( $slug_map ),
					'errors'        => array(),
					'warnings'      => array(),
					'started_at'    => (int) $start,
					'updated_at'    => time(),
					'finished_at'   => time(),
					'phase_timings' => array( 'sync_ms' => $duration_ms ),
					'sync'          => true,
					'cap_hit'       => false,
					'last_error'    => '',
					'duration_ms'   => $duration_ms,
					'pages'         => count( $slug_map ),
					'broken_links'  => $broken_count,
				)
			);

			return array(
				'success'      => true,
				'pages'        => count( $slug_map ),
				'broken_links' => $broken_count,
				'duration_ms'  => $duration_ms,
			);
		} catch ( \Throwable $e ) {
			NV_oOS_Docs_Hub_Rebuild_State::update(
				array(
					'phase'      => NV_oOS_Docs_Hub_Rebuild_State::PHASE_FAILED,
					'last_error' => $e->getMessage(),
				)
			);
			return array(
				'success'      => false,
				'pages'        => 0,
				'broken_links' => 0,
				'duration_ms'  => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'error'        => $e->getMessage(),
			);
		}
	}

	/**
	 * Enqueue an asynchronous chunked rebuild.
	 *
	 * Returns immediately with the queued job summary; the work spans
	 * multiple WP-Cron ticks via NV_oOS_Docs_Hub_Rebuild_Pipeline.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public static function enqueue_async() {
		return NV_oOS_Docs_Hub_Rebuild_Pipeline::enqueue();
	}

	/**
	 * Cancel an in-flight async rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public static function cancel_async() {
		return NV_oOS_Docs_Hub_Rebuild_Pipeline::cancel();
	}

	/**
	 * Resume a stalled / failed async rebuild.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public static function resume_async() {
		return NV_oOS_Docs_Hub_Rebuild_Pipeline::resume();
	}

	/**
	 * Handle the upgrader_process_complete hook.
	 *
	 * Clears the cache and enqueues an async rebuild when an
	 * NV-oOS-related plugin was updated.
	 *
	 * @since 1.0.0
	 *
	 * @param object $upgrader_object WP_Upgrader instance.
	 * @param array  $options         Upgrade options array.
	 * @return void
	 */
	public static function handle_upgrade( $upgrader_object, $options ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP hook signature; only $options is inspected.
		if ( ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}

		// Only react to updates of NV-oOS-related plugins. When the hook
		// payload carries no plugin list (unknown updater shape), fall back
		// to the legacy behaviour of rebuilding for any plugin update.
		$plugins = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? $options['plugins'] : array();
		if ( ! empty( $plugins ) && ! self::is_docs_related_plugin( $plugins ) ) {
			return;
		}

		self::clear_and_enqueue();
	}

	/**
	 * Handle activated_plugin / deactivated_plugin hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin Plugin file path.
	 * @return void
	 */
	public static function handle_plugin_change( $plugin ) {
		if ( ! self::is_docs_related_plugin( array( (string) $plugin ) ) ) {
			return;
		}

		self::clear_and_enqueue();
	}

	/**
	 * Handle the base plugin's in-place updater notification.
	 *
	 * The base plugin replaces its own files without going through the
	 * WordPress Plugin_Upgrader flow, so upgrader_process_complete never
	 * fires for those updates. The updater emits wp_mcp_ai_plugin_updated
	 * instead, and this method applies the same clear + rebuild treatment.
	 *
	 * @since 0.4.1
	 *
	 * @param string $plugin Updated plugin's file path relative to the plugins directory.
	 * @return void
	 */
	public static function handle_plugin_update_notice( $plugin ) {
		if ( ! self::is_docs_related_plugin( array( (string) $plugin ) ) ) {
			return;
		}

		self::clear_and_enqueue();
	}

	/**
	 * Whether any of the given plugin basenames ships documentation that
	 * the Docs Hub indexes.
	 *
	 * @since 0.4.1
	 *
	 * @param array $plugins Plugin file paths relative to the plugins directory.
	 * @return bool
	 */
	public static function is_docs_related_plugin( $plugins ) {
		foreach ( $plugins as $plugin ) {
			$basename = (string) $plugin;
			if (
				false !== strpos( $basename, 'mcp-ai-wpoos' ) ||
				false !== strpos( $basename, 'nvoos-docs-hub' )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Invalidate the cache and start an async rebuild.
	 *
	 * Clearing alone was insufficient: the next visitor request would only
	 * auto-enqueue a rebuild when an admin logged in, so updates could
	 * leave the index stale indefinitely. Enqueueing here starts the
	 * chunked pipeline immediately. The remote file cache is preserved —
	 * a plugin update only changes local docs, so the rebuild should not
	 * re-fetch every remote Markdown file from GitHub.
	 *
	 * @since 0.4.1
	 *
	 * @return void
	 */
	public static function clear_and_enqueue() {
		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->clear( true );

		self::enqueue_async();
	}

	/**
	 * Build a lightweight search index from indexed pages.
	 *
	 * @since 1.0.0
	 *
	 * @param array                   $slug_map Slug map from indexer.
	 * @param NV_oOS_Docs_Hub_Indexer $indexer  Indexer instance.
	 * @return array
	 */
	private static function build_search_index( $slug_map, $indexer ) {
		$index = array();

		foreach ( $slug_map as $slug => $data ) {
			$payload = $indexer->build_page_payload( $slug );
			if ( ! is_array( $payload ) ) {
				continue;
			}

			// Strip markdown syntax for plain-text excerpt.
			$plain = preg_replace( '/[#*`\[\]_~>]/', '', $payload['content'] );
			$plain = preg_replace( '/\s+/', ' ', $plain );
			$plain = trim( $plain );

			$index[] = array(
				'slug'        => $slug,
				'title'       => $data['title'],
				'excerpt'     => substr( $plain, 0, 500 ),
				'plugin_name' => $data['plugin_name'],
				'source'      => $data['source'],
			);
		}

		return $index;
	}
}
