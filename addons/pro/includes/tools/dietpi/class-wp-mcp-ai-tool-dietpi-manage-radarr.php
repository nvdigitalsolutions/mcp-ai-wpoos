<?php
/**
 * DietPi Manage Radarr Tool
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Manage_Radarr' ) ) {
	class WP_MCP_AI_Tool_DietPi_Manage_Radarr extends WP_MCP_AI_Tool_DietPi_Base {
		/**
		 * {@inheritdoc}
		 */
		public function get_slug() {
			return 'dietpi_manage_radarr';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_name() {
			return __( 'Manage Radarr', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_description() {
			return __( 'Manage Radarr: trigger movie refresh, search for movies, check queue, view system status, or see upcoming releases calendar.', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'action' => array(
						'type'        => 'string',
						'description' => __( 'Management action.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'refresh_all', 'search_all', 'queue', 'system_status', 'calendar', 'diskspace' ),
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
			if ( ! $this->app_client()->is_app_configured( 'radarr' ) ) {
				return new WP_Error( 'wp_mcp_ai_radarr_not_configured', __( 'Radarr is not configured.', 'mcp-ai-wpoos-pro' ) );
			}
			$action = $this->sanitize_string( $arguments, 'action' );
			switch ( $action ) {
				case 'refresh_all':
					$result = $this->app_client()->post( 'radarr', '/api/v3/command', array( 'name' => 'RefreshMovie' ), 15 );
					return is_wp_error( $result ) ? $result : $this->success( __( 'Movie refresh triggered.', 'mcp-ai-wpoos-pro' ), $result );
				case 'search_all':
					$result = $this->app_client()->post( 'radarr', '/api/v3/command', array( 'name' => 'MoviesSearch' ), 15 );
					return is_wp_error( $result ) ? $result : $this->success( __( 'All movies search triggered.', 'mcp-ai-wpoos-pro' ), $result );
				case 'queue':
					$result = $this->app_client()->get( 'radarr', '/api/v3/queue', array(), 15 );
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
					$result = $this->app_client()->get( 'radarr', '/api/v3/system/status', array(), 10 );
					return is_wp_error( $result ) ? $result : $this->success( __( 'System status retrieved.', 'mcp-ai-wpoos-pro' ), $result );
				case 'calendar':
					$start  = gmdate( 'Y-m-d' );
					$end    = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
					$result = $this->app_client()->get( 'radarr', '/api/v3/calendar', array( 'start' => $start, 'end' => $end ), 15 );
					return is_wp_error( $result ) ? $result : $this->success( sprintf( __( 'Calendar: %d upcoming movies.', 'mcp-ai-wpoos-pro' ), count( $result ) ), array( 'calendar' => $result ) );
				case 'diskspace':
					$result = $this->app_client()->get( 'radarr', '/api/v3/diskspace', array(), 10 );
					return is_wp_error( $result ) ? $result : $this->success( __( 'Disk space retrieved.', 'mcp-ai-wpoos-pro' ), $result );
				default:
					return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
			}
		}
	}
}
