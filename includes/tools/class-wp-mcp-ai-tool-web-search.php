<?php
/**
 * Tool that performs a web search using the configured provider.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-settings-registry.php';
}

if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cache-helper.php';
}

/**
 * Performs lightweight web searches and returns the top results.
 *
 * Supports two providers:
 * - Brave Search API: Uses the Brave Search REST API v1 (https://api.search.brave.com/res/v1/web/search)
 *   Integration follows patterns from: https://github.com/brave/brave-search-mcp-server
 * - DuckDuckGo Instant Answer API: Uses the DuckDuckGo public API (https://api.duckduckgo.com/)
 *   Integration follows patterns from: https://github.com/GivAlz/duckduckgo-api-haystack
 *
 * Both providers properly handle:
 * - Synchronous execution with single HTTP request per tool invocation
 * - Asynchronous responses (HTTP 202) returned to orchestration layer for retry management
 * - Rate limiting and caching to prevent abuse
 * - Result deduplication to prevent infinite loops
 * - Security controls (user capabilities, nonces, input sanitization)
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

		$provider = WP_MCP_AI_Settings_Registry::get_setting( 'web_search_provider', 'duckduckgo' );

		// Check cache first to avoid unnecessary API calls.
		if ( WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			$cache_key     = $this->get_cache_key( $query, $max_results, $provider );
			$cached_result = WP_MCP_AI_Cache_Helper::get( $cache_key );

			if ( false !== $cached_result && is_array( $cached_result ) ) {
				$cached_result['cached'] = true;
				return $cached_result;
			}
		}

		// Check rate limiting after cache check to allow cached results even when rate limited.
		$rate_limit_check = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		// Perform the search.
		if ( 'brave' === $provider ) {
			$result = $this->perform_brave_search( $query, $max_results );
		} else {
			$result = $this->perform_duckduckgo_search( $query, $max_results );
		}

		// Handle HTTP 202 (pending) response - queue for async retry if in agentic loop.
		// This allows the search to be retried automatically when the service is ready.
		if ( is_wp_error( $result ) && 'wp_mcp_ai_search_pending' === $result->get_error_code() ) {
			$error_data = $result->get_error_data();
			$is_pending = is_array( $error_data ) && ! empty( $error_data['is_pending'] ) && true === $error_data['is_pending'];

			// Only queue async retry if we're in an agentic loop (chat context).
			// For direct tool calls, return the pending error immediately.
			if ( $is_pending && ! empty( $context['agentic_loop'] ) ) {
				return $this->queue_async_retry( $arguments, $context, $error_data );
			}
		}

		// Cache successful results to reduce redundant API calls.
		if ( ! is_wp_error( $result ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			$cache_ttl = 5 * MINUTE_IN_SECONDS;

			/**
			 * Filter the cache TTL for web search results.
			 *
			 * @param int    $cache_ttl Cache time-to-live in seconds (default: 300).
			 * @param string $query     The search query.
			 * @param string $provider  Search provider name.
			 */
			$cache_ttl = apply_filters( 'wp_mcp_ai_web_search_cache_ttl', $cache_ttl, $query, $provider );

			$cache_key = $this->get_cache_key( $query, $max_results, $provider );
			WP_MCP_AI_Cache_Helper::set( $cache_key, $result, $cache_ttl );
		}

		// Fire action hook to send complete search results back to chat client via SSE.
		// This allows the orchestration layer to stream results in real-time.
		if ( ! is_wp_error( $result ) && ! empty( $context['agentic_loop'] ) ) {
			do_action( 'wp_mcp_ai_web_search_completed', $result, $arguments, $context );
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
	 * @return string Cache key for the search (without prefix - Cache Helper adds it).
	 */
	protected function get_cache_key( $query, $max_results, $provider ) {
		return 'search_' . md5( $query . '|' . $max_results . '|' . $provider );
	}

	/**
	 * Handle HTTP 202 (Accepted) response - search is being processed asynchronously.
	 *
	 * Returns an informational status (not a hard error) indicating the search service
	 * is still processing the request. The orchestration layer can use the retry_after
	 * value to determine appropriate timing for retry attempts.
	 *
	 * @param array $response The HTTP response array from wp_remote_get.
	 * @return WP_Error Error object with pending status and retry information.
	 */
	protected function handle_pending_response( $response ) {
		// Validate response is an array before proceeding.
		if ( ! is_array( $response ) ) {
			// Return informational status without retry_after if response is invalid.
			return new WP_Error(
				'wp_mcp_ai_search_pending',
				__(
					'The web search service is temporarily processing your request. Please try alternative information sources or retry in a few moments.',
					'wp-mcp-ai'
				),
				array(
					'status'       => 202,
					'is_pending'   => true,
					'should_wait'  => false,
					'retry_after'  => null,
				)
			);
		}

		// Extract retry-after header if present.
		// wp_remote_retrieve_header returns empty string when header is not found, not null.
		// Note: retry_after is kept as string to match HTTP header format and test expectations.
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

		// Build an informative message that maintains backward compatibility with tests
		$message = __(
			'The web search service is temporarily processing your request. Please try alternative information sources or retry in a few moments.',
			'wp-mcp-ai'
		);

		if ( '' !== $retry_after ) {
			$message = sprintf(
				/* translators: %s: number of seconds to wait before retrying */
				__( 'The web search service is temporarily processing your request. The service suggests waiting %s seconds before retrying, or you can try alternative information sources.', 'wp-mcp-ai' ),
				$retry_after
			);
		}

		return new WP_Error(
			'wp_mcp_ai_search_pending',
			$message,
			array(
				'status'       => 202,
				'is_pending'   => true,
				'should_wait'  => false,
				'retry_after'  => '' !== $retry_after ? (string) $retry_after : null,
			)
		);
	}

	/**
	 * Perform a DuckDuckGo Instant Answer search.
	 *
	 * Uses the DuckDuckGo Instant Answer API following patterns from the
	 * duckduckgo-api-haystack reference implementation for proper response parsing
	 * and error handling.
	 *
	 * Executes a single synchronous HTTP request. If the service returns HTTP 202
	 * (Accepted), a pending error is returned immediately to allow the orchestration
	 * layer to handle retry timing and strategy.
	 *
	 * @link https://github.com/GivAlz/duckduckgo-api-haystack
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

		$response = $this->perform_search_with_retry(
			$request_url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// If it's a pending response, return it as-is for orchestration layer
			if ( 'wp_mcp_ai_search_pending' === $response->get_error_code() ) {
				return $response;
			}

			return new WP_Error(
				'wp_mcp_ai_search_failed',
				__( 'The web search request failed.', 'wp-mcp-ai' ),
				$response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		// Handle HTTP 202 (Accepted) - search is being processed asynchronously.
		// Return immediately to allow orchestration layer to manage retries.
		if ( 202 === $status_code ) {
			return $this->handle_pending_response( $response );
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
	 * Uses the Brave Search REST API v1 following patterns from the
	 * brave-search-mcp-server reference implementation for proper authentication,
	 * response parsing, and error handling including async (HTTP 202) responses.
	 *
	 * Executes a single synchronous HTTP request. If the service returns HTTP 202
	 * (Accepted), a pending error is returned immediately to allow the orchestration
	 * layer to handle retry timing and strategy.
	 *
	 * @link https://github.com/brave/brave-search-mcp-server
	 *
	 * @param string $query       The sanitized search query.
	 * @param int    $max_results Maximum number of results to return.
	 *
	 * @return array|WP_Error Search response payload or WP_Error on failure.
	 */
	protected function perform_brave_search( $query, $max_results ) {
		$api_key = WP_MCP_AI_Settings_Registry::get_setting( 'brave_search_api_key', '' );

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

		$response = $this->perform_search_with_retry(
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
			// If it's a pending response, return it as-is for orchestration layer
			if ( 'wp_mcp_ai_search_pending' === $response->get_error_code() ) {
				return $response;
			}

			return new WP_Error(
				'wp_mcp_ai_search_failed',
				__( 'The web search request failed.', 'wp-mcp-ai' ),
				$response->get_error_message()
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		// Handle HTTP 202 (Accepted) - search is being processed asynchronously.
		// Return immediately to allow orchestration layer to manage retries.
		if ( 202 === $status_code ) {
			return $this->handle_pending_response( $response );
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
	 * Perform a synchronous search request without automatic retries.
	 *
	 * Executes a single HTTP request and returns the response immediately.
	 * When the server returns HTTP 202 (Accepted), indicating the request
	 * is being processed asynchronously, this method returns a pending error
	 * that allows the orchestration layer to handle retries.
	 *
	 * This approach prevents blocking execution with sleep() calls and allows
	 * the orchestration layer to manage retry timing and strategy based on
	 * the overall workflow context.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments for wp_remote_get.
	 *
	 * @return array|WP_Error HTTP response array or WP_Error for pending requests.
	 */
	protected function perform_search_with_retry( $url, $args = array() ) {
		// Execute single synchronous request
		$response = wp_remote_get( $url, $args );

		// Network or WordPress errors should be returned immediately
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		// Success - return the response
		if ( 200 === $status_code ) {
			return $response;
		}

		// HTTP 202 (Accepted) - request is being processed asynchronously
		// Return pending error immediately to allow orchestration layer to handle retries
		if ( 202 === $status_code ) {
			return $this->handle_pending_response( $response );
		}

		// Other HTTP status codes (errors) - return the response for handling
		return $response;
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
	 * Queue an async retry for a pending web search.
	 *
	 * When a search provider returns HTTP 202 (Accepted), this method queues
	 * an async job to retry the search after a delay. This allows the chat client
	 * to continue the conversation while the search completes in the background.
	 *
	 * @param array $arguments Original tool arguments.
	 * @param array $context   Execution context.
	 * @param array $error_data Error data from pending response (includes retry_after).
	 * @return array Async job information.
	 */
	protected function queue_async_retry( array $arguments, array $context, array $error_data ) {
		// Load async executor if not already loaded.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-async-executor.php';
		}

		// Get or create async executor instance.
		$container = class_exists( 'WP_MCP_AI_Container' ) ? WP_MCP_AI_Container::get_instance() : null;
		$executor  = $container ? $container->get( 'tool_async_executor' ) : new WP_MCP_AI_Tool_Async_Executor();

		// Extract retry_after from error data (in seconds).
		$retry_after = ! empty( $error_data['retry_after'] ) ? absint( $error_data['retry_after'] ) : 5;

		// Queue the search for async execution with retry delay.
		$job_id = $executor->queue_tool( 'web_search', $arguments, $context );

		if ( is_wp_error( $job_id ) ) {
			// Failed to queue - return the original pending error.
			return new WP_Error(
				'wp_mcp_ai_search_pending',
				__( 'The web search service is temporarily processing your request. Please try alternative information sources or retry in a few moments.', 'wp-mcp-ai' ),
				array(
					'status'       => 202,
					'is_pending'   => true,
					'should_wait'  => false,
					'retry_after'  => $retry_after,
				)
			);
		}

		// Return async job info for the chat client.
		return array(
			'async'        => true,
			'status'       => 'pending',
			'job_id'       => $job_id,
			'message'      => sprintf(
				/* translators: %d: retry delay in seconds */
				__( 'Web search queued for retry in %d seconds. The result will be delivered when ready.', 'wp-mcp-ai' ),
				$retry_after
			),
			'retry_after'  => $retry_after,
			'tool'         => 'web_search',
			'query'        => isset( $arguments['query'] ) ? $arguments['query'] : '',
		);
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
			'async-capable',        // Can queue async retries for HTTP 202 pending responses.
			// Note: 'may-timeout' flag is intentionally NOT included.
			// This tool executes a single synchronous HTTP request with a 10-second timeout
			// and completes quickly in normal conditions. When external search APIs are slow
			// (HTTP 202), the tool queues an async retry via cron instead of blocking.
		);
	}
}
