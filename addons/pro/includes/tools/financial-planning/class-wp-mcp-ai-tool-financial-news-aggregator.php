<?php
/**
 * Financial News Aggregator Tool
 *
 * Aggregates financial news from configurable RSS/API sources including Yahoo Finance,
 * MarketWatch, Reuters, SEC EDGAR filings, and more. Provides unified trend analysis
 * and market pulse summaries.
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
 * Tool for aggregating financial news from multiple sources.
 *
 * Supports:
 * - Multiple configurable news sources (Yahoo Finance, MarketWatch, Reuters, etc.)
 * - Category-based filtering
 * - Keyword search within results
 * - Transient-based caching (15 min TTL)
 * - Unified trend analysis and market pulse summaries
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Financial_News_Aggregator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Cache TTL in seconds (15 minutes).
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const CACHE_TTL = 900;

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

		return __( 'Financial news aggregator tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'financial_news_aggregator';
	}

	/**
	 * Get the tool name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Financial News Aggregator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Aggregate financial news from configurable sources including Yahoo Finance RSS, MarketWatch, Reuters, and SEC EDGAR filings. Filter by category and keywords. Returns unified trend analysis and market pulse summaries. EDUCATIONAL ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' );
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
				'sources'    => array(
					'type'        => 'array',
					'description' => __( 'Which news sources to fetch from. Defaults to all configured sources.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'yahoo_finance', 'marketwatch', 'reuters', 'sec_filings', 'google_finance', 'financial_times', 'bloomberg', 'cnbc', 'wsj' ),
					),
				),
				'category'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by news category.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'markets', 'economy', 'technology', 'commodities', 'forex', 'crypto', 'earnings', 'ipo', 'regulatory' ),
				),
				'keywords'   => array(
					'type'        => 'string',
					'description' => __( 'Search keywords to filter news articles.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of articles to return.', 'mcp-ai-wpoos-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'hours_back' => array(
					'type'        => 'integer',
					'description' => __( 'How many hours back to fetch news.', 'mcp-ai-wpoos-pro' ),
					'default'     => 24,
					'minimum'     => 1,
					'maximum'     => 168,
				),
			),
			'required'   => array(),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the financial news aggregator.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$sources    = isset( $arguments['sources'] ) && is_array( $arguments['sources'] ) ? array_map( 'sanitize_text_field', $arguments['sources'] ) : array( 'yahoo_finance' );
		$category   = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';
		$keywords   = isset( $arguments['keywords'] ) ? sanitize_text_field( $arguments['keywords'] ) : '';
		$limit      = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 50 ) : 20;
		$hours_back = isset( $arguments['hours_back'] ) ? min( absint( $arguments['hours_back'] ), 168 ) : 24;

		if ( $limit < 1 ) {
			$limit = 20;
		}
		if ( $hours_back < 1 ) {
			$hours_back = 24;
		}

		$cache_key = 'wp_mcp_ai_news_' . md5( wp_json_encode( compact( 'sources', 'category', 'keywords', 'limit', 'hours_back' ) ) );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$cached['from_cache'] = true;
			return $cached;
		}

		$all_articles  = array();
		$source_errors = array();

		foreach ( $sources as $source ) {
			$result = $this->fetch_from_source( $source, $category, $hours_back );
			if ( is_wp_error( $result ) ) {
				$source_errors[ $source ] = $result->get_error_message();
			} elseif ( is_array( $result ) ) {
				$all_articles = array_merge( $all_articles, $result );
			}
		}

		if ( ! empty( $keywords ) ) {
			$all_articles = $this->filter_by_keywords( $all_articles, $keywords );
		}

		// Sort by published date descending.
		usort(
			$all_articles,
			function ( $a, $b ) {
				$time_a = isset( $a['published_date'] ) ? strtotime( $a['published_date'] ) : 0;
				$time_b = isset( $b['published_date'] ) ? strtotime( $b['published_date'] ) : 0;
				return $time_b - $time_a;
			}
		);

		$all_articles   = array_slice( $all_articles, 0, $limit );
		$unified_trends = $this->generate_unified_trends( $all_articles );
		$market_pulse   = $this->generate_market_pulse( $all_articles );

		$result = array(
			'success'         => true,
			'articles'        => $all_articles,
			'article_count'   => count( $all_articles ),
			'sources_queried' => $sources,
			'source_errors'   => $source_errors,
			'unified_trends'  => $unified_trends,
			'market_pulse'    => $market_pulse,
			'filters'         => array(
				'category'   => $category,
				'keywords'   => $keywords,
				'hours_back' => $hours_back,
			),
			'from_cache'      => false,
			'disclaimer'      => __( 'EDUCATIONAL ONLY. News aggregation is for informational purposes only. Articles may be delayed or incomplete. Verify information from primary sources before making financial decisions. Not investment advice.', 'mcp-ai-wpoos-pro' ),
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Fetch articles from a specific source.
	 *
	 * @since 1.1.0
	 *
	 * @param string $source     Source identifier.
	 * @param string $category   Category filter.
	 * @param int    $hours_back Hours to look back.
	 * @return array|WP_Error Array of articles or error.
	 */
	private function fetch_from_source( $source, $category, $hours_back ) {
		$source_urls = $this->get_source_urls();

		if ( ! isset( $source_urls[ $source ] ) ) {
			return new WP_Error(
				'invalid_source',
				/* translators: %s: source name */
				sprintf( __( 'Unknown news source: %s', 'mcp-ai-wpoos-pro' ), $source )
			);
		}

		$url = $source_urls[ $source ]['url'];
		if ( ! empty( $category ) && ! empty( $source_urls[ $source ]['category_param'] ) ) {
			$url = add_query_arg( $source_urls[ $source ]['category_param'], $category, $url );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				'headers'    => array(
					'Accept' => 'application/rss+xml, application/xml, text/xml, application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'fetch_failed',
				/* translators: 1: source name, 2: error message */
				sprintf( __( 'Failed to fetch from %1$s: %2$s', 'mcp-ai-wpoos-pro' ), $source, $response->get_error_message() )
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

		$body     = wp_remote_retrieve_body( $response );
		$cutoff   = current_time( 'timestamp' ) - ( $hours_back * HOUR_IN_SECONDS );
		$articles = $this->parse_rss_feed( $body, $source, $cutoff );

		return $articles;
	}

	/**
	 * Get source URLs configuration.
	 *
	 * @since 1.1.0
	 *
	 * @return array Source URL configurations.
	 */
	private function get_source_urls() {
		return array(
			'yahoo_finance'   => array(
				'url'            => 'https://finance.yahoo.com/news/rssindex',
				'category_param' => '',
				'label'          => __( 'Yahoo Finance', 'mcp-ai-wpoos-pro' ),
			),
			'marketwatch'     => array(
				'url'            => 'https://feeds.marketwatch.com/marketwatch/topstories/',
				'category_param' => '',
				'label'          => __( 'MarketWatch', 'mcp-ai-wpoos-pro' ),
			),
			'reuters'         => array(
				'url'            => 'https://www.reutersagency.com/feed/',
				'category_param' => 'best-topics',
				'label'          => __( 'Reuters', 'mcp-ai-wpoos-pro' ),
			),
			'sec_filings'     => array(
				'url'            => 'https://www.sec.gov/cgi-bin/browse-edgar?action=getcurrent&type=&dateb=&owner=include&count=40&search_text=&start=0&output=atom',
				'category_param' => 'type',
				'label'          => __( 'SEC EDGAR Filings', 'mcp-ai-wpoos-pro' ),
			),
			'google_finance'  => array(
				'url'            => 'https://news.google.com/rss/search?q=finance+stock+market&hl=en-US&gl=US&ceid=US:en',
				'category_param' => 'q',
				'label'          => __( 'Google Finance', 'mcp-ai-wpoos-pro' ),
			),
			'financial_times' => array(
				'url'            => 'https://www.ft.com/rss/home',
				'category_param' => '',
				'label'          => __( 'Financial Times', 'mcp-ai-wpoos-pro' ),
			),
			'bloomberg'       => array(
				'url'            => 'https://feeds.bloomberg.com/markets/news.rss',
				'category_param' => '',
				'label'          => __( 'Bloomberg', 'mcp-ai-wpoos-pro' ),
			),
			'cnbc'            => array(
				'url'            => 'https://search.cnbc.com/rs/search/combinedcms/view.xml?partnerId=wrss01&id=100003114',
				'category_param' => 'id',
				'label'          => __( 'CNBC', 'mcp-ai-wpoos-pro' ),
			),
			'wsj'             => array(
				'url'            => 'https://feeds.a.dj.com/rss/RSSMarketsMain.xml',
				'category_param' => '',
				'label'          => __( 'Wall Street Journal', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Parse RSS/XML feed body into articles.
	 *
	 * @since 1.1.0
	 *
	 * @param string $body   Feed body content.
	 * @param string $source Source identifier.
	 * @param int    $cutoff Unix timestamp cutoff for filtering old articles.
	 * @return array Parsed articles.
	 */
	private function parse_rss_feed( $body, $source, $cutoff ) {
		$articles = array();

		// Suppress XML errors.
		$use_errors = libxml_use_internal_errors( true );
		$xml        = simplexml_load_string( $body );
		libxml_use_internal_errors( $use_errors );

		if ( false === $xml ) {
			return $articles;
		}

		$source_urls  = $this->get_source_urls();
		$source_label = isset( $source_urls[ $source ]['label'] ) ? $source_urls[ $source ]['label'] : $source;

		// Handle RSS 2.0 format.
		if ( isset( $xml->channel->item ) ) {
			foreach ( $xml->channel->item as $item ) {
				$pub_date = isset( $item->{'pubDate'} ) ? strtotime( (string) $item->{'pubDate'} ) : 0;

				if ( $pub_date > 0 && $pub_date < $cutoff ) {
					continue;
				}

				$articles[] = array(
					'title'          => isset( $item->title ) ? sanitize_text_field( (string) $item->title ) : '',
					'source'         => $source_label,
					'url'            => isset( $item->link ) ? esc_url_raw( (string) $item->link ) : '',
					'published_date' => $pub_date > 0 ? gmdate( 'Y-m-d H:i:s', $pub_date ) : '',
					'summary'        => isset( $item->description ) ? wp_strip_all_tags( (string) $item->description ) : '',
					'category'       => isset( $item->category ) ? sanitize_text_field( (string) $item->category ) : '',
				);
			}
		}

		// Handle Atom format.
		if ( isset( $xml->entry ) ) {
			foreach ( $xml->entry as $entry ) {
				$pub_date = 0;
				if ( isset( $entry->updated ) ) {
					$pub_date = strtotime( (string) $entry->updated );
				} elseif ( isset( $entry->published ) ) {
					$pub_date = strtotime( (string) $entry->published );
				}

				if ( $pub_date > 0 && $pub_date < $cutoff ) {
					continue;
				}

				$link = '';
				if ( isset( $entry->link ) ) {
					$link_attrs = $entry->link->attributes();
					if ( $link_attrs && isset( $link_attrs['href'] ) ) {
						$link = (string) $link_attrs['href'];
					}
				}

				$articles[] = array(
					'title'          => isset( $entry->title ) ? sanitize_text_field( (string) $entry->title ) : '',
					'source'         => $source_label,
					'url'            => esc_url_raw( $link ),
					'published_date' => $pub_date > 0 ? gmdate( 'Y-m-d H:i:s', $pub_date ) : '',
					'summary'        => isset( $entry->summary ) ? wp_strip_all_tags( (string) $entry->summary ) : '',
					'category'       => '',
				);
			}
		}

		return $articles;
	}

	/**
	 * Filter articles by keywords.
	 *
	 * @since 1.1.0
	 *
	 * @param array  $articles Articles to filter.
	 * @param string $keywords Keywords to search for.
	 * @return array Filtered articles.
	 */
	private function filter_by_keywords( $articles, $keywords ) {
		$keyword_list = array_map( 'trim', explode( ',', strtolower( $keywords ) ) );
		$keyword_list = array_filter( $keyword_list );

		if ( empty( $keyword_list ) ) {
			return $articles;
		}

		return array_values(
			array_filter(
				$articles,
				function ( $article ) use ( $keyword_list ) {
					$text = strtolower( $article['title'] . ' ' . $article['summary'] );
					foreach ( $keyword_list as $keyword ) {
						if ( false !== strpos( $text, $keyword ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * Generate unified trends summary from articles.
	 *
	 * Groups articles by common themes and keywords.
	 *
	 * @since 1.1.0
	 *
	 * @param array $articles Articles to analyze.
	 * @return array Trend groups.
	 */
	private function generate_unified_trends( $articles ) {
		$theme_keywords = array(
			'markets'     => array( 'stock', 'market', 'index', 'dow', 'nasdaq', 's&p', 'rally', 'selloff' ),
			'economy'     => array( 'gdp', 'inflation', 'fed', 'interest rate', 'unemployment', 'jobs', 'cpi' ),
			'technology'  => array( 'tech', 'ai', 'software', 'cloud', 'semiconductor', 'apple', 'google', 'microsoft' ),
			'commodities' => array( 'oil', 'gold', 'silver', 'commodity', 'crude', 'natural gas', 'copper' ),
			'forex'       => array( 'dollar', 'euro', 'yen', 'forex', 'currency', 'exchange rate' ),
			'crypto'      => array( 'bitcoin', 'crypto', 'ethereum', 'blockchain', 'defi', 'nft' ),
			'earnings'    => array( 'earnings', 'revenue', 'profit', 'quarterly', 'eps', 'guidance' ),
		);

		$trends = array();

		foreach ( $theme_keywords as $theme => $keywords ) {
			$matching = array();
			foreach ( $articles as $article ) {
				$text = strtolower( $article['title'] . ' ' . $article['summary'] );
				foreach ( $keywords as $keyword ) {
					if ( false !== strpos( $text, $keyword ) ) {
						$matching[] = $article['title'];
						break;
					}
				}
			}

			if ( ! empty( $matching ) ) {
				$trends[] = array(
					'theme'         => $theme,
					'article_count' => count( $matching ),
					'headlines'     => array_slice( $matching, 0, 5 ),
				);
			}
		}

		// Sort by article count descending.
		usort(
			$trends,
			function ( $a, $b ) {
				return $b['article_count'] - $a['article_count'];
			}
		);

		return $trends;
	}

	/**
	 * Generate a brief market pulse summary.
	 *
	 * @since 1.1.0
	 *
	 * @param array $articles Articles to summarize.
	 * @return string Market pulse summary.
	 */
	private function generate_market_pulse( $articles ) {
		$count = count( $articles );
		if ( 0 === $count ) {
			return __( 'No recent financial news articles found matching your criteria.', 'mcp-ai-wpoos-pro' );
		}

		$sources = array();
		foreach ( $articles as $article ) {
			if ( ! empty( $article['source'] ) ) {
				$sources[ $article['source'] ] = true;
			}
		}

		return sprintf(
			/* translators: 1: article count, 2: source count, 3: top headline */
			__( 'Market Pulse: %1$d articles aggregated from %2$d sources. Top headline: "%3$s"', 'mcp-ai-wpoos-pro' ),
			$count,
			count( $sources ),
			! empty( $articles[0]['title'] ) ? $articles[0]['title'] : __( 'N/A', 'mcp-ai-wpoos-pro' )
		);
	}
}
