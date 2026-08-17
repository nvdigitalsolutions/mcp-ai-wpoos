<?php
/**
 * SessionEvent — one append-only entry in a session log.
 *
 * Every entry carries a monotonic seq, a timestamp, a type-discriminated
 * payload, and (for projection-relevant facts) a source seq list. The log
 * is the single source of truth: model history is derived from it, never
 * stored separately (deepseek-harness session model, adapted for PHP).
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Session;

final class SessionEvent {

	/**
	 * Format version of the exported entry shape. Bump only on structural
	 * changes to toArray()/fromArray().
	 */
	public const FORMAT_VERSION = 1;

	public function __construct(
		public readonly int $seq,
		public readonly float $time,
		public readonly string $type,
		public readonly array $data,
		public readonly array $sourceEventSeqs = array(),
	) {}

	public function toArray(): array {
		return array(
			'v'                => self::FORMAT_VERSION,
			'seq'              => $this->seq,
			'time'             => $this->time,
			'type'             => $this->type,
			'data'             => $this->data,
			'source_event_seqs' => $this->sourceEventSeqs,
		);
	}

	public static function fromArray( array $raw ): self {
		return new self(
			seq: (int) ( $raw['seq'] ?? 0 ),
			time: (float) ( $raw['time'] ?? 0.0 ),
			type: (string) ( $raw['type'] ?? '' ),
			data: is_array( $raw['data'] ?? null ) ? $raw['data'] : array(),
			sourceEventSeqs: is_array( $raw['source_event_seqs'] ?? null ) ? $raw['source_event_seqs'] : array(),
		);
	}
}
