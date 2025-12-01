<?php
/**
 * Text chunking utility for splitting large documents.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides intelligent text chunking for large documents.
 *
 * This class splits long text into manageable chunks while preserving
 * semantic boundaries (paragraphs, sentences) where possible.
 */
class WP_MCP_AI_Text_Chunker {

	/**
	 * Default maximum chunk size in characters.
	 */
	const DEFAULT_CHUNK_SIZE = 1200;

	/**
	 * Default overlap between chunks in characters.
	 */
	const DEFAULT_CHUNK_OVERLAP = 200;

	/**
	 * Chunk text into manageable pieces.
	 *
	 * @param string $text          Text to chunk.
	 * @param int    $chunk_size    Maximum chunk size in characters.
	 * @param int    $overlap       Overlap between chunks in characters.
	 * @return array Array of text chunks.
	 */
	public static function chunk_text( $text, $chunk_size = null, $overlap = null ) {
		if ( null === $chunk_size ) {
			$chunk_size = self::DEFAULT_CHUNK_SIZE;
		}

		if ( null === $overlap ) {
			$overlap = self::DEFAULT_CHUNK_OVERLAP;
		}

		$chunk_size = max( 100, absint( $chunk_size ) );
		$overlap    = max( 0, absint( $overlap ) );
		$overlap    = min( $overlap, (int) ( $chunk_size * 0.5 ) ); // Overlap can't be more than 50% of chunk size.

		$text = (string) $text;

		if ( '' === $text ) {
			return array();
		}

		// If text is short enough, return as single chunk.
		if ( strlen( $text ) <= $chunk_size ) {
			return array( $text );
		}

		$chunks = array();

		// Try to split on paragraphs first.
		$paragraphs = preg_split( '/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $paragraphs ) {
			$paragraphs = array( $text );
		}

		$current_chunk = '';

		foreach ( $paragraphs as $paragraph ) {
			$paragraph = trim( $paragraph );

			if ( '' === $paragraph ) {
				continue;
			}

			// If adding this paragraph would exceed chunk size.
			if ( strlen( $current_chunk ) + strlen( $paragraph ) + 2 > $chunk_size ) {
				// Save current chunk if not empty.
				if ( '' !== $current_chunk ) {
					$chunks[]      = $current_chunk;
					$current_chunk = '';

					// Add overlap from previous chunk.
					if ( $overlap > 0 && ! empty( $chunks ) ) {
						$prev_chunk    = $chunks[ count( $chunks ) - 1 ];
						$overlap_text  = substr( $prev_chunk, -$overlap );
						$current_chunk = $overlap_text . "\n\n";
					}
				}

				// If paragraph itself is too large, split it further.
				if ( strlen( $paragraph ) > $chunk_size ) {
					$sub_chunks = self::chunk_paragraph( $paragraph, $chunk_size, $overlap );
					foreach ( $sub_chunks as $sub_chunk ) {
						if ( '' !== $current_chunk ) {
							$chunks[] = $current_chunk;
						}
						$current_chunk = $sub_chunk;
					}
				} else {
					$current_chunk .= $paragraph;
				}
			} else {
				// Add paragraph to current chunk.
				if ( '' !== $current_chunk ) {
					$current_chunk .= "\n\n";
				}
				$current_chunk .= $paragraph;
			}
		}

		// Save final chunk.
		if ( '' !== $current_chunk ) {
			$chunks[] = $current_chunk;
		}

		return array_values( array_filter( $chunks, 'strlen' ) );
	}

	/**
	 * Chunk a large paragraph by splitting on sentences.
	 *
	 * @param string $paragraph  Paragraph to chunk.
	 * @param int    $chunk_size Maximum chunk size.
	 * @param int    $overlap    Overlap between chunks.
	 * @return array Array of text chunks.
	 */
	protected static function chunk_paragraph( $paragraph, $chunk_size, $overlap ) {
		// Split on sentence boundaries.
		$sentences = preg_split( '/([.!?]+\s+)/', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		if ( false === $sentences ) {
			// Fallback to character-based chunking.
			return self::chunk_by_characters( $paragraph, $chunk_size, $overlap );
		}

		$chunks        = array();
		$current_chunk = '';

		for ( $i = 0; $i < count( $sentences ); $i++ ) {
			$sentence = $sentences[ $i ];

			if ( strlen( $current_chunk ) + strlen( $sentence ) > $chunk_size ) {
				if ( '' !== $current_chunk ) {
					$chunks[]      = $current_chunk;
					$current_chunk = '';

					// Add overlap.
					if ( $overlap > 0 && ! empty( $chunks ) ) {
						$prev_chunk    = $chunks[ count( $chunks ) - 1 ];
						$overlap_text  = substr( $prev_chunk, -$overlap );
						$current_chunk = $overlap_text . ' ';
					}
				}

				// If sentence itself is too large, split it.
				if ( strlen( $sentence ) > $chunk_size ) {
					$char_chunks = self::chunk_by_characters( $sentence, $chunk_size, $overlap );
					foreach ( $char_chunks as $char_chunk ) {
						if ( '' !== $current_chunk ) {
							$chunks[] = $current_chunk;
						}
						$current_chunk = $char_chunk;
					}
					continue;
				}
			}

			$current_chunk .= $sentence;
		}

		if ( '' !== $current_chunk ) {
			$chunks[] = $current_chunk;
		}

		return array_values( array_filter( $chunks, 'strlen' ) );
	}

	/**
	 * Chunk text by fixed character count.
	 *
	 * @param string $text       Text to chunk.
	 * @param int    $chunk_size Maximum chunk size.
	 * @param int    $overlap    Overlap between chunks.
	 * @return array Array of text chunks.
	 */
	protected static function chunk_by_characters( $text, $chunk_size, $overlap ) {
		$chunks = array();
		$length = strlen( $text );
		$start  = 0;

		while ( $start < $length ) {
			$chunk    = substr( $text, $start, $chunk_size );
			$chunks[] = $chunk;
			$start   += $chunk_size - $overlap;
		}

		return array_values( array_filter( $chunks, 'strlen' ) );
	}

	/**
	 * Estimate token count for text using character-based heuristic.
	 *
	 * @param string $text Text to estimate.
	 * @return int Estimated token count.
	 */
	public static function estimate_tokens( $text ) {
		$char_count = strlen( (string) $text );
		// Use the same heuristic as REST API: ~4 characters per token.
		return (int) ceil( $char_count / 4 );
	}

	/**
	 * Trim text to fit within a token budget.
	 *
	 * @param string $text         Text to trim.
	 * @param int    $max_tokens   Maximum token budget.
	 * @return string Trimmed text.
	 */
	public static function trim_to_token_budget( $text, $max_tokens ) {
		$max_tokens = max( 10, absint( $max_tokens ) );

		$estimated_tokens = self::estimate_tokens( $text );

		if ( $estimated_tokens <= $max_tokens ) {
			return $text;
		}

		// Calculate target character count.
		$target_chars = $max_tokens * 4;

		// Try to preserve whole words.
		$trimmed = substr( $text, 0, $target_chars );

		// Find last space to avoid cutting words.
		$last_space = strrpos( $trimmed, ' ' );

		if ( false !== $last_space && $last_space > $target_chars * 0.9 ) {
			$trimmed = substr( $trimmed, 0, $last_space );
		}

		return $trimmed . '...';
	}
}
