<?php
/**
 * Architectural_Precedents_Engine (ecosystem port - Wave F2, architectural-design tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/architectural-design/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * per-file seams — the base-owned interface/Logger/media-url-utils requires gain exists-check seams
 * resolving from the addon's D8-compat `src/` copies, the response/subprocess traits are
 * wave-proof-guarded, the openai/gemini client requires stay monolith-gated, and the
 * `WP_MCP_AI_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Architectural precedent search engine.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Architectural_Precedents_Engine {

	/**
	 * Build a corpus string from a precedent post + meta.
	 *
	 * @param WP_Post $post Precedent post.
	 * @return string
	 */
	public static function build_corpus( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$pieces   = array();
		$pieces[] = (string) $post->post_title;

		$excerpt = (string) $post->post_excerpt;
		if ( '' !== $excerpt ) {
			$pieces[] = $excerpt;
		}

		$body = wp_strip_all_tags( (string) $post->post_content );
		if ( '' !== $body ) {
			$pieces[] = $body;
		}

		$country = (string) get_post_meta( $post->ID, '_arch_prec_country_code', true );
		if ( '' !== $country ) {
			$pieces[] = 'Country: ' . $country;
		}
		$btype = (string) get_post_meta( $post->ID, '_arch_prec_building_type', true );
		if ( '' !== $btype ) {
			$pieces[] = 'Building type: ' . $btype;
		}
		$rating = (string) get_post_meta( $post->ID, '_arch_prec_sustainability_rating', true );
		if ( '' !== $rating ) {
			$pieces[] = 'Sustainability rating: ' . $rating;
		}
		$climate = (string) get_post_meta( $post->ID, '_arch_prec_climate_zone', true );
		if ( '' !== $climate ) {
			$pieces[] = 'Climate zone: ' . $climate;
		}
		$features = get_post_meta( $post->ID, '_arch_prec_key_features', true );
		if ( is_array( $features ) && ! empty( $features ) ) {
			$pieces[] = 'Key features: ' . implode( '; ', array_map( 'strval', $features ) );
		}

		return trim( implode( "\n", $pieces ) );
	}

	/**
	 * Embed a text string using the global vector context service.
	 *
	 * Returns `null` when embeddings are unavailable so the caller can
	 * fall back to keyword matching.
	 *
	 * @param string $text Text to embed.
	 * @return array|null
	 */
	public static function embed_text( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return null;
		}
		if ( ! class_exists( 'WP_MCP_AI_Vector_Context_Service' ) ) {
			return null;
		}

		/**
		 * Filter to allow tests / hosting environments to short-circuit the
		 * embedding call. Callbacks receive the raw text and should return
		 * either an array of floats (the embedding) or null.
		 *
		 * @since 1.5.0
		 *
		 * @param array|null $embedding Embedding vector or null to fall through.
		 * @param string     $text      Raw text being embedded.
		 */
		$short_circuit = apply_filters( 'wp_mcp_ai_arch_prec_embedding', null, $text );
		if ( is_array( $short_circuit ) ) {
			return $short_circuit;
		}

		try {
			$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( ! is_object( $service ) || ! method_exists( $service, 'embed_context' ) ) {
			return null;
		}
		$result = $service->embed_context( $text );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return null;
		}
		return $result;
	}

	/**
	 * Compute cosine similarity between two equal-length numeric vectors.
	 *
	 * @param array $a Vector a.
	 * @param array $b Vector b.
	 * @return float Similarity in [-1.0, 1.0]; 0.0 when either vector is
	 *               empty or magnitudes are zero.
	 */
	public static function cosine( array $a, array $b ) {
		$len = min( count( $a ), count( $b ) );
		if ( 0 === $len ) {
			return 0.0;
		}
		$dot   = 0.0;
		$mag_a = 0.0;
		$mag_b = 0.0;
		for ( $i = 0; $i < $len; $i++ ) {
			$va     = (float) $a[ $i ];
			$vb     = (float) $b[ $i ];
			$dot   += $va * $vb;
			$mag_a += $va * $va;
			$mag_b += $vb * $vb;
		}
		if ( 0.0 === $mag_a || 0.0 === $mag_b ) {
			return 0.0;
		}
		return $dot / ( sqrt( $mag_a ) * sqrt( $mag_b ) );
	}

	/**
	 * Score a query string against a precedent corpus using simple
	 * keyword matching. Used as a fallback when embeddings are unavailable.
	 *
	 * Returns a score in [0.0, 1.0] derived from the proportion of unique
	 * query tokens (length >= 3) that appear in the corpus.
	 *
	 * @param string $query  Query.
	 * @param string $corpus Corpus text.
	 * @return float
	 */
	public static function keyword_score( $query, $corpus ) {
		$query  = strtolower( (string) $query );
		$corpus = strtolower( (string) $corpus );
		if ( '' === $query || '' === $corpus ) {
			return 0.0;
		}
		$tokens = preg_split( '/[^a-z0-9]+/', $query, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $tokens ) ) {
			return 0.0;
		}
		$tokens = array_unique(
			array_filter(
				$tokens,
				static function ( $t ) {
					return strlen( $t ) >= 3;
				}
			)
		);
		if ( empty( $tokens ) ) {
			return 0.0;
		}
		$hits = 0;
		foreach ( $tokens as $t ) {
			if ( false !== strpos( $corpus, $t ) ) {
				++$hits;
			}
		}
		return (float) $hits / (float) count( $tokens );
	}

	/**
	 * Regenerate and persist the cached embedding for a precedent post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Embedding or null if unavailable.
	 */
	public static function regenerate_embedding_for_post( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || WP_MCP_AI_Architectural_Precedent_CPT::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$corpus = self::build_corpus( $post );
		if ( '' === $corpus ) {
			delete_post_meta( $post_id, '_arch_prec_embedding' );
			delete_post_meta( $post_id, '_arch_prec_embedding_model' );
			return null;
		}
		$embedding = self::embed_text( $corpus );
		if ( null === $embedding ) {
			// Leave any existing embedding in place — caller decided to.
			// degrade rather than corrupt.
			return null;
		}
		update_post_meta( $post_id, '_arch_prec_embedding', $embedding );
		$model = defined( 'WP_MCP_AI_Vector_Context_Service::EMBEDDING_MODEL' )
			? constant( 'WP_MCP_AI_Vector_Context_Service::EMBEDDING_MODEL' )
			: 'text-embedding-3-small';
		update_post_meta( $post_id, '_arch_prec_embedding_model', $model );
		return $embedding;
	}
}
