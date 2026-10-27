<?php
/**
 * Chunking strategies for RAG indexing — Proposal 091, Track C.
 *
 * Pure, provider-agnostic text chunkers used by the embedding pipeline:
 * fixed-size windows with overlap and a recursive (heading-aware) strategy
 * that keeps section boundaries intact.
 *
 * @package NvoosContentGraphAi
 * @since   1.1.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Embeddings;

/**
 * Chunking strategy helpers.
 *
 * @since 1.1.0
 */
final class ChunkingStrategy {

	public const DEFAULT_SIZE_TOKENS = 800;
	public const DEFAULT_OVERLAP    = 0.15;
	public const CHARS_PER_TOKEN    = 4;

	/**
	 * Default chunking settings.
	 *
	 * @return array{strategy: string, size_tokens: int, overlap: float}
	 */
	public static function defaults(): array {
		return array(
			'strategy'    => 'recursive',
			'size_tokens' => self::DEFAULT_SIZE_TOKENS,
			'overlap'     => self::DEFAULT_OVERLAP,
		);
	}

	/**
	 * Chunk a document according to the requested strategy.
	 *
	 * @param string               $text     Document text.
	 * @param array<string, mixed> $settings Optional overrides: strategy
	 *                                       ('fixed'|'recursive'), size_tokens,
	 *                                       overlap.
	 * @return string[] Chunks (never empty for non-empty input).
	 */
	public static function chunk( string $text, array $settings = array() ): array {
		$defaults    = self::defaults();
		$strategy    = isset( $settings['strategy'] ) && 'fixed' === $settings['strategy'] ? 'fixed' : $defaults['strategy'];
		$sizeTokens  = isset( $settings['size_tokens'] ) ? \max( 64, (int) $settings['size_tokens'] ) : $defaults['size_tokens'];
		$overlap     = isset( $settings['overlap'] ) && \is_numeric( $settings['overlap'] ) ? \max( 0.0, \min( 0.5, (float) $settings['overlap'] ) ) : $defaults['overlap'];

		$sizeChars    = $sizeTokens * self::CHARS_PER_TOKEN;
		$overlapChars = (int) \round( $sizeChars * $overlap );

		return 'fixed' === $strategy
			? self::chunkFixed( $text, $sizeChars, $overlapChars )
			: self::chunkRecursive( $text, $sizeChars, $overlapChars );
	}

	/**
	 * Fixed-size chunking with character overlap.
	 *
	 * @param string $text         Document text.
	 * @param int    $sizeChars    Chunk size in characters.
	 * @param int    $overlapChars Overlap in characters.
	 * @return string[]
	 */
	public static function chunkFixed( string $text, int $sizeChars, int $overlapChars ): array {
		$length = \function_exists( 'mb_strlen' ) ? \mb_strlen( $text ) : \strlen( $text );
		if ( $length <= $sizeChars ) {
			return '' === $text ? array() : array( $text );
		}

		$chunks = array();
		$step   = \max( 1, $sizeChars - $overlapChars );
		for ( $offset = 0; $offset < $length; $offset += $step ) {
			$chunk = \function_exists( 'mb_substr' ) ? \mb_substr( $text, $offset, $sizeChars ) : \substr( $text, $offset, $sizeChars );
			if ( '' !== $chunk ) {
				$chunks[] = $chunk;
			}
		}

		return $chunks;
	}

	/**
	 * Recursive (heading-aware) chunking: split on markdown headings first,
	 * then fixed-chunk any over-long section; merge tiny trailing chunks.
	 *
	 * @param string $text         Document text.
	 * @param int    $sizeChars    Chunk size in characters.
	 * @param int    $overlapChars Overlap in characters.
	 * @return string[]
	 */
	public static function chunkRecursive( string $text, int $sizeChars, int $overlapChars ): array {
		$length = \function_exists( 'mb_strlen' ) ? \mb_strlen( $text ) : \strlen( $text );
		if ( $length <= $sizeChars ) {
			return '' === $text ? array() : array( $text );
		}

		// Split on markdown headings (## … etc.), keeping the heading with
		// its section.
		$sections = \preg_split( '/(?=^#{1,3} )/m', $text );
		if ( false === $sections || 1 === \count( $sections ) ) {
			return self::chunkFixed( $text, $sizeChars, $overlapChars );
		}

		$chunks = array();
		foreach ( $sections as $section ) {
			$section = \trim( $section );
			if ( '' === $section ) {
				continue;
			}
			foreach ( self::chunkFixed( $section, $sizeChars, $overlapChars ) as $chunk ) {
				$chunks[] = $chunk;
			}
		}

		// Merge tiny trailing chunks into their predecessor (context loss guard).
		$minChars = (int) \round( $sizeChars * 0.25 );
		$merged   = array();
		foreach ( $chunks as $chunk ) {
			$chunkLen = \function_exists( 'mb_strlen' ) ? \mb_strlen( $chunk ) : \strlen( $chunk );
			if ( $chunkLen < $minChars && ! empty( $merged ) ) {
				$merged[ \count( $merged ) - 1 ] .= "\n\n" . $chunk;
				continue;
			}
			$merged[] = $chunk;
		}

		return $merged;
	}
}
