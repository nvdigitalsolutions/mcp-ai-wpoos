<?php
/**
 * Corpus PII scrubber — Model Foundry (Pro, Phase 1).
 *
 * Two-tier PII handling per the researched privacy practice (R13):
 *
 * 1. Deterministic tier (always on) — every string leaf in a row passes
 *    through the Base `WP_MCP_AI_Pii_Filter::scrub()` (emails, phones,
 *    card-shaped digits, SSNs, API-key prefixes, Bearer tokens).
 * 2. Provider-backed tier (seam, opt-in) — the
 *    `wp_mcp_ai_model_foundry_extra_scrub` filter may supply a callable that
 *    further redacts free-text PII (GLiNER/Presidio-style ML detection).
 *    No provider call happens without the filter.
 *
 * Redaction counts are aggregated (never per-row content) and surfaced in
 * the provenance manifest so trainers can see how much scrubbing occurred.
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
 * Corpus PII scrubber.
 */
class WP_MCP_AI_Corpus_Pii {

	/**
	 * Scrub a batch of rows and aggregate redaction stats.
	 *
	 * @param array<int,array<string,mixed>> $rows Row payloads.
	 * @return array{rows:array<int,array<string,mixed>>,redactions:int,rows_with_redactions:int,extra_scrub_active:bool}
	 */
	public function scrub_rows( array $rows ) {
		$extra_scrub = $this->resolve_extra_scrub();

		$total_redactions = 0;
		$rows_with        = 0;
		$out              = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				$out[] = $row;
				continue;
			}
			$before = $total_redactions;
			$out[]  = $this->scrub_row( $row, $total_redactions, $extra_scrub );
			if ( $total_redactions > $before ) {
				++$rows_with;
			}
		}

		return array(
			'rows'                 => $out,
			'redactions'           => $total_redactions,
			'rows_with_redactions' => $rows_with,
			'extra_scrub_active'   => is_callable( $extra_scrub ),
		);
	}

	/**
	 * Resolve the optional provider-backed extra scrub callable.
	 *
	 * @return callable|null
	 */
	private function resolve_extra_scrub() {
		/**
		 * Filter: supply a callable (string → string) that redacts
		 * free-text PII beyond the deterministic patterns. Return null to
		 * keep the deterministic tier only (the default).
		 *
		 * @param callable|null $scrub Callable or null.
		 */
		$scrub = apply_filters( 'wp_mcp_ai_model_foundry_extra_scrub', null );
		return is_callable( $scrub ) ? $scrub : null;
	}

	/**
	 * Recursively scrub string leaves in a row, accumulating the redaction
	 * count by reference.
	 *
	 * @param mixed         $value      Value to scrub.
	 * @param int           $redactions Redaction counter (by reference).
	 * @param callable|null $extra      Optional extra scrub callable.
	 * @return mixed Scrubbed value.
	 */
	private function scrub_row( $value, &$redactions, $extra = null ) {
		if ( is_string( $value ) ) {
			$result = WP_MCP_AI_Pii_Filter::scrub( $value );
			$redactions += (int) $result['redactions'];
			$text = $result['text'];
			if ( null !== $extra ) {
				$extra_result = call_user_func( $extra, $text );
				if ( is_string( $extra_result ) ) {
					$text = $extra_result;
				}
			}
			return $text;
		}

		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $entry ) {
				$out[ $key ] = $this->scrub_row( $entry, $redactions, $extra );
			}
			return $out;
		}

		return $value;
	}
}
