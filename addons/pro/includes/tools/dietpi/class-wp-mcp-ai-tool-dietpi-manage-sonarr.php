<?php
/**
 * DietPi Manage Sonarr Tool — Trigger refresh, rescan, search, monitor/unmonitor.
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Manage_Sonarr' ) ) {
	class WP_MCP_AI_Tool_DietPi_Manage_Sonarr extends WP_MCP_AI_Tool_DietPi_Base {
		/**
		 * {@inheritdoc}
		 */
		public function get_slug() {
			return 'dietpi_manage_sonarr';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_name() {
			return __( 'Manage Sonarr', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_description() {
			return __( 'Manage Sonarr: trigger series refresh, rescan episodes, search for missing episodes, monitor/unmonitor series, check download queue, and view system status.', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'action'    => array(
						'type'        => 'string',
						'description' => __( 'Management action.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'refresh_all', 'rescan_series', 'search_missing', 'monitor', 'unmonitor', 'queue', 'system_status', 'calendar' ),
					),
					'series_id' => array(
						'type'        => 'integer',
						'description' => __( 'Series ID (required for rescan_series, search_missing, monitor, unmonitor).', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'action' ),
			);
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_capability_flags() {
			return array_merge(
				parent::get_capability_flags(),
				array( 'write', 'state-changing' )
			);
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context including user_id.
		 * @return array|WP_Error Tool results or error.
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			if ( ! $this->app_client()->is_app_configured( 'sonarr' ) ) {
				return new WP_Error( 'wp_mcp_ai_sonarr_not_configured', __( 'Sonarr is not configured.', 'mcp-ai-wpoos-pro' ) );
			}
			$action = $this->sanitize_string( $arguments, 'action' );
			switch ( $action ) {
				case 'refresh_all':
					$result = $this->app_client()->post( 'sonarr', '/api/v3/command', array( 'name' => 'RefreshSeries' ), 15 );
					return is_wp_error( $result ) ? $result : $this->success( __( 'Series refresh triggered.', 'mcp-ai-wpoos-pro' ), $result );
				case 'rescan_series':
					$sid = $this->sanitize_int( $arguments, 'series_id' );
					if ( $sid <= 0 ) {
						return new WP_Error( 'wp_mcp_ai_missing_series_id', __( 'series_id is required for this action.', 'mcp-ai-wpoos-pro' ) );
					}
					$result = $this->app_client()->post( 'sonarr', '/api/v3/command', array( 'name' => 'RescanSeries', 'seriesId' => $sid ), 15 );
					return is_wp_error( $result ) ? $result : $this->success( __( 'Series rescan triggered.', 'mcp-ai-wpoos-pro' ), $result );
				case 'search_missing':
					$sid = $this->sanitize_int( $arguments, 'series_id' );
					if ( $sid <= 0 ) {
						return new WP_Error( 'wp_mcp_ai_missing_series_id', __( 'series_id is required.', 'mcp-ai-wpoos-pro' ) );
					}
					$result = $this->app_client()->post( 'sonarr', '/api/v3/command', array( 'name' => 'MissingEpisodeSearch', 'seriesId' => $sid ), 15 );
					return is_wp_error( $result ) ? $result : $this->success( __( 'Missing episode search triggered.', 'mcp-ai-wpoos-pro' ), $result );
				case 'monitor':
				case 'unmonitor':
					$sid = $this->sanitize_int( $arguments, 'series_id' );
					if ( $sid <= 0 ) {
						return new WP_Error( 'wp_mcp_ai_missing_series_id', __( 'series_id is required.', 'mcp-ai-wpoos-pro' ) );
					}
					$result = $this->app_client()->put( 'sonarr', '/api/v3/series/' . $sid, array( 'id' => $sid, 'monitored' => 'monitor' === $action ), 15 );
					return is_wp_error( $result ) ? $result : $this->success( sprintf( __( 'Series %s.', 'mcp-ai-wpoos-pro' ), 'monitor' === $action ? __( 'monitored', 'mcp-ai-wpoos-pro' ) : __( 'unmonitored', 'mcp-ai-wpoos-pro' ) ), $result );
				case 'queue':
					$result = $this->app_client()->get( 'sonarr', '/api/v3/queue', array(), 15 );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					return $this->success(
						sprintf(
							_n( '%d item in queue.', '%d items in queue.', count( $result ), 'mcp-ai-wpoos-pro' ),
							count( $result )
						),
						array( 'queue' => $result )
					);
				case 'system_status':
					$result = $this->app_client()->get( 'sonarr', '/api/v3/system/status', array(), 10 );
					return is_wp_error( $result ) ? $result : $this->success( __( 'System status retrieved.', 'mcp-ai-wpoos-pro' ), $result );
				case 'calendar':
					$start  = gmdate( 'Y-m-d' );
					$end    = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
					$result = $this->app_client()->get( 'sonarr', '/api/v3/calendar', array( 'start' => $start, 'end' => $end ), 15 );
					return is_wp_error( $result ) ? $result : $this->success( sprintf( __( 'Calendar: %d upcoming episodes.', 'mcp-ai-wpoos-pro' ), count( $result ) ), array( 'calendar' => $result ) );
				default:
					return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
			}
		}
	}
}
