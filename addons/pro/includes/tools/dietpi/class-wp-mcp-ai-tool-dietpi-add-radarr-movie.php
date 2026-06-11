<?php
/**
 * DietPi Add Radarr Movie Tool
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Add_Radarr_Movie' ) ) {
	class WP_MCP_AI_Tool_DietPi_Add_Radarr_Movie extends WP_MCP_AI_Tool_DietPi_Base {
		public function get_slug() { return 'dietpi_add_radarr_movie'; }
		public function get_name() { return __( 'Add Radarr Movie', 'mcp-ai-wpoos-pro' ); }
		public function get_description() { return __( 'Add a new movie to Radarr. Supports lookup by TMDB ID, IMDb ID, or title search.', 'mcp-ai-wpoos-pro' ); }
		public function get_parameters_schema() {
			return array(
				'type' => 'object',
				'properties' => array(
					'lookup_type' => array( 'type' => 'string', 'description' => __( 'How to identify the movie.', 'mcp-ai-wpoos-pro' ), 'enum' => array( 'tmdb', 'imdb', 'title' ), 'default' => 'title' ),
					'tmdb_id' => array( 'type' => 'integer', 'description' => __( 'TMDB ID.', 'mcp-ai-wpoos-pro' ) ),
					'imdb_id' => array( 'type' => 'string', 'description' => __( 'IMDb ID (e.g. tt1234567).', 'mcp-ai-wpoos-pro' ) ),
					'title' => array( 'type' => 'string', 'description' => __( 'Movie title to search (for title lookup).', 'mcp-ai-wpoos-pro' ) ),
					'quality_profile_id' => array( 'type' => 'integer', 'description' => __( 'Quality profile ID. Default: 1.', 'mcp-ai-wpoos-pro' ), 'default' => 1 ),
					'root_folder_path' => array( 'type' => 'string', 'description' => __( 'Root folder path.', 'mcp-ai-wpoos-pro' ) ),
					'monitored' => array( 'type' => 'boolean', 'description' => __( 'Monitor the movie. Default: true.', 'mcp-ai-wpoos-pro' ), 'default' => true ),
					'search_for_movie' => array( 'type' => 'boolean', 'description' => __( 'Immediately search for the movie. Default: true.', 'mcp-ai-wpoos-pro' ), 'default' => true ),
				),
			);
		}
		public function get_capability_flags() { return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'reversible' ) ); }
		public function execute( array $arguments = array(), array $context = array() ) {
			if ( ! $this->app_client()->is_app_configured( 'radarr' ) ) { return new WP_Error( 'wp_mcp_ai_radarr_not_configured', __( 'Radarr is not configured.', 'mcp-ai-wpoos-pro' ) ); }
			$lookup_type = $this->sanitize_string( $arguments, 'lookup_type', 'title' );
			$lookup = null; $term = '';
			switch ( $lookup_type ) {
				case 'tmdb': $id = $this->sanitize_int( $arguments, 'tmdb_id' ); if ( $id <= 0 ) return new WP_Error( 'wp_mcp_ai_missing_tmdb', __( 'tmdb_id is required.', 'mcp-ai-wpoos-pro' ) ); $lookup = $this->app_client()->get( 'radarr', '/api/v3/movie/lookup/tmdb', array( 'tmdbId' => $id ), 15 ); $term = 'tmdb:' . $id; break;
				case 'imdb': $imdb = $this->sanitize_string( $arguments, 'imdb_id' ); if ( '' === $imdb ) return new WP_Error( 'wp_mcp_ai_missing_imdb', __( 'imdb_id is required.', 'mcp-ai-wpoos-pro' ) ); $lookup = $this->app_client()->get( 'radarr', '/api/v3/movie/lookup/imdb', array( 'imdbId' => $imdb ), 15 ); $term = 'imdb:' . $imdb; break;
				default: $title = $this->sanitize_string( $arguments, 'title' ); if ( '' === $title ) return new WP_Error( 'wp_mcp_ai_missing_title', __( 'title is required.', 'mcp-ai-wpoos-pro' ) ); $lookup = $this->app_client()->get( 'radarr', '/api/v3/movie/lookup', array( 'term' => $title ), 15 ); $term = $title; break;
			}
			if ( is_wp_error( $lookup ) ) return $lookup;
			if ( ! is_array( $lookup ) || empty( $lookup ) ) return new WP_Error( 'wp_mcp_ai_not_found', sprintf( __( 'No movie found for "%s".', 'mcp-ai-wpoos-pro' ), $term ) );
			$selected = $lookup[0];
			$root_path = $this->sanitize_string( $arguments, 'root_folder_path' );
			if ( '' === $root_path ) {
				$folders = $this->app_client()->get( 'radarr', '/api/v3/rootfolder', array(), 10 );
				if ( is_wp_error( $folders ) ) return $folders;
				if ( ! empty( $folders ) && isset( $folders[0]['path'] ) ) $root_path = $folders[0]['path'];
			}
			if ( '' === $root_path ) return new WP_Error( 'wp_mcp_ai_no_root_folder', __( 'Could not determine root folder.', 'mcp-ai-wpoos-pro' ) );
			$payload = array(
				'tmdbId' => isset( $selected['tmdbId'] ) ? (int) $selected['tmdbId'] : 0,
				'title' => isset( $selected['title'] ) ? $selected['title'] : '',
				'qualityProfileId' => $this->sanitize_int( $arguments, 'quality_profile_id', 1 ),
				'rootFolderPath' => $root_path,
				'monitored' => $this->sanitize_bool( $arguments, 'monitored', true ),
				'addOptions' => array( 'searchForMovie' => $this->sanitize_bool( $arguments, 'search_for_movie', true ) ),
			);
			$add = $this->app_client()->post( 'radarr', '/api/v3/movie', $payload, 15 );
			if ( is_wp_error( $add ) ) return $add;
			return $this->success( sprintf( __( 'Movie "%s" added to Radarr.', 'mcp-ai-wpoos-pro' ), $selected['title'] ), array( 'movie' => $add ) );
		}
	}
}
