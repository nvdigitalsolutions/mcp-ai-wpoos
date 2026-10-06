<?php
/**
 * Tool returning the fleet status summary from the Media Worker.
 *
 * The worker's status module aggregates heartbeats from every connected
 * site; this tool exposes that summary (optionally filtered to one site)
 * to chat agents with the canonical success/WP_Error envelope.
 *
 * @package   WP_MCP_AI
 * @since     1.1.93
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fleet status tool class.
 *
 * @since 1.1.93
 */
class WP_MCP_AI_Tool_Get_Fleet_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_fleet_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Fleet Status', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the Media Worker fleet status summary — the monitoring view of every site connected to the worker (optionally filtered to a single site slug).', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Checking whether the sites connected to the Media Worker are up, degraded, or down; triaging a cross-site incident.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Local diagnostics for THIS site only; use get_site_health, get_environment_status, or the /status command. Uptime history; use get_site_uptime.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_site_uptime', 'get_site_health', 'get_environment_status' ),
			'notes'           => __( 'Optional slug argument filters to one site. Requires an enrolled Media Worker (heartbeat feature enabled); returns a WP_Error when not configured.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'slug' => array(
					'type'        => 'string',
					'description' => __( 'Optional site slug to filter the summary to a single site.', 'mcp-ai-wpoos' ),
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Two-gate rule: sanitize at entry.
		$slug = isset( $arguments['slug'] ) ? sanitize_key( $arguments['slug'] ) : '';

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to inspect fleet status.', 'mcp-ai-wpoos' ) );
		}

		if ( ! WP_MCP_AI_Media_Worker_Config::is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_worker_not_configured',
				__( 'Fleet status is unavailable: the Media Worker URL is not configured. Configure it in Settings → Media Worker and enable the status heartbeat.', 'mcp-ai-wpoos' )
			);
		}

		$summary = WP_MCP_AI_Media_Worker_Config::request( '/api/status/summary', 'GET', array(), 5 );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$sites_raw = isset( $summary['sites'] ) && is_array( $summary['sites'] ) ? $summary['sites'] : array();
		$sites     = array();

		foreach ( $sites_raw as $site ) {
			if ( ! is_array( $site ) || empty( $site['slug'] ) ) {
				continue;
			}

			$site_slug = sanitize_key( $site['slug'] );
			if ( '' !== $slug && $slug !== $site_slug ) {
				continue;
			}

			$sites[] = array(
				'slug'              => $site_slug,
				'status'            => sanitize_key( isset( $site['status'] ) ? $site['status'] : 'unknown' ),
				'message'           => sanitize_text_field( isset( $site['message'] ) ? $site['message'] : '' ),
				'site_url'          => isset( $site['site_url'] ) ? esc_url_raw( $site['site_url'] ) : '',
				'heartbeat_age_s'   => isset( $site['heartbeat_age_s'] ) ? absint( $site['heartbeat_age_s'] ) : null,
				'latency_ms'        => isset( $site['latency_ms'] ) ? absint( $site['latency_ms'] ) : null,
				'last_heartbeat_at' => isset( $site['last_heartbeat_at'] ) ? absint( $site['last_heartbeat_at'] ) : null,
			);
		}

		$overall = isset( $summary['overall_status'] ) ? sanitize_key( $summary['overall_status'] ) : 'unknown';

		$message = sprintf(
			/* translators: 1: number of sites in the result, 2: overall fleet status */
			__( 'Fleet status: %1$d site(s) — overall %2$s.', 'mcp-ai-wpoos' ),
			count( $sites ),
			$overall
		);

		return array(
			'overall_status' => $overall,
			'generated_at'   => isset( $summary['generated_at'] ) ? absint( $summary['generated_at'] ) : null,
			'count'          => count( $sites ),
			'sites'          => $sites,
			'message'        => $message,
			'summary'        => $message,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'requires-capability',
		);
	}
}
