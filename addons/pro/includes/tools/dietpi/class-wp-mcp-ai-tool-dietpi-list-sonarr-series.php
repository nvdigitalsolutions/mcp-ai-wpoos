<?php
/**
 * DietPi List Sonarr Series Tool
 *
 * @package WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_List_Sonarr_Series' ) ) {
	/**
	 * Lists TV series in Sonarr via the Sonarr API.
	 */
	class WP_MCP_AI_Tool_DietPi_List_Sonarr_Series extends WP_MCP_AI_Tool_DietPi_Base {
		/**
		 * {@inheritdoc}
		 */
		public function get_slug() {
			return 'dietpi_list_sonarr_series';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_name() {
			return __( 'List Sonarr Series', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_description() {
			return __( 'List all TV series in Sonarr with monitoring status, episode counts, and quality profile info.', 'mcp-ai-wpoos-pro' );
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(),
			);
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_required_capability() {
			return 'edit_posts';
		}

		/**
		 * {@inheritdoc}
		 */
		public function get_capability_flags() {
			return array_merge(
				parent::get_capability_flags(),
				array( 'read-only', 'cacheable' )
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
				return new WP_Error( 'wp_mcp_ai_sonarr_not_configured', __( 'Sonarr is not configured in DietPi settings.', 'mcp-ai-wpoos-pro' ) );
			}
			$series = $this->app_client()->get( 'sonarr', '/api/v3/series', array(), 20 );
			if ( is_wp_error( $series ) ) {
				return $series;
			}
			$out = array();
			if ( is_array( $series ) ) {
				foreach ( $series as $s ) {
					$out[] = array(
						'id'                  => isset( $s['id'] ) ? (int) $s['id'] : 0,
						'title'               => isset( $s['title'] ) ? sanitize_text_field( $s['title'] ) : '',
						'title_slug'          => isset( $s['titleSlug'] ) ? sanitize_text_field( $s['titleSlug'] ) : '',
						'tvdb_id'             => isset( $s['tvdbId'] ) ? (int) $s['tvdbId'] : 0,
						'year'                => isset( $s['year'] ) ? (int) $s['year'] : 0,
						'network'             => isset( $s['network'] ) ? sanitize_text_field( $s['network'] ) : '',
						'status'              => isset( $s['status'] ) ? sanitize_text_field( $s['status'] ) : '',
						'monitored'           => ! empty( $s['monitored'] ),
						'season_count'        => isset( $s['statistics']['seasonCount'] ) ? (int) $s['statistics']['seasonCount'] : 0,
						'episode_count'       => isset( $s['statistics']['totalEpisodeCount'] ) ? (int) $s['statistics']['totalEpisodeCount'] : 0,
						'episode_file_count'  => isset( $s['statistics']['episodeFileCount'] ) ? (int) $s['statistics']['episodeFileCount'] : 0,
						'path'                => isset( $s['path'] ) ? sanitize_text_field( $s['path'] ) : '',
					);
				}
			}
			return $this->success(
				sprintf(
					/* translators: %d: number of series */
					_n( 'Found %d series.', 'Found %d series.', count( $out ), 'mcp-ai-wpoos-pro' ),
					count( $out )
				),
				array( 'series' => $out )
			);
		}
	}
}
