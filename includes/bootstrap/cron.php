<?php
/**
 * Cron Job Schedules and Handlers
 *
 * Registers cron schedules, ensures cleanup cron jobs are scheduled on every
 * plugin load (handles upgrades where the activation hook did not fire), and
 * provides the handler callbacks for all scheduled events.
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

if ( ! has_action( 'plugins_loaded', 'wp_mcp_ai_ensure_cleanup_cron_scheduled' ) ) {
	add_action( 'plugins_loaded', 'wp_mcp_ai_ensure_cleanup_cron_scheduled', 25 );
}

if ( ! function_exists( 'wp_mcp_ai_ensure_cleanup_cron_scheduled' ) ) {
	/**
	 * Ensure cleanup cron jobs are scheduled on every plugin load.
	 *
	 * This ensures existing installations get the cron jobs when they upgrade,
	 * not just on fresh activations.
	 */
	function wp_mcp_ai_ensure_cleanup_cron_scheduled() {
		// Schedule Gemini file cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_gemini_files' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_gemini_files' );
		}

		// Schedule OpenAI file cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_openai_files' ) ) {
			wp_schedule_event( time(), 'daily', 'wp_mcp_ai_cleanup_openai_files' );
		}

		// Schedule hourly cleanup of plugin-owned temp files (F-FS-01).
		if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_temp_files' ) ) {
			wp_schedule_event( time(), 'hourly', 'wp_mcp_ai_cleanup_temp_files' );
		}

		// Schedule daily model catalog discovery cron job (April 2026 refresh).
		// Deliberately offset the first run by an hour to keep the activation/upgrade
		// page load light — the daily file-cleanup crons above run on essentially
		// idempotent local data, but discovery makes outbound HTTP calls to provider
		// APIs and we do not want that fanning out at the same moment dozens of
		// other plugin-load actions are firing.
		if ( ! wp_next_scheduled( 'wp_mcp_ai_model_catalog_discovery' ) ) {
			/**
			 * Filter the WP-Cron interval used for model catalog discovery.
			 *
			 * Accepts any registered schedule slug (e.g., 'hourly', 'twicedaily',
			 * 'daily', 'weekly').
			 *
			 * @since 2026.04
			 *
			 * @param string $interval Default 'daily'.
			 */
			$interval = apply_filters( 'wp_mcp_ai_model_discovery_interval', 'daily' );
			$interval = is_string( $interval ) && '' !== $interval ? $interval : 'daily';
			wp_schedule_event( time() + HOUR_IN_SECONDS, $interval, 'wp_mcp_ai_model_catalog_discovery' );
		}
	}
}

// Register cron event → handler bindings.

if ( ! has_action( 'wp_mcp_ai_cleanup_gemini_files', 'wp_mcp_ai_cleanup_gemini_files_handler' ) ) {
	add_action( 'wp_mcp_ai_cleanup_gemini_files', 'wp_mcp_ai_cleanup_gemini_files_handler' );
}

if ( ! has_action( 'wp_mcp_ai_cleanup_openai_files', 'wp_mcp_ai_cleanup_openai_files_handler' ) ) {
	add_action( 'wp_mcp_ai_cleanup_openai_files', 'wp_mcp_ai_cleanup_openai_files_handler' );
}

if ( ! has_action( 'wp_mcp_ai_cleanup_temp_files', 'wp_mcp_ai_cleanup_temp_files_handler' ) ) {
	add_action( 'wp_mcp_ai_cleanup_temp_files', 'wp_mcp_ai_cleanup_temp_files_handler' );
}

if ( ! has_action( 'wp_mcp_ai_deep_research_background', 'wp_mcp_ai_deep_research_background_handler' ) ) {
	add_action( 'wp_mcp_ai_deep_research_background', 'wp_mcp_ai_deep_research_background_handler', 10, 1 );
}

if ( ! has_action( 'wp_mcp_ai_model_catalog_discovery', 'wp_mcp_ai_model_catalog_discovery_handler' ) ) {
	add_action( 'wp_mcp_ai_model_catalog_discovery', 'wp_mcp_ai_model_catalog_discovery_handler' );
}

if ( ! has_action( 'wp_mcp_ai_process_delegation', 'wp_mcp_ai_process_delegation_handler' ) ) {
	add_action( 'wp_mcp_ai_process_delegation', 'wp_mcp_ai_process_delegation_handler', 10, 1 );
}

