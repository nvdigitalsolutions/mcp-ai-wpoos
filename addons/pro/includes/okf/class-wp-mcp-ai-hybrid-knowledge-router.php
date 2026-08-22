<?php
/**
 * Hybrid Knowledge Router (Pro).
 *
 * Classifies a knowledge query and produces an ordered routing plan across
 * the three knowledge stores: OKF bundles (curated markdown), the vector
 * store (semantic embeddings), and Paper Store (structured JSON records).
 *
 * The default classifier is deterministic (keyword/pattern signals, no LLM
 * cost); the whole decision is overridable via
 * `wp_mcp_ai_hybrid_router_decision` for custom or LLM-backed routing.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.62
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies knowledge queries and routes them across OKF / Vector / Paper.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_Hybrid_Knowledge_Router {

	const SOURCE_OKF    = 'okf';
	const SOURCE_VECTOR = 'vector';
	const SOURCE_PAPER  = 'paper';

	/**
	 * Deterministic signal table: query pattern → source hint.
	 *
	 * @var array<string, string>
	 */
	private $signals = array();

	/**
	 * Constructor.
	 *
	 * @since 1.1.62
	 */
	public function __construct() {
		// Curated, authoritative knowledge: policies, procedures, how-tos.
		$okf_patterns = array(
			'policy',
			'procedure',
			'how do',
			'how to',
			'how-to',
			'compute',
			'definition',
			'what is',
			'what are',
			'refund',
			'guide',
			'steps',
			'playbook',
			'reference',
			'standard',
			'rule',
			'protocol',
			'formula',
		);

		// Structured records and event history.
		$paper_patterns = array(
			'incident',
			'ticket',
			'log',
			'history',
			'record',
			'past ',
			'list all',
			'find all',
			'entry',
			'events',
			'inventory',
			'transaction',
			'entry in',
			'entries',
			'report of',
		);

		// Semantic similarity and unstructured discovery.
		$vector_patterns = array(
			'similar to',
			'semantic',
			'recommend',
			'related to',
			'something like',
			'unstructured',
			'resembles',
		);

		foreach ( $okf_patterns as $pattern ) {
			$this->signals[ $pattern ] = self::SOURCE_OKF;
		}
		foreach ( $paper_patterns as $pattern ) {
			$this->signals[ $pattern ] = self::SOURCE_PAPER;
		}
		foreach ( $vector_patterns as $pattern ) {
			$this->signals[ $pattern ] = self::SOURCE_VECTOR;
		}

		/**
		 * Filter the router's signal table.
		 *
		 * Keys are lowercase query substrings; values are one of
		 * 'okf', 'vector', or 'paper'.
		 *
		 * @since 1.1.62
		 *
		 * @param array $signals Pattern → source map.
		 */
		$this->signals = apply_filters( 'wp_mcp_ai_hybrid_router_signals', $this->signals );
	}

	/**
	 * Classify a query and produce an ordered routing plan.
	 *
	 * @since 1.1.62
	 *
	 * @param string $query The knowledge query.
	 * @return array Plan with 'sources' (ordered source descriptors),
	 *               'primary', and 'signals'.
	 */
	public function classify( $query ) {
		$query   = strtolower( trim( (string) $query ) );
		$matched = array();

		foreach ( $this->signals as $pattern => $source ) {
			if ( false !== strpos( $query, $pattern ) ) {
				$matched[ $source ] = true;
			}
		}

		$ordered = array();
		if ( isset( $matched[ self::SOURCE_OKF ] ) ) {
			$ordered[] = self::SOURCE_OKF;
		}
		if ( isset( $matched[ self::SOURCE_PAPER ] ) ) {
			$ordered[] = self::SOURCE_PAPER;
		}
		if ( isset( $matched[ self::SOURCE_VECTOR ] ) ) {
			$ordered[] = self::SOURCE_VECTOR;
		}

		// Fallback order: curated knowledge first, semantic search second,
		// structured records last.
		foreach ( array( self::SOURCE_OKF, self::SOURCE_VECTOR, self::SOURCE_PAPER ) as $source ) {
			if ( ! in_array( $source, $ordered, true ) ) {
				$ordered[] = $source;
			}
		}

		$reasons = array(
			self::SOURCE_OKF    => __( 'Curated, authoritative knowledge match (policies, procedures, definitions).', 'mcp-ai-wpoos-pro' ),
			self::SOURCE_PAPER  => __( 'Structured records or event history match (incidents, logs, entries).', 'mcp-ai-wpoos-pro' ),
			self::SOURCE_VECTOR => __( 'Semantic similarity needed; ranked embedding search.', 'mcp-ai-wpoos-pro' ),
		);

		$sources = array();
		foreach ( $ordered as $source ) {
			$sources[] = array(
				'source' => $source,
				'reason' => $reasons[ $source ],
			);
		}

		$plan = array(
			'sources' => $sources,
			'primary' => $sources[0]['source'],
			'signals' => array_keys( $matched ),
		);

		/**
		 * Filter the final routing plan.
		 *
		 * Custom or LLM-backed classifiers may replace the whole plan.
		 *
		 * @since 1.1.62
		 *
		 * @param array  $plan  Routing plan (sources, primary, signals).
		 * @param string $query Original query.
		 */
		return apply_filters( 'wp_mcp_ai_hybrid_router_decision', $plan, $query );
	}

	/**
	 * Search OKF concepts by simple token overlap (deterministic, no LLM).
	 *
	 * @since 1.1.62
	 *
	 * @param string $bundle Bundle name.
	 * @param string $query  Search query.
	 * @param int    $top    Maximum results.
	 * @return array|WP_Error Ranked results (concept_id, title, score, trust info).
	 */
	public function search_okf( $bundle, $query, $top = 5 ) {
		if ( ! class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' ) ) {
			return new WP_Error(
				'wp_mcp_ai_okf_engine_missing',
				__( 'The OKF engine is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$manager = new WP_MCP_AI_OKF_Bundle_Manager();
		$root    = $manager->resolve_bundle_root( $bundle );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$reader = new WP_MCP_AI_OKF_Reader( $root );
		$tokens = $this->tokenize( $query );
		if ( empty( $tokens ) ) {
			return array();
		}

		$scored = array();
		foreach ( $reader->search( array() ) as $concept ) {
			$haystack = strtolower( $concept['title'] . ' ' . $concept['description'] );
			$score    = 0;
			foreach ( $tokens as $token ) {
				if ( false !== strpos( $haystack, $token ) ) {
					$score += strlen( $token );
				}
			}
			if ( $score <= 0 ) {
				continue;
			}
			$scored[] = array(
				'concept_id'  => $concept['concept_id'],
				'title'       => $concept['title'],
				'description' => $concept['description'],
				'trust_tier'  => $concept['trust_tier'],
				'stale'       => $concept['stale'],
				'score'       => $score,
			);
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, max( 1, min( 10, absint( $top ) ) ) );
	}

	/**
	 * Tokenize a query into lowercase words of length >= 3.
	 *
	 * @param string $query Query.
	 * @return string[]
	 */
	private function tokenize( $query ) {
		$words = preg_split( '/[^a-z0-9]+/i', strtolower( trim( (string) $query ) ) );
		if ( ! is_array( $words ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_unique( $words ),
				static function ( $word ) {
					return strlen( $word ) >= 3;
				}
			)
		);
	}
}
