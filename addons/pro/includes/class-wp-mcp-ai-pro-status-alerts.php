<?php
/**
 * Pro Status Alerts — fleet event → incident automation.
 *
 * Bridges the base pull-diff alert poller (wp_mcp_ai_site_status_event)
 * into the Pro incident system: confirmed remote-site outages auto-create
 * incidents via the existing WP_MCP_AI_Incident_CPT machinery (which
 * carries its own cooldown), and recoveries auto-resolve the matching
 * open incidents with a timeline entry.
 *
 * Opt-in via the `wp_mcp_ai_pro_status_auto_incidents` option (default
 * off) — fleet automation must be an explicit operator choice.
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
 * Pro status alerts class.
 *
 * @since 1.1.93
 */
class WP_MCP_AI_Pro_Status_Alerts {

	/**
	 * Option flag enabling incident automation for fleet events.
	 *
	 * @since 1.1.93
	 * @var string
	 */
	const OPTION_ENABLED = 'wp_mcp_ai_pro_status_auto_incidents';

	/**
	 * Register hooks.
	 *
	 * @since 1.1.93
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_mcp_ai_site_status_event', array( __CLASS__, 'handle_event' ), 10, 3 );
	}

	/**
	 * Handle a fleet status transition event.
	 *
	 * @since 1.1.93
	 *
	 * @param string $slug  Site slug.
	 * @param string $event Event name (site.down, site.recovered, site.degraded).
	 * @param array  $data  Allowlisted summary entry for the site.
	 * @return void
	 */
	public static function handle_event( $slug, $event, $data ) {
		if ( ! get_option( self::OPTION_ENABLED, false ) ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Incident_CPT' ) ) {
			return;
		}

		$slug = sanitize_key( (string) $slug );

		if ( 'site.down' === $event ) {
			// Reuse the existing auto-create machinery (major_outage only,
			// per-component cooldown built in). The component slug is the
			// remote site's slug so incidents are distinguishable per site.
			WP_MCP_AI_Incident_CPT::maybe_auto_create_incident(
				'site:' . $slug,
				'',
				'major_outage',
				array( 'message' => isset( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '' )
			);
			return;
		}

		if ( 'site.recovered' === $event ) {
			self::resolve_open_incidents( 'site:' . $slug, $slug );
		}
	}

	/**
	 * Resolve open incidents whose affected services include the site.
	 *
	 * @since 1.1.93
	 *
	 * @param string $component Component slug used at creation time.
	 * @param string $site_slug Site slug for the timeline message.
	 * @return void
	 */
	private static function resolve_open_incidents( $component, $site_slug ) {
		$incidents = WP_MCP_AI_Incident_CPT::get_active_incidents( 20 );

		foreach ( $incidents as $incident ) {
			$services = get_post_meta( $incident->ID, '_mcp_ai_incident_services', true );
			if ( ! is_array( $services ) || ! in_array( $component, $services, true ) ) {
				continue;
			}

			WP_MCP_AI_Incident_CPT::transition_phase(
				$incident->ID,
				WP_MCP_AI_Incident_CPT::PHASE_RESOLVED,
				sprintf(
					/* translators: %s: site slug that recovered */
					__( 'Fleet monitor confirmed site "%s" recovered; incident auto-resolved.', 'mcp-ai-wpoos-pro' ),
					$site_slug
				)
			);
		}
	}
}

// Bootstrap (self-instantiating, matching the Pro admin class convention).
WP_MCP_AI_Pro_Status_Alerts::init();
