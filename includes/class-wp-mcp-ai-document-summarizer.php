<?php
/**
 * Document summarization utility for managing large attachments.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides document summarization to reduce token usage for large files.
 */
class WP_MCP_AI_Document_Summarizer {

	/**
	 * Maximum document size before summarization is triggered (in characters).
	 */
	const MAX_DOCUMENT_CHARS = 4000;

	/**
	 * Target summary size (in characters).
	 */
	const SUMMARY_TARGET_CHARS = 1000;

	/**
	 * Summarize document content if it exceeds size limits.
	 *
	 * @param string $content      Document content.
	 * @param array  $options      Optional parameters (force_summarize, target_chars).
	 * @return string Summarized or original content.
	 */
	public static function summarize_if_needed( $content, array $options = array() ) {
		$content = (string) $content;

		if ( '' === $content ) {
			return '';
		}

		$force_summarize = isset( $options['force_summarize'] ) ? (bool) $options['force_summarize'] : false;
		$max_chars       = isset( $options['max_chars'] ) ? absint( $options['max_chars'] ) : self::MAX_DOCUMENT_CHARS;
		$target_chars    = isset( $options['target_chars'] ) ? absint( $options['target_chars'] ) : self::SUMMARY_TARGET_CHARS;

		// If content is short enough and summarization not forced, return as-is.
		if ( ! $force_summarize && strlen( $content ) <= $max_chars ) {
			return $content;
		}

		WP_MCP_AI_Logger::log_event(
			'document_summarization',
			'Summarizing large document to reduce token usage.',
			array(
				'original_length' => strlen( $content ),
				'target_length'   => $target_chars,
			)
		);

		return self::extract_summary( $content, $target_chars );
	}

	/**
	 * Extract a summary from document content.
	 *
	 * Uses simple extraction strategies:
	 * - Take beginning and end of document
	 * - Extract key paragraphs
	 * - Preserve structural markers
	 *
	 * @param string $content     Document content.
	 * @param int    $target_chars Target summary size.
	 * @return string Extracted summary.
	 */
	protected static function extract_summary( $content, $target_chars ) {
		$content = trim( $content );

		if ( strlen( $content ) <= $target_chars ) {
			return $content;
		}

		// Strategy: Take beginning, middle, and end sections.
		$section_size = (int) ( $target_chars / 3 );

		// Extract beginning.
		$beginning = self::extract_section( $content, 0, $section_size );

		// Extract from middle.
		$middle_start = (int) ( strlen( $content ) / 2 - $section_size / 2 );
		$middle       = self::extract_section( $content, $middle_start, $section_size );

		// Extract from end.
		$end_start = strlen( $content ) - $section_size;
		$end       = self::extract_section( $content, max( 0, $end_start ), $section_size );

		// Combine sections with ellipsis.
		$summary = $beginning . "\n\n[...]\n\n" . $middle . "\n\n[...]\n\n" . $end;

		// Add metadata note.
		$note = sprintf(
			'[Document summarized: %d of %d characters shown]',
			strlen( $summary ),
			strlen( $content )
		);

		return $note . "\n\n" . $summary;
	}

	/**
	 * Extract a section of text, trying to preserve paragraph boundaries.
	 *
	 * @param string $content     Full content.
	 * @param int    $start       Start position.
	 * @param int    $length      Desired length.
	 * @return string Extracted section.
	 */
	protected static function extract_section( $content, $start, $length ) {
		$section = substr( $content, $start, $length );

		if ( false === $section ) {
			return '';
		}

		// Try to find paragraph boundary at the end.
		$last_para = strrpos( $section, "\n\n" );

		if ( false !== $last_para && $last_para > $length * 0.7 ) {
			$section = substr( $section, 0, $last_para );
		}

		// Try to find sentence boundary if no paragraph found.
		if ( strlen( $section ) === $length ) {
			$last_sentence = max(
				strrpos( $section, '. ' ),
				strrpos( $section, '! ' ),
				strrpos( $section, '? ' )
			);

			if ( false !== $last_sentence && $last_sentence > $length * 0.7 ) {
				$section = substr( $section, 0, $last_sentence + 1 );
			}
		}

		return trim( $section );
	}

	/**
	 * Summarize multiple documents and return optimized chunks.
	 *
	 * @param array $documents Array of document arrays with 'content' and optional 'title'.
	 * @param int   $total_budget Total character budget for all documents.
	 * @return array Summarized documents.
	 */
	public static function summarize_document_set( array $documents, $total_budget ) {
		$total_budget = max( 1000, absint( $total_budget ) );

		if ( empty( $documents ) ) {
			return array();
		}

		$total_chars = 0;

		foreach ( $documents as $doc ) {
			if ( isset( $doc['content'] ) ) {
				$total_chars += strlen( (string) $doc['content'] );
			}
		}

		// If under budget, return as-is.
		if ( $total_chars <= $total_budget ) {
			return $documents;
		}

		WP_MCP_AI_Logger::log_event(
			'document_set_summarization',
			'Summarizing document set to fit token budget.',
			array(
				'document_count' => count( $documents ),
				'total_chars'    => $total_chars,
				'budget'         => $total_budget,
			)
		);

		// Distribute budget proportionally.
		$summarized = array();

		foreach ( $documents as $doc ) {
			if ( ! isset( $doc['content'] ) ) {
				$summarized[] = $doc;
				continue;
			}

			$content     = (string) $doc['content'];
			$content_len = strlen( $content );
			$proportion  = $content_len / $total_chars;
			$doc_budget  = max( 500, (int) ( $total_budget * $proportion ) );

			$doc['content'] = self::summarize_if_needed(
				$content,
				array(
					'force_summarize' => true,
					'target_chars'    => $doc_budget,
				)
			);

			$summarized[] = $doc;
		}

		return $summarized;
	}
}
