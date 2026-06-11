<?php
/**
 * DietPi Dashboard Summary Tool
 *
 * Generate a comprehensive one-shot overview of the entire DietPi system:
 * system health, service status, app queue summaries, storage, and recent activity.
 * Designed as the "first tool to call" for a quick system snapshot.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Dashboard_Summary' ) ) {

	/**
	 * Dashboard summary tool.
	 */
	class WP_MCP_AI_Tool_DietPi_Dashboard_Summary extends WP_MCP_AI_Tool_DietPi_Base {

		public function get_slug()        { return 'dietpi_dashboard_summary'; }
		public function get_name()        { return __( 'DietPi Dashboard Summary', 'mcp-ai-wpoos-pro' ); }
		public function get_description() {
			return __( 'Generate a comprehensive dashboard summary of the entire DietPi system. This is the recommended first tool to call when checking system status. Returns: system health (CPU/RAM/disk/temp), all service states, Transmission torrent count, Sonarr/Radarr queue summaries, storage overview, and any active warnings. Designed for quick at-a-glance monitoring.', 'mcp-ai-wpoos-pro' );
		}

		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'include_queues' => array(
						'type'        => 'boolean',
						'description' => __( 'Include detailed Sonarr/Radarr download queue information. Default: true.', 'mcp-ai-wpoos-pro' ),
						'default'     => true,
					),
				),
			);
		}

		public function get_required_capability() { return 'edit_posts'; }

		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'read-only', 'cacheable', 'may-timeout' ) );
		}

		public function execute( array $arguments = array(), array $context = array() ) {
			$include_queues = $this->sanitize_bool( $arguments, 'include_queues', true );
			$summary        = array();
			$warnings       = array();

			// ── 1. System Stats ──
			$stats = $this->ssh()->system_stats();
			if ( ! is_wp_error( $stats ) ) {
				$summary['system'] = $stats;
				// Check disk.
				if ( isset( $stats['disk'] ) && preg_match( '/(\d+)%/', $stats['disk'], $m ) ) {
					if ( (int) $m[1] > 85 ) {
						$warnings[] = array( 'type' => 'disk_space_low', 'detail' => $stats['disk'] );
					}
				}
			}

			// ── 2. Pi info (model + throttling) ──
			$pi_info = $this->ssh()->raspberry_pi_info();
			if ( ! is_wp_error( $pi_info ) ) {
				$summary['hardware'] = $pi_info;
				if ( isset( $pi_info['throttled'] ) && $pi_info['throttled'] !== 'throttled=0x0' ) {
					$warnings[] = array( 'type' => 'pi_throttled', 'detail' => $pi_info['throttled'] );
				}
			}

			// ── 3. Services ──
			$svc_result = $this->ssh()->dietpi_services( 'status', '' );
			if ( ! is_wp_error( $svc_result ) ) {
				$managed        = WP_MCP_AI_DietPi_Service_Catalogue::get_managed_apps();
				$service_states = array();

				foreach ( $managed as $app_slug ) {
					$catalogue = WP_MCP_AI_DietPi_Service_Catalogue::get( $app_slug );
					$app_name  = $catalogue ? $catalogue['name'] : $app_slug;
					$running   = ( false !== strpos( $svc_result['stdout'], $app_slug ) && false !== strpos( $svc_result['stdout'], 'active' ) );
					$service_states[ $app_slug ] = array(
						'name'    => $app_name,
						'running' => $running,
					);
					if ( ! $running ) {
						$warnings[] = array( 'type' => 'service_down', 'detail' => $app_name );
					}
				}

				$summary['services'] = $service_states;
			}

			// ── 4. Transmission ──
			if ( $this->app_client()->is_app_configured( 'transmission' ) ) {
				$tx = $this->app_client()->transmission_rpc(
					'torrent-get',
					array( 'fields' => array( 'id', 'name', 'status', 'percentDone', 'rateDownload', 'rateUpload' ) )
				);
				if ( ! is_wp_error( $tx ) && isset( $tx['torrents'] ) ) {
					$torrents = $tx['torrents'];
					$active   = 0; $paused = 0; $seeding = 0; $dl_speed = 0; $ul_speed = 0; $errors = 0;
					foreach ( $torrents as $t ) {
						$status = (int) $t['status'];
						if ( 4 === $status ) { $active++; }
						elseif ( 0 === $status ) { $paused++; }
						elseif ( 6 === $status ) { $seeding++; }
						elseif ( 16 === $status ) { $errors++; }
						else { $active++; }
						$dl_speed += (int) $t['rateDownload'];
						$ul_speed += (int) $t['rateUpload'];
					}
					$summary['transmission'] = array(
						'total'          => count( $torrents ),
						'active'         => $active,
						'seeding'        => $seeding,
						'paused'         => $paused,
						'errors'         => $errors,
						'download_speed' => $dl_speed,
						'upload_speed'   => $ul_speed,
					);
					if ( $errors > 0 ) {
						$warnings[] = array( 'type' => 'transmission_errors', 'detail' => $errors . ' torrent(s) with errors' );
					}
				}
			}

			// ── 5. Sonarr ──
			if ( $include_queues && $this->app_client()->is_app_configured( 'sonarr' ) ) {
				$series_list = $this->app_client()->get( 'sonarr', '/api/v3/series', array(), 15 );
				$queue       = $this->app_client()->get( 'sonarr', '/api/v3/queue', array(), 10 );

				$series_count = is_array( $series_list ) ? count( $series_list ) : '?';
				$monitored    = 0;
				if ( is_array( $series_list ) ) {
					foreach ( $series_list as $s ) {
						if ( ! empty( $s['monitored'] ) ) { $monitored++; }
					}
				}
				$queue_count  = is_array( $queue ) ? count( $queue ) : '?';

				$summary['sonarr'] = array(
					'total_series' => $series_count,
					'monitored'    => $monitored,
					'queue_items'  => $queue_count,
				);
			}

			// ── 6. Radarr ──
			if ( $include_queues && $this->app_client()->is_app_configured( 'radarr' ) ) {
				$movie_list = $this->app_client()->get( 'radarr', '/api/v3/movie', array(), 15 );
				$queue      = $this->app_client()->get( 'radarr', '/api/v3/queue', array(), 10 );

				$movie_count  = is_array( $movie_list ) ? count( $movie_list ) : '?';
				$monitored    = 0;
				$missing      = 0;
				if ( is_array( $movie_list ) ) {
					foreach ( $movie_list as $m ) {
						if ( ! empty( $m['monitored'] ) ) { $monitored++; }
						if ( empty( $m['hasFile'] ) && ! empty( $m['monitored'] ) && 'released' === ( isset( $m['status'] ) ? $m['status'] : '' ) ) { $missing++; }
					}
				}
				$queue_count = is_array( $queue ) ? count( $queue ) : '?';

				$summary['radarr'] = array(
					'total_movies'  => $movie_count,
					'monitored'     => $monitored,
					'missing_files' => $missing,
					'queue_items'   => $queue_count,
				);
			}

			// ── 7. Storage ──
			$disk_result = $this->ssh()->exec( 'df -h / /mnt/dietpi_userdata 2>/dev/null | tail -n +2', 10 );
			if ( ! is_wp_error( $disk_result ) ) {
				$summary['storage'] = array();
				foreach ( explode( "\n", trim( $disk_result['stdout'] ) ) as $line ) {
					$line = trim( $line );
					if ( '' === $line ) { continue; }
					$cols = preg_split( '/\s+/', $line, 6 );
					if ( count( $cols ) >= 6 ) {
						$summary['storage'][] = array(
							'path'          => $cols[5],
							'size'          => $cols[1],
							'used'          => $cols[2],
							'available'     => $cols[3],
							'use_percent'   => $cols[4],
						);
					}
				}
			}

			// ── 8. Uptime ──
			$uptime_result = $this->ssh()->exec( 'uptime -p | sed "s/up //"', 5 );
			if ( ! is_wp_error( $uptime_result ) ) {
				$summary['uptime'] = trim( $uptime_result['stdout'] );
			}

			// ── 9. Determine overall health ──
			$health  = empty( $warnings ) ? 'healthy' : 'warning';
			$message = empty( $warnings )
				? __( 'All systems healthy.', 'mcp-ai-wpoos-pro' )
				: sprintf( _n( '%d warning detected.', '%d warnings detected.', count( $warnings ), 'mcp-ai-wpoos-pro' ), count( $warnings ) );

			return $this->success(
				$message,
				array(
					'health'   => $health,
					'summary'  => $summary,
					'warnings' => $warnings,
				)
			);
		}
	}
}
