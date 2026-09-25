<?php
/**
 * Tool: seo_analyze_content — Analyzes a post's readability, keyword usage, and structure.
 *
 * Port of mcp-wordpress wp_seo_analyze_content tool.
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
 * SEO Analyze Content — computes on-page SEO metrics for a single post.
 *
 * Provides readability scores (Flesch Reading Ease, grade level), keyword
 * usage statistics (occurrences, density, placement), and structural
 * diagnostics (headings, links, images) for published or draft content.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_SEO_Analyze_Content implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'seo_analyze_content';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'SEO Content Analyzer', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyzes the SEO quality of a post\'s content: readability scores (Flesch Reading Ease, grade level), keyword usage and density, and structural elements (headings, links, images). Works on the plain text of title, excerpt, and content.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Auditing one post before publishing: checking readability, keyword placement/density, heading structure, and image alt coverage.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Writing or editing metadata; use seo_meta_optimizer to generate titles/descriptions. For site-wide checks, use seo_site_audit.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'seo_meta_optimizer', 'suggest_internal_links', 'get_rankmath_seo', 'content_freshness_checker', 'get_recent_posts' ),
			'notes'           => __( 'Keyword density uses plain-text word count. Flesch scores are approximations based on vowel-group syllable counting.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'        => array(
					'type'        => 'integer',
					'description' => __( 'ID of the post to analyze.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
				),
				'analysis_type'  => array(
					'type'        => 'string',
					'description' => __( 'Which analysis sections to run.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'readability', 'keywords', 'structure', 'full' ),
					'default'     => 'full',
				),
				'focus_keywords' => array(
					'type'        => 'array',
					'description' => __( 'Optional list of focus keywords to measure (capped at 10).', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'string' ),
					'maxItems'    => 10,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'post_id' ),
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
			'read-only',           // Only reads data, does not modify state.
			'local-only',          // No external API calls.
			'requires-capability', // Requires user capabilities.
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

		$post_id       = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$analysis_type = isset( $arguments['analysis_type'] ) ? sanitize_key( $arguments['analysis_type'] ) : 'full';

		if ( ! in_array( $analysis_type, array( 'readability', 'keywords', 'structure', 'full' ), true ) ) {
			$analysis_type = 'full';
		}

		$focus_keywords = array();
		if ( isset( $arguments['focus_keywords'] ) && is_array( $arguments['focus_keywords'] ) ) {
			$focus_keywords = array_values(
				array_filter(
					array_map( 'sanitize_text_field', array_slice( $arguments['focus_keywords'], 0, 10 ) )
				)
			);
		}

		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return new WP_Error( 'wp_mcp_ai_post_not_found', __( 'The requested post could not be found.', 'mcp-ai-wpoos' ) );
		}

		if ( ! user_can( $acting_user_id, 'edit_post', $post_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to analyze this post.', 'mcp-ai-wpoos' ) );
		}

		$plain = trim(
			wp_strip_all_tags( get_the_title( $post ) ) . "\n\n" .
			wp_strip_all_tags( $post->post_excerpt ) . "\n\n" .
			wp_strip_all_tags( $post->post_content )
		);

		$word_count = str_word_count( $plain );
		$analysis   = array();

		if ( in_array( $analysis_type, array( 'readability', 'full' ), true ) ) {
			$analysis['readability'] = $this->analyze_readability( $plain );
		}

		if ( in_array( $analysis_type, array( 'keywords', 'full' ), true ) ) {
			$analysis['keywords'] = $this->analyze_keywords( $post, $plain, $focus_keywords, $word_count );
		}

		if ( in_array( $analysis_type, array( 'structure', 'full' ), true ) ) {
			$analysis['structure'] = $this->analyze_structure( $post );
		}

		// Score summary for quick LLM consumption.
		$scores = array(
			'readability'     => null,
			'word_count'      => $word_count,
			'keyword_density' => null,
		);

		if ( isset( $analysis['readability']['flesch_reading_ease'] ) ) {
			$scores['readability'] = $analysis['readability']['flesch_reading_ease'];
		}

		if ( ! empty( $analysis['keywords'] ) ) {
			$best_density = null;
			foreach ( $analysis['keywords'] as $keyword_data ) {
				if ( isset( $keyword_data['density_percent'] ) && ( null === $best_density || $keyword_data['density_percent'] > $best_density ) ) {
					$best_density = $keyword_data['density_percent'];
				}
			}
			$scores['keyword_density'] = $best_density;
		}

		$message = sprintf(
			/* translators: 1: post title, 2: analysis type */
			__( 'SEO analysis (%2$s) completed for "%1$s".', 'mcp-ai-wpoos' ),
			esc_html( get_the_title( $post ) ),
			$analysis_type
		);

		if ( 0 === $word_count ) {
			$message .= ' ' . __( 'The post content is empty, so most metrics are zero.', 'mcp-ai-wpoos' );
		}

		return array(
			'message'  => $message,
			'analysis' => $analysis,
			'scores'   => $scores,
		);
	}

	/**
	 * Computes readability metrics for plain text.
	 *
	 * @since 1.1.87
	 *
	 * @param string $plain Plain text to analyze.
	 * @return array Readability metrics.
	 */
	private function analyze_readability( $plain ) {
		$words      = array_values( array_filter( preg_split( '/\s+/', trim( $plain ) ) ) );
		$word_count = count( $words );

		$sentences      = array_values( array_filter( preg_split( '/[.!?]+/', $plain ) ) );
		$sentence_count = count( $sentences );

		$syllables = $this->count_syllables( $plain );

		$avg_sentence_length = $sentence_count > 0 ? round( $word_count / $sentence_count, 1 ) : 0.0;

		preg_match_all( '/[a-zA-Z]+/', $plain, $word_matches );
		$letters = 0;
		foreach ( $word_matches[0] as $word ) {
			$letters += strlen( $word );
		}
		$avg_word_length = $word_count > 0 ? round( $letters / $word_count, 1 ) : 0.0;

		$paragraphs = array_values(
			array_filter(
				preg_split( '/\n+/', $plain ),
				function ( $paragraph ) {
					return '' !== trim( $paragraph );
				}
			)
		);

		$flesch = 0.0;
		$grade  = 0.0;
		if ( $word_count > 0 && $sentence_count > 0 ) {
			$flesch = round( 206.835 - ( 1.015 * ( $word_count / $sentence_count ) ) - ( 84.6 * ( $syllables / $word_count ) ), 1 );
			$grade  = round( ( 0.39 * ( $word_count / $sentence_count ) ) + ( 11.8 * ( $syllables / $word_count ) ) - 15.59, 1 );
		}

		$long_sentence_count = 0;
		foreach ( $sentences as $sentence ) {
			if ( str_word_count( $sentence ) > 25 ) {
				++$long_sentence_count;
			}
		}

		return array(
			'word_count'          => $word_count,
			'sentence_count'      => $sentence_count,
			'avg_sentence_length' => $avg_sentence_length,
			'avg_word_length'     => $avg_word_length,
			'paragraph_count'     => count( $paragraphs ),
			'flesch_reading_ease' => $flesch,
			'reading_grade_level' => $grade,
			'long_sentence_count' => $long_sentence_count,
		);
	}

	/**
	 * Computes keyword usage statistics for each focus keyword.
	 *
	 * @since 1.1.87
	 *
	 * @param WP_Post $post            The post being analyzed.
	 * @param string  $plain           Plain text of the post.
	 * @param array   $focus_keywords  Focus keywords.
	 * @param int     $word_count      Total word count of the plain text.
	 * @return array Keyword statistics.
	 */
	private function analyze_keywords( $post, $plain, $focus_keywords, $word_count ) {
		$lower_plain = strtolower( $plain );
		$lower_title = strtolower( wp_strip_all_tags( get_the_title( $post ) ) );

		$words           = preg_split( '/\s+/', trim( $plain ) );
		$first_100_lower = strtolower( implode( ' ', array_slice( $words, 0, 100 ) ) );

		preg_match_all( '/<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', $post->post_content, $heading_matches );
		$headings_lower = strtolower( wp_strip_all_tags( implode( ' ', $heading_matches[1] ) ) );

		$results = array();
		foreach ( $focus_keywords as $keyword ) {
			$lower_keyword = strtolower( $keyword );
			if ( '' === $lower_keyword ) {
				continue;
			}

			$occurrences   = substr_count( $lower_plain, $lower_keyword );
			$keyword_words = count( preg_split( '/\s+/', trim( $lower_keyword ) ) );
			$density       = ( $word_count > 0 && $keyword_words > 0 ) ? round( ( $occurrences * $keyword_words / $word_count ) * 100, 2 ) : 0.0;

			$results[] = array(
				'keyword'            => esc_html( $keyword ),
				'occurrences'        => $occurrences,
				'density_percent'    => $density,
				'in_title'           => false !== strpos( $lower_title, $lower_keyword ),
				'in_first_100_words' => false !== strpos( $first_100_lower, $lower_keyword ),
				'in_headings'        => false !== strpos( $headings_lower, $lower_keyword ),
			);
		}

		return $results;
	}

	/**
	 * Computes structural diagnostics for a post.
	 *
	 * @since 1.1.87
	 *
	 * @param WP_Post $post The post being analyzed.
	 * @return array Structural metrics.
	 */
	private function analyze_structure( $post ) {
		$content = $post->post_content;

		$h1_count = preg_match_all( '/<h1[^>]*>/i', $content, $h1_matches );
		$h2_count = preg_match_all( '/<h2[^>]*>/i', $content, $h2_matches );
		$h3_count = preg_match_all( '/<h3[^>]*>/i', $content, $h3_matches );

		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $link_matches );
		$site_host      = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$internal_links = 0;
		$external_links = 0;
		foreach ( $link_matches[1] as $href ) {
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( empty( $host ) ) {
				++$internal_links;
				continue;
			}
			if ( $host === $site_host ) {
				++$internal_links;
			} else {
				++$external_links;
			}
		}

		$images             = preg_match_all( '/<img\b[^>]*>/i', $content, $img_matches );
		$images_without_alt = preg_match_all( '/<img\b(?![^>]*\balt\s*=)[^>]*>/i', $content, $alt_matches );

		return array(
			'h1_count'           => $h1_count,
			'h2_count'           => $h2_count,
			'h3_count'           => $h3_count,
			'multiple_h1'        => $h1_count > 1,
			'internal_links'     => $internal_links,
			'external_links'     => $external_links,
			'images'             => $images,
			'images_without_alt' => $images_without_alt,
			'content_length'     => mb_strlen( wp_strip_all_tags( $content ) ),
			'has_excerpt'        => ! empty( $post->post_excerpt ),
		);
	}

	/**
	 * Counts the approximate number of syllables in a text block.
	 *
	 * @since 1.1.87
	 *
	 * @param string $text Plain text.
	 * @return int Syllable count.
	 */
	private function count_syllables( $text ) {
		$words = preg_split( '/\s+/', trim( strtolower( $text ) ) );
		$total = 0;
		foreach ( $words as $word ) {
			$total += $this->count_word_syllables( $word );
		}
		return $total;
	}

	/**
	 * Counts the approximate number of syllables in a single word using vowel groups.
	 *
	 * @since 1.1.87
	 *
	 * @param string $word A single word.
	 * @return int Syllable count (minimum 1 for non-empty words).
	 */
	private function count_word_syllables( $word ) {
		$word = preg_replace( '/[^a-z]/', '', $word );
		if ( '' === $word ) {
			return 0;
		}

		// Drop a silent trailing "e" (a common simplification).
		$word = preg_replace( '/e$/', '', $word );

		preg_match_all( '/[aeiouy]+/', $word, $matches );
		return max( 1, count( $matches[0] ) );
	}
}
