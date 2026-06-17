<?php
/**
 * Interface: Cron-Status Job Source
 *
 * Contract for subsystems that contribute jobs to the chat-UI Tasks drawer
 * (and the `/mcp-ai/v1/cron-status` REST endpoint).
 *
 * Implementations register themselves through the
 * `wp_mcp_ai_cron_status_job_sources` filter — see Phase 1 of
 * `docs/features/chat/cron-status-tasks-drawer-plan.md`.
 *
 * Every source returns a list of *normalized* records keyed by `job_id`.
 * The normalized record shape is documented in
 * `WP_MCP_AI_Cron_Status_Service::normalize_source_record()`.
 *
 * Sources MUST NOT throw on `get_jobs()`; if they cannot read their backing
 * store they should log internally and return an empty array. The service
 * also defensively wraps every call in a try/catch so one misbehaving source
 * cannot break the chat surface.
 *
 * @package   WP_MCP_AI
 * @since     1.9.2
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for cron-status job-source adapters.
 *
 * @since 1.9.2
 */
interface Interface_WP_MCP_AI_Cron_Status_Job_Source {

	/**
	 * Unique slug identifying this job source.
	 *
	 * Used as the array key when sources are registered via the
	 * `wp_mcp_ai_cron_status_job_sources` filter and surfaced on each
	 * normalized record as the `source` field. Must be a non-empty,
	 * lowercase, snake-case identifier (e.g. `transcript_mining`).
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Return the source's current jobs, normalized for the chat-UI drawer.
	 *
	 * The returned array MUST be keyed by `job_id` and each record MUST
	 * contain at least `job_id`, `kind`, and `status` — additional fields
	 * are normalized and defaulted by the service.
	 *
	 * Implementations SHOULD respect the caller's `$user_id` /
	 * `$assistant_id` scope when their backing store supports it; the
	 * service performs a defensive second pass regardless.
	 *
	 * @param int             $user_id      Requesting user (0 = current).
	 * @param int|string|null $assistant_id Optional assistant scope.
	 * @return array<string,array<string,mixed>> Map of job_id => record.
	 */
	public function get_jobs( $user_id = 0, $assistant_id = null );
}
