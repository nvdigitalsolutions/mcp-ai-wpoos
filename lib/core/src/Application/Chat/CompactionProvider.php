<?php
/**
 * CompactionProvider — unified context-compaction seam (Phase 5, R6).
 *
 * Combines a trigger policy (budget-driven, iteration-gated) with a
 * strategy cascade: semantic compression first, context compression as
 * fallback, passthrough last. The orchestrator asks "should we compact?"
 * before every continuation step and records compaction as a durable
 * session-log entry.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Chat;

use Nvoos\Core\Domain\Contract\ContextCompressionInterface;
use Nvoos\Core\Domain\Contract\SemanticCompressorInterface;

class CompactionProvider {

	/**
	 * Compaction only earns its cost after the first tool round-trip.
	 */
	private const MIN_ITERATION = 2;

	/**
	 * Default trigger threshold as a fraction of the context window.
	 */
	private const DEFAULT_THRESHOLD = 0.85;

	public function __construct(
		private readonly ?ContextCompressionInterface $contextCompressor = null,
		private readonly ?SemanticCompressorInterface $semantic = null,
	) {}

	/**
	 * Whether the message list should be compacted before the next step.
	 *
	 * @param array<int, array<string, mixed>>  $messages     Current message list.
	 * @param int    $contextLimit Model context window (0 = unknown → no trigger).
	 * @param int    $iteration    Current loop iteration (0-based).
	 * @param float  $threshold    Fraction of the window that triggers compaction.
	 */
	public function shouldCompact( array $messages, int $contextLimit, int $iteration, float $threshold = self::DEFAULT_THRESHOLD ): bool {
		if ( $iteration < self::MIN_ITERATION ) {
			return false;
		}

		if ( $contextLimit <= 0 ) {
			return false;
		}

		return $this->estimateTokens( $messages ) > (int) ( $contextLimit * $threshold );
	}

	/**
	 * Compact a message list.
	 *
	 * Strategy cascade: semantic compression (facts-preserving) → context
	 * compression → passthrough. A failed or non-decodable compression
	 * never destroys history — the input is returned unchanged.
	 *
	 * @param array<int, array<string, mixed>> $messages
	 * @return array<int, array<string, mixed>> The (possibly compacted) message
	 *         list; the input unchanged when compaction is unavailable or the
	 *         result is not a valid message list.
	 */
	public function compact( array $messages, string $model = '' ): array {
		$encoded = \json_encode( $messages, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE );
		if ( false === $encoded ) {
			return $messages;
		}

		if ( null !== $this->semantic ) {
			$decoded = $this->decodeResult( $this->semantic->compress( $encoded, 2, 0 ) );
			if ( null !== $decoded ) {
				return $decoded;
			}
		}

		if ( null !== $this->contextCompressor ) {
			$decoded = $this->decodeResult( $this->contextCompressor->compress( $encoded ) );
			if ( null !== $decoded ) {
				return $decoded;
			}
		}

		return $messages;
	}

	/**
	 * Estimate the token footprint of a message list.
	 * @param array<int, array<string, mixed>> $messages
	 */
	public function estimateTokens( array $messages ): int {
		$total = 0;

		foreach ( $messages as $msg ) {
			$content = \is_array( $msg['content'] ?? null )
				? ( \json_encode( $msg['content'] ) ?: '' )
				: (string) ( $msg['content'] ?? '' );

			if ( null !== $this->semantic ) {
				$total += $this->semantic->estimateTokens( $content );
				continue;
			}

			if ( null !== $this->contextCompressor ) {
				$total += $this->contextCompressor->estimateTokens( $content );
				continue;
			}

			$total += '' === $content ? 0 : (int) \ceil( \strlen( $content ) / 4 );
		}

		return $total;
	}

	/**
	 * Decode a compressor result shape into a message list, or null when
	 * the result is unusable.
	 *
	 * A decoded payload that is not a list of string-keyed message maps is
	 * rejected so callers can always treat the return value as history (a
	 * malformed compression must never replace the conversation).
	 *
	 * @param array<string, mixed> $result
	 * @return array<int, array<string, mixed>>|null
	 */
	private function decodeResult( array $result ): ?array {
		if ( empty( $result['compressed'] ) || ! \is_string( $result['compressed'] ) ) {
			return null;
		}

		$decoded = \json_decode( $result['compressed'], true );

		if ( ! \is_array( $decoded ) || ! \array_is_list( $decoded ) ) {
			return null;
		}

		$messages = array();
		foreach ( $decoded as $message ) {
			if ( ! \is_array( $message ) ) {
				return null;
			}

			$normalized = array();
			foreach ( $message as $key => $value ) {
				if ( ! \is_string( $key ) ) {
					return null;
				}

				$normalized[ $key ] = $value;
			}

			$messages[] = $normalized;
		}

		return $messages;
	}
}
