<?php
/**
 * Tool for searching Upwork job postings via the Upwork GraphQL API.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches the Upwork marketplace for job postings matching specified criteria.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Search_Upwork_Jobs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Determine whether CRM toolkit is enabled.
	 *
	 * @since 2.3.0
	 * @return bool
	 */
	public static function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_crm_toolkit'] ) && class_exists( 'WP_MCP_AI_Upwork_Client' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.3.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return __( 'The Search Upwork Jobs tool requires the CRM Toolkit to be enabled in plugin settings.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'The Search Upwork Jobs tool requires the Upwork client integration to be configured.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * GraphQL query used to search job postings.
	 *
	 * @var string
	 */
	const SEARCH_QUERY = '
		query SearchUpworkJobs($marketPlaceJobFilter: MarketplaceJobPostingsSearchFilter, $paging: Paging, $sortAttributes: [MarketplaceJobPostingSearchSortAttribute]) {
			marketplaceJobPostingsSearch(
				marketPlaceJobFilter: $marketPlaceJobFilter,
				paging: $paging,
				sortAttributes: $sortAttributes
			) {
				totalCount
				edges {
					node {
						id
						title
						description
						createdDateTime
						publishedDateTime
						contractorTier
						jobType
						engagement
						duration
						budget { amount currency }
						hourlyBudget { min max currency }
						skills { prettyName }
						client {
							totalFeedback
							totalHires
							totalJobsPosted
							totalSpent { amount currency }
							paymentVerificationStatus
							location { country }
						}
						category { name }
						subcategory { name }
						totalApplicants
						tierText
					}
					cursor
				}
				pageInfo { endCursor hasNextPage }
			}
		}
	';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_upwork_jobs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Upwork Jobs', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search Upwork marketplace job postings with filters for keyword, category, skills, budget, job type, experience level, duration, and more. Returns a paginated list of matching jobs. When no Upwork connection is configured, automatically falls back to web search for job discovery.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Discovering Upwork postings by keyword, category, skills, budget, job type, or experience level.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Evaluating a specific posting; use score_upwork_job. Drafting a response; use draft_upwork_proposal.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'score_upwork_job', 'draft_upwork_proposal', 'import_upwork_project' ),
			'notes'           => __( 'Falls back to web search when no Upwork connection is configured. Paginate with cursor.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'      => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites Upwork connection ID. Optional — when omitted or invalid, falls back to web search.', 'mcp-ai-wpoos-pro' ),
				),
				'query'              => array(
					'type'        => 'string',
					'description' => __( 'Keyword search query.', 'mcp-ai-wpoos-pro' ),
				),
				'location'           => array(
					'type'        => 'string',
					'description' => __( 'Location filter (e.g. "Remote", "United States"). Combined with the keyword query; leave blank for worldwide.', 'mcp-ai-wpoos-pro' ),
				),
				'sort'               => array(
					'type'        => 'string',
					'enum'        => array( 'recency', 'best_match' ),
					'description' => __( 'Sort order. recency returns the newest postings first (default); best_match favours keyword relevance.', 'mcp-ai-wpoos-pro' ),
					'default'     => 'recency',
				),
				'category2'          => array(
					'type'        => 'string',
					'description' => __( 'Job category name (e.g. "Web, Mobile & Software Dev").', 'mcp-ai-wpoos-pro' ),
				),
				'skills'             => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Required skill names.', 'mcp-ai-wpoos-pro' ),
				),
				'budget_min'         => array(
					'type'        => 'number',
					'description' => __( 'Minimum budget amount.', 'mcp-ai-wpoos-pro' ),
				),
				'budget_max'         => array(
					'type'        => 'number',
					'description' => __( 'Maximum budget amount.', 'mcp-ai-wpoos-pro' ),
				),
				'job_type'           => array(
					'type'        => 'string',
					'enum'        => array( 'hourly', 'fixed', 'all' ),
					'description' => __( 'Job type filter. "all" (or omitting the argument) applies no filter.', 'mcp-ai-wpoos-pro' ),
				),
				'experience_level'   => array(
					'type'        => 'string',
					'enum'        => array( 'entry', 'intermediate', 'expert', 'all' ),
					'description' => __( 'Required experience level. "all" (or omitting the argument) applies no filter.', 'mcp-ai-wpoos-pro' ),
				),
				'exclude_keywords'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Keywords to exclude from results (e.g. "homework", "essay"). Mapped to Upwork boolean NOT for the API path and minus operators for the web-search fallback.', 'mcp-ai-wpoos-pro' ),
				),
				'duration_weeks_min' => array(
					'type'        => 'number',
					'description' => __( 'Minimum engagement duration in weeks.', 'mcp-ai-wpoos-pro' ),
				),
				'duration_weeks_max' => array(
					'type'        => 'number',
					'description' => __( 'Maximum engagement duration in weeks.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'              => array(
					'type'        => 'integer',
					'description' => __( 'Number of results to return (1-50, default 10).', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'cursor'             => array(
					'type'        => 'string',
					'description' => __( 'Pagination cursor from a previous search response.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array(),
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
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-capability',
			'external-api',
			'rate-limited',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, $this->get_required_capability() ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to search Upwork jobs.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Resolve defaults from CRM toolkit settings when arguments are omitted.
		$arguments = $this->apply_defaults( $arguments );

		// Normalise "all" sentinel filters to "no filter" so workflow presets
		// carrying job_type/experience_level "all" never leak the literal into
		// the GraphQL filter or the fallback query string.
		if ( isset( $arguments['job_type'] ) && ( '' === $arguments['job_type'] || 'all' === $arguments['job_type'] ) ) {
			unset( $arguments['job_type'] );
		}
		if ( isset( $arguments['experience_level'] ) && ( '' === $arguments['experience_level'] || 'all' === $arguments['experience_level'] ) ) {
			unset( $arguments['experience_level'] );
		}

		// Echo the effective criteria back to the caller so workflow digests
		// can distinguish weak filters from a missing Upwork connection.
		$criteria          = array(
			'query'            => isset( $arguments['query'] ) ? sanitize_text_field( $arguments['query'] ) : '',
			'location'         => isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '',
			'skills'           => isset( $arguments['skills'] ) && is_array( $arguments['skills'] ) ? array_map( 'sanitize_text_field', $arguments['skills'] ) : array(),
			'category2'        => isset( $arguments['category2'] ) ? sanitize_text_field( $arguments['category2'] ) : '',
			'job_type'         => isset( $arguments['job_type'] ) ? sanitize_key( $arguments['job_type'] ) : '',
			'experience_level' => isset( $arguments['experience_level'] ) ? sanitize_key( $arguments['experience_level'] ) : '',
			'budget_min'       => isset( $arguments['budget_min'] ) ? (float) $arguments['budget_min'] : null,
			'budget_max'       => isset( $arguments['budget_max'] ) ? (float) $arguments['budget_max'] : null,
			'exclude_keywords' => isset( $arguments['exclude_keywords'] ) && is_array( $arguments['exclude_keywords'] ) ? array_map( 'sanitize_text_field', $arguments['exclude_keywords'] ) : array(),
			'sort'             => isset( $arguments['sort'] ) ? sanitize_key( $arguments['sort'] ) : 'recency',
			'limit'            => isset( $arguments['limit'] ) ? min( 50, max( 1, absint( $arguments['limit'] ) ) ) : 10,
		);
		$criteria_provided = ( '' !== $criteria['query'] || '' !== $criteria['location'] || ! empty( $criteria['skills'] ) || '' !== $criteria['category2'] || '' !== $criteria['job_type'] || '' !== $criteria['experience_level'] || null !== $criteria['budget_min'] || null !== $criteria['budget_max'] );

		// Determine whether the Upwork API is available.
		$use_api = $this->has_valid_connection( $arguments );

		// Fall back to web search when the Upwork connection is not configured.
		if ( ! $use_api ) {
			return $this->execute_fallback( $arguments, $context, $criteria, $criteria_provided );
		}

		$connection_id = sanitize_text_field( $arguments['connection_id'] );

		// Build GraphQL variables.
		$filter = array();

		// Combine the keyword query with the location filter into a single
		// search expression (Upwork's searchExpression understands partial
		// Lucene syntax incl. OR/AND grouping), so location filtering works
		// without relying on dialect-specific filter keys.
		$search_expression = '';
		if ( ! empty( $arguments['query'] ) ) {
			$search_expression = sanitize_text_field( $arguments['query'] );
		}
		if ( ! empty( $arguments['location'] ) ) {
			$location = sanitize_text_field( $arguments['location'] );
			if ( '' === $search_expression ) {
				$search_expression = $location;
			} else {
				$search_expression .= ' (' . $location . ')';
			}
		}
		if ( ! empty( $arguments['exclude_keywords'] ) && is_array( $arguments['exclude_keywords'] ) && '' !== $search_expression ) {
			// Exclusions use Upwork's documented boolean NOT (uppercase, with
			// parentheses for groups) — the official alternative to the "-"
			// operator, which Upwork search does not support.
			$excludes = array();
			foreach ( $arguments['exclude_keywords'] as $exclude ) {
				$exclude = sanitize_text_field( $exclude );
				if ( '' === $exclude ) {
					continue;
				}
				$excludes[] = false !== strpos( $exclude, ' ' ) ? '"' . $exclude . '"' : $exclude;
			}
			if ( ! empty( $excludes ) ) {
				$search_expression .= ' NOT (' . implode( ' OR ', $excludes ) . ')';
			}
		}
		if ( '' !== $search_expression ) {
			$filter['searchExpression'] = $search_expression;
		}

		if ( ! empty( $arguments['category2'] ) ) {
			$filter['category2'] = sanitize_text_field( $arguments['category2'] );
		}

		if ( ! empty( $arguments['skills'] ) && is_array( $arguments['skills'] ) ) {
			$filter['skills'] = array_map( 'sanitize_text_field', $arguments['skills'] );
		}

		if ( isset( $arguments['budget_min'] ) || isset( $arguments['budget_max'] ) ) {
			$budget = array();
			if ( isset( $arguments['budget_min'] ) ) {
				$budget['min'] = (float) $arguments['budget_min'];
			}
			if ( isset( $arguments['budget_max'] ) ) {
				$budget['max'] = (float) $arguments['budget_max'];
			}
			$filter['budget'] = $budget;
		}

		if ( ! empty( $arguments['job_type'] ) ) {
			// Use the jobType filter for hourly/fixed — distinct from contractorTier (experience level).
			$filter['jobType'] = strtoupper( sanitize_text_field( $arguments['job_type'] ) );
		}

		$exp_map = array(
			'entry'        => 1,
			'intermediate' => 2,
			'expert'       => 3,
		);
		if ( ! empty( $arguments['experience_level'] ) && isset( $exp_map[ $arguments['experience_level'] ] ) ) {
			// contractorTier maps to the experience level tier (1=Entry, 2=Intermediate, 3=Expert).
			$filter['contractorTier'] = $exp_map[ $arguments['experience_level'] ];
		}

		if ( isset( $arguments['duration_weeks_min'] ) || isset( $arguments['duration_weeks_max'] ) ) {
			$duration = array();
			if ( isset( $arguments['duration_weeks_min'] ) ) {
				$duration['min'] = (int) $arguments['duration_weeks_min'];
			}
			if ( isset( $arguments['duration_weeks_max'] ) ) {
				$duration['max'] = (int) $arguments['duration_weeks_max'];
			}
			$filter['durationV3'] = $duration;
		}

		$limit  = isset( $arguments['limit'] ) ? min( 50, max( 1, absint( $arguments['limit'] ) ) ) : 10;
		$paging = array( 'first' => $limit );
		if ( ! empty( $arguments['cursor'] ) ) {
			$paging['after'] = sanitize_text_field( $arguments['cursor'] );
		}

		$variables = array(
			'paging' => $paging,
		);

		if ( ! empty( $filter ) ) {
			$variables['marketPlaceJobFilter'] = $filter;
		}

		// Recency sort — surface the newest postings first so job discovery
		// sees fresh opportunities (the default Upwork feed behaviour).
		$sort = isset( $arguments['sort'] ) ? sanitize_key( $arguments['sort'] ) : 'recency';
		if ( 'recency' === $sort ) {
			$variables['sortAttributes'] = array( array( 'field' => 'RECENCY' ) );
		}

		// Execute the search.
		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-upwork-client.php';
		$client = new WP_MCP_AI_Upwork_Client( $connection_id );
		$result = $client->graphql( self::SEARCH_QUERY, $variables );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$search_data = isset( $result['data']['marketplaceJobPostingsSearch'] )
			? $result['data']['marketplaceJobPostingsSearch']
			: array();

		$jobs      = array();
		$page_info = isset( $search_data['pageInfo'] ) ? $search_data['pageInfo'] : array();
		$total     = isset( $search_data['totalCount'] ) ? (int) $search_data['totalCount'] : 0;
		$edges     = isset( $search_data['edges'] ) ? $search_data['edges'] : array();

		foreach ( $edges as $edge ) {
			$node = isset( $edge['node'] ) ? $edge['node'] : array();
			if ( empty( $node ) ) {
				continue;
			}

			$jobs[] = array(
				'id'            => isset( $node['id'] ) ? $node['id'] : '',
				'title'         => isset( $node['title'] ) ? $node['title'] : '',
				'description'   => isset( $node['description'] ) ? wp_trim_words( $node['description'], 60 ) : '',
				'url'           => self::build_job_url( $node ),
				'created'       => isset( $node['createdDateTime'] ) ? $node['createdDateTime'] : '',
				'published'     => isset( $node['publishedDateTime'] ) ? $node['publishedDateTime'] : '',
				'job_type'      => isset( $node['jobType'] ) ? $node['jobType'] : '',
				'engagement'    => isset( $node['engagement'] ) ? $node['engagement'] : '',
				'duration'      => isset( $node['duration'] ) ? $node['duration'] : '',
				'budget'        => isset( $node['budget'] ) ? $node['budget'] : null,
				'hourly_budget' => isset( $node['hourlyBudget'] ) ? $node['hourlyBudget'] : null,
				'skills'        => isset( $node['skills'] ) ? wp_list_pluck( $node['skills'], 'prettyName' ) : array(),
				'category'      => isset( $node['category']['name'] ) ? $node['category']['name'] : '',
				'subcategory'   => isset( $node['subcategory']['name'] ) ? $node['subcategory']['name'] : '',
				'applicants'    => isset( $node['totalApplicants'] ) ? (int) $node['totalApplicants'] : 0,
				'tier'          => isset( $node['tierText'] ) ? $node['tierText'] : '',
				'client'        => array(
					'feedback'         => isset( $node['client']['totalFeedback'] ) ? (float) $node['client']['totalFeedback'] : null,
					'total_hires'      => isset( $node['client']['totalHires'] ) ? (int) $node['client']['totalHires'] : null,
					'jobs_posted'      => isset( $node['client']['totalJobsPosted'] ) ? (int) $node['client']['totalJobsPosted'] : null,
					'total_spent'      => isset( $node['client']['totalSpent'] ) ? $node['client']['totalSpent'] : null,
					'payment_verified' => isset( $node['client']['paymentVerificationStatus'] ) ? $node['client']['paymentVerificationStatus'] : null,
					'country'          => isset( $node['client']['location']['country'] ) ? $node['client']['location']['country'] : '',
				),
				'cursor'        => isset( $edge['cursor'] ) ? $edge['cursor'] : '',
			);
		}

		$jobs = $this->apply_result_format( $jobs );

		return array(
			'success'           => true,
			'mode'              => 'api',
			'total_count'       => $total,
			'count'             => count( $jobs ),
			'jobs'              => $jobs,
			'page_info'         => $page_info,
			'has_next_page'     => isset( $page_info['hasNextPage'] ) ? (bool) $page_info['hasNextPage'] : false,
			'end_cursor'        => isset( $page_info['endCursor'] ) ? $page_info['endCursor'] : null,
			'criteria'          => $criteria,
			'criteria_provided' => $criteria_provided,
		);
	}

	/**
	 * Check whether the arguments include a valid, enabled Upwork connection.
	 *
	 * @param array $arguments Tool arguments.
	 * @return bool True when the Upwork API can be used.
	 */
	private function has_valid_connection( array $arguments ) {
		if ( empty( $arguments['connection_id'] ) ) {
			return false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return false;
		}

		$connection_id = sanitize_text_field( $arguments['connection_id'] );
		$connection    = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			return false;
		}
		if ( 'upwork' !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
			return false;
		}
		if ( empty( $connection['enabled'] ) ) {
			return false;
		}

		// If explicitly set to web_search mode, never use the API.
		$mode = isset( $connection['upwork_mode'] ) ? $connection['upwork_mode'] : 'api';
		if ( 'web_search' === $mode ) {
			return false;
		}

		// API mode: require OAuth credentials.
		if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) || empty( $connection['refresh_token'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Execute a web-search-based fallback when the Upwork API is unavailable.
	 *
	 * Builds one or more targeted search queries from the provided filters,
	 * runs them through the web_search tool, and returns the results in a
	 * format consistent with the primary API response.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @param array $criteria  Effective search criteria echo (see execute()).
	 * @param bool  $criteria_provided Whether any narrowing criteria were supplied.
	 * @return array|WP_Error Fallback results or error.
	 */
	private function execute_fallback( array $arguments, array $context, array $criteria = array(), $criteria_provided = false ) {
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			return new WP_Error(
				'wp_mcp_ai_fallback_unavailable',
				__( 'Upwork connection is not configured and the web search tool is not available. Please configure an Upwork connection in Remote Sites or enable the web_search tool.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build a descriptive search query from the provided filters.
		$search_query = $this->build_fallback_query( $arguments );

		$limit = isset( $arguments['limit'] ) ? min( 50, max( 1, absint( $arguments['limit'] ) ) ) : 10;
		// Ask the web search provider for its full result cap (10). Category
		// landing pages dominate Upwork SERPs and get dropped by the
		// post-filter below, so request headroom to leave real job postings.
		$max_results = 10;

		// Two-pass strategy: a site-restricted Upwork pass first, then — when
		// the first pass leaves too few job postings — a broader second pass
		// (aggregators, job boards) whose results are merged and deduped.
		// The broad pass always runs as a fallback: with no keyword filters
		// (e.g. the discovery-scan preset with no defaults configured) the
		// first pass still surfaces mostly category pages, and the second
		// pass is the only path to real postings.
		$pass_queries   = array( $search_query );
		$pass_queries[] = $this->build_fallback_query( $arguments, true );

		// Normalise web search results into the standard job listing format.
		$jobs_by_url  = array();
		$filtered_out = 0;
		$passes_run   = 0;

		foreach ( $pass_queries as $pass_query ) {
			$search_result = $web_search_tool->execute(
				array(
					'query'       => $pass_query,
					'max_results' => $max_results,
				),
				$context
			);

			if ( is_wp_error( $search_result ) ) {
				// A failed first pass is fatal; a failed second pass degrades
				// to the first pass's results.
				if ( 0 === $passes_run ) {
					return $search_result;
				}
				break;
			}

			++$passes_run;

			$results = isset( $search_result['results'] ) && is_array( $search_result['results'] )
				? $search_result['results']
				: array();

			// Drop non-job results (Upwork category landing pages, empty entries)
			// so the fallback returns leads the scoring pipeline can actually use.
			$results       = $this->filter_upwork_job_results( $results );
			$filtered_out += count( $search_result['results'] ) - count( $results );

			foreach ( $results as $idx => $result ) {
				$title   = isset( $result['title'] ) ? $result['title'] : '';
				$snippet = isset( $result['snippet'] ) ? $result['snippet'] : '';
				// Canonicalise Upwork SERP URLs (search engines index the
				// /freelance-jobs/apply/ SEO form) into the marketplace's
				// canonical job URL (/jobs/<slug>_~<jobId>/) — the format that
				// reliably resolves to the listing regardless of slug truncation.
				$url = isset( $result['url'] ) ? $this->normalize_upwork_job_url( $result['url'] ) : '';

				// Best-effort structured fields extracted from the snippet, since
				// the web search fallback has no API payload to normalise.
				$meta = $this->extract_snippet_metadata( $snippet );

				$job = array(
					'id'            => 'web_' . ( $idx + 1 ),
					'title'         => $title,
					'description'   => $snippet,
					'url'           => $url,
					'created'       => '',
					'published'     => '' !== $meta['published']
						? $meta['published']
						: ( isset( $result['published_date'] ) ? sanitize_text_field( $result['published_date'] ) : '' ),
					'job_type'      => $meta['job_type'],
					'engagement'    => '',
					'duration'      => '',
					'budget'        => $meta['budget'],
					'budget_max'    => $meta['budget_max'],
					'hourly_budget' => null,
					'skills'        => array(),
					'category'      => '',
					'subcategory'   => '',
					'applicants'    => 0,
					'tier'          => $meta['tier'],
					'client'        => array(
						'feedback'         => null,
						'total_hires'      => null,
						'jobs_posted'      => null,
						'total_spent'      => null,
						'payment_verified' => null,
						'country'          => '',
					),
					'cursor'        => '',
				);

				// Dedupe across passes by URL (first pass wins).
				$dedupe_key = '' !== $url ? $url : strtolower( $title );
				if ( '' !== $dedupe_key && ! isset( $jobs_by_url[ $dedupe_key ] ) ) {
					$jobs_by_url[ $dedupe_key ] = $job;
				}
			}

			// Stop when the first pass already collected a useful batch.
			if ( count( $jobs_by_url ) >= min( $limit, 5 ) ) {
				break;
			}
		}

		$jobs = $this->rank_fallback_jobs( array_values( $jobs_by_url ), $arguments );

		// Respect the caller's limit now that filtering has completed.
		$jobs = array_slice( $jobs, 0, $limit );

		$notice = __( 'Results obtained via web search because no Upwork connection is configured. Data is less structured than the Upwork API. Configure an Upwork connection in Remote Sites for full access to job details, client history, and pagination.', 'mcp-ai-wpoos-pro' );
		if ( ! $criteria_provided ) {
			$notice .= ' ' . __( 'No search criteria were supplied and no CRM search defaults are configured, so results are unfiltered marketplace noise. Pass a query keyword or skills, or set default search keywords in CRM settings, to narrow this search.', 'mcp-ai-wpoos-pro' );
		}
		if ( $filtered_out > 0 ) {
			$notice .= ' ' . sprintf(
				/* translators: %d: number of excluded results */
				_n( '%d result was excluded because it was an Upwork category page, help-centre page, or other non-job listing.', '%d results were excluded because they were Upwork category pages, help-centre pages, or other non-job listings.', $filtered_out, 'mcp-ai-wpoos-pro' ),
				$filtered_out
			);
		}
		if ( $passes_run > 1 ) {
			$notice .= ' ' . __( 'An expanded second search was merged in because the site-restricted first pass returned few job postings.', 'mcp-ai-wpoos-pro' );
		}
		if ( empty( $jobs ) ) {
			$notice .= ' ' . __( 'No individual job postings were found. Pass a query keyword (or configure default search keywords in CRM settings) to narrow the fallback search, or configure an Upwork connection in Remote Sites for direct API search.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'           => true,
			'mode'              => 'fallback',
			'source'            => 'web_search',
			'total_count'       => count( $jobs ),
			'count'             => count( $jobs ),
			'jobs'              => $jobs,
			'page_info'         => array(),
			'has_next_page'     => false,
			'end_cursor'        => null,
			'filtered_out'      => $filtered_out,
			'criteria'          => $criteria,
			'criteria_provided' => $criteria_provided,
			'notice'            => $notice,
		);
	}

	/**
	 * Build a natural-language search query from the tool arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @param bool  $broad     Broad pass: drop the site restriction and phrase
	 *                         the query for aggregators and job boards instead
	 *                         of Upwork's own SERPs.
	 * @return string Search query string.
	 */
	private function build_fallback_query( array $arguments, $broad = false ) {
		// Primary pass: individual postings live under
		// /freelance-jobs/apply/{title}~{jobId}/ — restricting the site search
		// to that subtree keeps bare category pages out of the SERP in the
		// first place instead of relying on post-filtering alone.
		$parts = $broad
			? array( 'upwork', 'freelance', 'job' )
			: array( 'site:upwork.com/freelance-jobs/apply' );

		if ( ! empty( $arguments['query'] ) ) {
			$keyword = sanitize_text_field( $arguments['query'] );
			// Quote multi-word queries so providers treat them as a phrase and
			// surface postings that mention the exact skill set.
			if ( false !== strpos( $keyword, ' ' ) ) {
				$keyword = '"' . $keyword . '"';
			}
			$parts[] = $keyword;
		}

		if ( ! empty( $arguments['category2'] ) ) {
			$parts[] = sanitize_text_field( $arguments['category2'] );
		}

		if ( ! empty( $arguments['skills'] ) && is_array( $arguments['skills'] ) ) {
			// Skills are alternatives for discovery (Upwork's "Any of these
			// words" semantics), so group them as a quoted OR expression — the
			// web-search standard — instead of an implicit AND of bare terms.
			$skill_terms = array();
			foreach ( array_slice( $arguments['skills'], 0, 5 ) as $skill ) {
				$skill = sanitize_text_field( $skill );
				if ( '' === $skill ) {
					continue;
				}
				$skill_terms[] = '"' . $skill . '"';
			}
			if ( ! empty( $skill_terms ) ) {
				$parts[] = '(' . implode( ' OR ', $skill_terms ) . ')';
			}
		}

		if ( ! empty( $arguments['location'] ) ) {
			$parts[] = sanitize_text_field( $arguments['location'] );
		}

		if ( ! empty( $arguments['job_type'] ) && 'all' !== $arguments['job_type'] ) {
			$parts[] = sanitize_text_field( $arguments['job_type'] );
		}

		if ( ! empty( $arguments['experience_level'] ) && 'all' !== $arguments['experience_level'] ) {
			$parts[] = sanitize_text_field( $arguments['experience_level'] ) . ' level';
		}

		if ( isset( $arguments['budget_min'] ) || isset( $arguments['budget_max'] ) ) {
			$budget_str = '';
			if ( isset( $arguments['budget_min'] ) ) {
				$budget_str .= '$' . number_format( (float) $arguments['budget_min'], 0 );
			}
			if ( isset( $arguments['budget_min'] ) && isset( $arguments['budget_max'] ) ) {
				$budget_str .= '-';
			}
			if ( isset( $arguments['budget_max'] ) ) {
				$budget_str .= '$' . number_format( (float) $arguments['budget_max'], 0 );
			}
			if ( $budget_str ) {
				$parts[] = $budget_str;
			}
		}

		// Exclusions become minus operators — the web-search standard for
		// removing noise (e.g. academic-help postings) from the SERP.
		if ( ! empty( $arguments['exclude_keywords'] ) && is_array( $arguments['exclude_keywords'] ) ) {
			foreach ( $arguments['exclude_keywords'] as $exclude ) {
				$exclude = sanitize_text_field( $exclude );
				if ( '' === $exclude ) {
					continue;
				}
				$parts[] = '-"' . $exclude . '"';
			}
		}

		// If no meaningful filters were provided, add a sensible default. The
		// broad pass seeds three generic terms, so its floor is higher.
		$floor = $broad ? 3 : 1;
		if ( count( $parts ) <= $floor ) {
			$parts[] = 'recently posted freelance job openings';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Derive a human-facing Upwork job URL from a GraphQL job node.
	 *
	 * Upwork resolves job URLs by the `~<jobId>` suffix; the title slug is
	 * cosmetic (the server redirects on a mismatched slug), so this works
	 * without an extra API round trip. Matches the shape Upwork itself uses:
	 * `https://www.upwork.com/jobs/<slug>_~<id>/`.
	 *
	 * @param array $node GraphQL job node.
	 * @return string Job URL, or empty string when the node lacks an id/title.
	 */
	private function build_job_url( array $node ) {
		$id    = isset( $node['id'] ) ? trim( (string) $node['id'] ) : '';
		$title = isset( $node['title'] ) ? (string) $node['title'] : '';

		if ( '' === $id || '' === $title ) {
			return '';
		}

		$slug = sanitize_title( $title );
		if ( '' === $slug ) {
			return '';
		}

		return 'https://www.upwork.com/jobs/' . $slug . '_' . $id . '/';
	}

	/**
	 * Rank fallback jobs by how likely they are to be actionable leads.
	 *
	 * Upwork job-post URLs (which carry the `~jobId` suffix) rank above
	 * aggregator listings; keyword matches in the title and snippet follow,
	 * so the most relevant postings surface first before the limit slice.
	 * Deterministic and side-effect free.
	 *
	 * @param array $jobs      Normalised fallback job entries.
	 * @param array $arguments Original tool arguments (query, skills).
	 * @return array Ranked jobs.
	 */
	private function rank_fallback_jobs( array $jobs, array $arguments ) {
		// Build the keyword bag once.
		$tokens = array();
		if ( ! empty( $arguments['query'] ) ) {
			$query_tokens = preg_split( '/\s+/', strtolower( sanitize_text_field( $arguments['query'] ) ) );
			if ( is_array( $query_tokens ) ) {
				$tokens = array_merge( $tokens, $query_tokens );
			}
		}
		if ( ! empty( $arguments['skills'] ) && is_array( $arguments['skills'] ) ) {
			foreach ( $arguments['skills'] as $skill ) {
				$tokens[] = strtolower( sanitize_text_field( $skill ) );
			}
		}
		$tokens = array_values( array_filter( array_unique( array_map( 'trim', $tokens ) ) ) );

		foreach ( $jobs as $idx => $job ) {
			$score = 0;
			$url   = isset( $job['url'] ) ? (string) $job['url'] : '';
			$title = isset( $job['title'] ) ? strtolower( (string) $job['title'] ) : '';
			$desc  = isset( $job['description'] ) ? strtolower( (string) $job['description'] ) : '';

			// Direct Upwork job postings carry the ~jobId suffix on the
			// marketplace host; other Upwork subdomains never host listings.
			if ( $this->is_upwork_marketplace_host( $url ) && false !== strpos( $url, '~' ) ) {
				$score += 100;
			} elseif ( $this->is_upwork_marketplace_host( $url ) ) {
				$score += 30;
			}

			foreach ( $tokens as $token ) {
				if ( '' === $token ) {
					continue;
				}
				if ( false !== strpos( $title, $token ) ) {
					$score += 10;
				}
				if ( false !== strpos( $desc, $token ) ) {
					$score += 3;
				}
			}

			// Prefer job-type signals in the snippet over generic listings.
			if ( '' !== ( isset( $job['job_type'] ) ? (string) $job['job_type'] : '' ) ) {
				$score += 2;
			}

			$jobs[ $idx ]['_rank'] = $score;
		}

		usort(
			$jobs,
			static function ( $a, $b ) {
				return (int) $b['_rank'] <=> (int) $a['_rank'];
			}
		);

		// Drop the internal ranking key before returning.
		foreach ( $jobs as $idx => $job ) {
			unset( $jobs[ $idx ]['_rank'] );
		}

		return $jobs;
	}

	/**
	 * Whether a URL is an Upwork category landing page rather than a job posting.
	 *
	 * Upwork job post URLs carry a `~<jobId>` suffix (e.g.
	 * `/freelance-jobs/Some-Title_~01d7d03bb39cc7daec/`), while category pages
	 * are bare `/freelance-jobs/{category}/`, `/freelance-jobs/apply/{category}/`,
	 * or `/hire/{category}/` paths. Web search engines index the category
	 * pages heavily, so the fallback must recognise and drop them — they
	 * describe a job family, not a bidding opportunity.
	 *
	 * @param string $url Result URL.
	 * @return bool True when the URL is an Upwork category page.
	 */
	private function is_upwork_category_page( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $host ) || false === stripos( $host, 'upwork.com' ) || ! is_string( $path ) ) {
			return false;
		}

		$path = strtolower( rtrim( $path, '/' ) );

		// Bare category/landing paths: /freelance-jobs/{slug}, /hire/{slug},
		// and the category-apply form /freelance-jobs/apply/{category} — a
		// job detail URL always carries the ~jobId suffix, so an anchored
		// slug-only match can never swallow a real posting.
		if ( preg_match( '#^/freelance-jobs/[a-z0-9\-]+$#', $path ) ) {
			return true;
		}
		if ( preg_match( '#^/freelance-jobs/apply/[a-z0-9\-]+$#', $path ) ) {
			return true;
		}
		if ( preg_match( '#^/hire/[a-z0-9\-]+$#', $path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a URL's host is the Upwork job marketplace proper.
	 *
	 * Only `upwork.com` and `www.upwork.com` carry job postings; other
	 * Upwork subdomains (`community.upwork.com`, `support.upwork.com`)
	 * never resolve to a listing, so links to them must not be delivered
	 * as job URLs.
	 *
	 * @param string $url Result URL.
	 * @return bool True when the host is the Upwork marketplace.
	 */
	private function is_upwork_marketplace_host( $url ) {
		$host = wp_parse_url( trim( (string) $url ), PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}
		$host = strtolower( $host );
		return ( 'upwork.com' === $host || 'www.upwork.com' === $host );
	}

	/**
	 * Whether a URL's host belongs to the upwork.com domain family.
	 *
	 * @param string $url Result URL.
	 * @return bool True when the host is upwork.com or any of its subdomains.
	 */
	private function is_upwork_host( $url ) {
		$host = wp_parse_url( trim( (string) $url ), PHP_URL_HOST );
		return is_string( $host ) && false !== stripos( $host, 'upwork.com' );
	}

	/**
	 * Normalise an Upwork marketplace URL to the canonical job URL.
	 *
	 * Search engines index Upwork postings under the SEO form
	 * `/freelance-jobs/apply/<slug>_~<jobId>/` (and sometimes truncate the
	 * slug), while the canonical, reliably-resolving form is
	 * `https://www.upwork.com/jobs/<slug>_~<jobId>/`. Upwork resolves by the
	 * `~<jobId>` suffix, so a truncated slug still lands on the listing.
	 * Non-marketplace URLs (aggregators, community links) pass through
	 * unchanged.
	 *
	 * @param string $url Raw search-result URL.
	 * @return string Canonical job URL, or the original URL when not a marketplace posting.
	 */
	private function normalize_upwork_job_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! $this->is_upwork_marketplace_host( $url ) ) {
			return $url;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return $url;
		}

		// A job posting URL ends with <slug>_~<jobId>/; the ~jobId suffix is
		// the part Upwork resolves. Slug characters stay URL-safe (percent
		// encodings are preserved).
		if ( ! preg_match( '#/([a-z0-9%\-\.]+)_(~[a-z0-9]+)/?$#i', $path, $m ) ) {
			return $url;
		}

		return 'https://www.upwork.com/jobs/' . $m[1] . '_' . $m[2] . '/';
	}

	/**
	 * Whether a title is an Upwork category, help-centre, or community page
	 * title rather than an individual job posting.
	 *
	 * Search engines occasionally merge a category page's title with a job
	 * URL (or vice versa); those entries read like jobs in the digest but
	 * lead to landing pages, so they are dropped.
	 *
	 * @param string $title Search-result title.
	 * @return bool True when the title is a non-job Upwork page title.
	 */
	private function is_upwork_landing_title( $title ) {
		$title = trim( (string) $title );
		if ( '' === $title ) {
			return false;
		}

		if ( 0 === stripos( $title, 'Freelance Jobs on Upwork' ) ) {
			return true;
		}
		if ( false !== stripos( $title, 'Work Remote & Earn Online' ) ) {
			return true;
		}
		if ( 0 === stripos( $title, 'Upwork Customer Service' ) || false !== stripos( $title, ' | Upwork Help' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Drop non-job results from the web search fallback list.
	 *
	 * Keeps only entries that have a title and URL and are not Upwork
	 * category landing pages, so downstream scoring and import steps operate
	 * on actual job leads.
	 *
	 * @param array $results Raw web search results.
	 * @return array Filtered results.
	 */
	private function filter_upwork_job_results( array $results ) {
		$filtered = array();

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$title = isset( $result['title'] ) ? trim( (string) $result['title'] ) : '';
			$url   = isset( $result['url'] ) ? trim( (string) $result['url'] ) : '';

			if ( '' === $title || '' === $url ) {
				continue;
			}

			if ( $this->is_upwork_category_page( $url ) ) {
				continue;
			}

			// Upwork subdomains other than the marketplace (community.,
			// support.) never host job listings.
			if ( $this->is_upwork_host( $url ) && ! $this->is_upwork_marketplace_host( $url ) ) {
				continue;
			}

			// Category/help-centre titles merged onto job URLs read as jobs in
			// the digest but lead to landing pages.
			if ( $this->is_upwork_landing_title( $title ) ) {
				continue;
			}

			$filtered[] = $result;
		}

		return $filtered;
	}

	/**
	 * Extract best-effort structured fields from a search result snippet.
	 *
	 * Upwork SERP snippets embed the job type ("Fixed-price", "Hourly"),
	 * a budget figure (optionally a range), an experience tier, and a
	 * "Posted … ago" recency label. Parsing them gives the fallback the same
	 * shape of data the GraphQL API returns natively, without any scraping —
	 * only the snippet text is read.
	 *
	 * @param string $snippet Search result snippet.
	 * @return array{job_type:string,budget:float|null,budget_max:float|null,tier:string,published:string} Extracted metadata.
	 */
	private function extract_snippet_metadata( $snippet ) {
		$snippet = wp_strip_all_tags( (string) $snippet );

		$meta = array(
			'job_type'   => '',
			'budget'     => null,
			'budget_max' => null,
			'tier'       => '',
			'published'  => '',
		);

		// Job type — Upwork snippets label "Hourly" or "Fixed-price" contracts.
		if ( false !== stripos( $snippet, 'hourly' ) ) {
			$meta['job_type'] = 'hourly';
		} elseif ( false !== stripos( $snippet, 'fixed-price' ) || false !== stripos( $snippet, 'fixed price' ) ) {
			$meta['job_type'] = 'fixed';
		}

		// Budget — the first currency amount in the snippet, plus the upper
		// bound when the snippet carries a range ("$25.00-$45.00",
		// "$500-$1,000"). A range requires either a second dollar sign or a
		// decimal in the first amount, so year-like numbers ("-2024") never
		// false-positive as an upper bound.
		if ( preg_match( '/\$\s?([\d,]+(?:\.\d+)?)\s*[-–—‐]\s*\$\s?([\d,]+(?:\.\d+)?)/', $snippet, $m )
			|| preg_match( '/\$\s?([\d,]+\.\d+)\s*[-–—‐]\s*([\d,]+(?:\.\d+)?)/', $snippet, $m ) ) {
			$meta['budget']     = (float) str_replace( ',', '', $m[1] );
			$meta['budget_max'] = (float) str_replace( ',', '', $m[2] );
		} elseif ( preg_match( '/\$\s?([\d,]+(?:\.\d+)?)/', $snippet, $m ) ) {
			$meta['budget'] = (float) str_replace( ',', '', $m[1] );
		}

		// Experience tier — Upwork snippets prefix the posting with
		// "Entry level", "Intermediate", or "Expert" (optionally with
		// "Experience level"). Anchored to the level wording so a description
		// phrase like "seeking an expert" can't false-positive.
		if ( preg_match( '/entry\s+(?:experience\s+)?level/i', $snippet ) ) {
			$meta['tier'] = 'Entry level';
		} elseif ( preg_match( '/intermediate\s+(?:experience\s+)?level/i', $snippet ) ) {
			$meta['tier'] = 'Intermediate';
		} elseif ( preg_match( '/expert\s+(?:experience\s+)?level/i', $snippet ) ) {
			$meta['tier'] = 'Expert';
		}

		// Recency — "Posted 2 days ago" style labels.
		if ( preg_match( '/posted\s+(.+?ago)/i', $snippet, $m ) ) {
			$meta['published'] = sanitize_text_field( $m[1] );
		}

		return $meta;
	}

	/**
	 * Fill missing search arguments from CRM toolkit settings.
	 *
	 * @since 2.12.0
	 * @param array $arguments Tool arguments.
	 * @return array Arguments with defaults applied.
	 */
	private function apply_defaults( array $arguments ) {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return $arguments;
		}

		$crm_settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$upwork_cfg   = isset( $crm_settings['external_sourcing']['upwork'] )
			? $crm_settings['external_sourcing']['upwork']
			: array();

		// Resolve the connection_id from defaults if not provided.
		if ( empty( $arguments['connection_id'] ) && ! empty( $upwork_cfg['default_connection_id'] ) ) {
			$arguments['connection_id'] = $upwork_cfg['default_connection_id'];
		}

		// Search keywords.
		if ( empty( $arguments['query'] ) && ! empty( $upwork_cfg['default_search_keywords'] ) ) {
			$arguments['query'] = $upwork_cfg['default_search_keywords'];
		}

		// Location.
		if ( empty( $arguments['location'] ) && ! empty( $upwork_cfg['default_location'] ) ) {
			$arguments['location'] = $upwork_cfg['default_location'];
		}

		// Sort order.
		if ( empty( $arguments['sort'] ) && ! empty( $upwork_cfg['default_sort'] ) ) {
			$arguments['sort'] = $upwork_cfg['default_sort'];
		} elseif ( empty( $arguments['sort'] ) ) {
			$arguments['sort'] = 'recency';
		}

		// Job type (hourly / fixed).
		if ( empty( $arguments['job_type'] ) && ! empty( $upwork_cfg['default_job_type'] ) ) {
			$arguments['job_type'] = $upwork_cfg['default_job_type'];
		}

		// Experience level.
		if ( empty( $arguments['experience_level'] ) && ! empty( $upwork_cfg['default_experience_level'] ) ) {
			$arguments['experience_level'] = $upwork_cfg['default_experience_level'];
		}

		// Categories.
		if ( empty( $arguments['category2'] ) && ! empty( $upwork_cfg['default_categories'] ) ) {
			$arguments['category2'] = $upwork_cfg['default_categories'];
		}

		// Budget range from shared defaults.
		if ( empty( $arguments['budget_min'] ) && ! empty( $crm_settings['external_sourcing']['default_budget_min'] ) ) {
			$arguments['budget_min'] = (float) $crm_settings['external_sourcing']['default_budget_min'];
		}
		if ( empty( $arguments['budget_max'] ) && ! empty( $crm_settings['external_sourcing']['default_budget_max'] ) ) {
			$arguments['budget_max'] = (float) $crm_settings['external_sourcing']['default_budget_max'];
		}

		// Max results per search.
		if ( empty( $arguments['limit'] ) && ! empty( $upwork_cfg['max_results_per_search'] ) ) {
			$arguments['limit'] = (int) $upwork_cfg['max_results_per_search'];
		}

		return $arguments;
	}

	/**
	 * Apply result format settings to search results.
	 *
	 * Reads the result_format configuration from CRM toolkit settings
	 * and filters the jobs array accordingly — trimming descriptions,
	 * conditionally removing fields, and applying compact mode.
	 *
	 * @since 2.12.0
	 * @param array $jobs Array of job result arrays.
	 * @return array Filtered jobs.
	 */
	private function apply_result_format( array $jobs ) {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return $jobs;
		}

		$crm_settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
		$fmt          = isset( $crm_settings['external_sourcing']['result_format'] )
			? $crm_settings['external_sourcing']['result_format']
			: array();

		if ( empty( $fmt ) ) {
			return $jobs;
		}

		// Resolve description trim length.  0 = full, >0 = trim to N words.
		$trim_length = isset( $fmt['description_length'] ) ? (int) $fmt['description_length'] : 200;

		foreach ( $jobs as &$job ) {
			// Trim description.
			if ( $trim_length > 0 && ! empty( $job['description'] ) ) {
				$job['description'] = wp_trim_words( $job['description'], $trim_length );
			}

			// Conditionally remove fields.
			if ( empty( $fmt['include_email'] ) ) {
				unset( $job['email'] );
			}
			if ( empty( $fmt['include_client_info'] ) ) {
				unset( $job['client'] );
			}
			if ( empty( $fmt['include_budget'] ) ) {
				unset( $job['budget'], $job['hourly_budget'] );
			}
			if ( empty( $fmt['include_skills'] ) ) {
				unset( $job['skills'] );
			}
			if ( empty( $fmt['include_applicants'] ) ) {
				unset( $job['applicants'] );
			}

			// Compact mode: strip null, empty-string, and empty-array values.
			if ( ! empty( $fmt['compact_mode'] ) ) {
				$job = array_filter(
					$job,
					function ( $v ) {
						if ( is_array( $v ) ) {
							return ! empty( $v );
						}
						return null !== $v && '' !== $v;
					}
				);
			}
		}
		unset( $job );

		return $jobs;
	}
}
