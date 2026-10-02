<?php
/**
 * Status Alert Poller — plugin-side alerting for fleet monitoring.
 *
 * The worker computes per-site states; this class pulls the fleet summary
 * on the five-minute tick and diffs it against the previous snapshot,
 * firing `wp_mcp_ai_site_status_event` for transitions (site.down,
 * site.recovered, site.degraded). Pull-diff keeps every plugin↔worker
 * path outbound-only — no inbound webhook surface is required on sites.
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
 * Status alert poller class.
 *
 * @since 1.1.93
 */
class WP_MCP_AI_Status_Alert_Poller {

	/**
	 * Option storing the last seen fleet summary (for diffing).
	 *
	 * @since 1.1.93
	 * @var string
	 */
	const OPTION_SNAPSHOT = 'wp_mcp_ai_status_alert_snapshot';

	/**
	 * Statuses considered alert-worthy when newly entered.
	 *
	 * @since 1.1.93
	 * @var array<string, string> Map of status => event name.
	 */
	const ALERT_EVENTS = array(
		'major_outage'   => 'site.down',
		'partial_outage' => 'site.degraded',
	);

	/**
	 * Register hooks.
	 *
	 * Runs only when the heartbeat feature is enabled (same opt-in gate).
	 *
	 * @since 1.1.93
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_mcp_ai_five_minute_tick_completed', array( __CLASS__, 'maybe_poll' ), 20, 0 );
	}

	/**
	 * Poll the worker summary and diff against the previous snapshot.
	 *
	 * @since 1.1.93
	 *
	 * @return bool True when a poll was attempted.
	 */
	public static function maybe_poll() {
		if ( ! WP_MCP_AI_Status_Heartbeat::is_enabled() ) {
			return false;
		}

		$summary = WP_MCP_AI_Media_Worker_Config::request( '/api/status/summary', 'GET', array(), 5 );
		if ( is_wp_error( $summary ) ) {
			return false;
		}

		$previous = get_option( self::OPTION_SNAPSHOT, array() );
		$previous = is_array( $previous ) ? $previous : array();

		$sites   = isset( $summary['sites'] ) && is_array( $summary['sites'] ) ? $summary['sites'] : array();
		$current = array();

		foreach ( $sites as $site ) {
			if ( ! is_array( $site ) || empty( $site['slug'] ) ) {
				continue;
			}

			$slug   = sanitize_key( $site['slug'] );
			$status = isset( $site['status'] ) ? sanitize_key( $site['status'] ) : 'unknown';

			$current[ $slug ] = $status;

			$old_status = isset( $previous[ $slug ] ) ? $previous[ $slug ] : 'unknown';

			self::maybe_dispatch( $slug, $old_status, $status, $site );
		}

		update_option( self::OPTION_SNAPSHOT, $current, false );

		return true;
	}

	/**
	 * Dispatch a transition event when the site's fleet state changed.
	 *
	 * @since 1.1.93
	 *
	 * @param string $slug       Site slug.
	 * @param string $old_status Previous fleet status.
	 * @param string $new_status Current fleet status.
	 * @param array  $site       Site summary entry (allowlisted fields only).
	 * @return void
	 */
	private static function maybe_dispatch( $slug, $old_status, $new_status, array $site ) {
		if ( $old_status === $new_status ) {
			return;
		}

		$event = '';

		if ( isset( self::ALERT_EVENTS[ $new_status ] ) ) {
			$event = self::ALERT_EVENTS[ $new_status ];
		} elseif ( 'operational' === $new_status && in_array( $old_status, array( 'major_outage', 'partial_outage', 'at_risk' ), true ) ) {
			$event = 'site.recovered';
		}

		if ( '' === $event ) {
			return;
		}

		/**
		 * Fires when a connected site's fleet status transitions.
		 *
		 * @since 1.1.93
		 *
		 * @param string $slug  Site slug.
		 * @param string $event Event name (site.down, site.recovered, site.degraded).
		 * @param array  $data  Allowlisted summary entry for the site.
		 */
		do_action( 'wp_mcp_ai_site_status_event', $slug, $event, $site );
	}
}
