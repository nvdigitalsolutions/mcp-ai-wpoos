<?php
/**
 * Tool that performs comprehensive deep research on any topic using multi-step AI analysis.
 *
 * This tool is provider-agnostic and works with OpenAI, Gemini, Anthropic, Ollama, and all
 * supported AI providers. It combines web search with iterative AI analysis to produce
 * comprehensive research reports.
 *
 * Recommended Models for Deep Research (December 2025):
 * - OpenAI: gpt-4o (multimodal, fast, cost-effective) or gpt-4.5/o3 (advanced reasoning)
 * - Gemini: gemini-3-pro-preview (flagship, 1M token context, agentic capabilities)
 * - Anthropic: claude-opus-4.5 (highest intelligence, 200K context, persistent memory)
 * - Cloudflare: @cf/meta/llama-4-scout-17b-instruct or @cf/deepseek/deepseek-v3.2-thinking
 * - HuggingFace: meta-llama/Llama-3.3-70B-Instruct or deepseek-ai/DeepSeek-V3.2
 * - Ollama: llama3.3 or deepseek-r1 (privacy-focused local research)
 *
 * @package WP_MCP_AI
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
class WP_MCP_AI_Tool_Deep_Research implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Maximum number of search queries to perform.
	 *
	 * @var int
	 */
	const MAX_SEARCH_QUERIES = 3;

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
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'topic'           => array(
					'type'        => 'string',
					'description' => __( 'The research topic or question to investigate.', 'mcp-ai-wpoos' ),
				),
				'depth'           => array(
					'type'        => 'string',
					'description' => __( 'Research depth level.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'basic', 'standard', 'comprehensive' ),
					'default'     => 'standard',
				),
				'focus_areas'     => array(
					'type'        => 'array',
					'description' => __( 'Optional specific aspects to focus on (e.g., "technical", "historical", "economic").', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'include_sources' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include source citations in the research report.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'run_mode'        => array(
					'type'        => 'string',
					'description' => __( 'Execution mode: "immediate" runs synchronously, "background" schedules via WordPress cron for long-running research.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'immediate', 'background' ),
					'default'     => 'immediate',
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

		$topic           = sanitize_text_field( $arguments['topic'] );
		$depth           = isset( $arguments['depth'] ) ? sanitize_key( $arguments['depth'] ) : 'standard';
		$focus_areas     = isset( $arguments['focus_areas'] ) && is_array( $arguments['focus_areas'] ) ? array_map( 'sanitize_text_field', $arguments['focus_areas'] ) : array();
		$include_sources = isset( $arguments['include_sources'] ) ? (bool) $arguments['include_sources'] : true;
		$run_mode        = isset( $arguments['run_mode'] ) ? sanitize_key( $arguments['run_mode'] ) : 'immediate';

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
			return $this->schedule_background_research( $topic, $depth, $focus_areas, $include_sources, $user_id );
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
					'topic'       => $topic,
					'depth'       => $depth,
					'focus_areas' => $focus_areas,
					'user_id'     => $user_id,
				)
			);
		}

		// Step 1: Perform web searches to gather information.
		$search_results = $this->gather_information( $topic, $depth, $focus_areas, $context );

		if ( is_wp_error( $search_results ) ) {
			return $search_results;
		}

		// Step 2: Analyze findings with AI.
		$analysis_result = $this->analyze_findings( $topic, $search_results, $depth, $focus_areas, $include_sources, $context );

		if ( is_wp_error( $analysis_result ) ) {
			return $analysis_result;
		}

		// Step 3: Build final research report.
		$research_report = $this->build_research_report( $topic, $analysis_result, $search_results, $include_sources );

		// Cache the results for 1 hour.
		if ( WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
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
		}

		// Log success.
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'deep_research_completed',
				'Deep research completed successfully',
				array(
					'topic'        => $topic,
					'depth'        => $depth,
					'sources_used' => count( $research_report['sources'] ?? array() ),
					'word_count'   => str_word_count( $research_report['report'] ?? '' ),
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

		// Generate search queries based on depth and focus areas.
		$search_queries = $this->generate_search_queries( $topic, $depth, $focus_areas );

		$all_results = array();
		$all_sources = array();

		foreach ( $search_queries as $query ) {
			// Execute web search.
			$search_result = $web_search_tool->execute(
				array(
					'query'       => $query,
					'max_results' => self::MAX_RESULTS_PER_QUERY,
				),
				$context
			);

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
				__( 'No web search results found for the research topic.', 'mcp-ai-wpoos' )
			);
		}

		// Deduplicate sources by URL.
		$all_sources = $this->deduplicate_sources( $all_sources );

		return array(
			'results' => $all_results,
			'sources' => $all_sources,
			'queries' => $search_queries,
		);
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

		// Determine number of additional queries based on depth.
		$num_queries = 'basic' === $depth ? 1 : ( 'comprehensive' === $depth ? 3 : 2 );

		// Add focus area queries.
		if ( ! empty( $focus_areas ) ) {
			foreach ( $focus_areas as $area ) {
				if ( count( $queries ) >= $num_queries ) {
					break;
				}
				$queries[] = $topic . ' ' . $area;
			}
		}

		// Add depth-specific queries.
		if ( count( $queries ) < $num_queries ) {
			if ( 'comprehensive' === $depth ) {
				$queries[] = $topic . ' research latest developments';
			} elseif ( 'standard' === $depth ) {
				$queries[] = $topic . ' overview';
			}
		}

		return array_slice( $queries, 0, min( self::MAX_SEARCH_QUERIES, $num_queries ) );
	}

	/**
	 * Analyze findings using AI.
	 *
	 * @param string $topic          Research topic.
	 * @param array  $search_results Search results.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param bool   $include_sources Whether to include sources.
	 * @param array  $context        Execution context.
	 * @return array|WP_Error Analysis result or error.
	 */
	protected function analyze_findings( $topic, $search_results, $depth, $focus_areas, $include_sources, $context ) {
		// Get AI client and model.
		$ai_setup = $this->get_ai_setup();

		if ( is_wp_error( $ai_setup ) ) {
			return $ai_setup;
		}

		$client   = $ai_setup['client'];
		$provider = $ai_setup['provider'];
		$model    = $ai_setup['model'];

		// Build analysis prompt.
		$prompt = $this->build_analysis_prompt( $topic, $search_results, $depth, $focus_areas, $include_sources );

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

		// Call AI.
		$result = $client->create_chat_completion(
			$messages,
			array(
				'model'       => $model,
				'temperature' => 0.3, // Low temperature for factual research.
				'max_tokens'  => $this->get_max_tokens_for_depth( $depth ),
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
					)
				);
			}
			return $result;
		}

		// Extract content.
		if ( ! isset( $result['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_response',
				__( 'Invalid response from AI provider.', 'mcp-ai-wpoos' )
			);
		}

		return array(
			'content'  => $result['choices'][0]['message']['content'],
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Get AI client, provider, and model for research.
	 *
	 * Checks for a dedicated deep research model setting first, then falls back to
	 * provider-specific defaults. This allows users to specify a particular model
	 * optimized for research tasks (e.g., a model with larger context window or
	 * better reasoning capabilities).
	 *
	 * @return array|WP_Error Setup information or error.
	 */
	protected function get_ai_setup() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

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

			// Get client for specified provider.
			if ( $provider ) {
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

		// Fall back to trying providers in order of preference: OpenAI > Gemini > Anthropic > Cloudflare > HuggingFace > Ollama.
		if ( ! empty( $settings['openai_api_key'] ) && class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			$client = new WP_MCP_AI_OpenAI_Client();
			// Prefer gpt-4o for research (multimodal, fast, cost-effective) or fall back to configured default.
			$model = ! empty( $settings['openai_default_model'] ) ? $settings['openai_default_model'] : 'gpt-4o';
			return array(
				'client'   => $client,
				'provider' => 'openai',
				'model'    => $model,
			);
		}

		if ( ! empty( $settings['gemini_api_key'] ) && class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			$client = new WP_MCP_AI_Gemini_Client();
			// Prefer gemini-3-pro-preview for deep research (1M context, agentic capabilities) or fall back to configured default.
			$model = ! empty( $settings['gemini_default_model'] ) ? $settings['gemini_default_model'] : 'gemini-3-pro-preview';
			return array(
				'client'   => $client,
				'provider' => 'gemini',
				'model'    => $model,
			);
		}

		if ( ! empty( $settings['anthropic_api_key'] ) && class_exists( 'WP_MCP_AI_Anthropic_Client' ) ) {
			$client = new WP_MCP_AI_Anthropic_Client();
			// Prefer claude-opus-4.5 for comprehensive research (highest intelligence, 200K context, persistent memory).
			$model = 'claude-opus-4.5';
			return array(
				'client'   => $client,
				'provider' => 'anthropic',
				'model'    => $model,
			);
		}

		if ( ! empty( $settings['cloudflare_api_token'] ) && ! empty( $settings['cloudflare_account_id'] ) && class_exists( 'WP_MCP_AI_Cloudflare_Client' ) ) {
			$client = new WP_MCP_AI_Cloudflare_Client();
			// Prefer Llama 4 or DeepSeek for research, fall back to configured default.
			$default_model = ! empty( $settings['cloudflare_model'] ) ? $settings['cloudflare_model'] : '@cf/meta/llama-4-scout-17b-instruct';
			return array(
				'client'   => $client,
				'provider' => 'cloudflare',
				'model'    => $default_model,
			);
		}

		if ( ! empty( $settings['huggingface_api_key'] ) && ! empty( $settings['huggingface_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Huggingface_Client' ) ) {
			$client = new WP_MCP_AI_Huggingface_Client();
			// Prefer Llama 3.3 70B or DeepSeek V3.2 for research, fall back to configured default.
			$default_model = ! empty( $settings['huggingface_model'] ) ? $settings['huggingface_model'] : 'meta-llama/Llama-3.3-70B-Instruct';
			return array(
				'client'   => $client,
				'provider' => 'huggingface',
				'model'    => $default_model,
			);
		}

		if ( ! empty( $settings['ollama_endpoint_url'] ) && class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
			$client = new WP_MCP_AI_Ollama_Client();
			// Prefer llama3.3 or deepseek-r1 for local research, fall back to configured default.
			$default_model = ! empty( $settings['ollama_model'] ) ? $settings['ollama_model'] : 'llama3.3';
			return array(
				'client'   => $client,
				'provider' => 'ollama',
				'model'    => $default_model,
			);
		}

		return new WP_Error(
			'wp_mcp_ai_no_provider',
			__( 'No AI provider configured. Please configure OpenAI, Gemini, Anthropic, Cloudflare, HuggingFace, or Ollama in plugin settings.', 'mcp-ai-wpoos' )
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
		if ( strpos( $model, 'llama' ) !== false || strpos( $model, 'mistral' ) !== false ) {
			// Could be Ollama or HuggingFace - check which is configured.
			if ( ! empty( $settings['ollama_endpoint_url'] ) ) {
				return 'ollama';
			}
			if ( ! empty( $settings['huggingface_api_key'] ) ) {
				return 'huggingface';
			}
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
	 * @return string Analysis prompt.
	 */
	protected function build_analysis_prompt( $topic, $search_results, $depth, $focus_areas, $include_sources ) {
		$prompt = sprintf(
			/* translators: %s: research topic */
			__( 'Research Topic: %s', 'mcp-ai-wpoos' ) . "\n\n",
			$topic
		);

		if ( ! empty( $focus_areas ) ) {
			$prompt .= __( 'Focus Areas: ', 'mcp-ai-wpoos' ) . implode( ', ', $focus_areas ) . "\n\n";
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

		if ( $include_sources ) {
			$prompt .= "\n" . __( 'Important: Reference sources using [1], [2], etc. notation when making claims.', 'mcp-ai-wpoos' ) . "\n";
		}

		return $prompt;
	}

	/**
	 * Build final research report.
	 *
	 * @param string $topic          Research topic.
	 * @param array  $analysis       Analysis result.
	 * @param array  $search_results Search results.
	 * @param bool   $include_sources Whether to include sources.
	 * @return array Research report.
	 */
	protected function build_research_report( $topic, $analysis, $search_results, $include_sources ) {
		$report = array(
			'topic'      => $topic,
			'report'     => $analysis['content'],
			'provider'   => $analysis['provider'],
			'model'      => $analysis['model'],
			'timestamp'  => time(),
			'cached'     => false,
			'word_count' => str_word_count( $analysis['content'] ),
		);

		// Add sources if requested.
		if ( $include_sources && ! empty( $search_results['sources'] ) ) {
			$report['sources']      = $search_results['sources'];
			$report['source_count'] = count( $search_results['sources'] );
		} else {
			$report['sources']      = array();
			$report['source_count'] = 0;
		}

		// Add metadata.
		$report['queries_used'] = ! empty( $search_results['queries'] ) ? $search_results['queries'] : array();

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
	 * Deduplicate sources by URL.
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

		return $unique;
	}

	/**
	 * Schedule background research via WordPress cron.
	 *
	 * @param string $topic          Research topic.
	 * @param string $depth          Research depth.
	 * @param array  $focus_areas    Focus areas.
	 * @param bool   $include_sources Whether to include sources.
	 * @param int    $user_id        User ID.
	 * @return array Status information.
	 */
	protected function schedule_background_research( $topic, $depth, $focus_areas, $include_sources, $user_id ) {
		// Generate unique job ID.
		$job_id = 'deep_research_' . md5( $topic . microtime( true ) );

		// Schedule single event.
		$scheduled = wp_schedule_single_event(
			time() + 10, // Run in 10 seconds.
			'wp_mcp_ai_deep_research_background',
			array(
				'job_id'          => $job_id,
				'topic'           => $topic,
				'depth'           => $depth,
				'focus_areas'     => $focus_areas,
				'include_sources' => $include_sources,
				'user_id'         => $user_id,
			)
		);

		if ( false === $scheduled ) {
			return new WP_Error(
				'wp_mcp_ai_schedule_failed',
				__( 'Failed to schedule background research. WordPress cron may not be working.', 'mcp-ai-wpoos' )
			);
		}

		// Store job status.
		$job_status = array(
			'job_id'     => $job_id,
			'topic'      => $topic,
			'status'     => 'scheduled',
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
	 * @param string $job_id          Job ID.
	 * @param string $topic           Research topic.
	 * @param string $depth           Research depth.
	 * @param array  $focus_areas     Focus areas.
	 * @param bool   $include_sources Whether to include sources.
	 * @param int    $user_id         User ID.
	 */
	public static function execute_background_research( $job_id, $topic, $depth, $focus_areas, $include_sources, $user_id ) {
		// Update job status.
		$job_status = array(
			'job_id'     => $job_id,
			'topic'      => $topic,
			'status'     => 'running',
			'started_at' => time(),
			'user_id'    => $user_id,
		);
		set_transient( 'wp_mcp_ai_research_job_' . $job_id, $job_status, DAY_IN_SECONDS );

		// Create tool instance and execute research.
		$tool   = new self();
		$result = $tool->execute(
			array(
				'topic'           => $topic,
				'depth'           => $depth,
				'focus_areas'     => $focus_areas,
				'include_sources' => $include_sources,
				'run_mode'        => 'immediate', // Prevent recursive scheduling.
			),
			array(
				'user_id' => $user_id,
			)
		);

		// Update job with results.
		$job_status['status']       = is_wp_error( $result ) ? 'failed' : 'completed';
		$job_status['completed_at'] = time();

		if ( is_wp_error( $result ) ) {
			$job_status['error'] = $result->get_error_message();
		} else {
			$job_status['result'] = $result;
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
