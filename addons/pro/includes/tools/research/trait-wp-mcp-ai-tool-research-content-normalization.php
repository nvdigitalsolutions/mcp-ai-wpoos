<?php
/**
 * Trait for normalizing AI provider content into plain strings.
 *
 * Some providers (e.g. Gemini) and OpenAI-compatible gateways return chat
 * message content as an array of parts/blocks instead of a plain string.
 * The research tools feed that content to string functions
 * (preg_match, json_decode), which fatal on array input. This trait
 * flattens such arrays into a single string.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Research_Content_Normalization
 *
 * Shared AI-content normalization helpers for the research tools.
 *
 * Usage:
 * ```php
 * class WP_MCP_AI_Tool_Research_Post implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Research_Content_Normalization;
 *
 *     protected function parse_research_results( $research_result, $topic ) {
 *         $content = $research_result['content'];
 *         if ( is_array( $content ) ) {
 *             $content = $this->normalize_content_parts( $content );
 *         }
 *         // ...
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Research_Content_Normalization {

	/**
	 * Flatten a content parts array into a plain string.
	 *
	 * Each part may be a string or an array carrying a 'text' or 'content'
	 * key. Other part shapes (e.g. tool results, image blocks) are skipped.
	 *
	 * @param array $content Content parts array.
	 * @return string Flattened text content.
	 */
	protected function normalize_content_parts( $content ) {
		$parts = array();

		foreach ( $content as $segment ) {
			if ( is_string( $segment ) || is_numeric( $segment ) ) {
				$parts[] = (string) $segment;
				continue;
			}
			if ( ! is_array( $segment ) ) {
				continue;
			}
			if ( isset( $segment['text'] ) && ( is_string( $segment['text'] ) || is_numeric( $segment['text'] ) ) ) {
				$parts[] = (string) $segment['text'];
			} elseif ( isset( $segment['content'] ) && ( is_string( $segment['content'] ) || is_numeric( $segment['content'] ) ) ) {
				$parts[] = (string) $segment['content'];
			}
		}

		return implode( "\n\n", $parts );
	}
}
