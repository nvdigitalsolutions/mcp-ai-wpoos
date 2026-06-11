<?php
/**
 * DietPi Add Sonarr Series Tool
 * @package WP_MCP_AI_Pro @subpackage DietPi_Toolkit @since 1.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Add_Sonarr_Series' ) ) {
	class WP_MCP_AI_Tool_DietPi_Add_Sonarr_Series extends WP_MCP_AI_Tool_DietPi_Base {
		public function get_slug() { return 'dietpi_add_sonarr_series'; }
		public function get_name() { return __( 'Add Sonarr Series', 'mcp-ai-wpoos-pro' ); }
		public function get_description() { return __( 'Add a new TV series to Sonarr. Supports lookup by TVDb ID, IMDb ID, or title search. Also supports setting quality profile, root folder, and monitoring options.', 'mcp-ai-wpoos-pro' ); }
		public function get_parameters_schema() {
			return array(
				'type' => 'object',
				'properties' => array(
					'lookup_type' => array( 'type' => 'string', 'description' => __( 'How to identify the series.', 'mcp-ai-wpoos-pro' ), 'enum' => array( 'tvdb', 'imdb', 'title' ), 'default' => 'title' ),
					'tvdb_id' => array( 'type' => 'integer', 'description' => __( 'TheTVDB ID (for tvdb lookup).', 'mcp-ai-wpoos-pro' ) ),
					'imdb_id' => array( 'type' => 'string', 'description' => __( 'IMDb ID (for imdb lookup, e.g. tt1234567).', 'mcp-ai-wpoos-pro' ) ),
					'title' => array( 'type' => 'string', 'description' => __( 'Series title to search for (for title lookup). Use "Breaking Bad" not "Breaking Bad S01".', 'mcp-ai-wpoos-pro' ) ),
					'quality_profile_id' => array( 'type' => 'integer', 'description' => __( 'Quality profile ID. Use 1 if unsure (first/default profile).', 'mcp-ai-wpoos-pro' ), 'default' => 1 ),
					'root_folder_path' => array( 'type' => 'string', 'description' => __( 'Root folder path (e.g. /mnt/dietpi_userdata/downloads/tv).', 'mcp-ai-wpoos-pro' ) ),
					'monitored' => array( 'type' => 'boolean', 'description' => __( 'Monitor the series for new episodes. Default: true.', 'mcp-ai-wpoos-pro' ), 'default' => true ),
					'search_for_missing_episodes' => array( 'type' => 'boolean', 'description' => __( 'Immediately search for missing episodes after adding. Default: true.', 'mcp-ai-wpoos-pro' ), 'default' => true ),
				),
				'required' => array(),
			);
		}
		public function get_capability_flags() { return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'reversible' ) ); }
		public function execute( array $arguments = array(), array $context = array() ) {
			if ( ! $this->app_client()->is_app_configured( 'sonarr' ) ) { return new WP_Error( 'wp_mcp_ai_sonarr_not_configured', __( 'Sonarr is not configured.', 'mcp-ai-wpoos-pro' ) ); }
			$lookup_type = $this->sanitize_string( $arguments, 'lookup_type', 'title' );
			// Do lookup based on type.
			$lookup_result = null;
			$term = '';
			switch ( $lookup_type ) {
				case 'tvdb': $id = $this->sanitize_int( $arguments, 'tvdb_id' ); if ( $id <= 0 ) { return new WP_Error( 'wp_mcp_ai_missing_tvdb', __( 'tvdb_id is required for TVDb lookup.', 'mcp-ai-wpoos-pro' ) ); } $lookup_result = $this->app_client()->get( 'sonarr', '/api/v3/series/lookup', array( 'term' => 'tvdb:' . $id ), 15 ); $term = 'tvdb:' . $id; break;
				case 'imdb': $imdb = $this->sanitize_string( $arguments, 'imdb_id' ); if ( '' === $imdb ) { return new WP_Error( 'wp_mcp_ai_missing_imdb', __( 'imdb_id is required for IMDb lookup.', 'mcp-ai-wpoos-pro' ) ); } $lookup_result = $this->app_client()->get( 'sonarr', '/api/v3/series/lookup', array( 'term' => 'imdb:' . $imdb ), 15 ); $term = 'imdb:' . $imdb; break;
				default: $title = $this->sanitize_string( $arguments, 'title' ); if ( '' === $title ) { return new WP_Error( 'wp_mcp_ai_missing_title', __( 'title is required for title lookup.', 'mcp-ai-wpoos-pro' ) ); } $lookup_result = $this->app_client()->get( 'sonarr', '/api/v3/series/lookup', array( 'term' => $title ), 15 ); $term = $title; break;
			}
			if ( is_wp_error( $lookup_result ) ) { return $lookup_result; }
			if ( ! is_array( $lookup_result ) || empty( $lookup_result ) ) { return new WP_Error( 'wp_mcp_ai_not_found', sprintf( __( 'No series found for "%s".', 'mcp-ai-wpoos-pro' ), $term ) ); }
			$selected = $lookup_result[0];
			// Get root folder.
			$root_path = $this->sanitize_string( $arguments, 'root_folder_path' );
			if ( '' === $root_path ) {
				$folders = $this->app_client()->get( 'sonarr', '/api/v3/rootfolder', array(), 10 );
				if ( is_wp_error( $folders ) ) { return $folders; }
				if ( ! empty( $folders ) && isset( $folders[0]['path'] ) ) { $root_path = $folders[0]['path']; }
			}
			if ( '' === $root_path ) { return new WP_Error( 'wp_mcp_ai_no_root_folder', __( 'Could not determine root folder. Please specify root_folder_path.', 'mcp-ai-wpoos-pro' ) ); }
			// Build add payload.
			$payload = array(
				'tvdbId' => isset( $selected['tvdbId'] ) ? (int) $selected['tvdbId'] : 0,
				'title' => isset( $selected['title'] ) ? $selected['title'] : '',
				'qualityProfileId' => $this->sanitize_int( $arguments, 'quality_profile_id', 1 ),
				'rootFolderPath' => $root_path,
				'monitored' => $this->sanitize_bool( $arguments, 'monitored', true ),
				'addOptions' => array(
					'searchForMissingEpisodes' => $this->sanitize_bool( $arguments, 'search_for_missing_episodes', true ),
				),
			);
			$add_result = $this->app_client()->post( 'sonarr', '/api/v3/series', $payload, 15 );
			if ( is_wp_error( $add_result ) ) { return $add_result; }
			return $this->success( sprintf( __( 'Series "%s" added to Sonarr.', 'mcp-ai-wpoos-pro' ), $selected['title'] ), array( 'series' => $add_result ) );
		}
	}
}
