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

if ( ! interface_exists( 'WP_MCP_AI_Tool_LLM_Sanitizer_Interface' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
}

/**
 * Performs lightweight web searches and returns the top results.
 *
 * This implementation follows industry best practices:
 *
 * **Architecture Patterns**:
 * - Single Responsibility: Tool focuses solely on web search, delegating SSE streaming
 *   to the orchestration layer (Separation of Concerns pattern)
 * - Strategy Pattern: Supports multiple search providers (Brave, DuckDuckGo) via
 *   configurable strategy without changing tool interface
 * - Context-Aware Execution: Adapts behavior based on execution context (agentic loop
 *   vs standalone API call) following Context pattern
 *
 * **WordPress Standards**:
 * - Implements core tool interfaces (WP_MCP_AI_Tool_Interface)
 * - Uses WordPress coding standards (WPCS)
 * - Follows WordPress hook patterns (actions, filters with proper documentation)
 * - Respects capability checks and user permissions
 * - Uses WordPress HTTP API (wp_remote_get) instead of cURL
 *
 * **API Integration Best Practices**:
 * - Brave Search API: Uses the Brave Search REST API v1 (https://api.search.brave.com/res/v1/web/search)
 *   Integration follows patterns from: https://github.com/brave/brave-search-mcp-server
 * - DuckDuckGo Instant Answer API: Uses the DuckDuckGo public API (https://api.duckduckgo.com/)
 *   Integration follows patterns from: https://github.com/GivAlz/duckduckgo-api-haystack
 *
 * **Reliability & Performance**:
 * - Synchronous execution with single HTTP request (10-second timeout, returns quickly)
 * - HTTP 202 (Accepted) responses returned immediately to orchestration layer for async handling
 * - Rate limiting to prevent abuse (configurable via filter)
 * - Result caching to reduce redundant API calls (configurable TTL)
 * - Result deduplication to prevent infinite loops
 *
 * **Security Controls**:
 * - User capability checks (requires 'read' capability minimum)
 * - Input sanitization (esc_url_raw, sanitize_text_field)
 * - Output escaping for safe data transmission
 * - Rate limiting per user to prevent abuse
 * - UTF-8 sanitization to prevent JSON encoding failures
 *
 * **Observability**:
 * - Detailed logging when agentic loop logging is enabled
 * - Action hooks for extensibility and monitoring
 * - Filter hooks for behavior customization
 *
 * **Agentic Loop Integration**:
 * In agentic loop contexts, results are returned synchronously to the orchestration layer
 * which handles SSE streaming via tool_result events. The wp_mcp_ai_web_search_completed
 * action only fires for standalone API calls outside of chat flows, preventing duplicate
 * events that could confuse the chat client (avoiding "double response" anti-pattern).
 *
 * @since 1.0.0
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Tool_Web_Search implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
	/**
	 * Maximum number of results to include in LLM payload.
	 *
	 * Chat client receives all results, but LLM only gets this many to reduce token usage.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_LLM_RESULTS = 3;

	/**
	 * Context flag indicating execution within an agentic loop.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CONTEXT_AGENTIC_LOOP = 'agentic_loop';

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
	 * @since 1.0.0
	 *
	 * @param array $arguments Tool arguments containing 'query' and optional 'max_results'.
	 * @param array $context   Execution context including user_id and agentic_loop flag.
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

		// Validate and normalize the result before caching and returning.
		// This ensures consistent structure and prevents corrupted data from being cached.
		if ( ! is_wp_error( $result ) ) {
			$result = $this->validate_and_normalize_result( $result, $query, $provider );
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

		// Fire action hook for non-agentic contexts (e.g., standalone tool API calls).
		// In agentic loop contexts, the result is already handled by the orchestration layer.
		// which sends it as a tool_result SSE event and adds it to the conversation.
		// Firing this action in agentic contexts would cause duplicate/conflicting events.
		$is_agentic_loop = ! empty( $context[ self::CONTEXT_AGENTIC_LOOP ] );

		/**
		 * Filter whether to fire the web_search_completed action.
		 *
		 * This allows advanced users to override the default behavior of skipping
		 * the action during agentic loops.
		 *
		 * @since 1.0.0
		 *
		 * @param bool  $should_fire   Whether to fire the action. Default false for agentic loops, true otherwise.
		 * @param array $result        Search results array.
		 * @param array $arguments     Original search arguments.
		 * @param array $context       Execution context.
		 * @param bool  $is_agentic_loop Whether executing within an agentic loop.
		 */
		$should_fire_action = apply_filters(
			'wp_mcp_ai_web_search_should_fire_completed_action',
			! $is_agentic_loop,
			$result,
			$arguments,
			$context,
			$is_agentic_loop
		);

		if ( ! is_wp_error( $result ) && $should_fire_action ) {
			// Log action firing for debugging (respects agentic loop logging setting).
			if ( WP_MCP_AI_Admin_Settings::is_agentic_loop_logging_enabled() ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Firing wp_mcp_ai_web_search_completed action',
					array(
						'query'           => $arguments['query'] ?? '',
						'result_count'    => $result['result_count'] ?? 0,
						'provider'        => $result['provider'] ?? 'unknown',
						'cached'          => $result['cached'] ?? false,
						'is_agentic_loop' => $is_agentic_loop,
					)
				);
			}

			/**
			 * Fires when a web search completes successfully outside of agentic loop.
			 *
			 * This hook allows extensions to react to search completions when the
			 * tool is called directly via REST API rather than through the chat flow.
			 *
			 * Note: By default, this action does NOT fire during agentic loop execution
			 * to avoid sending duplicate/conflicting events that could confuse the chat
			 * client. The orchestration layer handles result streaming in that context.
			 *
			 * @since 1.0.0
			 *
			 * @param array $result    Search results array with query, results, provider, etc.
			 * @param array $arguments Original search arguments (query, max_results).
			 * @param array $context   Execution context (user_id, agentic_loop flag, etc.).
			 */
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
					'status'      => 202,
					'is_pending'  => true,
					'should_wait' => false,
					'retry_after' => null,
				)
			);
		}

		// Extract retry-after header if present.
		// wp_remote_retrieve_header returns empty string when header is not found, not null.
		// Note: retry_after is kept as string to match HTTP header format and test expectations.
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

		// Build an informative message that maintains backward compatibility with tests.
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
				'status'      => 202,
				'is_pending'  => true,
				'should_wait' => false,
				'retry_after' => '' !== $retry_after ? (string) $retry_after : null,
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
			// If it's a pending response, return it as-is for orchestration layer.
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
				'title'   => isset( $data['Heading'] ) ? $this->sanitize_utf8( sanitize_text_field( $data['Heading'] ) ) : $this->sanitize_utf8( sanitize_text_field( wp_trim_words( $data['AbstractText'], 12 ) ) ),
				'url'     => esc_url_raw( $data['AbstractURL'] ),
				'snippet' => $this->sanitize_utf8( sanitize_text_field( $data['AbstractText'] ) ),
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
			$task_id = $this->generate_task_id( $query, 'duckduckgo' );
			return array(
				'task_id'        => $task_id,
				'query'          => $query,
				'results'        => array(),
				'note'           => __( 'No web search results were found for this query.', 'wp-mcp-ai' ),
				'cached'         => false,
				'provider'       => 'duckduckgo',
				'system_message' => sprintf(
					/* translators: %s: search query */
					__( 'Web search completed for "%s" but no results were found.', 'wp-mcp-ai' ),
					$query
				),
			);
		}

		// Build descriptive text message for the LLM (removed from base result to prevent SSE streaming extraction).
		// The text will be added by sanitize_for_llm() for LLM consumption only.
		// Include system_message for chat client display without treating it as assistant content.
		$text_parts   = array();
		$text_parts[] = sprintf(
			/* translators: 1: result count, 2: search query */
			_n(
				'Found %1$d web search result for "%2$s"',
				'Found %1$d web search results for "%2$s"',
				count( $results ),
				'wp-mcp-ai'
			),
			count( $results ),
			$query
		);

		// Add brief summary of top results for chat UI visibility.
		if ( ! empty( $results[0]['title'] ) ) {
			$text_parts[] = sprintf(
				/* translators: %s: title of first search result */
				__( 'Top result: %s', 'wp-mcp-ai' ),
				wp_trim_words( $results[0]['title'], 10, '...' )
			);
		}

		$task_id = $this->generate_task_id( $query, 'duckduckgo' );
		return array(
			'task_id'        => $task_id,
			'query'          => $query,
			'results'        => $results,
			'result_count'   => count( $results ),
			'cached'         => false,
			'provider'       => 'duckduckgo',
			'timestamp'      => time(),
			'system_message' => implode( ' ', $text_parts ), // System message for chat client (not extracted as assistant content).
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
			// If it's a pending response, return it as-is for orchestration layer.
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

				$title   = isset( $item['title'] ) ? $this->sanitize_utf8( sanitize_text_field( $item['title'] ) ) : '';
				$snippet = '';

				if ( ! empty( $item['description'] ) ) {
					$snippet = $this->sanitize_utf8( sanitize_text_field( $item['description'] ) );
				} elseif ( ! empty( $item['extra_snippets'] ) && is_array( $item['extra_snippets'] ) ) {
					$snippet = $this->sanitize_utf8( sanitize_text_field( implode( ' ', $item['extra_snippets'] ) ) );
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
			$task_id = $this->generate_task_id( $query, 'brave' );
			return array(
				'task_id'        => $task_id,
				'query'          => $query,
				'results'        => array(),
				'note'           => __( 'No web search results were found for this query.', 'wp-mcp-ai' ),
				'cached'         => false,
				'provider'       => 'brave',
				'system_message' => sprintf(
					/* translators: %s: search query */
					__( 'Web search completed for "%s" but no results were found.', 'wp-mcp-ai' ),
					$query
				),
			);
		}

		// Build descriptive text message for the LLM and chat UI.
		$text_parts   = array();
		$text_parts[] = sprintf(
			/* translators: 1: result count, 2: search query */
			_n(
				'Found %1$d web search result for "%2$s"',
				'Found %1$d web search results for "%2$s"',
				count( $results ),
				'wp-mcp-ai'
			),
			count( $results ),
			$query
		);

		// Add brief summary of top results for chat UI visibility.
		if ( ! empty( $results[0]['title'] ) ) {
			$text_parts[] = sprintf(
				/* translators: %s: title of first search result */
				__( 'Top result: %s', 'wp-mcp-ai' ),
				wp_trim_words( $results[0]['title'], 10, '...' )
			);
		}

		$task_id = $this->generate_task_id( $query, 'brave' );
		return array(
			'task_id'        => $task_id,
			'query'          => $query,
			'results'        => $results,
			'result_count'   => count( $results ),
			'cached'         => false,
			'provider'       => 'brave',
			'timestamp'      => time(),
			'system_message' => implode( ' ', $text_parts ), // System message for chat client (not extracted as assistant content).
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
			'title'   => isset( $topic['Text'] ) ? $this->sanitize_utf8( sanitize_text_field( $topic['Text'] ) ) : '',
			'url'     => esc_url_raw( $topic['FirstURL'] ),
			'snippet' => isset( $topic['Result'] ) ? $this->sanitize_utf8( wp_strip_all_tags( $topic['Result'] ) ) : '',
			'source'  => 'duckduckgo',
			'type'    => 'result',
		);

		return count( $results ) >= $max_results;
	}

	/**
	 * Perform a search request without blocking retry logic.
	 *
	 * Executes a single synchronous HTTP request and returns immediately.
	 * If the server returns HTTP 202 (Accepted), a pending error is returned
	 * to allow the orchestration layer to handle retries asynchronously.
	 *
	 * This approach ensures the tool returns quickly (typically < 10 seconds)
	 * and doesn't block agentic workflows, allowing the LLM to proceed with
	 * alternative information sources or retry later.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Request arguments for wp_remote_get.
	 *
	 * @return array|WP_Error HTTP response array or WP_Error for pending requests.
	 */
	protected function perform_search_with_retry( $url, $args = array() ) {
		// Execute single HTTP request - no retry loop.
		$response = wp_remote_get( $url, $args );

		// Network or WordPress errors should be returned immediately.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		// Success - return the response.
		if ( 200 === $status_code ) {
			return $response;
		}

		// HTTP 202 (Accepted) - request is being processed asynchronously.
		// Return pending error immediately for orchestration layer to handle.
		if ( 202 === $status_code ) {
			return $this->handle_pending_response( $response );
		}

		// Other HTTP status codes (errors) - return the response for handling.
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
			// Note: 'may-timeout' and 'async' flags are intentionally NOT included.
			// This tool executes a single synchronous HTTP request with a 10-second timeout.
			// and completes quickly in normal conditions. When external search APIs return
			// HTTP 202 (pending), the error is returned to the caller for handling.
		);
	}

	/**
	 * Generate a unique task ID for web search results.
	 *
	 * This enables tracking and caching of search results for SSE retrieval,
	 * similar to how Crawl4AI jobs are tracked.
	 *
	 * @param string $query    The search query.
	 * @param string $provider Search provider name.
	 * @return string Unique task identifier.
	 */
	protected function generate_task_id( $query, $provider ) {
		// Generate a unique ID with timestamp and query hash for better uniqueness.
		$timestamp = time();
		$hash      = substr( md5( $query . $provider . microtime( true ) ), 0, 8 );

		return 'search-' . $provider . '-' . $timestamp . '-' . $hash;
	}

	/**
	 * Sanitize web search results for LLM consumption.
	 *
	 * Web searches can return large result sets with long URLs and snippets that
	 * consume many tokens. The LLM doesn't need the full dataset - it only needs
	 * enough information to understand what was found and provide a response.
	 *
	 * This method keeps essential metadata (query, result_count, text summary) and
	 * provides a condensed version of results that's sufficient for the LLM while
	 * the chat client receives the full result set.
	 *
	 * Note: The 'text' field is generated here for LLM consumption and is NOT included
	 * in the base tool result to prevent it from being extracted and streamed as
	 * assistant content during SSE streaming (which would break the chat client connection).
	 *
	 * @param mixed $result Tool execution result.
	 * @return mixed Sanitized result with condensed data for LLM.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Keep only essential fields for the LLM.
		$keep_fields = array(
			'query',        // The search query (essential context).
			'result_count', // How many results were found.
			'note',         // Any notes (e.g., "no results found").
			'provider',     // Which search provider was used.
			'cached',       // Whether results were cached.
		);

		$sanitized = array();
		foreach ( $keep_fields as $key ) {
			if ( isset( $result[ $key ] ) ) {
				$sanitized[ $key ] = $result[ $key ];
			}
		}

		// Generate descriptive text for the LLM.
		// This is created here rather than in the base result to prevent.
		// it from being extracted during SSE streaming fallback text extraction.
		$text = $this->generate_result_text( $result );
		if ( '' !== $text ) {
			$sanitized['text'] = $text;
		}

		// Include a condensed version of results (just titles and URLs, no snippets).
		// This gives the LLM enough context to reference specific results if needed.
		// while dramatically reducing token usage.
		if ( ! empty( $result['results'] ) && is_array( $result['results'] ) ) {
			$condensed_results = array();

			// Only include top results for LLM (chat client gets all results).
			$count = 0;

			foreach ( $result['results'] as $item ) {
				if ( $count >= self::MAX_LLM_RESULTS ) {
					break;
				}

				// Only include title and URL, skip snippets to save tokens.
				$condensed_results[] = array(
					'title' => isset( $item['title'] ) ? $item['title'] : '',
					'url'   => isset( $item['url'] ) ? $item['url'] : '',
				);

				++$count;
			}

			if ( ! empty( $condensed_results ) ) {
				$sanitized['results'] = $condensed_results;
			}
		}

		return ! empty( $sanitized ) ? $sanitized : $result;
	}

	/**
	 * Generate descriptive text for search results.
	 *
	 * Creates a human-readable summary of the search operation for LLM consumption.
	 * This text is only included in the LLM-sanitized version to prevent it from
	 * being extracted and streamed as assistant content during SSE streaming.
	 *
	 * @param array $result Tool execution result.
	 * @return string Descriptive text about the search results.
	 */
	protected function generate_result_text( array $result ) {
		$query = isset( $result['query'] ) ? $result['query'] : '';

		// Handle empty results case.
		if ( empty( $result['results'] ) || 0 === $result['result_count'] ) {
			return sprintf(
				/* translators: %s: search query */
				__( 'Web search completed for "%s" but no results were found.', 'wp-mcp-ai' ),
				$query
			);
		}

		// Build descriptive text for successful search.
		$result_count = isset( $result['result_count'] ) ? absint( $result['result_count'] ) : 0;
		$text_parts   = array();

		$text_parts[] = sprintf(
			/* translators: 1: result count, 2: search query */
			_n(
				'Found %1$d web search result for "%2$s"',
				'Found %1$d web search results for "%2$s"',
				$result_count,
				'wp-mcp-ai'
			),
			$result_count,
			$query
		);

		// Add brief summary of top result if available.
		if ( ! empty( $result['results'][0]['title'] ) ) {
			$text_parts[] = sprintf(
				/* translators: %s: title of first search result */
				__( 'Top result: %s', 'wp-mcp-ai' ),
				wp_trim_words( $result['results'][0]['title'], 10, '...' )
			);
		}

		return implode( ' ', $text_parts );
	}

	/**
	 * Sanitize a string to ensure it contains only valid UTF-8 characters.
	 *
	 * Search results from external APIs may contain invalid UTF-8 sequences
	 * that can cause wp_json_encode() to fail, corrupting SSE streams and
	 * causing HTTP2 protocol errors. This method removes or replaces invalid
	 * sequences to ensure the string is safe for JSON encoding.
	 *
	 * @param string $string String to sanitize.
	 * @return string Sanitized string with only valid UTF-8 characters.
	 */
	protected function sanitize_utf8( $string ) {
		// Return early for non-strings.
		if ( ! is_string( $string ) ) {
			return $string;
		}

		// Remove invalid UTF-8 sequences from the source string.
		// The iconv IGNORE flag skips any bytes that are not valid in the source encoding (UTF-8),.
		// effectively removing malformed UTF-8 sequences while preserving valid characters.
		$sanitized = iconv( 'UTF-8', 'UTF-8//IGNORE', $string );

		// If iconv failed (returned false), fall back to mb_convert_encoding.
		if ( false === $sanitized && function_exists( 'mb_convert_encoding' ) ) {
			$sanitized = mb_convert_encoding( $string, 'UTF-8', 'UTF-8' );
		}

		// If both methods failed, use preg_replace to remove common problematic control characters.
		// This targets specific control characters (null bytes, form feed, etc.) that often cause issues.
		// Note: Not using 'u' modifier since we're dealing with potentially invalid UTF-8.
		if ( false === $sanitized ) {
			$sanitized = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $string );
		}

		// Final fallback: if still invalid, return empty string.
		if ( false === $sanitized ) {
			return '';
		}

		return $sanitized;
	}

	/**
	 * Validate and normalize search results to ensure consistent structure.
	 *
	 * This method ensures that all search results have the expected structure
	 * and all required fields are present. It also validates that the data can
	 * be JSON-encoded without errors, which is critical for SSE streaming.
	 *
	 * @param array  $result   Raw search result from provider.
	 * @param string $query    Original search query.
	 * @param string $provider Provider name.
	 * @return array Validated and normalized result.
	 */
	protected function validate_and_normalize_result( array $result, $query, $provider ) {
		// Ensure required top-level fields are present.
		$normalized = array(
			'query'        => isset( $result['query'] ) ? $this->sanitize_utf8( $result['query'] ) : $query,
			'provider'     => isset( $result['provider'] ) ? sanitize_key( $result['provider'] ) : $provider,
			'cached'       => isset( $result['cached'] ) ? (bool) $result['cached'] : false,
			'results'      => isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : array(),
			'result_count' => isset( $result['result_count'] ) ? absint( $result['result_count'] ) : 0,
		);

		// Preserve task_id if present (critical for SSE tracking).
		if ( isset( $result['task_id'] ) ) {
			$normalized['task_id'] = sanitize_text_field( $result['task_id'] );
		}

		// Add optional fields if present.
		if ( isset( $result['note'] ) ) {
			$normalized['note'] = $this->sanitize_utf8( $result['note'] );
		}

		if ( isset( $result['system_message'] ) ) {
			$normalized['system_message'] = $this->sanitize_utf8( $result['system_message'] );
		}

		if ( isset( $result['timestamp'] ) ) {
			$normalized['timestamp'] = absint( $result['timestamp'] );
		}

		// Validate each result item to ensure it can be JSON-encoded.
		$validated_results = array();
		foreach ( $normalized['results'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			// Ensure each result has required fields with safe values.
			$validated_item = array(
				'title'   => isset( $item['title'] ) ? $this->sanitize_utf8( $item['title'] ) : '',
				'url'     => isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '',
				'snippet' => isset( $item['snippet'] ) ? $this->sanitize_utf8( $item['snippet'] ) : '',
				'source'  => isset( $item['source'] ) ? sanitize_key( $item['source'] ) : $provider,
				'type'    => isset( $item['type'] ) ? sanitize_key( $item['type'] ) : 'result',
			);

			// Skip items with no title and no URL (invalid results).
			if ( '' === $validated_item['title'] && '' === $validated_item['url'] ) {
				continue;
			}

			// Verify this item can be JSON-encoded before including it.
			$test_encode = wp_json_encode( $validated_item );
			if ( false !== $test_encode ) {
				$validated_results[] = $validated_item;
			} else {
				// Log the problematic item for debugging.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error(
						'web_search_result_json_encode_failed',
						'Failed to JSON encode search result item',
						array(
							'query'      => $query,
							'provider'   => $provider,
							'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'Unknown',
						)
					);
				}
			}
		}

		$normalized['results']      = $validated_results;
		$normalized['result_count'] = count( $validated_results );

		// Update system_message field if result count changed due to validation.
		if ( isset( $normalized['system_message'] ) && count( $validated_results ) !== count( $result['results'] ) ) {
			$normalized['system_message'] = sprintf(
				/* translators: 1: result count, 2: search query */
				_n(
					'Found %1$d web search result for "%2$s"',
					'Found %1$d web search results for "%2$s"',
					count( $validated_results ),
					'wp-mcp-ai'
				),
				count( $validated_results ),
				$query
			);

			if ( ! empty( $validated_results[0]['title'] ) ) {
				$normalized['system_message'] .= ' ' . sprintf(
					/* translators: %s: title of first search result */
					__( 'Top result: %s', 'wp-mcp-ai' ),
					wp_trim_words( $validated_results[0]['title'], 10, '...' )
				);
			}
		}

		// Final validation: ensure the entire result can be JSON-encoded.
		$final_encode = wp_json_encode( $normalized );
		if ( false === $final_encode ) {
			// If even the normalized result fails encoding, return a safe minimal result.
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_error(
					'web_search_complete_result_json_encode_failed',
					'Failed to JSON encode complete search result',
					array(
						'query'      => $query,
						'provider'   => $provider,
						'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'Unknown',
					)
				);
			}

			// Return minimal safe result.
			return array(
				'query'          => $query,
				'provider'       => $provider,
				'cached'         => false,
				'results'        => array(),
				'result_count'   => 0,
				'note'           => __( 'Search completed but results could not be properly encoded for transmission.', 'wp-mcp-ai' ),
				'system_message' => sprintf(
					/* translators: %s: search query */
					__( 'Web search for "%s" completed but encountered data encoding issues.', 'wp-mcp-ai' ),
					$query
				),
			);
		}

		return $normalized;
	}
}
