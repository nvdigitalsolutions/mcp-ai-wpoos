<?php
/**
 * Corpus deduper — Model Foundry (Pro, Phase 1).
 *
 * Three-tier deduplication for corpus rows, per the researched curation
 * pipeline (R1/R2):
 *
 * 1. Exact  — MD5 of the canonical row serialization (identical rows).
 * 2. Fuzzy  — SHA-256 of a normalization (lowercase, letters/digits only),
 *             catching near-duplicates deterministically with zero provider
 *             cost.
 * 3. Semantic (opt-in) — cosine distance over embedding vectors, capped to a
 *             bounded row count. Embeddings come from the
 *             `wp_mcp_ai_model_foundry_embed_text` filter; the default
 *             implementation uses the site's vector context service, so a
 *             site without an embedding provider degrades to exact+fuzzy
 *             only (never a fatal).
 *
 * All tiers are deterministic given the same rows and options; semantic
 * results additionally depend on the configured embedding model (documented
 * in the manifest).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.4
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three-tier corpus deduper.
 */
class WP_MCP_AI_Corpus_Deduper {

	/**
	 * Default semantic-dedup row cap. Above this the semantic tier is
	 * skipped (with a reason) instead of spending unbounded embedding calls.
	 */
	const DEFAULT_SEMANTIC_MAX_ROWS = 1000;

	/**
	 * Default semantic-dedup cosine threshold. Rows whose vectors are this
	 * similar are treated as duplicates.
	 */
	const DEFAULT_SEMANTIC_THRESHOLD = 0.95;

	/**
	 * Deduplicate rows.
	 *
	 * @param array<int,array<string,mixed>> $rows    Row payloads.
	 * @param array<string,mixed>            $options {
	 *     Optional.
	 *
	 *     @type bool   $semantic          Enable the embedding tier. Default false.
	 *     @type int    $semantic_max_rows Row cap for the semantic tier. Default 1000.
	 *     @type float  $semantic_threshold Cosine threshold. Default 0.95.
	 * }
	 * @return array{rows:array<int,array<string,mixed>>,removed:int,by_tier:array{exact:int,fuzzy:int,semantic:int},semantic_skipped_reason:string}
	 */
	public function dedup( array $rows, array $options = array() ) {
		$semantic          = ! empty( $options['semantic'] );
		$semantic_max      = isset( $options['semantic_max_rows'] ) ? max( 1, (int) $options['semantic_max_rows'] ) : self::DEFAULT_SEMANTIC_MAX_ROWS;
		$semantic_threshold = isset( $options['semantic_threshold'] ) ? (float) $options['semantic_threshold'] : self::DEFAULT_SEMANTIC_THRESHOLD;
		if ( $semantic_threshold < 0.0 || $semantic_threshold > 1.0 ) {
			$semantic_threshold = self::DEFAULT_SEMANTIC_THRESHOLD;
		}

		$removed_exact    = 0;
		$removed_fuzzy    = 0;
		$removed_semantic = 0;
		$seen_exact       = array();
		$seen_fuzzy       = array();
		$kept             = array();
		$skip_reason      = '';

		if ( $semantic && count( $rows ) > $semantic_max ) {
			$skip_reason = 'semantic_tier_skipped_row_cap';
			$semantic    = false;
		}

		$kept_vectors = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				++$removed_fuzzy;
				continue;
			}

			$exact_key = md5( (string) wp_json_encode( $row ) );
			if ( isset( $seen_exact[ $exact_key ] ) ) {
				++$removed_exact;
				continue;
			}

			$fuzzy_key = $this->fuzzy_key( $row );
			if ( '' !== $fuzzy_key && isset( $seen_fuzzy[ $fuzzy_key ] ) ) {
				++$removed_fuzzy;
				continue;
			}

			if ( $semantic ) {
				$vector = $this->embed_row( $row );
				if ( is_array( $vector ) ) {
					$is_dup = false;
					foreach ( $kept_vectors as $kept_vector ) {
						if ( $this->cosine( $vector, $kept_vector ) >= $semantic_threshold ) {
							$is_dup = true;
							break;
						}
					}
					if ( $is_dup ) {
						++$removed_semantic;
						continue;
					}
					$kept_vectors[] = $vector;
				}
				// A failed embed is not a duplicate verdict — keep the row.
			}