if ( ! function_exists( 'wp_mcp_ai_model_catalog_discovery_handler' ) ) {
	/**
	 * Cron job handler for the daily model catalog discovery service.
	 *
	 * Delegates to {@see WP_MCP_AI_Model_Discovery_Service::cron_handler()}, which
	 * obeys the wp_mcp_ai_model_discovery_enabled filter and persists a diff
	 * payload (additions / sunsets / price changes) into the
	 * wp_mcp_ai_model_catalog_suggestions option for admin review.
	 *
	 * @return void
	 */
	function wp_mcp_ai_model_catalog_discovery_handler() {
		if ( ! class_exists( 'WP_MCP_AI_Model_Discovery_Service' ) ) {
			return;
		}
		WP_MCP_AI_Model_Discovery_Service::cron_handler();
	}
}

if ( ! function_exists( 'wp_mcp_ai_cleanup_gemini_files_handler' ) ) {
	/**
	 * Cron job handler for cleaning up old Gemini files.
	 *
	 * Runs daily to remove files older than 24 hours from Gemini File API
	 * and clear associated cache entries.
	 */
	function wp_mcp_ai_cleanup_gemini_files_handler() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';

		$file_service = new WP_MCP_AI_Gemini_File_Service();

		// Cleanup files older than 24 hours.
		$result = $file_service->cleanup_old_files( 24 * HOUR_IN_SECONDS );

		WP_MCP_AI_Logger::log_event(
			'gemini_file_cleanup_cron',
			'Daily Gemini file cleanup completed.',
			$result
		);
	}
}

if ( ! function_exists( 'wp_mcp_ai_cleanup_openai_files_handler' ) ) {
	/**
	 * Cron job handler for cleaning up old OpenAI files.
	 *
	 * Runs daily to remove files older than 24 hours from OpenAI File API
	 * and clear associated cache entries.
	 */
	function wp_mcp_ai_cleanup_openai_files_handler() {
		require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-openai-file-service.php';

		$file_service = new WP_MCP_AI_OpenAI_File_Service();

		// Cleanup files older than 24 hours.
		$result = $file_service->cleanup_old_files( 24 * HOUR_IN_SECONDS );

		WP_MCP_AI_Logger::log_event(
			'openai_file_cleanup_cron',
			'Daily OpenAI file cleanup completed.',
			$result
		);
	}
}

if ( ! function_exists( 'wp_mcp_ai_deep_research_background_handler' ) ) {
	/**
	 * Cron job handler for background deep research execution.
	 *
	 * Runs when a deep research job is scheduled in background mode.
	 *
	 * @param array $args Cron job arguments containing job parameters.
	 */
	function wp_mcp_ai_deep_research_background_handler( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		// Extract job parameters.
		$job_id          = isset( $args['job_id'] ) ? sanitize_text_field( $args['job_id'] ) : '';
		$topic           = isset( $args['topic'] ) ? sanitize_text_field( $args['topic'] ) : '';
		$depth           = isset( $args['depth'] ) ? sanitize_key( $args['depth'] ) : 'standard';
		$focus_areas     = isset( $args['focus_areas'] ) && is_array( $args['focus_areas'] ) ? $args['focus_areas'] : array();
		$include_sources = isset( $args['include_sources'] ) ? (bool) $args['include_sources'] : true;
		$user_id         = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : 0;

		if ( empty( $job_id ) || empty( $topic ) ) {
			WP_MCP_AI_Logger::log_error(
				'Deep research background job missing required parameters.',
				array( 'args' => $args )
			);
			return;
		}

		// Execute the research via the tool's static method.
		if ( class_exists( 'WP_MCP_AI_Tool_Deep_Research' ) ) {
			WP_MCP_AI_Tool_Deep_Research::execute_background_research(
				$job_id,
				$topic,
				$depth,
				$focus_areas,
				$include_sources,
				$user_id
			);
		}
	}
}

// Initialize async tool executor during plugin bootstrap (registers its cron hook handler).
if ( ! function_exists( 'wp_mcp_ai_process_delegation_handler' ) ) {
	/**
	 * Cron job handler for processing pending agent delegations.
	 *
	 * Delegates to
	 * {@see WP_MCP_AI_Agent_Communication_Service::process_pending_delegation()}.
	 *
	 * @since 1.1.0
	 *
	 * @param string $delegation_id The delegation identifier.
	 * @return void
	 */
	function wp_mcp_ai_process_delegation_handler( $delegation_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Communication_Service' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-agent-communication-service.php';
		}
		WP_MCP_AI_Agent_Communication_Service::process_pending_delegation( $delegation_id );
	}
}

