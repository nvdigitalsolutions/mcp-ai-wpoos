<?php
/**
 * Financial Search Tool
 *
 * Specialized financial search that queries financial-specific sources
 * including SEC EDGAR, Yahoo Finance, Google Finance, Investopedia,
 * and Finviz.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for performing specialized financial searches across multiple sources.
 *
 * Supports:
 * - SEC EDGAR full-text search for filings
 * - Yahoo Finance symbol and news search
 * - Google Finance search
 * - Investopedia definitions and articles
 * - Finviz stock screener search
 * - Result caching via transients (10 min TTL)
 * - Source-specific guidance
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Financial_Search implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Cache TTL in seconds (10 minutes).
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const CACHE_TTL = 600;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Financial search tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'financial_search';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Financial Search', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Search financial-specific sources including SEC EDGAR filings, Yahoo Finance, Google Finance, Investopedia, and Finviz. Supports general, company, filing, definition, and screener search types with result caching. EDUCATIONAL ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'       => array(
					'type'        => 'string',
					'description' => __( 'Search query.', 'mcp-ai-wpoos-pro' ),
				),
				'sources'     => array(
					'type'        => 'array',
					'description' => __( 'Sources to search.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'sec_edgar', 'yahoo_finance', 'google_finance', 'investopedia', 'finviz' ),
					),
				),
				'search_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of financial search.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'general', 'company', 'filing', 'definition', 'screener' ),
					'default'     => 'general',
				),
				'ticker'      => array(
					'type'        => 'string',
					'description' => __( 'Filter results by specific ticker symbol.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of results to return.', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 25,
				),
				'date_range'  => array(
					'type'        => 'string',
					'description' => __( 'Date filter for results.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'today', 'week', 'month', 'quarter', 'year' ),
				),
			),
			'required'   => array( 'query' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'computation',
			'external-api',
			'cacheable',
			'network-dependent',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.1.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the financial search.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$query       = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';
		$sources     = isset( $arguments['sources'] ) && is_array( $arguments['sources'] ) ? array_map( 'sanitize_text_field', $arguments['sources'] ) : array();
		$search_type = isset( $arguments['search_type'] ) ? sanitize_text_field( $arguments['search_type'] ) : 'general';
		$ticker      = isset( $arguments['ticker'] ) ? strtoupper( sanitize_text_field( $arguments['ticker'] ) ) : '';
		$limit       = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 25 ) : 10;
		$date_range  = isset( $arguments['date_range'] ) ? sanitize_text_field( $arguments['date_range'] ) : '';

		if ( empty( $query ) ) {
			return new WP_Error( 'missing_query', __( 'Search query is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $limit < 1 ) {
			$limit = 10;
		}

		// Default sources based on search type if not specified.
		if ( empty( $sources ) ) {
			$sources = $this->get_default_sources( $search_type );
		}

		$valid_sources = array( 'sec_edgar', 'yahoo_finance', 'google_finance', 'investopedia', 'finviz' );
		$sources       = array_intersect( $sources, $valid_sources );

		if ( empty( $sources ) ) {
			$sources = array( 'yahoo_finance' );
		}

		$cache_key = 'wp_mcp_ai_finsearch_' . md5( wp_json_encode( compact( 'query', 'sources', 'search_type', 'ticker', 'date_range' ) ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$all_results   = array();
		$source_errors = array();

		foreach ( $sources as $source ) {
			$results = $this->search_source( $source, $query, $search_type, $ticker, $date_range );
			if ( is_wp_error( $results ) ) {
				$source_errors[ $source ] = $results->get_error_message();
			} elseif ( is_array( $results ) ) {
				$all_results = array_merge( $all_results, $results );
			}
		}

		// Limit results.
		$all_results = array_slice( $all_results, 0, $limit );

		$guidance = $this->get_source_guidance( $sources, $search_type );

		$result = array(
			'success'       => true,
			'query'         => $query,
			'search_type'   => $search_type,
			'sources'       => $sources,
			'ticker'        => $ticker,
			'results'       => $all_results,
			'result_count'  => count( $all_results ),
			'source_errors' => $source_errors,
			'guidance'      => $guidance,
			'from_cache'    => false,
			'metadata'      => array(
				'search_quality'     => count( $source_errors ) === 0 ? 'high' : ( count( $source_errors ) < count( $sources ) ? 'partial' : 'degraded' ),
				'sources_successful' => count( $sources ) - count( $source_errors ),
				'sources_failed'     => count( $source_errors ),
			),
			'disclaimer'    => __( 'EDUCATIONAL ONLY. Search results are aggregated from third-party financial sources and may not be complete or current. Verify all information from primary sources. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Search a specific financial source.
	 *
	 * @since 1.1.0
	 *
	 * @param string $source      Source identifier.
	 * @param string $query       Search query.
	 * @param string $search_type Search type.
	 * @param string $ticker      Optional ticker filter.
	 * @param string $date_range  Optional date range.
	 * @return array|WP_Error Search results or error.
	 */
	private function search_source( $source, $query, $search_type, $ticker, $date_range ) {
		$search_query = ! empty( $ticker ) ? $ticker . ' ' . $query : $query;
		$url          = $this->build_search_url( $source, $search_query, $search_type, $date_range );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				'headers'    => array(
					'Accept' => 'application/json, text/html, application/xml',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'search_failed',
				/* translators: 1: source name, 2: error message */
				sprintf( __( 'Search failed for %1$s: %2$s', 'mcp-ai-wpoos-pro' ), $source, $response->get_error_message() )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new WP_Error(
				'http_error',
				/* translators: 1: source name, 2: HTTP status code */
				sprintf( __( 'HTTP error from %1$s: status %2$d', 'mcp-ai-wpoos-pro' ), $source, $status_code )
			);
		}

		$body = wp_remote_retrieve_body( $response );

		return $this->parse_search_results( $source, $body, $search_query );
	}

	/**
	 * Build search URL for a specific source.
	 *
	 * @since 1.1.0
	 *
	 * @param string $source      Source identifier.
	 * @param string $query       Search query.
	 * @param string $search_type Search type.
	 * @param string $date_range  Date range filter.
	 * @return string|WP_Error Search URL or error.
	 */
	private function build_search_url( $source, $query, $search_type, $date_range ) {
		$encoded_query = rawurlencode( $query );

		$date_param = '';
		if ( ! empty( $date_range ) ) {
			$date_map   = array(
				'today'   => '1',
				'week'    => '7',
				'month'   => '30',
				'quarter' => '90',
				'year'    => '365',
			);
			$date_param = isset( $date_map[ $date_range ] ) ? $date_map[ $date_range ] : '';
		}

		switch ( $source ) {
			case 'sec_edgar':
				$edgar_args = array( 'q' => $query );
				if ( ! empty( $date_param ) ) {
					$edgar_args['dateRange'] = 'custom';
					$edgar_args['startdt']   = gmdate( 'Y-m-d', current_time( 'timestamp' ) - ( intval( $date_param ) * DAY_IN_SECONDS ) );
					$edgar_args['enddt']     = gmdate( 'Y-m-d', current_time( 'timestamp' ) );
				}
				return add_query_arg( $edgar_args, 'https://efts.sec.gov/LATEST/search-index' );

			case 'yahoo_finance':
				return 'https://finance.yahoo.com/lookup?s=' . $encoded_query;

			case 'google_finance':
				return 'https://www.google.com/finance/quote/' . $encoded_query;

			case 'investopedia':
				return 'https://www.investopedia.com/search?q=' . $encoded_query;

			case 'finviz':
				if ( 'screener' === $search_type ) {
					return 'https://finviz.com/screener.ashx?v=111&ft=4&t=' . $encoded_query;
				}
				return 'https://finviz.com/quote.ashx?t=' . $encoded_query;

			default:
				return new WP_Error(
					'invalid_source',
					/* translators: %s: source name */
					sprintf( __( 'Unknown search source: %s', 'mcp-ai-wpoos-pro' ), $source )
				);
		}
	}

	/**
	 * Parse search results from source response.
	 *
	 * @since 1.1.0
	 *
	 * @param string $source Source identifier.
	 * @param string $body   Response body.
	 * @param string $query  Original search query.
	 * @return array Parsed results.
	 */
	private function parse_search_results( $source, $body, $query ) {
		$results      = array();
		$source_label = $this->get_source_label( $source );

		// Try JSON parsing first (for APIs that return JSON).
		$json_data = json_decode( $body, true );
		if ( is_array( $json_data ) ) {
			return $this->parse_json_results( $json_data, $source, $source_label );
		}

		// Try XML/RSS parsing.
		$use_errors = libxml_use_internal_errors( true );
		$xml        = simplexml_load_string( $body );
		libxml_use_internal_errors( $use_errors );

		if ( false !== $xml ) {
			return $this->parse_xml_results( $xml, $source, $source_label );
		}

		// Fallback: return the search URL as a result for the user to visit.
		$results[] = array(
			'title'     => sprintf(
				/* translators: 1: query, 2: source label */
				__( 'Search "%1$s" on %2$s', 'mcp-ai-wpoos-pro' ),
				$query,
				$source_label
			),
			'url'       => $this->build_search_url( $source, $query, 'general', '' ),
			'source'    => $source_label,
			'snippet'   => sprintf(
				/* translators: %s: source label */
				__( 'Visit %s directly to view search results. Automated parsing was not available for this response format.', 'mcp-ai-wpoos-pro' ),
				$source_label
			),
			'relevance' => 'direct_link',
			'date'      => current_time( 'Y-m-d' ),
		);

		return $results;
	}

	/**
	 * Parse JSON search results.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $data         Decoded JSON data.
	 * @param string $source       Source identifier.
	 * @param string $source_label Source display label.
	 * @return array Parsed results.
	 */
	private function parse_json_results( $data, $source, $source_label ) {
		$results = array();

		// SEC EDGAR format.
		if ( 'sec_edgar' === $source ) {
			$hits = isset( $data['hits']['hits'] ) ? $data['hits']['hits'] : array();
			foreach ( $hits as $hit ) {
				$src       = isset( $hit['_source'] ) ? $hit['_source'] : array();
				$results[] = array(
					'title'     => isset( $src['display_names'] ) ? sanitize_text_field( implode( ', ', (array) $src['display_names'] ) ) : '',
					'url'       => isset( $src['file_url'] ) ? esc_url_raw( $src['file_url'] ) : '',
					'source'    => $source_label,
					'snippet'   => isset( $src['form_type'] ) ? sanitize_text_field( $src['form_type'] ) : '',
					'relevance' => 'high',
					'date'      => isset( $src['file_date'] ) ? sanitize_text_field( $src['file_date'] ) : '',
				);
			}
			return $results;
		}

		// Generic JSON array handling.
		$items = isset( $data['results'] ) ? $data['results'] : ( isset( $data['items'] ) ? $data['items'] : array() );
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$results[] = array(
				'title'     => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
				'url'       => isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : ( isset( $item['link'] ) ? esc_url_raw( $item['link'] ) : '' ),
				'source'    => $source_label,
				'snippet'   => isset( $item['snippet'] ) ? sanitize_text_field( $item['snippet'] ) : ( isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '' ),
				'relevance' => 'medium',
				'date'      => isset( $item['date'] ) ? sanitize_text_field( $item['date'] ) : '',
			);
		}

		return $results;
	}

	/**
	 * Parse XML search results.
	 *
	 * @since 1.1.0
	 *
	 * @param SimpleXMLElement $xml          XML data.
	 * @param string           $source       Source identifier.
	 * @param string           $source_label Source display label.
	 * @return array Parsed results.
	 */
	private function parse_xml_results( $xml, $source, $source_label ) {
		$results = array();

		// RSS items.
		if ( isset( $xml->channel->item ) ) {
			foreach ( $xml->channel->item as $item ) {
				$results[] = array(
					'title'     => isset( $item->title ) ? sanitize_text_field( (string) $item->title ) : '',
					'url'       => isset( $item->link ) ? esc_url_raw( (string) $item->link ) : '',
					'source'    => $source_label,
					'snippet'   => isset( $item->description ) ? wp_strip_all_tags( (string) $item->description ) : '',
					'relevance' => 'medium',
					'date'      => isset( $item->{'pubDate'} ) ? sanitize_text_field( (string) $item->{'pubDate'} ) : '',
				);
			}
		}

		// Atom entries.
		if ( isset( $xml->entry ) ) {
			foreach ( $xml->entry as $entry ) {
				$link = '';
				if ( isset( $entry->link ) ) {
					$attrs = $entry->link->attributes();
					if ( $attrs && isset( $attrs['href'] ) ) {
						$link = (string) $attrs['href'];
					}
				}

				$results[] = array(
					'title'     => isset( $entry->title ) ? sanitize_text_field( (string) $entry->title ) : '',
					'url'       => esc_url_raw( $link ),
					'source'    => $source_label,
					'snippet'   => isset( $entry->summary ) ? wp_strip_all_tags( (string) $entry->summary ) : '',
					'relevance' => 'medium',
					'date'      => isset( $entry->updated ) ? sanitize_text_field( (string) $entry->updated ) : '',
				);
			}
		}

		return $results;
	}

	/**
	 * Get default sources based on search type.
	 *
	 * @since 1.1.0
	 *
	 * @param string $search_type Search type.
	 * @return array Default source identifiers.
	 */
	private function get_default_sources( $search_type ) {
		switch ( $search_type ) {
			case 'filing':
				return array( 'sec_edgar' );

			case 'definition':
				return array( 'investopedia' );

			case 'screener':
				return array( 'finviz' );

			case 'company':
				return array( 'yahoo_finance', 'google_finance', 'sec_edgar' );

			case 'general':
			default:
				return array( 'yahoo_finance', 'google_finance' );
		}
	}

	/**
	 * Get human-readable source label.
	 *
	 * @since 1.1.0
	 *
	 * @param string $source Source identifier.
	 * @return string Source label.
	 */
	private function get_source_label( $source ) {
		$labels = array(
			'sec_edgar'      => __( 'SEC EDGAR', 'mcp-ai-wpoos-pro' ),
			'yahoo_finance'  => __( 'Yahoo Finance', 'mcp-ai-wpoos-pro' ),
			'google_finance' => __( 'Google Finance', 'mcp-ai-wpoos-pro' ),
			'investopedia'   => __( 'Investopedia', 'mcp-ai-wpoos-pro' ),
			'finviz'         => __( 'Finviz', 'mcp-ai-wpoos-pro' ),
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : $source;
	}

	/**
	 * Get guidance text for the selected sources and search type.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $sources     Source identifiers.
	 * @param string $search_type Search type.
	 * @return array Guidance entries per source.
	 */
	private function get_source_guidance( $sources, $search_type ) {
		$guidance_map = array(
			'sec_edgar'      => __( 'SEC EDGAR: Best for official company filings (10-K, 10-Q, 8-K), insider transactions, and regulatory documents. Most authoritative source for US public company data.', 'mcp-ai-wpoos-pro' ),
			'yahoo_finance'  => __( 'Yahoo Finance: Good for real-time quotes, company profiles, financial statements, and general financial news. Widely used free source.', 'mcp-ai-wpoos-pro' ),
			'google_finance' => __( 'Google Finance: Useful for quick stock quotes, market overviews, and financial news aggregation from multiple sources.', 'mcp-ai-wpoos-pro' ),
			'investopedia'   => __( 'Investopedia: Excellent for financial term definitions, educational articles, and investment concept explanations. Best for learning.', 'mcp-ai-wpoos-pro' ),
			'finviz'         => __( 'Finviz: Powerful stock screener with technical and fundamental filters. Best for screening stocks by specific criteria and viewing heatmaps.', 'mcp-ai-wpoos-pro' ),
		);

		$guidance = array();
		foreach ( $sources as $source ) {
			if ( isset( $guidance_map[ $source ] ) ) {
				$guidance[ $source ] = $guidance_map[ $source ];
			}
		}

		return $guidance;
	}
}
