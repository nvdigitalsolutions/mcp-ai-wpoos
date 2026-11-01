<?php
/**
 * Tool that performs comprehensive deep research on any topic using multi-step AI analysis.
 *
 * This tool is provider-agnostic and works with OpenAI, Gemini, Anthropic, Ollama, and all
 * - All supported AI providers (including DeepSeek). It combines web search with iterative AI analysis to produce
 * comprehensive research reports.
 *
 * Recommended Models for Deep Research (February 2026):
 * - OpenAI: gpt-4o (multimodal, fast, cost-effective) or gpt-4.5/o3 (advanced reasoning)
 * - Gemini: gemini-3.1-pro-preview (flagship, 1M token context, 64K output, agentic capabilities)
 * - Anthropic: claude-opus-4.5 (highest intelligence, 200K context, persistent memory)
 * - Cloudflare: @cf/meta/llama-4-scout-17b-instruct or @cf/deepseek/deepseek-v3.2-thinking
 * - HuggingFace: meta-llama/Llama-3.3-70B-Instruct or deepseek-ai/DeepSeek-V3.2
 * - Ollama: llama3.3 or deepseek-r1 (privacy-focused local research)
 * - DeepSeek: deepseek-flash (V4.1 Flash — thinking + non-thinking, native vision)
 * - OpenRouter: openrouter/auto (access to 200+ models) or any specific model
 * - NVIDIA NIM: meta/llama-3.1-8b-instruct (fast inference)
 * - LM Studio: local models (privacy-focused, no API key needed)
 * - DigitalOcean: llama3.3-70b-instruct (cloud-hosted open models)
 * - Kimi: kimi-k3 (2.8T MoE, 1M context, Moonshot AI)
 * - Baseten: deepseek-ai/DeepSeek-V3 (serverless inference)
 * - Z.AI: GLM models (Chinese/English bilingual)
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cache-helper.php';
}

/**
 * Deep Research Tool
 *
 * Performs comprehensive research by:
 * 1. Using web search to gather relevant information
 * 2. Analyzing and synthesizing findings with AI
 * 3. Generating a detailed research report
 *
 * Works with any configured AI provider (OpenAI, Gemini, Anthropic, Ollama, etc.)
 */
