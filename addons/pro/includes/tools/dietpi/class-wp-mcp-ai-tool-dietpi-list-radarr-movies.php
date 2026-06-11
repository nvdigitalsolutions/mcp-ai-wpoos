<?php
/**
 * DietPi List Radarr Movies Tool
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_List_Radarr_Movies' ) ) {
	class WP_MCP_AI_Tool_DietPi_List_Radarr_Movies extends WP_MCP_AI_Tool_DietPi_Base {
		public function get_slug() { return 'dietpi_list_radarr_movies'; }
		public function get_name() { return __( 'List Radarr Movies', 'mcp-ai-wpoos-pro' ); }
		public function get_description() { return __( 'List all movies in Radarr with status, quality profile, availability, and file info.', 'mcp-ai-wpoos-pro' ); }
		public function get_parameters_schema() { return array( 'type' => 'object', 'properties' => array() ); }
		public function get_required_capability() { return 'edit_posts'; }
		public function get_capability_flags() { return array_merge( parent::get_capability_flags(), array( 'read-only', 'cacheable' ) ); }
		public function execute( array $arguments = array(), array $context = array() ) {
			if ( ! $this->app_client()->is_app_configured( 'radarr' ) ) { return new WP_Error( 'wp_mcp_ai_radarr_not_configured', __( 'Radarr is not configured.', 'mcp-ai-wpoos-pro' ) ); }
			$movies = $this->app_client()->get( 'radarr', '/api/v3/movie', array(), 20 );
			if ( is_wp_error( $movies ) ) { return $movies; }
			$out = array();
			if ( is_array( $movies ) ) {
				foreach ( $movies as $m ) {
					$out[] = array(
						'id' => isset( $m['id'] ) ? (int) $m['id'] : 0, 'title' => isset( $m['title'] ) ? sanitize_text_field( $m['title'] ) : '',
						'tmdb_id' => isset( $m['tmdbId'] ) ? (int) $m['tmdbId'] : 0, 'imdb_id' => isset( $m['imdbId'] ) ? sanitize_text_field( $m['imdbId'] ) : '',
						'year' => isset( $m['year'] ) ? (int) $m['year'] : 0, 'monitored' => ! empty( $m['monitored'] ),
						'status' => isset( $m['status'] ) ? sanitize_text_field( $m['status'] ) : '',
						'has_file' => isset( $m['hasFile'] ) ? (bool) $m['hasFile'] : false,
						'path' => isset( $m['path'] ) ? sanitize_text_field( $m['path'] ) : '',
					);
				}
			}
			return $this->success( sprintf( _n( 'Found %d movie.', 'Found %d movies.', count( $out ), 'mcp-ai-wpoos-pro' ), count( $out ) ), array( 'movies' => $out ) );
		}
	}
}