			$seen_exact[ $exact_key ] = true;
			if ( '' !== $fuzzy_key ) {
				$seen_fuzzy[ $fuzzy_key ] = true;
			}
			$kept[] = $row;
		}

		return array(
			'rows'                    => $kept,
			'removed'                 => $removed_exact + $removed_fuzzy + $removed_semantic,
			'by_tier'                 => array(
				'exact'    => $removed_exact,
				'fuzzy'    => $removed_fuzzy,
				'semantic' => $removed_semantic,
			),
			'semantic_skipped_reason' => $skip_reason,
		);
	}

	/**
	 * Compute the fuzzy (normalized) key for a row.
	 *
	 * Normalization: lowercase, keep unicode letters and numbers only. Rows
	 * whose normalized text is empty get an empty key, which means they are
	 * never fuzzy-deduplicated (empty rows are skipped upstream instead).
	 *
	 * @param array<string,mixed> $row Row payload.
	 * @return string Hex key, or empty string when there is no normalizable text.
	 */
	private function fuzzy_key( array $row ) {
		$text = $this->canonical_text( $row );
		if ( '' === $text ) {
			return '';
		}
		$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.preg_replace_preg_replace -- Intentional normalization for dedup hashing.
		$normalized = preg_replace( '/[^\p{L}\p{N}]+/u', '', $normalized );
		$normalized = ( null === $normalized ) ? '' : $normalized;
		if ( '' === $normalized ) {
			return '';
		}
		return hash( 'sha256', $normalized );
	}

	/**
	 * Build the canonical dedup text for a row: the JSON serialization with
	 * keys kept in insertion order (exporters build rows consistently).
	 *
	 * @param array<string,mixed> $row Row payload.
	 * @return string
	 */
	private function canonical_text( array $row ) {
		$encoded = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '';
	}

	/**
	 * Embed a row via the filter seam, falling back to the vector context
	 * service when available.
	 *
	 * @param array<string,mixed> $row Row payload.
	 * @return array<float>|WP_Error Vector or error.
	 */
	private function embed_row( array $row ) {
		$text = $this->canonical_text( $row );
		if ( '' === $text ) {
			return new WP_Error( 'wp_mcp_ai_embed_empty_text', __( 'Row has no embeddable text.', 'mcp-ai-wpoos-pro' ) );
		}

		/**
		 * Filter the embedding callback used by semantic dedup.
		 *
		 * Return a callable that maps a string to a float vector, or leave
		 * null to use the default vector-context-service embedding.
		 *
		 * @param callable|null $embed Callable or null.
		 */
		$embed = apply_filters( 'wp_mcp_ai_model_foundry_embed_text', null );
		if ( is_callable( $embed ) ) {
			$result = call_user_func( $embed, $text );
			return is_array( $result ) ? $result : new WP_Error( 'wp_mcp_ai_embed_failed', __( 'Embedding callback failed.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( function_exists( 'wp_mcp_ai_get_vector_context_service' ) ) {
			$service = wp_mcp_ai_get_vector_context_service();
			if ( $service && method_exists( $service, 'embed_context' ) ) {
				return $service->embed_context( $text, false );
			}
		}

		return new WP_Error( 'wp_mcp_ai_no_embedding_provider', __( 'No embedding provider is configured.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Cosine similarity between two same-length vectors.
	 *
	 * @param array<float> $a Vector A.
	 * @param array<float> $b Vector B.
	 * @return float Similarity in [-1, 1]; 0.0 on length mismatch or zero norm.
	 */
	private function cosine( array $a, array $b ) {
		$n = count( $a );
		if ( 0 === $n || count( $b ) !== $n ) {
			return 0.0;
		}

		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$av   = (float) $a[ $i ];
			$bv   = (float) $b[ $i ];
			$dot += $av * $bv;
			$na  += $av * $av;
			$nb  += $bv * $bv;
		}

		$norm = sqrt( $na ) * sqrt( $nb );
		if ( $norm <= 0.0 ) {
			return 0.0;
		}
		return $dot / $norm;
	}
}
