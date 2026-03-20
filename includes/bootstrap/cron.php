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
	}
}

// Register cron event → handler bindings.

if ( ! has_action( 'wp_mcp_ai_cleanup_gemini_files', 'wp_mcp_ai_cleanup_gemini_files_handler' ) ) {
	add_action( 'wp_mcp_ai_cleanup_gemini_files', 'wp_mcp_ai_cleanup_gemini_files_handler' );
}

if ( ! has_action( 'wp_mcp_ai_cleanup_openai_files', 'wp_mcp_ai_cleanup_openai_files_handler' ) ) {
	add_action( 'wp_mcp_ai_cleanup_openai_files', 'wp_mcp_ai_cleanup_openai_files_handler' );
}

if ( ! has_action( 'wp_mcp_ai_deep_research_background', 'wp_mcp_ai_deep_research_background_handler' ) ) {
	add_action( 'wp_mcp_ai_deep_research_background', 'wp_mcp_ai_deep_research_background_handler', 10, 1 );
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
