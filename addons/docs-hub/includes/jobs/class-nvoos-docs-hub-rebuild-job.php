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
	 * Run a full documentation rebuild: scan, index, and cache.
	 *
	 * @since 1.0.0
	 *
	 * @return array { success: bool, pages: int, broken_links: int, duration_ms: int }
	 */
	public static function run() {
		$start = microtime( true );

		try {
			$scanner = new NV_oOS_Docs_Hub_Scanner();
			$entries = $scanner->scan();

			$indexer  = new NV_oOS_Docs_Hub_Indexer();
			$manifest = $indexer->build_manifest( $entries );

			$cache = new NV_oOS_Docs_Hub_Cache();

			// Clear stale data first.
			$cache->clear();

			// Store manifest.
			$cache->set_manifest( $manifest );

			// Store per-page payloads.
			$slug_map = $indexer->get_slug_map();
			foreach ( array_keys( $slug_map ) as $slug ) {
				$payload = $indexer->build_page_payload( $slug );
				if ( is_array( $payload ) ) {
					$cache->set_page( $slug, $payload );
				}
			}

			// Build and store search index.
			$search_index = self::build_search_index( $slug_map, $indexer );
			$cache->set_search_index( $search_index );

			$duration_ms  = (int) round( ( microtime( true ) - $start ) * 1000 );
			$broken_count = count( $indexer->get_broken_links() );

			return array(
				'success'     => true,
				'pages'       => count( $slug_map ),
				'broken_links' => $broken_count,
				'duration_ms' => $duration_ms,
			);
		} catch ( Exception $e ) {
			return array(
				'success'     => false,
				'pages'       => 0,
				'broken_links' => 0,
				'duration_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'error'       => $e->getMessage(),
			);
		}
	}

	/**
	 * Handle the upgrader_process_complete hook.
	 *
	 * Clears the cache when an NV oOS-related plugin is updated.
	 *
	 * @since 1.0.0
	 *
	 * @param object $upgrader_object WP_Upgrader instance.
	 * @param array  $options         Upgrade options array.
	 * @return void
	 */
	public static function handle_upgrade( $upgrader_object, $options ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! isset( $options['type'] ) || 'plugin' !== $options['type'] ) {
			return;
		}

		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->clear();
	}

	/**
	 * Handle activated_plugin / deactivated_plugin hooks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin Plugin file path.
	 * @return void
	 */
	public static function handle_plugin_change( $plugin ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->clear();
	}

	/**
	 * Build a lightweight search index from indexed pages.
	 *
	 * @since 1.0.0
	 *
	 * @param array                    $slug_map Slug map from indexer.
	 * @param NV_oOS_Docs_Hub_Indexer  $indexer  Indexer instance.
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
			$plain = preg_replace( '/[#*`\[\]_~>]/', '', $payload['markdown'] );
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
