<?php
/**
 * NV oOS Docs Hub — WP-CLI Command
 *
 * Provides WP-CLI commands for managing the Docs Hub index.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for the Docs Hub addon.
 *
 * @since 1.0.0
 */
class NV_oOS_Docs_Hub_CLI extends WP_CLI_Command {

	/**
	 * Run a full documentation rebuild.
	 *
	 * ## OPTIONS
	 *
	 * [--strict]
	 * : Exit with a non-zero code if any broken links are found.
	 *
	 * ## EXAMPLES
	 *
	 *   # Basic rebuild
	 *   wp nvoos-docs sync
	 *
	 *   # Fail on broken links
	 *   wp nvoos-docs sync --strict
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function sync( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		WP_CLI::log( 'Starting documentation rebuild...' );

		$result = NV_oOS_Docs_Hub_Rebuild_Job::run();

		if ( ! $result['success'] ) {
			WP_CLI::error( 'Rebuild failed: ' . ( $result['error'] ?? 'Unknown error' ) );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Rebuilt in %dms. Pages: %d. Broken links: %d.',
				$result['duration_ms'],
				$result['pages'],
				$result['broken_links']
			)
		);

		$strict = ! empty( $assoc_args['strict'] );
		if ( $strict && $result['broken_links'] > 0 ) {
			WP_CLI::error(
				sprintf( '%d broken link(s) found and --strict is set.', $result['broken_links'] )
			);
		}
	}

	/**
	 * Run a chunked rebuild that may span multiple WP-Cron ticks.
	 *
	 * ## OPTIONS
	 *
	 * [--async]
	 * : Enqueue the chunked rebuild and return immediately. Default for this command.
	 *
	 * [--sync]
	 * : Run inline (equivalent to `wp nvoos-docs sync`). Useful for tests / cron hosts.
	 *
	 * [--resume]
	 * : Resume a previously failed or canceled rebuild from its last cursor.
	 *
	 * [--cancel]
	 * : Cancel the currently in-flight rebuild.
	 *
	 * [--reset]
	 * : Force-reset the rebuild state before enqueueing. Useful when a
	 * previous rebuild is stuck and the auto-stale detection hasn't
	 * triggered yet.
	 *
	 * [--strict]
	 * : With --sync, exit non-zero if any broken links are found.
	 *
	 * ## EXAMPLES
	 *
	 *   wp nvoos-docs rebuild --async
	 *   wp nvoos-docs rebuild --sync
	 *   wp nvoos-docs rebuild --resume
	 *   wp nvoos-docs rebuild --reset      # force-reset stuck state then rebuild
	 *
	 * @subcommand rebuild
	 * @since 1.2.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function rebuild( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
		if ( ! empty( $assoc_args['cancel'] ) ) {
			$state = NV_oOS_Docs_Hub_Rebuild_Job::cancel_async();
			WP_CLI::success( sprintf( 'Rebuild canceled at phase %s (cursor %d).', $state['phase'], (int) $state['cursor'] ) );
			return;
		}

		if ( ! empty( $assoc_args['reset'] ) ) {
			$this->reset( $args, $assoc_args );
		}

		if ( ! empty( $assoc_args['resume'] ) ) {
			$summary = NV_oOS_Docs_Hub_Rebuild_Job::resume_async();
			WP_CLI::success( sprintf( 'Rebuild resumed at phase %s.', $summary['phase'] ) );
			return;
		}

		if ( ! empty( $assoc_args['sync'] ) ) {
			$this->sync( $args, $assoc_args );
			return;
		}

		// Default: async.
		$summary = NV_oOS_Docs_Hub_Rebuild_Job::enqueue_async();
		WP_CLI::success(
			sprintf(
				'Rebuild queued (job_id=%s, phase=%s, total=%d).',
				$summary['job_id'],
				$summary['phase'],
				(int) $summary['total']
			)
		);
		WP_CLI::log( 'Run `wp nvoos-docs status` to monitor progress.' );
	}

	/**
	 * Force-reset a stuck rebuild state, optionally clearing caches.
	 *
	 * When a chunked rebuild stalls (cron down, server restart, etc.)
	 * the state is left in a non-terminal phase and every subsequent
	 * `enqueue()` call returns immediately without starting work.  This
	 * command resets the state back to idle so the next rebuild can
	 * proceed.
	 *
	 * ## OPTIONS
	 *
	 * [--hard]
	 * : Also clear the live documentation cache (manifest + page files).
	 *
	 * [--staging]
	 * : Also clear any partial staging artefacts left by a mid-rebuild
	 * crash. On by default when the state phase is not `idle` or `done`.
	 * Pass `--no-staging` to keep them.
	 *
	 * ## EXAMPLES
	 *
	 *   # Reset a stuck state, keep the existing cache
	 *   wp nvoos-docs reset
	 *
	 *   # Full nuclear reset — state + staging + live cache
	 *   wp nvoos-docs reset --hard
	 *
	 * @since 1.2.1
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function reset( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$state  = NV_oOS_Docs_Hub_Rebuild_State::get();
		$phase  = $state['phase'];
		$job_id = $state['job_id'];

		WP_CLI::log(
			sprintf(
				'Current rebuild state: phase=%s, cursor=%d/%d, job_id=%s, last_error=%s',
				$phase,
				(int) $state['cursor'],
				(int) $state['total'],
				$job_id ?: '—',
				$state['last_error'] ?: '—'
			)
		);

		$hard           = \WP_CLI\Utils\get_flag_value( $assoc_args, 'hard', false );
		$clear_staging  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'staging', true );
		$keep_staging   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'no-staging', false );

		if ( $keep_staging ) {
			$clear_staging = false;
		}

		// Cancel any in-flight cron ticks.
		wp_clear_scheduled_hook( NV_oOS_Docs_Hub_Rebuild_Pipeline::TICK_HOOK );
		NV_oOS_Docs_Hub_Rebuild_State::reset();
		WP_CLI::log( 'Rebuild state reset to idle.' );

		if ( $clear_staging || $hard ) {
			$cache = new NV_oOS_Docs_Hub_Cache();
			$cache->clear_staging();
			WP_CLI::log( 'Staging cache cleared.' );
		}

		if ( $hard ) {
			$cache = new NV_oOS_Docs_Hub_Cache();
			$cache->clear();
			WP_CLI::log( 'Live documentation cache cleared.' );
		}

		WP_CLI::success( 'Reset complete. Run `wp nvoos-docs rebuild` to start a fresh rebuild.' );
	}

	/**
	 * Clear the documentation cache.
	 *
	 * ## EXAMPLES
	 *
	 *   wp nvoos-docs clear
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function clear( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$cache = new NV_oOS_Docs_Hub_Cache();
		$cache->clear();
		WP_CLI::success( 'Documentation cache cleared.' );
	}

	/**
	 * Show the current documentation index status.
	 *
	 * ## EXAMPLES
	 *
	 *   wp nvoos-docs status
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$cache      = new NV_oOS_Docs_Hub_Cache();
		$last_built = $cache->get_last_built();
		$manifest   = $cache->get_manifest();

		$total_pages  = is_array( $manifest ) ? ( $manifest['total_pages'] ?? 0 ) : 0;
		$broken_links = is_array( $manifest ) ? count( $manifest['broken_links'] ?? array() ) : 0;
		$rebuild      = NV_oOS_Docs_Hub_Rebuild_State::to_summary();

		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'Key'   => 'Last Built',
					'Value' => $last_built > 0 ? gmdate( 'Y-m-d H:i:s', $last_built ) . ' UTC' : 'Never',
				),
				array(
					'Key'   => 'Total Pages',
					'Value' => $total_pages,
				),
				array(
					'Key'   => 'Broken Links',
					'Value' => $broken_links,
				),
				array(
					'Key'   => 'Version',
					'Value' => NVOOS_DOCS_HUB_VERSION,
				),
				array(
					'Key'   => 'Rebuild Phase',
					'Value' => $rebuild['phase'],
				),
				array(
					'Key'   => 'Rebuild Progress',
					'Value' => sprintf( '%d / %d (%d%%)', $rebuild['processed'], $rebuild['total'], $rebuild['percentage'] ),
				),
				array(
					'Key'   => 'Last Error',
					'Value' => $rebuild['last_error'] ? $rebuild['last_error'] : '—',
				),
			),
			array( 'Key', 'Value' )
		);
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'nvoos-docs', 'NV_oOS_Docs_Hub_CLI' );
}
