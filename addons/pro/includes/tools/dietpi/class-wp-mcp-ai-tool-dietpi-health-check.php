<?php
/**
 * DietPi Health Check Tool
 *
 * Comprehensive health scan across all services.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Health_Check' ) ) {

	/**
	 * DietPi health check tool.
	 */
	class WP_MCP_AI_Tool_DietPi_Health_Check extends WP_MCP_AI_Tool_DietPi_Base {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_health_check';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DietPi Health Check', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Run a comprehensive health check on the DietPi system: verify all managed services are running, check disk space and temperature, inspect download queues in Transmission/Sonarr/Radarr, and flag any warnings.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				// Empty stdClass encodes as `{}`; an empty PHP array would encode
				// as `[]`, which strict providers (DeepSeek) reject.
				'properties' => new stdClass(),
			);
		}

		/** {@inheritdoc} */
		public function get_required_capability() {
			return 'edit_posts';
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge(
				parent::get_capability_flags(),
				array( 'read-only', 'cacheable', 'may-timeout' )
			);
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$report = array(
				'status'   => 'healthy',
				'warnings' => array(),
				'details'  => array(),
			);
			// System stats.
			$stats = $this->ssh()->system_stats();
			if ( ! is_wp_error( $stats ) ) {
				$report['details']['system'] = $stats;
				// Check disk.
				if ( isset( $stats['disk'] ) && preg_match( '/(\d+)%/', $stats['disk'], $m ) ) {
					if ( (int) $m[1] > 90 ) {
						$report['warnings'][] = 'disk_usage_high';
						$report['status']     = 'warning';
					}
				}
			}
			// Pi throttling.
			$pi_info = $this->ssh()->raspberry_pi_info();
			if ( ! is_wp_error( $pi_info ) && isset( $pi_info['throttled'] ) && 'throttled=0x0' !== $pi_info['throttled'] ) {
				$report['warnings'][] = 'pi_throttling_detected';
				$report['status']     = 'warning';
			}
			// Services.
			$svc = $this->ssh()->dietpi_services( 'status', '' );
			if ( ! is_wp_error( $svc ) ) {
				$report['details']['services_raw'] = $svc['stdout'];
				$managed                           = WP_MCP_AI_DietPi_Service_Catalogue::get_managed_apps();
				foreach ( $managed as $app ) {
					if ( false === strpos( $svc['stdout'], $app ) || false === strpos( $svc['stdout'], 'active' ) ) {
						$report['warnings'][] = $app . '_not_running';
						$report['status']     = 'warning';
					}
				}
			}
			// Check configured apps.
			$app_names = array( 'transmission', 'jackett', 'sonarr', 'radarr', 'plex', 'jellyfin' );
			foreach ( $app_names as $app ) {
				if ( $this->app_client()->is_app_configured( $app ) ) {
					$endpoint = ( 'sonarr' === $app || 'radarr' === $app )
						? '/api/v3/system/status'
						: ( 'jackett' === $app ? '/api/v2.0/indexers' : '/status/sessions' );
					$result   = $this->app_client()->get( $app, $endpoint, array(), 10 );
					if ( is_wp_error( $result ) ) {
						$report['details'][ $app ] = 'unreachable';
						$report['warnings'][]      = $app . '_unreachable';
						$report['status']          = 'warning';
					} else {
						$report['details'][ $app ] = 'reachable';
					}
				} else {
					$report['details'][ $app ] = 'not_configured';
				}
			}
			$message = 'healthy' === $report['status']
				? __( 'All systems healthy.', 'mcp-ai-wpoos-pro' )
				: __( 'Some issues detected. See warnings for details.', 'mcp-ai-wpoos-pro' );
			return $this->success( $message, $report );
		}
	}
}
