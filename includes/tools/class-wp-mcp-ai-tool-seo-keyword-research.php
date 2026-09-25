<?php
/**
 * Tool: seo_keyword_research — Discovers related keywords via the Brave Search API.
 *
 * Port of mcp-wordpress wp_seo_keyword_research tool.
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
 * SEO Keyword Research — mines related keywords from live search results.
 *
 * Extracts 2-4 word candidate phrases from Brave search result titles and
 * snippets, optionally adding "{seed} tips"/"{seed} guide" variations and
 * how/what/why-style questions. Requires the seed word to appear in each
 * candidate.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_SEO_Keyword_Research implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Stopwords excluded from candidate n-grams.
	 *
	 * @since 1.1.87
	 *
	 * @var array
	 */
	private $stopwords = array( 'in', 'the', 'and', 'for', 'with', 'your', 'how', 'to', 'of', 'a', 'on', 'is', 'are', 'best', 'top', 'guide', 'what', 'why', 'when' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_keyword_research';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SEO Keyword Researcher', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Discovers related keywords, variations, and question phrases from live search results via the Brave Search API. Extracts candidate phrases from result titles and snippets (2-4 word n-grams), optional "{seed} tips"/"{seed} guide" variations, and how/what/why questions. Requires a Brave Search API key.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Building a keyword list around a seed term before writing content: related phrases, long-tail variations, and questions people ask.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Rank tracking; use seo_track_serp. Full page-1 SERP inspection is better served by web_search.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'seo_track_serp', 'web_search', 'deep_research' ),
			'notes'           => __( 'Makes up to 3 Brave API requests (seed, "{seed} tips", "{seed} guide"). Candidates must contain the seed word (or its stem).', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'seed_keyword'       => array(
					'type'        => 'string',
					'description' => __( 'Seed keyword to research around.', 'mcp-ai-wpoos' ),
					'minLength'   => 1,
				),
				'include_variations' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to run extra "{seed} tips" and "{seed} guide" queries for variation keywords.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'include_questions'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to collect how/what/why-style questions from result snippets.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'max_results'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of keywords to return.', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 50,
					'default'     => 20,
				),
			),
			'required'             => array( 'seed_keyword' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to run keyword research.', 'mcp-ai-wpoos' ) );
		}

		$seed = isset( $arguments['seed_keyword'] ) ? sanitize_text_field( $arguments['seed_keyword'] ) : '';
		if ( '' === $seed ) {
			return new WP_Error( 'wp_mcp_ai_missing_seed_keyword', __( 'A seed keyword is required.', 'mcp-ai-wpoos' ) );
		}

		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 20;
		if ( $max_results < 5 ) {
			$max_results = 5;
		} elseif ( $max_results > 50 ) {
			$max_results = 50;
		}

		$include_variations = isset( $arguments['include_variations'] ) ? ! empty( $arguments['include_variations'] ) : true;
		$include_questions  = isset( $arguments['include_questions'] ) ? ! empty( $arguments['include_questions'] ) : true;

		$api_key = WP_MCP_AI_Settings_Registry::get_setting( 'brave_search_api_key', '' );
		if ( '' === $api_key ) {
			return new WP_Error( 'wp_mcp_ai_search_missing_api_key', __( 'A Brave Search API key is required for keyword research. Configure it in the NV oOS settings.', 'mcp-ai-wpoos' ) );
		}

		$total_requests = 0;
		$candidates     = array();

		// Base search on the seed keyword.
		$search_results = $this->brave_web_search( $seed, $api_key );
		++$total_requests;

		if ( ! is_wp_error( $search_results ) ) {
			foreach ( $search_results as $item ) {
				$title   = isset( $item->title ) ? sanitize_text_field( $item->title ) : '';
				$snippet = isset( $item->description ) ? sanitize_text_field( $item->description ) : '';

				$this->add_ngrams( $title, 'related', 'title', $seed, $candidates );
				$this->add_ngrams( $snippet, 'related', 'snippet', $seed, $candidates );

				if ( $include_questions ) {
					$this->add_questions( $snippet, $seed, $candidates );
				}
			}
		}

		// Variation queries ("{seed} tips" and "{seed} guide", max 3 total requests).
		if ( $include_variations && count( $candidates ) < $max_results && $total_requests < 3 ) {
			$variation_queries = array( $seed . ' tips' );
			if ( count( $candidates ) < $max_results && $total_requests < 2 ) {
				$variation_queries[] = $seed . ' guide';
			}

			foreach ( $variation_queries as $variation_query ) {
				if ( count( $candidates ) >= $max_results || $total_requests >= 3 ) {
					break;
				}

				$search_results = $this->brave_web_search( $variation_query, $api_key );
				++$total_requests;

				if ( is_wp_error( $search_results ) ) {
					continue;
				}

				foreach ( $search_results as $item ) {
					if ( count( $candidates ) >= $max_results ) {
						break;
					}
					$title = isset( $item->title ) ? sanitize_text_field( $item->title ) : '';
					$this->add_ngrams( $title, 'variation', 'serp', $seed, $candidates );
				}
			}
		}

		// Assemble: scored phrases first (by frequency), then questions, then cap.
		$scored    = array();
		$questions = array();
		foreach ( $candidates as $candidate ) {
			if ( 'question' === $candidate['type'] ) {
				$questions[] = $candidate;
			} else {
				$scored[] = $candidate;
			}
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		$final    = array_merge( $scored, $questions );
		$final    = array_slice( $final, 0, $max_results );
		$keywords = array();
		foreach ( $final as $candidate ) {
			$keywords[] = array(
				'keyword' => esc_html( $candidate['keyword'] ),
				'type'    => $candidate['type'],
				'source'  => $candidate['source'],
			);
		}

		$message = sprintf(
			/* translators: 1: number of keywords found, 2: seed keyword */
			__( 'Keyword research found %1$d candidate(s) for "%2$s".', 'mcp-ai-wpoos' ),
			count( $keywords ),
			esc_html( $seed )
		);

		if ( empty( $keywords ) ) {
			$message = sprintf(
				/* translators: %s: seed keyword */
				__( 'Keyword research for "%s" returned no usable candidates; try a different seed keyword.', 'mcp-ai-wpoos' ),
				esc_html( $seed )
			);
		}

		return array(
			'message'        => $message,
			'keywords'       => $keywords,
			'seed_keyword'   => esc_html( $seed ),
			'total_requests' => $total_requests,
		);
	}

	/**
	 * Extracts 2-4 word n-grams from a text and scores them by frequency.
	 *
	 * @since 1.1.87
	 *
	 * @param string $text       Text to mine (title or snippet).
	 * @param string $type       Keyword type (related|variation).
	 * @param string $source     Source label (title|snippet|serp).
	 * @param string $seed       Seed keyword for relevance filtering.
	 * @param array  $candidates Candidate map (modified in place).
	 */
	private function add_ngrams( $text, $type, $source, $seed, &$candidates ) {
		if ( '' === $text ) {
			return;
		}

		$text  = strtolower( $text );
		$text  = preg_replace( '/[^a-z0-9\s\-]/', ' ', $text );
		$words = preg_split( '/\s+/', trim( $text ) );

		if ( count( $words ) < 2 ) {
			return;
		}

		$word_count = count( $words );
		for ( $n = 2; $n <= 4; $n++ ) {
			for ( $i = 0; $i <= $word_count - $n; $i++ ) {
				$gram = trim( implode( ' ', array_slice( $words, $i, $n ) ) );
				if ( '' === $gram ) {
					continue;
				}
				if ( $this->is_ignorable_gram( $gram ) ) {
					continue;
				}
				if ( ! $this->contains_seed( $gram, $seed ) ) {
					continue;
				}

				if ( isset( $candidates[ $gram ] ) ) {
					++$candidates[ $gram ]['score'];
				} else {
					$candidates[ $gram ] = array(
						'keyword' => ucwords( $gram ),
						'type'    => $type,
						'source'  => $source,
						'score'   => 1,
					);
				}
			}
		}
	}

	/**
	 * Collects question sentences from snippet text.
	 *
	 * @since 1.1.87
	 *
	 * @param string $text       Snippet text.
	 * @param string $seed       Seed keyword for relevance filtering.
	 * @param array  $candidates Candidate map (modified in place).
	 */
	private function add_questions( $text, $seed, &$candidates ) {
		if ( '' === $text ) {
			return;
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/', $text );
		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( '' === $sentence ) {
				continue;
			}

			if ( ! preg_match( '/^(how|what|why|which|when|where|can|does|is|are)\b/i', $sentence ) ) {
				continue;
			}

			if ( ! $this->contains_seed( strtolower( $sentence ), $seed ) ) {
				continue;
			}

			if ( mb_strlen( $sentence ) > 60 ) {
				$sentence = mb_substr( $sentence, 0, 57 ) . '...';
			}

			$key = strtolower( $sentence );
			if ( isset( $candidates[ $key ] ) ) {
				continue;
			}

			$candidates[ $key ] = array(
				'keyword' => $sentence,
				'type'    => 'question',
				'source'  => 'snippet',
				'score'   => 1,
			);
		}
	}

	/**
	 * Checks whether a gram contains a stopword or is numeric-only.
	 *
	 * @since 1.1.87
	 *
	 * @param string $gram Candidate n-gram.
	 * @return bool True when the gram should be skipped.
	 */
	private function is_ignorable_gram( $gram ) {
		$tokens    = explode( ' ', $gram );
		$has_alpha = false;

		foreach ( $tokens as $token ) {
			if ( in_array( $token, $this->stopwords, true ) ) {
				return true;
			}
			if ( preg_match( '/[a-z]/', $token ) ) {
				$has_alpha = true;
			}
		}

		return ! $has_alpha;
	}

	/**
	 * Checks whether a candidate contains the seed word (or a simple stem).
	 *
	 * @since 1.1.87
	 *
	 * @param string $candidate Candidate text (lowercased).
	 * @param string $seed      Seed keyword.
	 * @return bool True when the seed is present.
	 */
	private function contains_seed( $candidate, $seed ) {
		$seed = strtolower( trim( $seed ) );
		if ( '' === $seed ) {
			return true;
		}

		$seed_words = preg_split( '/\s+/', $seed );
		foreach ( $seed_words as $word ) {
			if ( strlen( $word ) < 4 ) {
				continue;
			}
			if ( false !== strpos( $candidate, $word ) ) {
				return true;
			}
			$stem = rtrim( $word, 's' );
			if ( strlen( $stem ) >= 4 && false !== strpos( $candidate, $stem ) ) {
				return true;
			}
		}

		// Fallback for short seeds: match the full phrase.
		return false !== strpos( $candidate, $seed );
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
