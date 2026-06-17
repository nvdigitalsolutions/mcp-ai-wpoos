<?php
/**
 * DietPi Media Request Flow Tool — End-to-end: search Jackett → add to Transmission → monitor in Sonarr/Radarr.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Media_Request_Flow' ) ) {

	/**
	 * Media Request Flow tool.
	 */
	class WP_MCP_AI_Tool_DietPi_Media_Request_Flow extends WP_MCP_AI_Tool_DietPi_Base {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_media_request_flow';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DietPi Media Request Flow', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( '🔑 End-to-end media automation workflow: search for a TV show or movie across all Jackett indexers, add the best-matching torrent to Transmission, and ensure the series/movie is monitored in Sonarr or Radarr. Specify the media_type (tv or movie) and the title or ID to retrieve.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'media_type' => array(
						'type'        => 'string',
						'description' => __( 'Type of media to request.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'tv', 'movie' ),
					),
					'title'      => array(
						'type'        => 'string',
						'description' => __( 'TV show or movie title to search for.', 'mcp-ai-wpoos-pro' ),
					),
					'tvdb_id'    => array(
						'type'        => 'integer',
						'description' => __( 'TheTVDB ID (for TV, alternative to title).', 'mcp-ai-wpoos-pro' ),
					),
					'tmdb_id'    => array(
						'type'        => 'integer',
						'description' => __( 'TMDB ID (for movies, alternative to title).', 'mcp-ai-wpoos-pro' ),
					),
					'imdb_id'    => array(
						'type'        => 'string',
						'description' => __( 'IMDb ID (alternative to title).', 'mcp-ai-wpoos-pro' ),
					),
					'season'     => array(
						'type'        => 'integer',
						'description' => __( 'Season number to search for (TV only).', 'mcp-ai-wpoos-pro' ),
						'minimum'     => 1,
					),
					'add_to_arr' => array(
						'type'        => 'boolean',
						'description' => __( 'Whether to also add the series/movie to Sonarr/Radarr. Default: true.', 'mcp-ai-wpoos-pro' ),
						'default'     => true,
					),
					'paused'     => array(
						'type'        => 'boolean',
						'description' => __( 'Add torrent in paused state. Default: false.', 'mcp-ai-wpoos-pro' ),
						'default'     => false,
					),
				),
				'required'   => array( 'media_type', 'title' ),
			);
		}

		/** {@inheritdoc} */
		public function get_required_capability() {
			return 'edit_posts';
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'may-timeout', 'reversible' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$media_type = $this->sanitize_string( $arguments, 'media_type' );
			$title      = $this->sanitize_string( $arguments, 'title' );
			$flow       = array( 'steps' => array() );
			// Step 1: Search Jackett.
			$search_type = 'movie' === $media_type ? 'movie' : 'tvsearch';
			$params      = array(
				't'     => $search_type,
				'q'     => $title,
				'limit' => 10,
			);
			$season      = $this->sanitize_int( $arguments, 'season', 0 );
			if ( $season > 0 ) {
				$params['season'] = $season;
			}
			$jackett = $this->app_client()->get( 'jackett', '/api/v2.0/indexers/all/results/torznab/api', $params, 45 );
			if ( is_wp_error( $jackett ) ) {
				return $jackett;
			}
			$flow['steps'][] = array(
				'step'          => 'jackett_search',
				'status'        => 'ok',
				'results_count' => 0,
			);
			// Step 2: Pick best result (highest seeders).
			$items = array();
			$raw   = isset( $jackett['Results'] ) ? $jackett['Results'] : ( isset( $jackett['rss']['channel']['item'] ) ? $jackett['rss']['channel']['item'] : array() );
			if ( is_array( $raw ) ) {
				if ( isset( $raw['title'] ) ) {
					$raw = array( $raw );
				}
				$items = $raw;
			}
			$best       = null;
			$best_seeds = -1;
			foreach ( $items as $item ) {
				$s = isset( $item['seeders'] ) ? (int) $item['seeders'] : 0;
				if ( $s > $best_seeds ) {
					$best_seeds = $s;
					$best       = $item;
				}
			}
			if ( null === $best ) {
				/* translators: %s: the search title. */
				return new WP_Error( 'wp_mcp_ai_no_results', sprintf( __( 'No results found for "%s".', 'mcp-ai-wpoos-pro' ), $title ) );
			}
			$flow['steps'][] = array(
				'step'    => 'select_best',
				'status'  => 'ok',
				'title'   => isset( $best['title'] ) ? $best['title'] : '',
				'seeders' => $best_seeds,
			);
			// Step 3: Add to Transmission.
			$link = isset( $best['link'] ) ? $best['link'] : ( isset( $best['magneturl'] ) ? $best['magneturl'] : '' );
			if ( '' === $link ) {
				return new WP_Error( 'wp_mcp_ai_no_link', __( 'Best result has no download link.', 'mcp-ai-wpoos-pro' ) );
			}
			$args = array();
			if ( 0 === strpos( $link, 'magnet:' ) ) {
				$args['filename'] = $link;
			} else {
				$args['filename'] = $link;
			}
			$args['paused'] = $this->sanitize_bool( $arguments, 'paused', false );
			$tx_result      = $this->app_client()->transmission_rpc( 'torrent-add', $args );
			if ( is_wp_error( $tx_result ) ) {
				return $tx_result;
			}
			$flow['steps'][] = array(
				'step'   => 'add_to_transmission',
				'status' => 'ok',
			);
			// Step 4: Add to Sonarr/Radarr if requested.
			if ( $this->sanitize_bool( $arguments, 'add_to_arr', true ) ) {
				$arr_app  = 'tv' === $media_type ? 'sonarr' : 'radarr';
				$arr_path = 'tv' === $media_type ? '/api/v3/series/lookup' : '/api/v3/movie/lookup';
				if ( $this->app_client()->is_app_configured( $arr_app ) ) {
					$lookup = $this->app_client()->get( $arr_app, $arr_path, array( 'term' => $title ), 15 );
					if ( ! is_wp_error( $lookup ) && is_array( $lookup ) && ! empty( $lookup ) ) {
						$folders  = $this->app_client()->get( $arr_app, '/api/v3/rootfolder', array(), 10 );
						$root     = ( ! is_wp_error( $folders ) && ! empty( $folders ) && isset( $folders[0]['path'] ) ) ? $folders[0]['path'] : '/mnt/dietpi_userdata/downloads';
						$selected = $lookup[0];
						if ( 'tv' === $media_type ) {
							$payload    = array(
								'tvdbId'           => isset( $selected['tvdbId'] ) ? (int) $selected['tvdbId'] : 0,
								'title'            => isset( $selected['title'] ) ? $selected['title'] : '',
								'qualityProfileId' => 1,
								'rootFolderPath'   => $root,
								'monitored'        => true,
								'addOptions'       => array( 'searchForMissingEpisodes' => true ),
							);
							$arr_result = $this->app_client()->post( 'sonarr', '/api/v3/series', $payload, 15 );
						} else {
							$payload    = array(
								'tmdbId'           => isset( $selected['tmdbId'] ) ? (int) $selected['tmdbId'] : 0,
								'title'            => isset( $selected['title'] ) ? $selected['title'] : '',
								'qualityProfileId' => 1,
								'rootFolderPath'   => $root,
								'monitored'        => true,
								'addOptions'       => array( 'searchForMovie' => true ),
							);
							$arr_result = $this->app_client()->post( 'radarr', '/api/v3/movie', $payload, 15 );
						}
						$flow['steps'][] = array(
							'step'   => 'add_to_' . $arr_app,
							'status' => is_wp_error( $arr_result ) ? 'error' : 'ok',
							'title'  => isset( $selected['title'] ) ? $selected['title'] : '',
						);
					} else {
						$flow['steps'][] = array(
							'step'   => 'add_to_' . $arr_app,
							'status' => 'skipped',
							'reason' => 'not_found',
						);
					}
				} else {
					$flow['steps'][] = array(
						'step'   => 'add_to_' . $arr_app,
						'status' => 'skipped',
						'reason' => 'not_configured',
					);
				}
			}
			return $this->success(
				/* translators: %s: the media title. */
				sprintf( __( 'Media request flow completed for "%s".', 'mcp-ai-wpoos-pro' ), $title ),
				$flow
			);
		}
	}
}
