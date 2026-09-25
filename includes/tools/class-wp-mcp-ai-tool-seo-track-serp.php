<?php
/**
 * Tool: seo_track_serp — Tracks keyword ranking positions via the Brave Search API.
 *
 * Port of mcp-wordpress wp_seo_track_serp tool.
 *
 * @link    https://github.com/docdyhr/mcp-wordpress
 * @credit  mcp-wordpress by Aionda GmbH (MIT)
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Track SERP — checks where a target URL ranks for each keyword.
 *
 * Uses the Brave Search API (which serves Google-style web results) to find
 * the position of the target host among the top 20 results, plus the top
 * competitors. One API request is made per keyword.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_SEO_Track_Serp implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_track_serp';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SERP Position Tracker', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Tracks keyword ranking positions against a target URL using the Brave Search API. Only google is supported because results come from the Brave API. Makes one API request per keyword (rate limits apply). Location is accepted but ignored — the API serves results from its configured region.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Checking where your site ranks for up to 10 keywords and which competitors occupy the top spots.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Historical rank data or bing/yahoo tracking; this tool only checks the current top 20 via Brave (google).', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'seo_keyword_research', 'web_search', 'deep_research' ),
			'notes'           => __( 'Requires a Brave Search API key configured in settings. One request is made per keyword, so 10 keywords = 10 requests.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'keywords'      => array(
					'type'        => 'array',
					'description' => __( 'Keywords to track (maximum 10).', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 1,
					'maxItems'    => 10,
				),
				'url'           => array(
					'type'        => 'string',
					'description' => __( 'Target URL to check rankings for. Defaults to the site home URL.', 'mcp-ai-wpoos' ),
					'format'      => 'uri',
				),
				'search_engine' => array(
					'type'        => 'string',
					'description' => __( 'Search engine. Only google is supported (results come from the Brave API).', 'mcp-ai-wpoos' ),
					'enum'        => array( 'google', 'bing', 'yahoo' ),
					'default'     => 'google',
				),
				'location'      => array(
					'type'        => 'string',
					'description' => __( 'Optional location. Accepted but ignored — Brave serves results from the API\'s configured region.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'keywords' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'content_publishing',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'seo_specialist', 'content_strategist' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external HTTP requests.
			'network-dependent',    // Requires internet connectivity.
			'requires-credentials', // Requires the Brave Search API key.
			'requires-capability',  // Requires user capabilities.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$acting_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $acting_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to use this tool.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $acting_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to track SERP positions.', 'mcp-ai-wpoos' ) );
		}

		$keywords = isset( $arguments['keywords'] ) && is_array( $arguments['keywords'] ) ? $arguments['keywords'] : array();
		$keywords = array_values( array_filter( array_map( 'sanitize_text_field', $keywords ) ) );
		$keywords = array_slice( $keywords, 0, 10 );

		if ( empty( $keywords ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_keywords', __( 'At least one keyword is required.', 'mcp-ai-wpoos' ) );
		}

		$engine = isset( $arguments['search_engine'] ) ? sanitize_key( $arguments['search_engine'] ) : 'google';
		if ( 'google' !== $engine ) {
			return new WP_Error( 'wp_mcp_ai_unsupported_engine', __( 'Only google is supported; results come from the Brave Search API.', 'mcp-ai-wpoos' ) );
		}

		// Accepted but ignored — Brave serves results from its configured region.
		$location = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';

		$target_url = isset( $arguments['url'] ) && '' !== $arguments['url'] ? esc_url_raw( $arguments['url'] ) : home_url( '/' );
		if ( '' === $target_url ) {
			$target_url = home_url( '/' );
		}

		$target_host = wp_parse_url( $target_url, PHP_URL_HOST );
		if ( empty( $target_host ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_url', __( 'The target URL is invalid.', 'mcp-ai-wpoos' ) );
		}

		$api_key = WP_MCP_AI_Settings_Registry::get_setting( 'brave_search_api_key', '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'wp_mcp_ai_search_missing_api_key', __( 'A Brave Search API key is required to track SERP positions. Configure it in the NV oOS settings.', 'mcp-ai-wpoos' ) );
		}

		$results     = array();
		$found_count = 0;

		foreach ( $keywords as $keyword ) {
			$entry = array(
				'keyword'     => esc_html( $keyword ),
				'position'    => null,
				'url'         => esc_url_raw( $target_url ),
				'found'       => false,
				'competitors' => array(),
				'status'      => 'success',
			);

			$search_results = $this->brave_web_search( $keyword, $api_key );
			if ( is_wp_error( $search_results ) ) {
				$entry['status'] = 'error';
				$entry['error']  = $search_results->get_error_message();
				$results[]       = $entry;
				continue;
			}

			$position    = null;
			$competitors = array();

			foreach ( $search_results as $index => $item ) {
				$item_url = isset( $item->url ) ? (string) $item->url : '';
				if ( '' === $item_url ) {
					continue;
				}

				$item_host = wp_parse_url( $item_url, PHP_URL_HOST );
				if ( null === $item_host ) {
					continue;
				}

				if ( $item_host === $target_host ) {
					if ( null === $position ) {
						$position = $index + 1;
					}
					continue;
				}

				if ( count( $competitors ) < 5 ) {
					$competitors[] = array(
						'position' => $index + 1,
						'url'      => esc_url_raw( $item_url ),
						'title'    => isset( $item->title ) ? esc_html( sanitize_text_field( $item->title ) ) : '',
					);
				}
			}

			$entry['position']    = $position;
			$entry['found']       = null !== $position;
			$entry['competitors'] = $competitors;

			if ( $entry['found'] ) {
				++$found_count;
			}

			$results[] = $entry;
		}

		$message = sprintf(
			/* translators: 1: number of keywords tracked, 2: number of keywords where the site was found */
			__( 'SERP tracking completed for %1$d keyword(s); the site appeared in the top 20 for %2$d of them.', 'mcp-ai-wpoos' ),
			count( $results ),
			$found_count
		);

		if ( '' !== $location ) {
			$message .= ' ' . __( 'The location parameter was accepted but ignored; Brave serves results from the API\'s configured region.', 'mcp-ai-wpoos' );
		}

		return array(
			'message'    => $message,
			'results'    => $results,
			'engine'     => 'google',
			'target_url' => esc_url_raw( $target_url ),
			'checked_at' => gmdate( 'c' ),
		);
	}

	/**
	 * Performs a single Brave web search request.
	 *
	 * @since 1.1.87
	 *
	 * @param string $query   Search query.
	 * @param string $api_key Brave Search API key.
	 * @return array|WP_Error Result objects or WP_Error on failure.
	 */
	private function brave_web_search( $query, $api_key ) {
		$request_url = add_query_arg(
			array(
				'q'     => $query,
				'count' => 20,
			),
			'https://api.search.brave.com/res/v1/web/search'
		);

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'X-Subscription-Token' => $api_key,
					'Accept'               => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_search_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The search service returned HTTP status %d.', 'mcp-ai-wpoos' ),
					$status_code
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( null === $data || ! isset( $data->web->results ) || ! is_array( $data->web->results ) ) {
			return new WP_Error( 'wp_mcp_ai_search_bad_json', __( 'The search response could not be decoded.', 'mcp-ai-wpoos' ) );
		}

		return $data->web->results;
	}
}
