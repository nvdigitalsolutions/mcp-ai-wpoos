<?php
/**
 * Tool returning per-site uptime history from the Media Worker.
 *
 * The worker's status module buckets per-site transition history into daily
 * uptime percentages (Statuspage rule: only major/partial outages count as
 * downtime). This tool exposes that history to chat agents.
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
 * Site uptime tool class.
 *
 * @since 1.1.93
 */
class WP_MCP_AI_Tool_Get_Site_Uptime implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_site_uptime';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Site Uptime', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns daily uptime percentages for a site monitored by the Media Worker (1–90 days).', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reviewing a connected site\u2019s uptime history, verifying SLA-style availability claims, or post-incident analysis.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Current status only; use get_fleet_status. Uptime for THIS site; use the local /status history endpoint.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_fleet_status', 'get_site_health' ),
			'notes'           => __( 'slug is required (as reported by get_fleet_status). days defaults to 30 (max 90). Returns a WP_Error for unknown slugs.', 'mcp-ai-wpoos' ),
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
					'description' => __( 'Site slug as reported by the fleet summary.', 'mcp-ai-wpoos' ),
				),
				'days' => array(
					'type'        => 'integer',
					'description' => __( 'Number of days of history (1–90). Default 30.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 90,
				),
			),
			'required'   => array( 'slug' ),
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
		$days = isset( $arguments['days'] ) ? absint( $arguments['days'] ) : 30;
		$days = max( 1, min( 90, $days ) );

		if ( '' === $slug ) {
			return new WP_Error( 'wp_mcp_ai_missing_slug', __( 'The slug argument is required. Use get_fleet_status to list monitored sites.', 'mcp-ai-wpoos' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to inspect site uptime.', 'mcp-ai-wpoos' ) );
		}

		if ( ! WP_MCP_AI_Media_Worker_Config::is_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_worker_not_configured',
				__( 'Site uptime is unavailable: the Media Worker URL is not configured.', 'mcp-ai-wpoos' )
			);
		}

		$endpoint = sprintf( '/api/status/history/%s?days=%d', rawurlencode( $slug ), $days );
		$history  = WP_MCP_AI_Media_Worker_Config::request( $endpoint, 'GET', array(), 5 );

		if ( is_wp_error( $history ) ) {
			return $history;
		}

		$by_day = isset( $history['history'] ) && is_array( $history['history'] ) ? $history['history'] : array();
		$clean  = array();

		foreach ( $by_day as $date => $pct ) {
			$date = sanitize_text_field( (string) $date );
			if ( '' === $date || ! is_numeric( $pct ) ) {
				continue;
			}
			$clean[ $date ] = (float) $pct;
		}

		ksort( $clean );

		$overall = isset( $history['overall_uptime'] ) && is_numeric( $history['overall_uptime'] )
			? (float) $history['overall_uptime']
			: null;

		$message = null !== $overall
			? sprintf(
				/* translators: 1: site slug, 2: overall uptime percentage, 3: number of days */
				__( 'Site "%1$s": %2$s%% uptime over the last %3$d day(s).', 'mcp-ai-wpoos' ),
				$slug,
				number_format_i18n( $overall, 2 ),
				$days
			)
			: sprintf(
				/* translators: %s: site slug */
				__( 'No uptime history recorded for site "%s" yet.', 'mcp-ai-wpoos' ),
				$slug
			);

		return array(
			'slug'           => $slug,
			'days'           => $days,
			'overall_uptime' => $overall,
			'history'        => $clean,
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
