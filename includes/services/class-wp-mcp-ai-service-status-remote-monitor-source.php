<?php
/**
 * Remote Monitor Service Status Source.
 *
 * Bridges the Media Worker's fleet status module into the local service
 * status registry. check_health() pulls the worker's summary and reports
 * THIS site's fleet-visible state (or the fleet overall when the site has
 * no heartbeat record yet). Registered only when the heartbeat feature is
 * enabled, so sites without a worker keep today's behavior unchanged.
 *
 * Contract (Interface_WP_MCP_AI_Service_Status_Source): must never throw.
 * An unreachable worker reports degraded_performance with a descriptive
 * message — the site itself is fine, the monitoring link is not.
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
 * Remote monitor status source class.
 *
 * @since 1.1.93
 */
class WP_MCP_AI_Service_Status_Remote_Monitor_Source implements Interface_WP_MCP_AI_Service_Status_Source {

	/**
	 * Statuses this source surfaces from the worker (shared taxonomy).
	 *
	 * @since 1.1.93
	 * @var array<string>
	 */
	const VALID_STATUSES = array(
		'operational',
		'under_maintenance',
		'degraded_performance',
		'partial_outage',
		'major_outage',
		'at_risk',
	);

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.1.93
	 */
	public function get_slug() {
		return 'remote_monitor';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.1.93
	 */
	public function get_name() {
		return __( 'Fleet Monitor', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.1.93
	 */
	public function get_group() {
		return 'remote_sites';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @since 1.1.93
	 */
	public function is_public() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Pulls this site's fleet-visible status from the worker summary.
	 *
	 * @since 1.1.93
	 *
	 * @return array Health check result (status, message, checked_at, latency_ms).
	 */
	public function check_health() {
		$started = microtime( true );

		if ( ! WP_MCP_AI_Media_Worker_Config::is_configured() ) {
			return array(
				'status'     => 'degraded_performance',
				'message'    => __( 'Fleet monitoring is not connected (Media Worker URL not configured).', 'mcp-ai-wpoos' ),
				'checked_at' => time(),
				'latency_ms' => null,
			);
		}

		$summary = WP_MCP_AI_Media_Worker_Config::request( '/api/status/summary', 'GET', array(), 5 );

		$latency_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $summary ) ) {
			return array(
				'status'     => 'degraded_performance',
				'message'    => sprintf(
					/* translators: %s: error message from the worker request */
					__( 'Fleet monitor unreachable: %s', 'mcp-ai-wpoos' ),
					$summary->get_error_message()
				),
				'checked_at' => time(),
				'latency_ms' => $latency_ms,
			);
		}

		$sites   = isset( $summary['sites'] ) && is_array( $summary['sites'] ) ? $summary['sites'] : array();
		$entry   = $this->find_own_entry( $sites );
		$status  = 'unknown';
		$message = __( 'Fleet status available.', 'mcp-ai-wpoos' );

		if ( null !== $entry ) {
			$status = isset( $entry['status'] ) && in_array( $entry['status'], self::VALID_STATUSES, true )
				? $entry['status']
				: 'unknown';

			$status_labels = array(
				'operational'          => __( 'Operational', 'mcp-ai-wpoos' ),
				'under_maintenance'    => __( 'Under maintenance', 'mcp-ai-wpoos' ),
				'degraded_performance' => __( 'Degraded', 'mcp-ai-wpoos' ),
				'partial_outage'       => __( 'Partial outage', 'mcp-ai-wpoos' ),
				'major_outage'         => __( 'Major outage', 'mcp-ai-wpoos' ),
				'at_risk'              => __( 'At risk', 'mcp-ai-wpoos' ),
				'unknown'              => __( 'Unknown', 'mcp-ai-wpoos' ),
			);

			$label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;

			$message = sprintf(
				/* translators: 1: fleet-visible status label, 2: site slug in the worker */
				__( 'Fleet monitor reports this site as: %1$s (slug "%2$s").', 'mcp-ai-wpoos' ),
				$label,
				sanitize_key( isset( $entry['slug'] ) ? $entry['slug'] : '' )
			);
		} elseif ( isset( $summary['overall_status'] ) && is_string( $summary['overall_status'] ) ) {
			$status  = in_array( $summary['overall_status'], self::VALID_STATUSES, true ) ? $summary['overall_status'] : 'unknown';
			$message = __( 'No heartbeat record for this site yet; showing the fleet overall status.', 'mcp-ai-wpoos' );
		}

		// Normalize the worker's internal at_risk state into the public
		// taxonomy the plugin status page understands.
		if ( 'at_risk' === $status ) {
			$status = 'degraded_performance';
		}

		// Fire the service-status-changed seam the Pro incident auto-create
		// machinery already listens to (with its own cooldown).
		$previous = get_transient( 'wp_mcp_ai_remote_monitor_last_status' );
		if ( false !== $previous && $previous !== $status ) {
			do_action( 'wp_mcp_ai_service_status_changed', $this->get_slug(), $previous, $status, array() );
		}
		set_transient( 'wp_mcp_ai_remote_monitor_last_status', $status, DAY_IN_SECONDS );

		return array(
			'status'     => $status,
			'message'    => $message,
			'checked_at' => time(),
			'latency_ms' => $latency_ms,
		);
	}

	/**
	 * Find this site's entry in the worker summary by matching site_url.
	 *
	 * @since 1.1.93
	 *
	 * @param array $sites Summary sites array.
	 * @return array|null Matching entry, or null when not found.
	 */
	private function find_own_entry( $sites ) {
		$own_url = trailingslashit( home_url() );

		foreach ( $sites as $site ) {
			if ( ! is_array( $site ) ) {
				continue;
			}

			$site_url = isset( $site['site_url'] ) && is_string( $site['site_url'] ) ? $site['site_url'] : '';
			if ( '' === $site_url ) {
				continue;
			}

			if ( trailingslashit( $site_url ) === $own_url ) {
				return $site;
			}
		}

		return null;
	}
}