class WP_MCP_AI_Tool_Deep_Research implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum number of search queries to perform.
	 *
	 * This is a safety ceiling; the effective per-run count is derived from
	 * the requested depth (basic 1, standard 3, comprehensive 6) and can be
	 * lowered via the `wp_mcp_ai_deep_research_max_queries` filter.
	 *
	 * @var int
	 */
	const MAX_SEARCH_QUERIES = 6;

	/**
	 * Maximum results per search query.
	 *
	 * @var int
	 */
	const MAX_RESULTS_PER_QUERY = 5;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'deep_research';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Deep Research', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( '(Pro) Performs comprehensive deep research on any topic using multi-step web search and AI analysis. Works with all supported AI providers (OpenAI, Gemini, Anthropic, Cloudflare, HuggingFace, Ollama). Generates detailed research reports with findings and citations. Configure a dedicated research model in Settings → NV oOS → deep_research_model.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Producing a cited multi-source research report on a topic through iterative web search and AI analysis.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'For a quick single search use web_search; for site-specific knowledge use semantic_content_search.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'web_search', 'semantic_content_search', 'store_agent_context', 'research_status', 'research_result', 'research_cancel' ),
			'notes'           => __( 'Depth is basic, standard, or comprehensive; run_mode=background uses cron and results are cached for one hour. Background jobs are polled with research_status, read with research_result, and stopped with research_cancel. Reports carry a status (complete/partial), validation results, warnings, pages read, and elapsed time.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'topic'                => array(
					'type'        => 'string',
					'description' => __( 'The research topic or question to investigate.', 'mcp-ai-wpoos' ),
				),
				'depth'                => array(
					'type'        => 'string',
					'description' => __( 'Research depth level.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas'          => array(
					'type'        => 'array',
					'description' => __( 'Optional specific aspects to focus on (e.g., "technical", "historical", "economic").', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'include_sources'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include source citations in the research report.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'run_mode'             => array(
					'type'        => 'string',
					'description' => __( 'Execution mode: "immediate" runs synchronously, "background" schedules via WordPress cron for long-running research.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'immediate', 'background' ),
					'default'     => 'immediate',
				),
				'include_site_content' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to search local WordPress content and the assistant\'s configured Vector Store as additional research sources. Combines site knowledge with web search results.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'memory_agent_id'      => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent or assistant ID used to recall prior research from agent memory before starting. When provided, relevant prior findings are included in the analysis context to avoid duplicate research.', 'mcp-ai-wpoos' ),
				),
				'store_to_memory'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to automatically store the completed research report in agent memory so it can be recalled in future sessions. Requires memory_agent_id.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'             => array( 'topic' ),
			'additionalProperties' => false,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'research_discovery',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'researcher', 'analyst', 'journalist' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                   // Tool is part of the Pro tier/addon.
			'requires-credentials',  // Requires AI provider API keys.
			'consumes-tokens',       // Uses AI model tokens.
			'external-api',          // Makes external API calls (web search + AI).
			'network-dependent',     // Requires internet connectivity.
			'may-timeout',           // Research can take significant time.
			'cacheable',             // Results can be cached.
			'read-only',             // Only retrieves and analyzes data.
			'non-deterministic',     // Results may vary over time.
			'background-capable',    // Supports background execution via cron.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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

		// Check permissions - requires read capability.
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to perform deep research.', 'mcp-ai-wpoos' )
			);
		}

		// Validate multisite access.
		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Validate required arguments.
		if ( empty( $arguments['topic'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_topic',
				__( 'A research topic is required.', 'mcp-ai-wpoos' )
			);
		}

		$topic                = sanitize_text_field( $arguments['topic'] );
		$depth                = isset( $arguments['depth'] ) ? sanitize_key( $arguments['depth'] ) : 'standard';
		$focus_areas          = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] ) ? array_map( 'sanitize_text_field', $arguments['focus_areas'] ) : array();
		$include_sources      = isset( $arguments['include_sources'] ) ? (bool) $arguments['include_sources'] : true;
		$run_mode             = isset( $arguments['run_mode'] ) ? sanitize_key( $arguments['run_mode'] ) : 'immediate';
		$include_site_content = isset( $arguments['include_site_content'] ) ? (bool) $arguments['include_site_content'] : true;
		$store_to_memory      = isset( $arguments['store_to_memory'] ) ? (bool) $arguments['store_to_memory'] : false;
		$memory_agent_id      = '';
		if ( ! empty( $arguments['memory_agent_id'] ) ) {
			$memory_agent_id = is_numeric( $arguments['memory_agent_id'] )
				? absint( $arguments['memory_agent_id'] )
				: sanitize_text_field( $arguments['memory_agent_id'] );
		}

		// Validate depth.
		if ( ! in_array( $depth, array( 'basic', 'standard', 'comprehensive' ), true ) ) {
			$depth = 'standard';
		}

		// Validate run mode.
		if ( ! in_array( $run_mode, array( 'immediate', 'background' ), true ) ) {
			$run_mode = 'immediate';
		}

		// Handle background execution mode.
		if ( 'background' === $run_mode ) {
			return $this->schedule_background_research( $topic, $depth, $focus_areas, $include_sources, $user_id, $include_site_content, $memory_agent_id, $store_to_memory );
		}

		// Check cache first.
		if ( WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			$cache_key     = $this->get_cache_key( $topic, $depth, $focus_areas );
			$cached_result = WP_MCP_AI_Cache_Helper::get( $cache_key );

			if ( false !== $cached_result && is_array( $cached_result ) ) {
				$cached_result['cached'] = true;
				return $cached_result;
			}
		}

		// Log research start.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'deep_research_started',
				'Starting deep research',
				array(
					'topic'                => $topic,
					'depth'                => $depth,
					'focus_areas'          => $focus_areas,
					'user_id'              => $user_id,
					'include_site_content' => $include_site_content,
					'memory_agent_id'      => $memory_agent_id ? (string) $memory_agent_id : '',
				)
			);
		}

		// Step 0: Recall prior research from agent memory (if agent ID provided).
		$prior_memory = array();
		if ( $memory_agent_id ) {
			$prior_memory = $this->recall_prior_research( $topic, $memory_agent_id, $context );
		}

		// Track elapsed time for budget enforcement and diagnostics.
		$start_time = microtime( true );

		// Step 1: Perform web searches to gather information. The gathered
		// evidence is persisted between stages so a failed synthesis or a
		// validation retry never repeats the (network-bound) searches.
		$search_results = $this->gather_information( $topic, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			return $search_results;
		}

		// Step 1b: Optionally search local WordPress content and vector store.
		$site_content = array();
		if ( $include_site_content ) {
			$site_content = $this->gather_site_content( $topic, $focus_areas, $context );
		}

		// Step 1c: Read full pages for the top sources so the synthesis works
		// from primary material instead of search snippets alone. Bounded by
		// depth, page count, and the elapsed-time budget; always optional.
		$pages = $this->read_source_pages( $search_results['sources'] ?? array(), $depth, $start_time );

		// Step 2: Analyze findings with AI (now including site content and prior memory).
		// The analysis stage validates the draft report and retries once when
		// quality checks fail before giving up with an explicit partial result.
		$analysis_result = $this->analyze_findings( $topic, $search_results, $depth, $focus_areas, $include_sources, $context, $site_content, $prior_memory, $pages, $start_time );

		if ( is_wp_error( $analysis_result ) ) {
			return $analysis_result;
		}

		// Step 3: Build final research report (with completion diagnostics).
		$research_report = $this->build_research_report( $topic, $depth, $analysis_result, $search_results, $include_sources, $site_content, $start_time, $pages );

		// Step 4: Store research in agent memory if requested.
		if ( $store_to_memory && $memory_agent_id ) {
			$this->persist_research( $topic, $depth, $research_report, $memory_agent_id, $context );
		}

		// Cache the results for 1 hour — but never cache a failed generation:
		// caching a partial/empty report would poison the cache for the TTL
		// window.
		if ( WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			$report_word_count = isset( $research_report['word_count'] ) ? absint( $research_report['word_count'] ) : 0;

			if ( $report_word_count > 0 && 'complete' === ( $research_report['status'] ?? 'partial' ) ) {
				$cache_key = $this->get_cache_key( $topic, $depth, $focus_areas );
				$cache_ttl = HOUR_IN_SECONDS;

				/**
				 * Filter the cache TTL for deep research results.
				 *
				 * @param int    $cache_ttl  Cache time-to-live in seconds (default: 3600).
				 * @param string $topic      The research topic.
				 * @param string $depth      Research depth level.
				 * @param array  $focus_areas Focus areas.
				 */
				$cache_ttl = apply_filters( 'wp_mcp_ai_deep_research_cache_ttl', $cache_ttl, $topic, $depth, $focus_areas );

				WP_MCP_AI_Cache_Helper::set( $cache_key, $research_report, $cache_ttl );
			} elseif ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'deep_research_cache_skipped',
					'Deep research report was not cached because it is empty or partial.',
					array(
						'topic'  => $topic,
						'depth'  => $depth,
						'status' => $research_report['status'] ?? 'unknown',
					)
				);
			}
		}

		// Log success.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'deep_research_completed',
				'Deep research completed successfully',
				array(
					'topic'                => $topic,
					'depth'                => $depth,
					'status'               => $research_report['status'] ?? 'unknown',
					'sources_used'         => count( $research_report['sources'] ?? array() ),
					'pages_read'           => absint( $research_report['pages_read'] ?? 0 ),
					'word_count'           => str_word_count( $research_report['report'] ?? '' ),
					'validation_passed'    => $research_report['validation']['passed'] ?? false,
					'warnings'             => $research_report['warnings'] ?? array(),
					'elapsed_seconds'      => $research_report['elapsed_seconds'] ?? 0,
					'site_content_matches' => count( $site_content ),
					'prior_memory_items'   => count( $prior_memory ),
					'stored_to_memory'     => $store_to_memory && $memory_agent_id,
				)
			);
		}

		return $research_report;
	}

	/**
	 * Gather information through web searches.
	 *
	 * @param string $topic       Research topic.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @param array  $context     Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function gather_information( $topic, $depth, $focus_areas, $context ) {
		// Check if web search tool is available.
		$registry        = WP_MCP_AI_Tool_Registry::get_instance();
		$web_search_tool = $registry->get_tool( 'web_search' );

		if ( ! $web_search_tool ) {
			return new WP_Error(
				'wp_mcp_ai_web_search_unavailable',
				__( 'Web search tool is not available. Deep research requires web search capability.', 'mcp-ai-wpoos' )
			);
		}

		// Evidence is persisted between stages: when a previous run already
		// gathered (and stored) search results for this exact topic/depth/
		// focus-area combination, reuse them instead of repeating the
		// network-bound searches.
		$cache_key = $this->get_cache_key( $topic, $depth, $focus_areas );
		$evidence  = $this->get_stored_evidence( $cache_key );

		if ( ! empty( $evidence['results'] ) ) {
			$evidence['reused'] = true;
			return $evidence;
		}

		// Generate search queries based on depth and focus areas.
		$search_queries = $this->generate_search_queries( $topic, $depth, $focus_areas );

		$all_results    = array();
		$all_sources    = array();
		$failed_queries = array();

		foreach ( $search_queries as $query ) {
			// Execute web search with one targeted retry on transient failure.
			$search_result = $this->execute_web_search_with_retry( $web_search_tool, $query, $context );

			if ( is_wp_error( $search_result ) ) {
				// Log the error but continue with other searches.
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error(
						'Deep research web search failed: ' . $search_result->get_error_message(),
						array(
							'query' => $query,
							'topic' => $topic,
						)
					);
				}
				$failed_queries[] = $query;
				continue;
			}

			// Collect results.
			if ( ! empty( $search_result['results'] ) && is_array( $search_result['results'] ) ) {
				foreach ( $search_result['results'] as $result ) {
					$all_results[] = $result;
					if ( ! empty( $result['url'] ) ) {
						$all_sources[] = array(
							'url'     => $result['url'],
							'title'   => isset( $result['title'] ) ? $result['title'] : '',
							'snippet' => isset( $result['snippet'] ) ? $result['snippet'] : '',
						);
					}
				}
			}
		}

		if ( empty( $all_results ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_search_results',
				__( 'No web search results found for the research topic.', 'mcp-ai-wpoos' ),
				array(
					'status'         => 502,
					'failed_queries' => $failed_queries,
				)
			);
		}

		// Deduplicate sources by URL and cap per-domain dominance.
		$all_sources = $this->deduplicate_sources( $all_sources );

		$evidence = array(
			'results'        => $all_results,
			'sources'        => $all_sources,
			'queries'        => $search_queries,
			'failed_queries' => $failed_queries,
			'reused'         => false,
		);

		// Persist the evidence so later stages (and background reruns) do not
		// need to repeat the searches.
		$this->store_evidence( $cache_key, $evidence );

		return $evidence;
	}

	/**
	 * Execute a single web search query with one targeted retry.
	 *
	 * @param object $tool    Web search tool instance.
	 * @param string $query   Search query.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Search results or error.
	 */
	protected function execute_web_search_with_retry( $tool, $query, $context ) {
		/**
		 * Filter the number of extra search attempts per query (default 1).
		 *
		 * @since 1.2.0
		 *
		 * @param int $retries Extra attempts after the first failure.
		 */
		$retries = (int) apply_filters( 'wp_mcp_ai_deep_research_search_retries', 1 );
		$retries = max( 0, min( $retries, 3 ) );

		$result = $tool->execute(
			array(
				'query'       => $query,
				'max_results' => self::MAX_RESULTS_PER_QUERY,
			),
			$context
		);

		$remaining = $retries;
		while ( $remaining > 0 && is_wp_error( $result ) ) {
			$result = $tool->execute(
				array(
					'query'       => $query,
					'max_results' => self::MAX_RESULTS_PER_QUERY,
				),
				$context
			);
			--$remaining;
		}

		return $result;
	}

	/**
	 * Retrieve previously persisted search evidence for a research run.
	 *
	 * @param string $cache_key Cache key for the topic/depth/focus-area set.
	 * @return array Evidence array (empty when nothing stored).
	 */
	protected function get_stored_evidence( $cache_key ) {
		$stored = get_transient( 'wp_mcp_ai_dr_evidence_' . $cache_key );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persist search evidence so later stages can reuse it.
	 *
	 * @param string $cache_key Cache key for the topic/depth/focus-area set.
	 * @param array  $evidence  Evidence array.
	 * @return void
	 */
	protected function store_evidence( $cache_key, $evidence ) {
		set_transient( 'wp_mcp_ai_dr_evidence_' . $cache_key, $evidence, HOUR_IN_SECONDS );
	}

	/**
	 * Generate search queries based on topic and parameters.
	 *
	 * @param string $topic       Research topic.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @return array Search queries.
	 */
	protected function generate_search_queries( $topic, $depth, $focus_areas ) {
		$queries = array();

		// Main query.
		$queries[] = $topic;

		// Determine number of additional queries based on depth. Depth is made
		// measurable: basic covers the topic only, standard adds overview and
		// focus-area subquestions, comprehensive adds evidence-oriented queries
		// (official documentation, data, and counter-evidence).
		$num_queries = 'basic' === $depth ? 1 : ( 'comprehensive' === $depth ? 6 : 3 );

		// Add focus area queries (subquestions).
		if ( ! empty( $focus_areas ) ) {
			foreach ( $focus_areas as $area ) {
				if ( count( $queries ) >= $num_queries ) {
					break;
				}
				$queries[] = $topic . ' ' . $area;
			}
		}

		// Add depth-specific queries.
		if ( count( $queries ) < $num_queries && 'comprehensive' === $depth ) {
			$queries[] = $topic . ' official documentation';
		}
		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				$queries[] = $topic . ' statistics and data';
			} elseif ( 'standard' === $depth ) {
				$queries[] = $topic . ' overview';
			}
		}
		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				$queries[] = $topic . ' limitations criticism controversy';
			} elseif ( 'standard' === $depth ) {
				$queries[] = $topic . ' key facts and figures';
			}
		}
		if ( 'comprehensive' === $depth && count( $queries ) < $num_queries ) {
			$queries[] = $topic . ' history and background';
		}
		if ( 'comprehensive' === $depth && count( $queries ) < $num_queries ) {
			$queries[] = $topic . ' latest developments news';
		}

		/**
		 * Filter the maximum number of search queries per research run.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_queries Default 6 (the class constant).
		 */
		$max_queries = (int) apply_filters( 'wp_mcp_ai_deep_research_max_queries', self::MAX_SEARCH_QUERIES );
		$max_queries = max( 1, min( $max_queries, 12 ) );

		return array_slice( $queries, 0, min( $max_queries, $num_queries ) );
	}

	/**
	 * Analyze findings using AI.
	 *
	 * @param string     $topic          Research topic.
	 * @param array      $search_results Search results.
	 * @param string     $depth          Research depth.
	 * @param array      $focus_areas    Focus areas.
	 * @param bool       $include_sources Whether to include sources.
	 * @param array      $context        Execution context.
	 * @param array      $site_content   Local site/vector-store content matches.
	 * @param array      $prior_memory   Prior research recalled from agent memory.
	 * @param array      $pages          Full-page content read from top sources.
	 * @param float|null $start_time     Monotonic start time for budget checks.
	 * @return array|WP_Error Analysis result or error.
	 */
	protected function analyze_findings( $topic, $search_results, $depth, $focus_areas, $include_sources, $context, $site_content = array(), $prior_memory = array(), $pages = array(), $start_time = null ) {
		/**
		 * Filter the maximum number of provider attempts for report generation.
		 *
		 * Best practice: reject output after a small number of failures instead
		 * of cascading retries. Each attempt walks the configured provider chain
		 * (the failed provider is excluded for the next attempt).
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_attempts Default 2.
		 */
		$max_attempts = (int) apply_filters( 'wp_mcp_ai_deep_research_max_attempts', 2 );
		$max_attempts = max( 1, $max_attempts );

		$excluded_providers  = array();
		$last_error          = null;
		$retry_larger_budget = false;
		$validation_feedback = array();
		$last_valid_content  = null;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			// Get AI client and model, skipping providers that already failed.
			$ai_setup = $this->get_ai_setup( $excluded_providers );

			if ( is_wp_error( $ai_setup ) ) {
				// If at least one provider already failed, the chain is exhausted:
				// surface the exhausted error with the salvageable sources below.
				if ( $last_error instanceof WP_Error ) {
					break;
				}

				// No provider was configured at all.
				return $ai_setup;
			}

			$client   = $ai_setup['client'];
			$provider = $ai_setup['provider'];
			$model    = $ai_setup['model'];

			// Build analysis prompt.
			$prompt = $this->build_analysis_prompt( $topic, $search_results, $depth, $focus_areas, $include_sources, $site_content, $prior_memory, $pages, $validation_feedback );

			// Build messages array.
			$messages = array(
				array(
					'role'    => 'system',
					'content' => __( 'You are an expert research analyst. Synthesize information from multiple sources into comprehensive, well-organized research reports. Always cite your sources and maintain objectivity.', 'mcp-ai-wpoos' ),
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			);

			// When retrying after finish_reason=length truncation, double the
			// token budget so the model can complete the report.
			$max_tokens = $this->get_max_tokens_for_depth( $depth );
			if ( $retry_larger_budget ) {
				$max_tokens *= 2;
			}

			// Call AI.
			$result = $client->create_chat_completion(
				$messages,
				array(
					'model'       => $model,
					'temperature' => 0.3, // Low temperature for factual research.
					'max_tokens'  => $max_tokens,
				)
			);

			if ( is_wp_error( $result ) ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_error(
						'Deep research AI analysis failed: ' . $result->get_error_message(),
						array(
							'topic'    => $topic,
							'provider' => $provider,
							'model'    => $model,
							'attempt'  => $attempt + 1,
						)
					);
				}

				$last_error           = $result;
				$excluded_providers[] = $provider;
				continue;
			}

			// Extract content. An empty string passes isset(), so validate
			// non-empty explicitly (reasoning models can return CoT-only output).
			if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
				$last_error           = new WP_Error(
					'wp_mcp_ai_invalid_response',
					__( 'Invalid response from AI provider.', 'mcp-ai-wpoos' )
				);
				$excluded_providers[] = $provider;
				continue;
			}

			$content = $this->extract_text_content( isset( $result['choices'][0]['message']['content'] ) ? $result['choices'][0]['message']['content'] : '' );

			// Clients differ on where finish_reason lives: DeepSeek normalises it
			// to the top level, OpenAI keeps it inside choices[0].
			$finish_reason = '';
			if ( isset( $result['finish_reason'] ) ) {
				$finish_reason = sanitize_key( (string) $result['finish_reason'] );
			} elseif ( isset( $result['choices'][0]['finish_reason'] ) ) {
				$finish_reason = sanitize_key( (string) $result['choices'][0]['finish_reason'] );
			}

			// Reasoning fallback: DeepSeek-style reasoning models may return the
			// chain of thought in reasoning_content with an empty final content.
			// Use the reasoning as the report when it is all we have — but run
			// the quality checks on it so callers see whether the fallback is
			// actually usable as a report.
			if ( '' === $content && ! empty( $result['reasoning_content'] ) && is_string( $result['reasoning_content'] ) ) {
				$content = trim( $result['reasoning_content'] );

				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'deep_research_reasoning_fallback',
						'Deep research used reasoning_content because the completion content was empty.',
						array(
							'topic'    => $topic,
							'provider' => $provider,
							'model'    => $model,
						)
					);
				}

				$validation = $this->validate_report_content( $content, $depth, $include_sources );

				return array(
					'content'                 => $content,
					'provider'                => $provider,
					'model'                   => $model,
					'used_reasoning_fallback' => true,
					'attempts'                => $attempt + 1,
					'validated'               => $validation['passed'],
					'validation_issues'       => $validation['issues'],
				);
			}

			// Truncation: finish_reason=length means the report was cut off.
			// Retry once with a larger budget before falling back to another provider.
			if ( 'length' === $finish_reason ) {
				if ( ! $retry_larger_budget ) {
					$retry_larger_budget = true;
					// Keep the same provider for the larger-budget retry.
					continue;
				}

				if ( '' !== $content ) {
					// Even after the larger budget the provider truncated: return
					// what we have with an explicit flag instead of a silent cut.
					$validation = $this->validate_report_content( $content, $depth, $include_sources );

					return array(
						'content'           => $content,
						'provider'          => $provider,
						'model'             => $model,
						'truncated'         => true,
						'attempts'          => $attempt + 1,
						'validated'         => $validation['passed'],
						'validation_issues' => $validation['issues'],
					);
				}

				$last_error           = new WP_Error(
					'wp_mcp_ai_report_truncated',
					__( 'The AI provider truncated the research report before producing any content. Try a lower research depth or a model with a larger output limit.', 'mcp-ai-wpoos' )
				);
				$excluded_providers[] = $provider;
				continue;
			}

			// Empty completion without reasoning: treat as a transient failure.
			if ( '' === $content ) {
				$last_error = new WP_Error(
					'wp_mcp_ai_empty_completion',
					sprintf(
						/* translators: %s: provider name */
						__( 'The AI provider (%s) returned an empty completion for the research report.', 'mcp-ai-wpoos' ),
						$provider
					)
				);
				$excluded_providers[] = $provider;
				continue;
			}

			// Report validation gate: a non-empty completion can still be
			// drafting notes, a cut-off fragment, or a report without usable
			// citations. Validate before accepting it as complete.
			$validation = $this->validate_report_content( $content, $depth, $include_sources );

			if ( ! $validation['passed'] ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_event(
						'deep_research_validation_failed',
						'Deep research draft failed quality checks.',
						array(
							'topic'    => $topic,
							'provider' => $provider,
							'model'    => $model,
							'attempt'  => $attempt + 1,
							'issues'   => $validation['issues'],
						)
					);
				}

				// Remember the draft so a failed retry can still salvage it.
				$last_valid_content = array(
					'content'  => $content,
					'provider' => $provider,
					'model'    => $model,
					'attempts' => $attempt + 1,
					'issues'   => $validation['issues'],
				);

				// Retry the synthesis once with explicit feedback, unless out
				// of attempts or over the elapsed-time budget.
				if ( ( $attempt + 1 ) < $max_attempts && ! $this->time_budget_exceeded( $start_time ) ) {
					$validation_feedback = $validation['issues'];
					continue;
				}

				return array(
					'content'           => $content,
					'provider'          => $provider,
					'model'             => $model,
					'attempts'          => $attempt + 1,
					'validated'         => false,
					'validation_issues' => $validation['issues'],
				);
			}

			// Success.
			return array(
				'content'           => $content,
				'provider'          => $provider,
				'model'             => $model,
				'attempts'          => $attempt + 1,
				'validated'         => true,
				'validation_issues' => array(),
			);
		}

		// All attempts exhausted. When a non-empty draft existed, return it
		// with its validation outcome instead of discarding the work — the
		// report builder marks it as a partial result.
		if ( null !== $last_valid_content ) {
			return array(
				'content'           => $last_valid_content['content'],
				'provider'          => $last_valid_content['provider'],
				'model'             => $last_valid_content['model'],
				'attempts'          => $last_valid_content['attempts'],
				'validated'         => false,
				'validation_issues' => $last_valid_content['issues'],
			);
		}

		// All attempts exhausted: return an actionable error that still carries
		// the gathered sources so callers can salvage the material.
		$error_message = $last_error instanceof WP_Error
			? $last_error->get_error_message()
			: __( 'The AI analysis failed after multiple attempts.', 'mcp-ai-wpoos' );

		$error_data = array(
			'status' => 502,
		);

		if ( ! empty( $search_results['sources'] ) ) {
			$error_data['sources'] = $search_results['sources'];
		}
		if ( ! empty( $search_results['queries'] ) ) {
			$error_data['queries_used'] = $search_results['queries'];
		}
		$error_data['site_content_count']  = count( $site_content );
		$error_data['attempted_providers'] = array_values( array_unique( $excluded_providers ) );

		return new WP_Error( 'wp_mcp_ai_analysis_exhausted', $error_message, $error_data );
	}

	/**
	 * Extract plain text from a completion content value.
	 *
	 * Provider clients may return `content` as a plain string or as an array
	 * of typed segments (`array( array( 'type' => 'text', 'text' => '...' ) )`).
	 * Normalise both shapes to a trimmed string.
	 *
	 * @param mixed $content Raw content value from a completion message.
	 * @return string Trimmed plain-text content (empty when unparseable).
	 */
	protected function extract_text_content( $content ) {
		if ( is_string( $content ) ) {
			return trim( $content );
		}

		if ( is_array( $content ) ) {
			$text = '';
			foreach ( $content as $segment ) {
				if ( is_string( $segment ) ) {
					$text .= $segment;
				} elseif ( is_array( $segment ) && isset( $segment['text'] ) && is_string( $segment['text'] ) ) {
					$text .= $segment['text'];
				}
			}
			return trim( $text );
		}

		return '';
	}

	/**
	 * Validate a generated report before it is marked complete.
	 *
	 * Structural quality checks (industry-standard pre-acceptance gate):
	 * minimum length, drafting-note detection, abrupt endings, section
	 * presence, and citation presence. Failing drafts trigger one
	 * feedback-driven rewrite in analyze_findings; when the rewrite still
	 * fails, the report is returned as an explicit partial result.
	 *
	 * @param string $content         Report content.
	 * @param string $depth           Research depth (affects thresholds).
	 * @param bool   $include_sources Whether citations were requested.
	 * @return array Shape: array( 'passed' => bool, 'issues' => string[] ).
	 */
	protected function validate_report_content( $content, $depth, $include_sources ) {
		$issues  = array();
		$content = is_string( $content ) ? trim( $content ) : '';

		/**
		 * Filter to disable report validation entirely.
		 *
		 * @since 1.2.0
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! (bool) apply_filters( 'wp_mcp_ai_deep_research_enable_validation', true ) ) {
			return array(
				'passed' => true,
				'issues' => array(),
			);
		}

		if ( '' === $content ) {
			return array(
				'passed' => false,
				'issues' => array( 'empty_report' ),
			);
		}

		// 1. Minimum length per depth.
		$min_words = (int) apply_filters( 'wp_mcp_ai_deep_research_min_words', $this->get_min_words_for_depth( $depth ) );
		if ( str_word_count( $content ) < $min_words ) {
			$issues[] = 'below_min_word_count';
		}

		// 2. Drafting notes / placeholders (the model is still planning, not
		// reporting).
		/**
		 * Filter the drafting-note detection patterns.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $patterns PCRE patterns.
		 */
		$drafting_patterns = (array) apply_filters(
			'wp_mcp_ai_deep_research_drafting_markers',
			array(
				"/I'll (write|draft|research|outline)/i",
				'/let me (research|check|look|draft|write)/i',
				'/to be (completed|continued|written)/i',
				'/(^|\s)TBD(\s|$)/i',
				'/(^|\s)TODO(\s|$)/i',
				'/draft (outline|notes|version)/i',
				'/as of my (knowledge|training) cutoff/i',
				"/I (do not|don't) have (access to|information about)/i",
				"/unfortunately I (cannot|can't)/i",
				'/here (is|are) (a|some) (draft|preliminary|initial)/i',
			)
		);
		foreach ( $drafting_patterns as $pattern ) {
			if ( is_string( $pattern ) && '' !== $pattern && preg_match( $pattern, $content ) ) {
				$issues[] = 'drafting_notes_detected';
				break;
			}
		}

		// 3. Abrupt endings: trailing ellipsis, dangling list punctuation, a
		// dangling section label, or a tail with no sentence punctuation.
		$tail      = trim( substr( $content, -120 ) );
		$last_char = substr( $tail, -1 );
		$abrupt    = false;
		if ( '…' === $last_char || '...' === substr( $tail, -3 ) ) {
			$abrupt = true;
		} elseif ( preg_match( '/[:;\-•]\s*$/', $tail ) ) {
			$abrupt = true;
		} elseif ( preg_match( '/(sources|references|notes|bibliography)\s*:\s*$/i', $tail ) ) {
			$abrupt = true;
		} elseif ( ! preg_match( '/[.!?]/', $tail ) ) {
			$abrupt = true;
		}
		if ( $abrupt ) {
			$issues[] = 'abrupt_ending';
		}

		// 4. Section presence: the report must contain a reasonable number of
		// the sections prompted for its depth.
		$expected_words = 'comprehensive' === $depth
			? array( 'Executive Summary', 'Background', 'Findings', 'Analysis', 'Conclusion' )
			: ( 'standard' === $depth
				? array( 'Overview', 'Findings', 'Analysis', 'Conclusion' )
				: array( 'Summary', 'Key Points' ) );
		$matched        = 0;
		foreach ( $expected_words as $word ) {
			if ( preg_match( '/^\s*(?:#{1,6}\s*|\d+[.)]\s*)?' . preg_quote( $word, '/' ) . '/im', $content ) ) {
				++$matched;
			}
		}
		$min_sections = (int) apply_filters( 'wp_mcp_ai_deep_research_min_sections', 'basic' === $depth ? 1 : 2 );
		if ( $matched < $min_sections ) {
			$issues[] = 'missing_sections';
		}

		// 5. Citations: when sources were requested, claims must carry
		// [n]-style citations (or at least reference the gathered URLs).
		if ( $include_sources && (bool) apply_filters( 'wp_mcp_ai_deep_research_require_citations', true ) ) {
			if ( ! preg_match( '/\[\d+\]/', $content ) ) {
				$issues[] = 'missing_citations';
			}
		}

		return array(
			'passed' => empty( $issues ),
			'issues' => $issues,
		);
	}

	/**
	 * Minimum word count for a report at the given depth.
	 *
	 * @param string $depth Research depth.
	 * @return int Minimum words.
	 */
	protected function get_min_words_for_depth( $depth ) {
		switch ( $depth ) {
			case 'comprehensive':
				return 250;
			case 'standard':
				return 120;
			case 'basic':
			default:
				return 40;
		}
	}

	/**
	 * Whether the elapsed-time budget has been exceeded.
	 *
	 * A null start time disables the budget (used by unit tests and by
	 * callers that do not track elapsed time).
	 *
	 * @param float|null $start_time Monotonic start time from microtime().
	 * @return bool True when the budget is exceeded.
	 */
	protected function time_budget_exceeded( $start_time ) {
		if ( null === $start_time ) {
			return false;
		}

		/**
		 * Filter the elapsed-time budget in seconds for a research run.
		 *
		 * The budget gates optional stages (page reads, validation retries);
		 * it never aborts the core synthesis once search evidence exists.
		 *
		 * @since 1.2.0
		 *
		 * @param int $seconds Default 90.
		 */
		$budget = (int) apply_filters( 'wp_mcp_ai_deep_research_time_budget', 90 );
		if ( $budget <= 0 ) {
			return false;
		}

		return ( microtime( true ) - (float) $start_time ) >= (float) $budget;
	}

	/**
	 * Number of source pages to read per depth.
	 *
	 * @param string $depth Research depth.
	 * @return int Maximum pages.
	 */
	protected function get_max_pages_for_depth( $depth ) {
		switch ( $depth ) {
			case 'comprehensive':
				return 5;
			case 'standard':
				return 2;
			case 'basic':
			default:
				return 0;
		}
	}

	/**
	 * Read full page content for the top sources.
	 *
	 * Primary-source material beats search snippets for synthesis quality.
	 * The reads are bounded by depth, a per-page character cap, and the
	 * elapsed-time budget; failures degrade to snippets gracefully.
	 *
	 * @param array      $sources    Deduplicated sources (url/title/snippet).
	 * @param string     $depth      Research depth.
	 * @param float|null $start_time Monotonic start time for budget checks.
	 * @return array Shape: array(
	 *     'pages' => array[], 'read' => int, 'failed' => int,
	 *     'skipped' => int, 'budget_exceeded' => bool ).
	 */
	protected function read_source_pages( $sources, $depth, $start_time = null ) {
		$stats = array(
			'pages'           => array(),
			'read'            => 0,
			'failed'          => 0,
			'skipped'         => 0,
			'budget_exceeded' => false,
		);

		/**
		 * Filter the maximum number of pages to read per research run.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_pages Depth default: basic 0, standard 2, comprehensive 5.
		 */
		$max_pages = (int) apply_filters( 'wp_mcp_ai_deep_research_max_pages', $this->get_max_pages_for_depth( $depth ) );
		$max_pages = max( 0, min( $max_pages, 10 ) );

		if ( $max_pages <= 0 || empty( $sources ) || ! is_array( $sources ) ) {
			return $stats;
		}

		if ( $this->time_budget_exceeded( $start_time ) ) {
			$stats['budget_exceeded'] = true;
			return $stats;
		}

		/**
		 * Filter the maximum characters kept per fetched page.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_chars Default 6000.
		 */
		$max_chars = (int) apply_filters( 'wp_mcp_ai_deep_research_page_max_chars', 6000 );
		$max_chars = max( 500, $max_chars );

		$read = 0;
		foreach ( $sources as $source ) {
			if ( $read >= $max_pages || $this->time_budget_exceeded( $start_time ) ) {
				break;
			}
			if ( empty( $source['url'] ) ) {
				continue;
			}

			$url = esc_url_raw( $source['url'] );
			if ( ! $url || ! preg_match( '#^https?://#i', $url ) ) {
				++$stats['skipped'];
				continue;
			}

			// SSRF defence in depth: the URL guard validates first and
			// wp_safe_remote_get blocks private/loopback addresses again.
			if ( class_exists( 'WP_MCP_AI_Url_Guard' ) ) {
				$guard = WP_MCP_AI_Url_Guard::validate( $url );
				if ( is_wp_error( $guard ) ) {
					++$stats['skipped'];
					continue;
				}
			}

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'user-agent'  => 'NV-oOS-Deep-Research/1.0',
				)
			);
			++$read;

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				++$stats['failed'];
				continue;
			}

			$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );
			if ( '' !== $content_type && false === stripos( $content_type, 'html' ) ) {
				++$stats['skipped'];
				continue;
			}

			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ) ) );
			if ( '' === $text ) {
				++$stats['skipped'];
				continue;
			}

			if ( strlen( $text ) > $max_chars ) {
				$text = substr( $text, 0, $max_chars ) . '…';
			}

			$stats['pages'][] = array(
				'url'     => $url,
				'title'   => isset( $source['title'] ) ? sanitize_text_field( $source['title'] ) : '',
				'content' => $text,
			);
		}

		$stats['read']            = count( $stats['pages'] );
		$stats['budget_exceeded'] = $this->time_budget_exceeded( $start_time );

		return $stats;
	}

	/**
	 * Get AI client, provider, and model for research.
	 *
	 * Checks for a dedicated deep research model setting first, then falls back to
	 * provider-specific defaults. This allows users to specify a particular model
	 * optimized for research tasks (e.g., a model with larger context window or
	 * better reasoning capabilities).
	 *
	 * @param array $exclude_providers Optional list of provider ids to skip
	 *                                 (used for retry/fallback walks).
	 * @return array|WP_Error Setup information or error.
	 */
	protected function get_ai_setup( $exclude_providers = array() ) {
		// Use the merged settings (credentials + main) so API keys stored in
		// the separate non-autoload wp_mcp_ai_credentials option are visible.
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
			? WP_MCP_AI_Admin_Settings_Base::get_settings()
			: get_option( 'wp_mcp_ai_settings', array() );

		$exclude_providers = is_array( $exclude_providers ) ? array_map( 'sanitize_key', $exclude_providers ) : array();

		/**
		 * Filter the list of providers skipped during deep-research fallbacks.
		 *
		 * @since 1.2.0
		 *
		 * @param array $exclude_providers Provider ids currently excluded.
		 */
		$exclude_providers = (array) apply_filters( 'wp_mcp_ai_deep_research_excluded_providers', $exclude_providers );

		// Check if there's a dedicated deep research model configured.
		if ( ! empty( $settings['deep_research_model'] ) ) {
			$research_model = sanitize_text_field( $settings['deep_research_model'] );

			// Parse provider from model string (format: "provider:model" or just "model").
			$parts = explode( ':', $research_model, 2 );
			if ( count( $parts ) === 2 ) {
				$provider = $parts[0];
				$model    = $parts[1];
			} else {
				// If no provider prefix, try to infer from configured providers.
				$model    = $research_model;
				$provider = $this->infer_provider_for_model( $model, $settings );
			}

			// Get client for specified provider (unless excluded by a retry walk).
			if ( $provider && ! in_array( $provider, $exclude_providers, true ) ) {
				$client = $this->get_client_for_provider( $provider, $settings );
				if ( ! is_wp_error( $client ) ) {
					return array(
						'client'   => $client,
						'provider' => $provider,
						'model'    => $model,
					);
				}
			}
		}

		// Fall back to trying providers in order of preference: OpenAI > Gemini > Anthropic > Cloudflare > HuggingFace > Ollama > DeepSeek > OpenRouter > NVIDIA > LM Studio > DigitalOcean > Kimi > Baseten > ZAI.
		// Providers in the exclude list are skipped so retry walks advance down the chain.
		if ( ! in_array( 'openai', $exclude_providers, true ) && ! empty( $settings['openai_api_key'] ) && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$client = new WP_MCP_AI_OpenAI_Client();
			// Prefer the configured default, else the current flagship research-grade model (1M context).
			$model = ! empty( $settings['openai_default_model'] ) ? $settings['openai_default_model'] : 'gpt-5.6-sol';
			return array(
				'client'   => $client,
				'provider' => 'openai',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'gemini', $exclude_providers, true ) && ! empty( $settings['gemini_api_key'] ) && class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			$client = new WP_MCP_AI_Gemini_Client();
			// Prefer gemini-3.1-pro-preview for deep research (1M context, 64K output, agentic capabilities) or fall back to configured default.
			$model = ! empty( $settings['gemini_default_model'] ) ? $settings['gemini_default_model'] : 'gemini-3.1-pro-preview';
			return array(
				'client'   => $client,
				'provider' => 'gemini',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'anthropic', $exclude_providers, true ) && ! empty( $settings['anthropic_api_key'] ) && class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
			$client = new WP_MCP_AI_Anthropic_Client();
			// Prefer claude-opus-4.5 for comprehensive research (highest intelligence, 200K context, persistent memory).
			$model = 'claude-opus-4.5';
			return array(
				'client'   => $client,
				'provider' => 'anthropic',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'cloudflare', $exclude_providers, true ) && ! empty( $settings['cloudflare_api_token'] ) && ! empty( $settings['cloudflare_account_id'] ) && class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			$client = new WP_MCP_AI_Cloudflare_Client();
			// Prefer Llama 4 or DeepSeek for research, fall back to configured default.
			$default_model = ! empty( $settings['cloudflare_model'] ) ? $settings['cloudflare_model'] : '@cf/meta/llama-4-scout-17b-instruct';
			return array(
				'client'   => $client,
				'provider' => 'cloudflare',
				'model'    => $default_model,
			);
		}

		if ( ! in_array( 'huggingface', $exclude_providers, true ) && ! empty( $settings['huggingface_api_key'] ) && ! empty( $settings['huggingface_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
			$client = new WP_MCP_AI_Huggingface_Client();
			// Prefer Llama 3.3 70B or DeepSeek V3.2 for research, fall back to configured default.
			$default_model = ! empty( $settings['huggingface_model'] ) ? $settings['huggingface_model'] : 'meta-llama/Llama-3.3-70B-Instruct';
			return array(
				'client'   => $client,
				'provider' => 'huggingface',
				'model'    => $default_model,
			);
		}

		if ( ! in_array( 'ollama', $exclude_providers, true ) && ! empty( $settings['ollama_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
			$client = new WP_MCP_AI_Ollama_Client();
			// Prefer llama3.3 or deepseek-r1 for local research, fall back to configured default.
			$default_model = ! empty( $settings['ollama_model'] ) ? $settings['ollama_model'] : 'llama3.3';
			return array(
				'client'   => $client,
				'provider' => 'ollama',
				'model'    => $default_model,
			);
		}

		if ( ! in_array( 'deepseek', $exclude_providers, true ) && ! empty( $settings['deepseek_api_key'] ) && class_exists( 'WP_MCP_AI_DeepSeek_Client' ) ) {
			$client = new WP_MCP_AI_DeepSeek_Client();
			// Prefer deepseek-flash (V4.1 Flash, thinking mode) or fall back to configured default.
			$model = ! empty( $settings['deepseek_model'] ) ? $settings['deepseek_model'] : 'deepseek-flash';
			return array(
				'client'   => $client,
				'provider' => 'deepseek',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'openrouter', $exclude_providers, true ) && ! empty( $settings['openrouter_api_key'] ) && class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
			$client = new WP_MCP_AI_OpenRouter_Client();
			// OpenRouter auto-routes to best model; user can override with openrouter_model setting.
			$model = ! empty( $settings['openrouter_model'] ) ? $settings['openrouter_model'] : 'openrouter/auto';
			return array(
				'client'   => $client,
				'provider' => 'openrouter',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'nvidia', $exclude_providers, true ) && ! empty( $settings['nvidia_api_key'] ) && class_exists( 'WP_MCP_AI_Nvidia_Client' ) ) {
			$client = new WP_MCP_AI_Nvidia_Client();
			$model  = ! empty( $settings['nvidia_model'] ) ? $settings['nvidia_model'] : 'meta/llama-3.1-8b-instruct';
			return array(
				'client'   => $client,
				'provider' => 'nvidia',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'lm_studio', $exclude_providers, true ) && ! empty( $settings['lm_studio_endpoint_url'] ) && class_exists( 'WP_MCP_AI_LM_Studio_Client' ) ) {
			$client = new WP_MCP_AI_LM_Studio_Client();
			$model  = ! empty( $settings['lm_studio_model'] ) ? $settings['lm_studio_model'] : '';
			return array(
				'client'   => $client,
				'provider' => 'lm_studio',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'digitalocean', $exclude_providers, true ) && ! empty( $settings['digitalocean_api_key'] ) && class_exists( 'WP_MCP_AI_DigitalOcean_Client' ) ) {
			$client = new WP_MCP_AI_DigitalOcean_Client();
			$model  = ! empty( $settings['digitalocean_model'] ) ? $settings['digitalocean_model'] : 'llama3.3-70b-instruct';
			return array(
				'client'   => $client,
				'provider' => 'digitalocean',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'kimi', $exclude_providers, true ) && ! empty( $settings['kimi_api_key'] ) && class_exists( 'WP_MCP_AI_Kimi_Client' ) ) {
			$client = new WP_MCP_AI_Kimi_Client();
			$model  = ! empty( $settings['kimi_model'] ) ? $settings['kimi_model'] : 'kimi-k3';
			return array(
				'client'   => $client,
				'provider' => 'kimi',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'baseten', $exclude_providers, true ) && ! empty( $settings['baseten_api_key'] ) && class_exists( 'WP_MCP_AI_Baseten_Client' ) ) {
			$client = new WP_MCP_AI_Baseten_Client();
			$model  = ! empty( $settings['baseten_model'] ) ? $settings['baseten_model'] : 'deepseek-ai/DeepSeek-V3';
			return array(
				'client'   => $client,
				'provider' => 'baseten',
				'model'    => $model,
			);
		}

		if ( ! in_array( 'zai', $exclude_providers, true ) && ! empty( $settings['zai_api_key'] ) && class_exists( 'WP_MCP_AI_ZAI_Client' ) ) {
			$client = new WP_MCP_AI_ZAI_Client();
			$model  = ! empty( $settings['zai_model'] ) ? $settings['zai_model'] : 'glm-5.3-flash';
			return array(
				'client'   => $client,
				'provider' => 'zai',
				'model'    => $model,
			);
		}

		return new WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider configured. Please configure OpenAI, Gemini, Anthropic, Cloudflare, HuggingFace, Ollama, DeepSeek, OpenRouter, NVIDIA, LM Studio, DigitalOcean, Kimi, Baseten, or Z.AI in plugin settings.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Infer provider from model name when no provider prefix is given.
	 *
	 * @param string $model    Model name.
	 * @param array  $settings Plugin settings.
	 * @return string|null Provider name or null if cannot be inferred.
	 */
	protected function infer_provider_for_model( $model, $settings ) {
		// Check common model name patterns.
		if ( strpos( $model, 'gpt-' ) === 0 || strpos( $model, 'o1-' ) === 0 ) {
			return 'openai';
		}
		if ( strpos( $model, 'gemini-' ) === 0 ) {
			return 'gemini';
		}
		if ( strpos( $model, 'claude-' ) === 0 ) {
			return 'anthropic';
		}
		if ( strpos( $model, '@cf/' ) === 0 ) {
			return 'cloudflare';
		}
		if ( strpos( $model, 'deepseek' ) !== false ) {
			return 'deepseek';
		}
		if ( strpos( $model, 'llama' ) !== false || strpos( $model, 'mistral' ) !== false ) {
			// Could be Ollama or HuggingFace - check which is configured.
			if ( ! empty( $settings['ollama_endpoint_url'] ) ) {
				return 'ollama';
			}
			if ( ! empty( $settings['huggingface_api_key'] ) ) {
				return 'huggingface';
			}
		}

		if ( strpos( $model, 'openrouter/' ) === 0 ) {
			return 'openrouter';
		}
		if ( strpos( $model, 'kimi-' ) === 0 || strpos( $model, 'moonshot' ) !== false ) {
			return 'kimi';
		}

		return null;
	}

	/**
	 * Get AI client instance for a specific provider.
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Plugin settings.
	 * @return object|WP_Error Client instance or error.
	 */
	protected function get_client_for_provider( $provider, $settings ) {
		switch ( $provider ) {
			case 'openai':
				if ( ! empty( $settings['openai_api_key'] ) && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
					return new WP_MCP_AI_OpenAI_Client();
				}
				break;

			case 'gemini':
				if ( ! empty( $settings['gemini_api_key'] ) && class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
					return new WP_MCP_AI_Gemini_Client();
				}
				break;

			case 'anthropic':
				if ( ! empty( $settings['anthropic_api_key'] ) && class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
					return new WP_MCP_AI_Anthropic_Client();
				}
				break;

			case 'cloudflare':
				if ( ! empty( $settings['cloudflare_api_token'] ) && ! empty( $settings['cloudflare_account_id'] ) && class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
					return new WP_MCP_AI_Cloudflare_Client();
				}
				break;

			case 'huggingface':
				if ( ! empty( $settings['huggingface_api_key'] ) && ! empty( $settings['huggingface_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
					return new WP_MCP_AI_Huggingface_Client();
				}
				break;

			case 'ollama':
				if ( ! empty( $settings['ollama_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
					return new WP_MCP_AI_Ollama_Client();
				}
				break;

			case 'deepseek':
				if ( ! empty( $settings['deepseek_api_key'] ) && class_exists( 'WP_MCP_AI_DeepSeek_Client' ) ) {
					return new WP_MCP_AI_DeepSeek_Client();
				}
				break;

			case 'openrouter':
				if ( ! empty( $settings['openrouter_api_key'] ) && class_exists( 'WP_MCP_AI_OpenRouter_Client' ) ) {
					return new WP_MCP_AI_OpenRouter_Client();
				}
				break;

			case 'nvidia':
				if ( ! empty( $settings['nvidia_api_key'] ) && class_exists( 'WP_MCP_AI_Nvidia_Client' ) ) {
					return new WP_MCP_AI_Nvidia_Client();
				}
				break;

			case 'lm_studio':
				if ( ! empty( $settings['lm_studio_endpoint_url'] ) && class_exists( 'WP_MCP_AI_LM_Studio_Client' ) ) {
					return new WP_MCP_AI_LM_Studio_Client();
				}
				break;

			case 'digitalocean':
				if ( ! empty( $settings['digitalocean_api_key'] ) && class_exists( 'WP_MCP_AI_DigitalOcean_Client' ) ) {
					return new WP_MCP_AI_DigitalOcean_Client();
				}
				break;

			case 'kimi':
				if ( ! empty( $settings['kimi_api_key'] ) && class_exists( 'WP_MCP_AI_Kimi_Client' ) ) {
					return new WP_MCP_AI_Kimi_Client();
				}
				break;

			case 'baseten':
				if ( ! empty( $settings['baseten_api_key'] ) && class_exists( 'WP_MCP_AI_Baseten_Client' ) ) {
					return new WP_MCP_AI_Baseten_Client();
				}
				break;

			case 'zai':
				if ( ! empty( $settings['zai_api_key'] ) && class_exists( 'WP_MCP_AI_ZAI_Client' ) ) {
					return new WP_MCP_AI_ZAI_Client();
				}
				break;
		}

		return new WP_Error(
			'wp_mcp_ai_provider_unavailable',
			sprintf(
				/* translators: %s: provider name */
				__( 'Provider %s is not configured or unavailable.', 'mcp-ai-wpoos' ),
				$provider
			)
		);
	}

	/**
	 * Build AI analysis prompt.
	 *
	 * @param string $topic          Research topic.
	 * @param array  $search_results Search results.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param bool   $include_sources Whether to include sources.
	 * @param array  $site_content   Local site/vector-store content matches.
	 * @param array  $prior_memory   Prior research recalled from agent memory.
	 * @param array  $pages          Full-page content read from top sources.
	 * @param array  $validation_feedback Issues from the previous draft (retry).
	 * @return string Analysis prompt.
	 */
	protected function build_analysis_prompt( $topic, $search_results, $depth, $focus_areas, $include_sources, $site_content = array(), $prior_memory = array(), $pages = array(), $validation_feedback = array() ) {
		$prompt = sprintf(
			/* translators: %s: research topic */
			__( 'Research Topic: %s', 'mcp-ai-wpoos' ) . "\n\n",
			$topic
		);

		if ( ! empty( $focus_areas ) ) {
			$prompt .= __( 'Focus Areas: ', 'mcp-ai-wpoos' ) . implode( ', ', $focus_areas ) . "\n\n";
		}

		// Include recalled prior research at the top to guide synthesis.
		if ( ! empty( $prior_memory ) ) {
			$prompt .= __( 'Prior Research (from agent memory — use to avoid repetition and build on existing knowledge):', 'mcp-ai-wpoos' ) . "\n\n";
			foreach ( $prior_memory as $index => $memory_item ) {
				/* translators: %d: memory item number */
				$title   = isset( $memory_item['title'] ) ? $memory_item['title'] : sprintf( __( 'Prior finding %d', 'mcp-ai-wpoos' ), $index + 1 );
				$content = isset( $memory_item['content'] ) ? $memory_item['content'] : '';
				if ( empty( $content ) && isset( $memory_item['data']['content'] ) ) {
					$content = $memory_item['data']['content'];
				}
				if ( ! empty( $content ) ) {
					$prompt .= sprintf( '[Memory %d] %s: %s', $index + 1, $title, $content ) . "\n\n";
				}
			}
		}

		$prompt .= __( 'Information gathered from web searches:', 'mcp-ai-wpoos' ) . "\n\n";

		// Include search results.
		$source_index = 1;
		foreach ( $search_results['results'] as $result ) {
			if ( empty( $result['title'] ) && empty( $result['snippet'] ) ) {
				continue;
			}

			$prompt .= sprintf( '[%d] ', $source_index );
			if ( ! empty( $result['title'] ) ) {
				$prompt .= $result['title'] . "\n";
			}
			if ( ! empty( $result['snippet'] ) ) {
				$prompt .= $result['snippet'] . "\n";
			}
			if ( ! empty( $result['url'] ) && $include_sources ) {
				$prompt .= __( 'Source: ', 'mcp-ai-wpoos' ) . $result['url'] . "\n";
			}
			$prompt .= "\n";
			++$source_index;
		}

		// Include full page content read from the top sources (primary material).
		if ( ! empty( $pages ) ) {
			$prompt .= "\n" . __( 'Full page content retrieved from the top sources (primary material — prefer these over snippets):', 'mcp-ai-wpoos' ) . "\n\n";
			foreach ( $pages as $index => $page ) {
				if ( empty( $page['content'] ) ) {
					continue;
				}
				/* translators: %d: page number */
				$title   = ! empty( $page['title'] ) ? $page['title'] : sprintf( __( 'Page %d', 'mcp-ai-wpoos' ), $index + 1 );
				$prompt .= sprintf( '[Page %d] %s', $index + 1, $title ) . "\n";
				if ( ! empty( $page['url'] ) ) {
					$prompt .= $page['url'] . "\n";
				}
				$prompt .= $page['content'] . "\n\n";
			}
		}

		// Include local site content from semantic search / vector store.
		if ( ! empty( $site_content ) ) {
			$prompt .= "\n" . __( 'Relevant content from this site\'s knowledge base:', 'mcp-ai-wpoos' ) . "\n\n";
			foreach ( $site_content as $index => $item ) {
				/* translators: %d: site content item number */
				$title   = isset( $item['title'] ) ? $item['title'] : sprintf( __( 'Site content %d', 'mcp-ai-wpoos' ), $index + 1 );
				$excerpt = isset( $item['excerpt'] ) ? $item['excerpt'] : ( isset( $item['snippet'] ) ? $item['snippet'] : '' );
				$url     = isset( $item['url'] ) ? $item['url'] : ( isset( $item['permalink'] ) ? $item['permalink'] : '' );

				$prompt .= sprintf( '[Site %d] %s', $index + 1, $title ) . "\n";
				if ( $excerpt ) {
					$prompt .= $excerpt . "\n";
				}
				if ( $url && $include_sources ) {
					$prompt .= __( 'Source: ', 'mcp-ai-wpoos' ) . $url . "\n";
				}
				$prompt .= "\n";
			}
		}

		$prompt .= __( 'Please analyze the above information and create a comprehensive research report with the following structure:', 'mcp-ai-wpoos' ) . "\n\n";

		if ( 'comprehensive' === $depth ) {
			$prompt .= __( '1. Executive Summary', 'mcp-ai-wpoos' ) . "\n";
			$prompt .= __( '2. Background and Context', 'mcp-ai-wpoos' ) . "\n";
			$prompt .= __( '3. Key Findings (organized by themes)', 'mcp-ai-wpoos' ) . "\n";
			$prompt .= __( '4. Analysis and Insights', 'mcp-ai-wpoos' ) . "\n";
			$prompt .= __( '5. Implications and Recommendations', 'mcp-ai-wpoos' ) . "\n";
			$prompt .= __( '6. Conclusion', 'mcp-ai-wpoos' ) . "\n";
		} elseif ( 'standard' === $depth ) {
			$prompt .= __( '1. Overview', 'mcp-ai-wpoos' ) . "\n";
			$prompt .= __( '2. Main Findings', 'mcp-ai-wpoos' ) . "\n";
			$prompt .= __( '3. Analysis', 'mcp-ai-wpoos' ) . "\n";
			$prompt .= __( '4. Conclusion', 'mcp-ai-wpoos' ) . "\n";
		} else {
			$prompt .= __( '1. Summary', 'mcp-ai-wpoos' ) . "\n";
			$prompt .= __( '2. Key Points', 'mcp-ai-wpoos' ) . "\n";
		}

		// Quality rules (citations, claim provenance, completeness).
		$prompt .= "\n" . __( 'CRITICAL RULES:', 'mcp-ai-wpoos' ) . "\n";
		$prompt .= __( '- Reference sources using [1], [2], etc. notation for EVERY factual claim (the numbers refer to the [n] entries above).', 'mcp-ai-wpoos' ) . "\n";
		$prompt .= __( '- Prefer official documentation and primary sources; say when a claim rests only on search snippets.', 'mcp-ai-wpoos' ) . "\n";
		$prompt .= __( '- Clearly distinguish documented capabilities, observed behavior, and unknown/unverified claims.', 'mcp-ai-wpoos' ) . "\n";
		$prompt .= __( '- Never include drafting notes, TODOs, placeholders, or meta-commentary. Complete every section and end with a full conclusion.', 'mcp-ai-wpoos' ) . "\n";

		// Feedback from a previous failed draft (validation retry).
		if ( ! empty( $validation_feedback ) ) {
			$prompt .= "\n" . __( 'YOUR PREVIOUS DRAFT FAILED QUALITY CHECKS with these problems:', 'mcp-ai-wpoos' ) . "\n";
			foreach ( $validation_feedback as $issue ) {
				$prompt .= '- ' . $issue . "\n";
			}
			$prompt .= __( 'Rewrite the complete report addressing every problem above. Do not repeat drafting notes or partial content.', 'mcp-ai-wpoos' ) . "\n";
		}

		return $prompt;
	}

	/**
	 * Build final research report.
	 *
	 * @param string     $topic          Research topic.
	 * @param string     $depth          Research depth.
	 * @param array      $analysis       Analysis result.
	 * @param array      $search_results Search results.
	 * @param bool       $include_sources Whether to include sources.
	 * @param array      $site_content   Local site content matches used in research.
	 * @param float|null $start_time     Monotonic start time for elapsed diagnostics.
	 * @param array      $pages          Page-read stats (read/failed/skipped/budget).
	 * @return array Research report.
	 */
	protected function build_research_report( $topic, $depth, $analysis, $search_results, $include_sources, $site_content = array(), $start_time = null, $pages = array() ) {
		$warnings = array();
		$status   = 'complete';

		$validation = array(
			'passed' => isset( $analysis['validated'] ) ? (bool) $analysis['validated'] : true,
			'issues' => isset( $analysis['validation_issues'] ) ? $analysis['validation_issues'] : array(),
		);

		if ( ! $validation['passed'] ) {
			$status     = 'partial';
			$warnings[] = 'report_failed_quality_checks';
		}

		if ( ! empty( $analysis['used_reasoning_fallback'] ) ) {
			$warnings[] = 'used_reasoning_fallback';
		}
		if ( ! empty( $analysis['truncated'] ) ) {
			$warnings[] = 'report_truncated_by_provider';
		}
		if ( ! empty( $search_results['failed_queries'] ) ) {
			$warnings[] = 'some_search_queries_failed';
		}
		if ( ! empty( $pages['budget_exceeded'] ) ) {
			$warnings[] = 'time_budget_exceeded_before_page_reads';
		}
		if ( $this->time_budget_exceeded( $start_time ) ) {
			$status     = 'partial';
			$warnings[] = 'time_budget_exceeded';
		}

		$report = array(
			'topic'           => $topic,
			'depth'           => $depth,
			'report'          => $analysis['content'],
			'provider'        => $analysis['provider'],
			'model'           => $analysis['model'],
			'timestamp'       => time(),
			'cached'          => false,
			'word_count'      => str_word_count( $analysis['content'] ),
			'status'          => $status,
			'warnings'        => $warnings,
			'validation'      => $validation,
			'elapsed_seconds' => null !== $start_time ? round( microtime( true ) - (float) $start_time, 2 ) : 0,
		);

		// Surface generation metadata so callers can see fallback/retry state.
		if ( ! empty( $analysis['used_reasoning_fallback'] ) ) {
			$report['used_reasoning_fallback'] = true;
		}
		if ( ! empty( $analysis['truncated'] ) ) {
			$report['truncated'] = true;
		}
		if ( isset( $analysis['attempts'] ) ) {
			$report['attempts'] = absint( $analysis['attempts'] );
		}

		// Add sources if requested.
		if ( $include_sources && ! empty( $search_results['sources'] ) ) {
			$report['sources']      = $search_results['sources'];
			$report['source_count'] = count( $search_results['sources'] );
		} else {
			$report['sources']      = array();
			$report['source_count'] = 0;
		}

		// Add metadata and diagnostics.
		$report['queries_used']       = ! empty( $search_results['queries'] ) ? $search_results['queries'] : array();
		$report['failed_queries']     = ! empty( $search_results['failed_queries'] ) ? $search_results['failed_queries'] : array();
		$report['evidence_reused']    = ! empty( $search_results['reused'] );
		$report['site_content_count'] = count( $site_content );
		$report['pages_read']         = isset( $pages['read'] ) ? absint( $pages['read'] ) : 0;
		$report['pages_failed']       = isset( $pages['failed'] ) ? absint( $pages['failed'] ) : 0;
		$report['pages_skipped']      = isset( $pages['skipped'] ) ? absint( $pages['skipped'] ) : 0;

		return $report;
	}

	/**
	 * Get maximum tokens for research depth.
	 *
	 * @param string $depth Research depth.
	 * @return int Maximum tokens.
	 */
	protected function get_max_tokens_for_depth( $depth ) {
		switch ( $depth ) {
			case 'comprehensive':
				return 4000;
			case 'standard':
				return 2000;
			case 'basic':
			default:
				return 1000;
		}
	}

	/**
	 * Generate cache key for research results.
	 *
	 * @param string $topic       Research topic.
	 * @param string $depth       Research depth.
	 * @param array  $focus_areas Focus areas.
	 * @return string Cache key.
	 */
	protected function get_cache_key( $topic, $depth, $focus_areas ) {
		$key_parts = array( $topic, $depth );
		if ( ! empty( $focus_areas ) ) {
			sort( $focus_areas );
			$key_parts[] = implode( ',', $focus_areas );
		}
		return 'deep_research_' . md5( implode( '|', $key_parts ) );
	}

	/**
	 * Search local WordPress content and the assistant's Vector Store for topic-relevant material.
	 *
	 * Uses the `semantic_content_search` tool (when available) to pull in site-specific knowledge
	 * alongside web search results. This improves research quality for topics that the site
	 * has existing authoritative content about (e.g. product info, documentation, policies).
	 *
	 * @param string $topic       Research topic.
	 * @param array  $focus_areas Focus areas.
	 * @param array  $context     Execution context (may carry assistant_config with vector_store_id).
	 * @return array Normalised site content items (each has 'title', 'excerpt'/'snippet', 'url').
	 */
	protected function gather_site_content( $topic, $focus_areas, $context ) {
		$registry         = WP_MCP_AI_Tool_Registry::get_instance();
		$site_search_tool = $registry->get_tool( 'semantic_content_search' );

		if ( ! $site_search_tool ) {
			return array();
		}

		// Build a combined query from topic and first focus area.
		$query = $topic;
		if ( ! empty( $focus_areas ) ) {
			$query .= ' ' . $focus_areas[0];
		}

		$result = $site_search_tool->execute(
			array(
				'query'           => $query,
				'limit'           => 5,
				'post_types'      => array( 'post', 'page' ),
				'vector_store_id' => isset( $context['assistant_config']['vector_store_id'] )
					? sanitize_text_field( $context['assistant_config']['vector_store_id'] )
					: '',
			),
			$context
		);

		if ( is_wp_error( $result ) || empty( $result['results'] ) || ! is_array( $result['results'] ) ) {
			return array();
		}

		// Normalise result shape.
		$items = array();
		foreach ( $result['results'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$items[] = array(
				'title'   => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
				'excerpt' => isset( $item['excerpt'] ) ? wp_strip_all_tags( $item['excerpt'] ) : ( isset( $item['snippet'] ) ? wp_strip_all_tags( $item['snippet'] ) : '' ),
				'url'     => isset( $item['permalink'] ) ? esc_url_raw( $item['permalink'] ) : ( isset( $item['url'] ) ? esc_url_raw( $item['url'] ) : '' ),
				'source'  => isset( $item['source'] ) ? $item['source'] : 'site',
			);
		}

		return $items;
	}

	/**
	 * Recall prior research from agent memory relevant to the current topic.
	 *
	 * Uses the `retrieve_agent_memory` tool to search stored contexts tagged
	 * as research findings. This allows the research tool to build on previous
	 * sessions rather than re-discovering the same information.
	 *
	 * @param string     $topic           Research topic.
	 * @param int|string $memory_agent_id Agent ID to retrieve memory for.
	 * @param array      $context         Execution context.
	 * @return array Array of prior memory context items (may be empty).
	 */
	protected function recall_prior_research( $topic, $memory_agent_id, $context ) {
		$registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$memory_tool = $registry->get_tool( 'retrieve_agent_memory' );

		if ( ! $memory_tool ) {
			return array();
		}

		$result = $memory_tool->execute(
			array(
				'agent_id'             => $memory_agent_id,
				'query'                => $topic,
				'limit'                => 3,
				'include_vector_store' => false,
				'filters'              => array(
					'context_types' => array( 'result', 'insight', 'fact', 'learning' ),
				),
			),
			$context
		);

		if ( is_wp_error( $result ) || empty( $result['contexts'] ) || ! is_array( $result['contexts'] ) ) {
			return array();
		}

		return $result['contexts'];
	}

	/**
	 * Persist completed research report to agent memory for future recall.
	 *
	 * Stores the research report summary via `store_agent_context` so that
	 * subsequent research sessions on related topics can leverage this work
	 * without duplicating effort.
	 *
	 * @param string     $topic           Research topic.
	 * @param string     $depth           Research depth level.
	 * @param array      $research_report Completed research report.
	 * @param int|string $memory_agent_id Agent ID to store memory for.
	 * @param array      $context         Execution context.
	 * @return void
	 */
	protected function persist_research( $topic, $depth, $research_report, $memory_agent_id, $context ) {
		$registry   = WP_MCP_AI_Tool_Registry::get_instance();
		$store_tool = $registry->get_tool( 'store_agent_context' );

		if ( ! $store_tool ) {
			return;
		}

		// Truncate report to a manageable excerpt for memory storage.
		// 4 000 characters keeps the stored context within transient size limits
		// while still retaining the executive summary and key findings of most
		// research reports (which typically front-load the most important content).
		$report_text = isset( $research_report['report'] ) ? $research_report['report'] : '';
		if ( mb_strlen( $report_text ) > 4000 ) {
			$report_text = mb_substr( $report_text, 0, 4000 ) . '…';
		}

		$store_tool->execute(
			array(
				'agent_id'     => $memory_agent_id,
				'context_type' => 'result',
				'context_data' => array(
					'title'      => sprintf(
						/* translators: 1: research topic, 2: depth level */
						__( 'Deep Research: %1$s (%2$s)', 'mcp-ai-wpoos' ),
						$topic,
						$depth
					),
					'content'    => $report_text,
					'importance' => 'comprehensive' === $depth ? 'high' : 'medium',
					'tags'       => array( 'deep-research', sanitize_key( $depth ) ),
					'metadata'   => array(
						'topic'        => $topic,
						'depth'        => $depth,
						'word_count'   => isset( $research_report['word_count'] ) ? $research_report['word_count'] : 0,
						'source_count' => isset( $research_report['source_count'] ) ? $research_report['source_count'] : 0,
						'provider'     => isset( $research_report['provider'] ) ? $research_report['provider'] : '',
						'model'        => isset( $research_report['model'] ) ? $research_report['model'] : '',
					),
				),
				'ttl'          => WEEK_IN_SECONDS * 4, // Store for 4 weeks.
			),
			$context
		);
	}

	/**
	 * Deduplicate sources by URL, then cap per-domain dominance.
	 *
	 * Search engines frequently return multiple pages (or mirrors) from the
	 * same site for one query. Capping per-domain keeps the evidence base
	 * diverse so the synthesis does not over-weight a single publisher.
	 *
	 * @param array $sources Array of sources.
	 * @return array Deduplicated sources.
	 */
	protected function deduplicate_sources( array $sources ) {
		$seen_urls = array();
		$unique    = array();

		foreach ( $sources as $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}

			$normalized_url = untrailingslashit( $source['url'] );

			if ( in_array( $normalized_url, $seen_urls, true ) ) {
				continue;
			}

			$seen_urls[] = $normalized_url;
			$unique[]    = $source;
		}

		/**
		 * Filter the maximum sources kept per domain (0 disables the cap).
		 *
		 * @since 1.2.0
		 *
		 * @param int $per_domain_max Default 2.
		 */
		$per_domain_max = (int) apply_filters( 'wp_mcp_ai_deep_research_max_sources_per_domain', 2 );
		if ( $per_domain_max <= 0 ) {
			return $unique;
		}

		$domain_counts = array();
		$capped        = array();
		foreach ( $unique as $source ) {
			$host = wp_parse_url( $source['url'], PHP_URL_HOST );
			$host = is_string( $host ) ? strtolower( preg_replace( '/^www\./', '', $host ) ) : '';

			// Unknown hosts are kept as-is (cannot establish domain identity).
			if ( '' === $host ) {
				$capped[] = $source;
				continue;
			}

			if ( ! isset( $domain_counts[ $host ] ) ) {
				$domain_counts[ $host ] = 0;
			}

			if ( $domain_counts[ $host ] >= $per_domain_max ) {
				continue;
			}

			++$domain_counts[ $host ];
			$capped[] = $source;
		}

		return $capped;
	}

	/**
	 * Schedule background research via WordPress cron.
	 *
	 * @param string     $topic                Research topic.
	 * @param string     $depth                Research depth.
	 * @param array      $focus_areas          Focus areas.
	 * @param bool       $include_sources      Whether to include sources.
	 * @param int        $user_id              User ID.
	 * @param bool       $include_site_content Whether to include local site content.
	 * @param int|string $memory_agent_id      Agent ID for memory recall/storage.
	 * @param bool       $store_to_memory      Whether to store results to memory.
	 * @return array Status information.
	 */
	protected function schedule_background_research( $topic, $depth, $focus_areas, $include_sources, $user_id, $include_site_content = true, $memory_agent_id = '', $store_to_memory = false ) {
		// Generate unique job ID.
		$job_id = 'deep_research_' . md5( $topic . microtime( true ) );

		// Schedule single event. The argument array is nested: cron spreads
		// event args positionally into the handler, and the handler's first
		// parameter is the whole job-parameter array.
		$scheduled = wp_schedule_single_event(
			time() + 10, // Run in 10 seconds.
			'wp_mcp_ai_deep_research_background',
			array(
				array(
					'job_id'               => $job_id,
					'topic'                => $topic,
					'depth'                => $depth,
					'focus_areas'          => $focus_areas,
					'include_sources'      => $include_sources,
					'user_id'              => $user_id,
					'include_site_content' => $include_site_content,
					'memory_agent_id'      => $memory_agent_id,
					'store_to_memory'      => $store_to_memory,
				),
			)
		);

		if ( false === $scheduled ) {
			return new WP_Error(
				'wp_mcp_ai_schedule_failed',
				__( 'Failed to schedule background research. WordPress cron may not be working.', 'mcp-ai-wpoos' )
			);
		}

		// Register in Cron Manager so the job is visible and monitorable.
		if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			WP_MCP_AI_Cron_Manager::record_job(
				'wp_mcp_ai_deep_research_background',
				array( $job_id ),
				'single',
				time() + 10,
				absint( $user_id )
			);
		}

		// Store job status.
		$job_status = array(
			'job_id'     => $job_id,
			'topic'      => $topic,
			'depth'      => $depth,
			'status'     => 'scheduled',
			'progress'   => 'scheduled',
			'created_at' => time(),
			'user_id'    => $user_id,
		);

		set_transient( 'wp_mcp_ai_research_job_' . $job_id, $job_status, DAY_IN_SECONDS );

		return array(
			'job_id'  => $job_id,
			'status'  => 'scheduled',
			'message' => sprintf(
				/* translators: %s: job ID */
				__( 'Research scheduled in background mode. Job ID: %s. Check back later for results.', 'mcp-ai-wpoos' ),
				$job_id
			),
			'topic'   => $topic,
		);
	}

	/**
	 * Execute background research job.
	 * Called by WordPress cron.
	 *
	 * @param string     $job_id               Job ID.
	 * @param string     $topic                Research topic.
	 * @param string     $depth                Research depth.
	 * @param array      $focus_areas          Focus areas.
	 * @param bool       $include_sources      Whether to include sources.
	 * @param int        $user_id              User ID.
	 * @param bool       $include_site_content Whether to search local site content.
	 * @param int|string $memory_agent_id      Agent ID for memory recall/storage.
	 * @param bool       $store_to_memory      Whether to store results to memory.
	 */
	public static function execute_background_research( $job_id, $topic, $depth, $focus_areas, $include_sources, $user_id, $include_site_content = true, $memory_agent_id = '', $store_to_memory = false ) {
		// Update job status.
		$job_status = array(
			'job_id'     => $job_id,
			'topic'      => $topic,
			'status'     => 'running',
			'progress'   => 'running',
			'started_at' => time(),
			'user_id'    => $user_id,
		);
		set_transient( 'wp_mcp_ai_research_job_' . $job_id, $job_status, DAY_IN_SECONDS );

		// Create tool instance and execute research.
		$tool   = new self();
		$result = $tool->execute(
			array(
				'topic'                => $topic,
				'depth'                => $depth,
				'focus_areas'          => $focus_areas,
				'include_sources'      => $include_sources,
				'run_mode'             => 'immediate', // Prevent recursive scheduling.
				'include_site_content' => $include_site_content,
				'memory_agent_id'      => $memory_agent_id,
				'store_to_memory'      => $store_to_memory,
			),
			array(
				'user_id' => $user_id,
			)
		);

		// Update job with results.
		$job_status['status']       = is_wp_error( $result ) ? 'failed' : 'completed';
		$job_status['progress']     = $job_status['status'];
		$job_status['completed_at'] = time();

		if ( is_wp_error( $result ) ) {
			$job_status['error'] = $result->get_error_message();
		} else {
			$job_status['result'] = $result;

			// Surface partial completions explicitly so job readers know the
			// report passed quality checks or was returned as-is.
			if ( is_array( $result ) && 'partial' === ( $result['status'] ?? '' ) ) {
				$job_status['partial']  = true;
				$job_status['warnings'] = $result['warnings'] ?? array();
			}
		}

		set_transient( 'wp_mcp_ai_research_job_' . $job_id, $job_status, DAY_IN_SECONDS );

		/**
		 * Fires when background research job completes.
		 *
		 * @param string           $job_id Job ID.
		 * @param array|WP_Error   $result Research result or error.
		 * @param array            $job_status Job status information.
		 */
		do_action( 'wp_mcp_ai_deep_research_background_completed', $job_id, $result, $job_status );
	}
}