if ( ! has_action( 'wp_mcp_ai_bootstrapped', 'wp_mcp_ai_init_async_executor' ) ) {
	add_action( 'wp_mcp_ai_bootstrapped', 'wp_mcp_ai_init_async_executor', 5 );
}

if ( ! function_exists( 'wp_mcp_ai_init_async_executor' ) ) {
	/**
	 * Initialize the async tool executor.
	 *
	 * Called during wp_mcp_ai_bootstrapped action to ensure the executor
	 * registers its cron hook handler before any async jobs might run.
	 */
	function wp_mcp_ai_init_async_executor() {
		wp_mcp_ai_get_async_tool_executor();
	}
}

// Initialize transcript mining job during plugin bootstrap so its cron tick
// handler is always registered — including during WP-Cron requests where the
// REST controller (the only other loader) is never instantiated.
if ( ! has_action( 'wp_mcp_ai_bootstrapped', 'wp_mcp_ai_init_transcript_mining_job' ) ) {
	add_action( 'wp_mcp_ai_bootstrapped', 'wp_mcp_ai_init_transcript_mining_job', 6 );
}

if ( ! function_exists( 'wp_mcp_ai_init_transcript_mining_job' ) ) {
	/**
	 * Load the transcript mining job service and register its cron tick handler.
	 *
	 * Called during wp_mcp_ai_bootstrapped so the handler is available on every
	 * plugin load — normal page loads, REST API requests, and WP-Cron runs alike.
	 * Without this, WP-Cron fires the wp_mcp_ai_transcript_mining_tick action but
	 * finds no listener because the REST controller (the only other code path that
	 * requires the class file) is not instantiated during cron execution.
	 */
	function wp_mcp_ai_init_transcript_mining_job() {
		if ( ! class_exists( 'WP_MCP_AI_Transcript_Mining_Job' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-transcript-mining-job.php';
		}
	}
}

if ( ! function_exists( 'wp_mcp_ai_cleanup_temp_files_handler' ) ) {
	/**
	 * Cron job handler: purge stale files from the plugin-owned temp directory.
	 *
	 * Runs hourly. Removes any file under `wp-mcp-ai-temp/` that is older than
	 * one hour. This acts as a safety-net for the rare cases where a document-
	 * generation tool exits abnormally before it can call `@unlink()` on its
	 * temp files.
	 *
	 * @since 1.2.0
	 */
	function wp_mcp_ai_cleanup_temp_files_handler() {
		if ( ! function_exists( 'wp_mcp_ai_get_temp_dir' ) ) {
			return;
		}

		$temp_dir = wp_mcp_ai_get_temp_dir();
		if ( is_wp_error( $temp_dir ) || ! is_dir( $temp_dir ) ) {
			return;
		}

		$cutoff  = time() - HOUR_IN_SECONDS;
		$deleted = 0;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: glob() may emit warnings on restricted hosts; failure is handled below with is_array() check.
		$files = @glob( trailingslashit( $temp_dir ) . '*' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			if ( ! is_file( $file ) ) {
				continue;
			}

			// Never remove the .htaccess guard file.
			if ( '.htaccess' === basename( $file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: filemtime() may emit warnings for files deleted between glob() and stat; failure is handled with false !== $mtime check.
			$mtime = @filemtime( $file );
			if ( false !== $mtime && $mtime < $cutoff ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Silenced intentionally: unlink() may emit warnings on permission errors; best-effort cleanup, failure is non-critical.
				if ( @unlink( $file ) ) {
					++$deleted;
				}
			}
		}

		if ( $deleted > 0 ) {
			WP_MCP_AI_Logger::log_event(
				'temp_file_cleanup_cron',
				sprintf(
					/* translators: %d: number of files deleted */
					__( 'Temp file cleanup: removed %d stale file(s).', 'mcp-ai-wpoos' ),
					$deleted
				),
				array(
					'temp_dir' => $temp_dir,
					'deleted'  => $deleted,
				)
			);
		}
	}
}
