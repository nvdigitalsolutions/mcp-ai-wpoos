<?php
/**
 * Interface: Service Status Source
 *
 * Contract for subsystems that report health status to the service status
 * registry. Implementations register themselves through the
 * `wp_mcp_ai_service_status_sources` filter.
 *
 * Every source reports a health check result with at minimum a status,
 * message, and timestamp. The status registry aggregates results across
 * all registered sources for the public status page and REST API.
 *
 * Sources MUST NOT throw on check_health(); if they cannot probe their
 * backing service they should return a degraded or major_outage status
 * with a descriptive message.
 *
 * @package   WP_MCP_AI
 * @since     1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for service status source adapters.
 *
 * @since 1.2.0
 */
interface Interface_WP_MCP_AI_Service_Status_Source {

	/**
	 * Unique slug identifying this service component.
	 *
	 * Used as the array key when sources are registered via the
	 * `wp_mcp_ai_service_status_sources` filter. Must be a non-empty,
	 * lowercase, snake-case identifier (e.g. `openai_api`, `tool_registry`).
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Human-readable component name.
	 *
	 * Displayed on the public status page and admin dashboard.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Grouping category for the component.
	 *
	 * Used to organise components on the status page. Suggested values:
	 * - `ai_providers` — AI API providers (OpenAI, Gemini, etc.)
	 * - `infrastructure` — Internal services (queue, cache, tool registry)
	 * - `integrations` — Third-party integrations
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_group();

	/**
	 * Perform a health check and return the current status.
	 *
	 * Implementations should perform a lightweight probe (e.g. ping an API
	 * endpoint, count available tools, check queue depth) and return a
	 * structured result. Long-running checks should use a timeout.
	 *
	 * The returned array must contain at minimum `status`, `message`, and
	 * `checked_at`. Additional fields such as `latency_ms` are optional.
	 *
	 * Valid status values:
	 * - `operational`          — Component is functioning normally.
	 * - `degraded_performance` — Component is slow or experiencing minor issues.
	 * - `partial_outage`       — Some users or features are affected.
	 * - `major_outage`         — Component is completely unavailable.
	 * - `under_maintenance`    — Scheduled maintenance in progress.
	 *
	 * @since 1.2.0
	 *
	 * @return array{
	 *     status: string,
	 *     message: string,
	 *     checked_at: int,
	 *     latency_ms?: int|null
	 * }
	 */
	public function check_health();

	/**
	 * Whether this component should appear on the public status page.
	 *
	 * Components that expose internal infrastructure details (e.g. queue
	 * depth, cache hit rates) should return false here. The admin dashboard
	 * always shows all components regardless of this flag.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_public();
}
