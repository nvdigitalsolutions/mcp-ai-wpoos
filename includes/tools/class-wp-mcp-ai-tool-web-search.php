<?php
/**
 * Tool that performs a web search using the configured provider.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
}

/**
 * Performs lightweight web searches and returns the top results.
 */
class WP_MCP_AI_Tool_Web_Search implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'web_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Web Search', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches the public web via the configured provider and returns the top results.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'       => array(
					'type'        => 'string',
					'description' => __( 'The search query to look up.', 'wp-mcp-ai' ),
				),
				'max_results' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return (1-10).', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 5,
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to perform web searches.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$query = isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '';

		if ( '' === $query ) {
			return new WP_Error( 'wp_mcp_ai_missing_query', __( 'A search query is required.', 'wp-mcp-ai' ) );
		}

		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 5;
		$max_results = $max_results > 0 ? min( $max_results, 10 ) : 5;

		$settings = array();

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
		}

		$provider = isset( $settings['web_search_provider'] ) ? sanitize_key( $settings['web_search_provider'] ) : 'duckduckgo';

		// Check cache first to avoid unnecessary API calls.
		$cache_key     = $this->get_cache_key( $query, $max_results, $provider );
		$cached_result = get_transient( $cache_key );

		if ( false !== $cached_result && is_array( $cached_result ) ) {
			$cached_result['cached'] = true;
			return $cached_result;
		}

		// Check rate limiting after cache check to allow cached results even when rate limited.
		$rate_limit_check = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		// Perform the search.
		if ( 'brave' === $provider ) {
			$result = $this->perform_brave_search( $query, $max_results, $settings );
		} else {
			$result = $this->perform_duckduckgo_search( $query, $max_results );
		}

		// Cache successful results to reduce redundant API calls.
		if ( ! is_wp_error( $result ) ) {
			$cache_ttl = 5 * MINUTE_IN_SECONDS;

			/**
			 * Filter the cache TTL for web search results.
			 *
			 * @param int    $cache_ttl Cache time-to-live in seconds (default: 300).
			 * @param string $query     The search query.
			 * @param string $provider  Search provider name.
			 */
			$cache_ttl = apply_filters( 'wp_mcp_ai_web_search_cache_ttl', $cache_ttl, $query, $provider );

			set_transient( $cache_key, $result, $cache_ttl );
		}

		return $result;
	}

	/**
	 * Check rate limiting for web searches.
	 *
	 * Prevents abuse and infinite loops by limiting search requests per user.
	 *
	 * @param int $user_id User ID performing the search.
	 * @return true|WP_Error True if allowed, WP_Error if rate limit exceeded.
	 */
	protected function check_rate_limit( $user_id ) {
		$transient_key  = 'wp_mcp_ai_web_search_' . $user_id;
		$current_count  = get_transient( $transient_key );
		$max_per_minute = 20; // Allow up to 20 searches per minute per user.

		/**
		 * Filter the maximum web searches allowed per minute per user.
		 *
		 * @param int $max_per_minute Maximum searches per minute (default: 20).
		 * @param int $user_id        User ID.
		 */
		$max_per_minute = apply_filters( 'wp_mcp_ai_web_search_rate_limit', $max_per_minute, $user_id );

		if ( false === $current_count ) {
			// First search, start counting.
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
			return true;
		}

		if ( $current_count >= $max_per_minute ) {
			return new WP_Error(
				'wp_mcp_ai_rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum searches allowed per minute */
					__( 'Web search rate limit exceeded. Maximum %d searches per minute allowed.', 'wp-mcp-ai' ),
					$max_per_minute
				)
			);
		}

		// Increment counter.
		set_transient( $transient_key, $current_count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Generate a cache key for search results.
	 *
	 * @param string $query       The search query.
	 * @param int    $max_results Maximum number of results.
	 * @param string $provider    Search provider name.
	 * @return string Cache key for the search.
	 */
	protected function get_cache_key( $query, $max_results, $provider ) {
		return 'wp_mcp_ai_search_' . md5( $query . '|' . $max_results . '|' . $provider );
	}

	/**
	 * Perform a DuckDuckGo Instant Answer search.
	 *
	 * @param string $query       The sanitized search query.
	 * @param int    $max_results Maximum number of results to return.
	 *
	 * @return array|WP_Error Search response payload or WP_Error on failure.
	 */
	protected function perform_duckduckgo_search( $query, $max_results ) {
		$request_url = add_query_arg(
			array(
				'q'             => $query,
				'format'        => 'json',
				'no_html'       => 1,
				'skip_disambig' => 1,
			),
			'https://api.duckduckgo.com/'
		);

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_failed',
				__( 'The web search request failed.', 'wp-mcp-ai' ),
				$response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 202 === $status_code ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

			$error_data = array(
				'status'      => 202,
				'is_pending'  => true,
				'should_wait' => false, // Don't suggest waiting - continue with alternative approaches.
			);

			if ( ! empty( $retry_after ) ) {
				$error_data['retry_after'] = $retry_after;
			}

			return new WP_Error(
				'wp_mcp_ai_search_pending',
				__( 'The web search provider is temporarily processing this query asynchronously. Since this result is cached, you can try the same query again in a moment if needed. For now, please try a different search query, use alternative information sources, or continue answering the user\'s question with available information.', 'wp-mcp-ai' ),
				$error_data
			);
		}

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_search_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The web search service returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
					$status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error( 'wp_mcp_ai_search_bad_json', __( 'The web search response could not be decoded.', 'wp-mcp-ai' ) );
		}

		$results = array();

		if ( ! empty( $data['AbstractText'] ) && ! empty( $data['AbstractURL'] ) ) {
			$results[] = array(
				'title'   => isset( $data['Heading'] ) ? sanitize_text_field( $data['Heading'] ) : sanitize_text_field( wp_trim_words( $data['AbstractText'], 12 ) ),
				'url'     => esc_url_raw( $data['AbstractURL'] ),
				'snippet' => sanitize_text_field( $data['AbstractText'] ),
				'source'  => 'duckduckgo',
				'type'    => 'abstract',
			);
		}

		if ( ! empty( $data['RelatedTopics'] ) && is_array( $data['RelatedTopics'] ) ) {
			foreach ( $data['RelatedTopics'] as $topic ) {
				if ( $this->maybe_add_topic_result( $topic, $results, $max_results ) ) {
					break;
				}
			}
		}

		// Deduplicate results by URL to prevent repeated content.
		$results = $this->deduplicate_results( $results );
		$results = array_slice( $results, 0, $max_results );

		if ( empty( $results ) ) {
			return array(
				'query'    => $query,
				'results'  => array(),
				'note'     => __( 'No web search results were found for this query.', 'wp-mcp-ai' ),
				'cached'   => false,
				'provider' => 'duckduckgo',
			);
		}

		return array(
			'query'        => $query,
			'results'      => $results,
			'result_count' => count( $results ),
			'cached'       => false,
			'provider'     => 'duckduckgo',
			'timestamp'    => time(),
		);
	}

	/**
	 * Perform a Brave Search API lookup.
	 *
	 * @param string $query       The sanitized search query.
	 * @param int    $max_results Maximum number of results to return.
	 * @param array  $settings    Cached plugin settings.
	 *
	 * @return array|WP_Error Search response payload or WP_Error on failure.
	 */
	protected function perform_brave_search( $query, $max_results, $settings ) {
		$api_key = isset( $settings['brave_search_api_key'] ) ? trim( (string) $settings['brave_search_api_key'] ) : '';

		if ( '' === $api_key ) {
			return new WP_Error( 'wp_mcp_ai_search_missing_api_key', __( 'A Brave Search API key is required to perform searches.', 'wp-mcp-ai' ) );
		}

		$request_url = add_query_arg(
			array(
				'q'          => $query,
				'count'      => max( 1, $max_results ),
				'safesearch' => 'moderate',
			),
			'https://api.search.brave.com/res/v1/web/search'
		);

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'               => 'application/json',
					'X-Subscription-Token' => $api_key,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_failed',
				__( 'The web search request failed.', 'wp-mcp-ai' ),
				$response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 202 === $status_code ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

			$error_data = array(
				'status'      => 202,
				'is_pending'  => true,
				'should_wait' => false, // Don't suggest waiting - continue with alternative approaches.
			);

			if ( ! empty( $retry_after ) ) {
				$error_data['retry_after'] = $retry_after;
			}

			return new WP_Error(
				'wp_mcp_ai_search_pending',
				__( 'The web search provider is temporarily processing this query asynchronously. Since this result is cached, you can try the same query again in a moment if needed. For now, please try a different search query, use alternative information sources, or continue answering the user\'s question with available information.', 'wp-mcp-ai' ),
				$error_data
			);
		}

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_search_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The web search service returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
					$status_code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( null === $data || ! is_array( $data ) ) {
			return new WP_Error( 'wp_mcp_ai_search_bad_json', __( 'The web search response could not be decoded.', 'wp-mcp-ai' ) );
		}

		$results = array();

		if ( isset( $data['web']['results'] ) && is_array( $data['web']['results'] ) ) {
			foreach ( $data['web']['results'] as $item ) {
				if ( empty( $item['url'] ) ) {
					continue;
				}

				$title   = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '';
				$snippet = '';

				if ( ! empty( $item['description'] ) ) {
					$snippet = sanitize_text_field( $item['description'] );
				} elseif ( ! empty( $item['extra_snippets'] ) && is_array( $item['extra_snippets'] ) ) {
					$snippet = sanitize_text_field( implode( ' ', $item['extra_snippets'] ) );
				}

				$results[] = array(
					'title'   => $title ? $title : esc_url_raw( $item['url'] ),
					'url'     => esc_url_raw( $item['url'] ),
					'snippet' => $snippet,
					'source'  => 'brave',
					'type'    => 'result',
				);

				if ( count( $results ) >= $max_results ) {
					break;
				}
			}
		}

		// Deduplicate results by URL to prevent repeated content.
		$results = $this->deduplicate_results( $results );

		if ( empty( $results ) ) {
			return array(
				'query'    => $query,
				'results'  => array(),
				'note'     => __( 'No web search results were found for this query.', 'wp-mcp-ai' ),
				'cached'   => false,
				'provider' => 'brave',
			);
		}

		return array(
			'query'        => $query,
			'results'      => $results,
			'result_count' => count( $results ),
			'cached'       => false,
			'provider'     => 'brave',
			'timestamp'    => time(),
		);
	}

	/**
	 * Maybe add a topic result to the results array.
	 *
	 * @param array $topic       Topic data from DuckDuckGo.
	 * @param array $results     Current list of results (passed by reference).
	 * @param int   $max_results Maximum number of results allowed.
	 *
	 * @return bool Whether the caller should stop processing further topics.
	 */
	protected function maybe_add_topic_result( $topic, array &$results, $max_results ) {
		if ( empty( $topic ) || ! is_array( $topic ) ) {
			return false;
		}

		if ( count( $results ) >= $max_results ) {
			return true;
		}

		if ( isset( $topic['Topics'] ) && is_array( $topic['Topics'] ) ) {
			foreach ( $topic['Topics'] as $nested_topic ) {
				if ( $this->maybe_add_topic_result( $nested_topic, $results, $max_results ) ) {
					return true;
				}
			}

			return false;
		}

		if ( empty( $topic['FirstURL'] ) || empty( $topic['Text'] ) ) {
			return false;
		}

		$results[] = array(
			'title'   => isset( $topic['Text'] ) ? sanitize_text_field( $topic['Text'] ) : '',
			'url'     => esc_url_raw( $topic['FirstURL'] ),
			'snippet' => isset( $topic['Result'] ) ? wp_strip_all_tags( $topic['Result'] ) : '',
			'source'  => 'duckduckgo',
			'type'    => 'result',
		);

		return count( $results ) >= $max_results;
	}

	/**
	 * Deduplicate search results by URL.
	 *
	 * Removes duplicate results to prevent the same content from appearing multiple times,
	 * which can help reduce confusion and prevent infinite loops in agentic workflows.
	 *
	 * @param array $results Array of search results.
	 * @return array Deduplicated results.
	 */
	protected function deduplicate_results( array $results ) {
		$seen_urls = array();
		$unique    = array();

		foreach ( $results as $result ) {
			if ( empty( $result['url'] ) ) {
				continue;
			}

			// Normalize URL for comparison (remove trailing slashes, query params that don't matter).
			$normalized_url = untrailingslashit( $result['url'] );

			// Skip if we've already seen this URL.
			if ( in_array( $normalized_url, $seen_urls, true ) ) {
				continue;
			}

			$seen_urls[] = $normalized_url;
			$unique[]    = $result;
		}

		return $unique;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'requires-credentials', // May require API credentials depending on provider.
			'requires-capability',  // Requires user capabilities.
			'read-only',            // Only retrieves data, does not modify state.
			'external-api',         // Makes external HTTP requests.
			'rate-limited',         // Subject to provider rate limits.
			'cacheable',            // Results can be cached for short periods.
			'network-dependent',    // Requires internet connectivity.
			'non-deterministic',    // Results may vary over time.
			'may-timeout',          // May exceed typical HTTP request timeout.
			'async-capable',        // Can execute asynchronously via cron.
		);
	}
}
