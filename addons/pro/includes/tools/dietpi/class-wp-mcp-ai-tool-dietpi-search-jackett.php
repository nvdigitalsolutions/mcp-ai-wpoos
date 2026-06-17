<?php
/**
 * DietPi Search Jackett Tool — Search across all configured indexers.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Search_Jackett' ) ) {

	/**
	 * Search Jackett tool.
	 */
	class WP_MCP_AI_Tool_DietPi_Search_Jackett extends WP_MCP_AI_Tool_DietPi_Base {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_search_jackett';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Search Jackett Indexers', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Search across all configured Jackett torrent indexers simultaneously. Supports filtering by category and search type. Returns torrent title, size, seeds, peers, tracker name, and download link.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'query'       => array(
						'type'        => 'string',
						'description' => __( 'Search term. Use TV show name, movie title, or general keywords.', 'mcp-ai-wpoos-pro' ),
					),
					'search_type' => array(
						'type'        => 'string',
						'description' => __( 'Torznab search type.', 'mcp-ai-wpoos-pro' ),
						'enum'        => array( 'search', 'tvsearch', 'movie', 'music', 'book' ),
						'default'     => 'search',
					),
					'categories'  => array(
						'type'        => 'string',
						'description' => __( 'Comma-separated Torznab category IDs (e.g. "2000,5000" for movies+TV).', 'mcp-ai-wpoos-pro' ),
					),
					'limit'       => array(
						'type'        => 'integer',
						'description' => __( 'Maximum results. Default: 50.', 'mcp-ai-wpoos-pro' ),
						'default'     => 50,
						'minimum'     => 1,
						'maximum'     => 200,
					),
				),
				'required'   => array( 'query' ),
			);
		}

		/** {@inheritdoc} */
		public function get_required_capability() {
			return 'edit_posts';
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'read-only', 'cacheable', 'may-timeout' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error Success array or WP_Error on failure.
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$query = $this->sanitize_string( $arguments, 'query' );
			if ( '' === $query ) {
				return new WP_Error( 'wp_mcp_ai_missing_query', __( 'A search query is required.', 'mcp-ai-wpoos-pro' ) );
			}
			$search_type = $this->sanitize_string( $arguments, 'search_type', 'search' );
			$limit       = $this->sanitize_int( $arguments, 'limit', 50 );
			$params      = array(
				't'     => $search_type,
				'q'     => $query,
				'limit' => $limit,
			);
			$cats        = $this->sanitize_string( $arguments, 'categories' );
			if ( '' !== $cats ) {
				$params['cat'] = $cats;
			}
			$result = $this->app_client()->get( 'jackett', '/api/v2.0/indexers/all/results/torznab/api', $params, 45 );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			// Jackett Torznab returns XML wrapped in JSON or raw XML. Try JSON first.
			$items = array();
			$raw   = isset( $result['Results'] ) ? $result['Results'] : ( isset( $result['rss'] ) ? $result : null );
			if ( is_array( $raw ) && isset( $raw['channel']['item'] ) ) {
				$items = $raw['channel']['item'];
				if ( isset( $items['title'] ) ) {
					$items = array( $items );
				} // Single result.
			}
			$out = array();
			foreach ( $items as $item ) {
				$out[] = array(
					'title'        => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
					'size_bytes'   => isset( $item['size'] ) ? (int) $item['size'] : 0,
					'seeders'      => isset( $item['seeders'] ) ? (int) $item['seeders'] : 0,
					'peers'        => isset( $item['peers'] ) ? (int) $item['peers'] : 0,
					'tracker'      => isset( $item['tracker'] ) ? sanitize_text_field( $item['tracker'] ) : '',
					'link'         => isset( $item['link'] ) ? esc_url_raw( $item['link'] ) : ( isset( $item['magneturl'] ) ? esc_url_raw( $item['magneturl'] ) : '' ),
					'publish_date' => isset( $item['pubDate'] ) ? sanitize_text_field( $item['pubDate'] ) : '',
				);
			}
			return $this->success(
				/* translators: %1$d: count of results, %2$s: the search query. */
				sprintf( __( 'Found %1$d results for "%2$s".', 'mcp-ai-wpoos-pro' ), count( $out ), $query ),
				array(
					'query'   => $query,
					'results' => $out,
					'total'   => count( $out ),
				)
			);
		}
	}
}
