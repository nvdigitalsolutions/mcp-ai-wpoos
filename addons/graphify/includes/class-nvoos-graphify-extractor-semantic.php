<?php
/**
 * NV oOS Graphify Addon — Semantic Extractor
 *
 * Stub for AI-powered semantic extraction (Phase v0.3.0).
 * For the MVP, implements basic keyword-frequency analysis to discover
 * topic concepts and content similarity without requiring an AI provider.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Semantic relationship extractor for the NV oOS Graphify addon.
 *
 * Analyses post content to discover topic concepts via word-frequency
 * analysis and creates inferred edges between posts that share
 * common concepts. All edges are tagged INFERRED.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Extractor_Semantic {

	/**
	 * Minimum shared concepts required to create a similarity edge.
	 *
	 * @var int
	 */
	const SIMILARITY_THRESHOLD = 3;

	/**
	 * Default number of top keywords to extract per post.
	 *
	 * @var int
	 */
	const DEFAULT_TOP_N = 5;

	/**
	 * Extract semantic nodes and edges from a content inventory.
	 *
	 * For each post the extractor:
	 * 1. Extracts the top keywords from title + content.
	 * 2. Creates `concept` nodes for each keyword.
	 * 3. Creates `discusses_topic` edges from posts to concepts.
	 * 4. Creates `semantically_similar_to` edges between posts
	 *    that share {@see SIMILARITY_THRESHOLD} or more concepts.
	 *
	 * @since 0.1.0
	 *
	 * @param array $inventory Content inventory from NV_oOS_Graphify_Detector::detect().
	 * @return array {
	 *     Extracted graph data.
	 *
	 *     @type array[] $nodes Array of node arrays.
	 *     @type array[] $edges Array of edge arrays.
	 * }
	 */
	public function extract( $inventory ) {
		$nodes = array();
		$edges = array();

		if ( ! $this->is_enabled() ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
			);
		}

		$posts = isset( $inventory['posts'] ) ? $inventory['posts'] : array();
		if ( empty( $posts ) ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
			);
		}

		$concept_index = array(); // keyword => node array.
		$post_concepts = array(); // post_id => array of keywords.

		foreach ( $posts as $post ) {
			$text     = sanitize_text_field( $post->post_title ) . ' ' . wp_strip_all_tags( $post->post_content );
			$keywords = $this->extract_keywords( $text, self::DEFAULT_TOP_N );

			if ( empty( $keywords ) ) {
				continue;
			}

			$post_node_id  = 'post_' . absint( $post->ID );
			$post_keywords = array();

			foreach ( $keywords as $keyword => $count ) {
				$concept_id = 'concept_' . sanitize_title( $keyword );

				// Create concept node if new.
				if ( ! isset( $concept_index[ $concept_id ] ) ) {
					$concept_index[ $concept_id ] = array(
						'node_id'     => $concept_id,
						'label'       => sanitize_text_field( $keyword ),
						'node_type'   => 'concept',
						'source_type' => 'keyword',
						'source_id'   => 0,
						'source_url'  => '',
						'metadata'    => array(
							'extraction_method' => 'word_frequency',
						),
					);
				}

				// Edge: post discusses topic.
				$edges[] = array(
					'source_node_id'   => $post_node_id,
					'target_node_id'   => $concept_id,
					'relation'         => 'discusses_topic',
					'confidence'       => 'INFERRED',
					'confidence_score' => 0.7,
					'metadata'         => array(
						'frequency' => absint( $count ),
					),
				);

				$post_keywords[] = $concept_id;
			}

			$post_concepts[ absint( $post->ID ) ] = $post_keywords;
		}

		// Detect similarity between posts.
		$post_ids = array_keys( $post_concepts );
		$count    = count( $post_ids );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$shared = array_intersect(
					$post_concepts[ $post_ids[ $i ] ],
					$post_concepts[ $post_ids[ $j ] ]
				);

				$shared_count = count( $shared );
				if ( $shared_count < self::SIMILARITY_THRESHOLD ) {
					continue;
				}

				// Calculate overlap ratio: shared / min( set_a, set_b ).
				$min_size = min(
					count( $post_concepts[ $post_ids[ $i ] ] ),
					count( $post_concepts[ $post_ids[ $j ] ] )
				);

				$score = ( $min_size > 0 ) ? round( $shared_count / $min_size, 2 ) : 0.0;

				$edges[] = array(
					'source_node_id'   => 'post_' . absint( $post_ids[ $i ] ),
					'target_node_id'   => 'post_' . absint( $post_ids[ $j ] ),
					'relation'         => 'semantically_similar_to',
					'confidence'       => 'INFERRED',
					'confidence_score' => $score,
					'metadata'         => array(
						'shared_concepts' => count( $shared ),
						'overlap_ratio'   => $score,
					),
				);
			}
		}

		$nodes = array_values( $concept_index );

		return array(
			'nodes' => $nodes,
			'edges' => $edges,
		);
	}

	/**
	 * Check whether semantic extraction is enabled in settings.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when semantic extraction is enabled.
	 */
	public function is_enabled() {
		$settings = NV_oOS_Graphify::get_settings();

		return ! empty( $settings['include_semantic'] );
	}

	/**
	 * Extract keywords from text using word-frequency analysis.
	 *
	 * Lowercases the text, strips HTML, splits on non-alpha characters,
	 * filters stop words and very short tokens, counts frequency, and
	 * returns the top N keywords ordered by frequency descending.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text  Raw text to analyse.
	 * @param int    $top_n Number of top keywords to return. Default 10.
	 * @return array Associative array of keyword => frequency, sorted descending.
	 */
	public function extract_keywords( $text, $top_n = 10 ) {
		$top_n = absint( $top_n );
		if ( 0 === $top_n ) {
			$top_n = 10;
		}

		$text = wp_strip_all_tags( $text );
		$text = strtolower( $text );

		// Split on anything that is not a letter (supports basic ASCII).
		$words = preg_split( '/[^a-z]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $words ) ) {
			return array();
		}

		$stop_words = $this->get_stop_words();
		$counts     = array();

		foreach ( $words as $word ) {
			// Skip very short words and stop words.
			if ( strlen( $word ) < 3 ) {
				continue;
			}
			if ( isset( $stop_words[ $word ] ) ) {
				continue;
			}

			if ( ! isset( $counts[ $word ] ) ) {
				$counts[ $word ] = 0;
			}
			++$counts[ $word ];
		}

		if ( empty( $counts ) ) {
			return array();
		}

		arsort( $counts );

		return array_slice( $counts, 0, $top_n, true );
	}

	/**
	 * Return a lookup array of common English stop words.
	 *
	 * The words are stored as array keys for O(1) lookup performance.
	 *
	 * @since 0.1.0
	 *
	 * @return array Associative array where keys are stop words and values are true.
	 */
	public function get_stop_words() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$words = array(
			'the',
			'a',
			'an',
			'and',
			'or',
			'but',
			'in',
			'on',
			'at',
			'to',
			'for',
			'of',
			'with',
			'by',
			'from',
			'as',
			'is',
			'was',
			'are',
			'were',
			'be',
			'been',
			'being',
			'have',
			'has',
			'had',
			'having',
			'do',
			'does',
			'did',
			'doing',
			'will',
			'would',
			'could',
			'should',
			'may',
			'might',
			'shall',
			'can',
			'need',
			'dare',
			'ought',
			'used',
			'not',
			'no',
			'nor',
			'so',
			'if',
			'then',
			'than',
			'too',
			'very',
			'just',
			'about',
			'above',
			'after',
			'again',
			'all',
			'also',
			'am',
			'any',
			'because',
			'before',
			'below',
			'between',
			'both',
			'during',
			'each',
			'few',
			'further',
			'get',
			'got',
			'here',
			'her',
			'hers',
			'herself',
			'him',
			'himself',
			'his',
			'how',
			'into',
			'its',
			'itself',
			'let',
			'me',
			'more',
			'most',
			'my',
			'myself',
			'now',
			'off',
			'once',
			'only',
			'other',
			'our',
			'ours',
			'ourselves',
			'out',
			'over',
			'own',
			'same',
			'she',
			'some',
			'such',
			'that',
			'their',
			'theirs',
			'them',
			'themselves',
			'there',
			'these',
			'they',
			'this',
			'those',
			'through',
			'under',
			'until',
			'up',
			'us',
			'what',
			'when',
			'where',
			'which',
			'while',
			'who',
			'whom',
			'why',
			'you',
			'your',
			'yours',
			'yourself',
			'yourselves',
			'it',
			'he',
			'we',
			'i',
			'much',
			'many',
			'one',
			'two',
		);

		$cache = array_fill_keys( $words, true );

		return $cache;
	}
}
