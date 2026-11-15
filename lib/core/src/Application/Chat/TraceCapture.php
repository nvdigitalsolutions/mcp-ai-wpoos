<?php

/**
 * Trace capture — one run, recorded.
 *
 * A run is a chat turn: it starts when the provider is first called and ends
 * when the answer is delivered. Capture appends records to the run's artifact
 * through the platform's `TraceStoreInterface`, so nothing here knows whether
 * the bytes land in a directory or a table.
 *
 * Capture is a *profile* feature, not a runtime one: an assistant opts in with
 * `trace_capture.enabled`, and the retention window is the one the profile
 * declares, clamped by `TracePolicy` before it is trusted. A runtime that wires
 * a store but turns capture off writes nothing.
 *
 * Two deliberate limits:
 *
 *  - Records carry identifiers, counts and timings, never message text. A trace
 *    that leaks the conversation into storage is a liability, and the whole
 *    point of the PII filter elsewhere is to keep that text out of long-lived
 *    stores.
 *  - `finish()` prunes. The store enforces the window, so a crashed turn cannot
 *    leave an unbounded run behind — the next completed turn sweeps it.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Chat;

use Nvoos\Core\Domain\Contract\TraceStoreInterface;
use Nvoos\Core\Domain\Service\Harness\TracePolicy;

final class TraceCapture
{
	/** Artifact all run records are appended to. */
	public const ARTIFACT = 'run.jsonl';

	public const TYPE_START = 'start';
	public const TYPE_ROUTING = 'routing';
	public const TYPE_ITERATION = 'iteration';
	public const TYPE_END = 'end';

	/** Records this run has written, so a runaway loop is visible in the trace. */
	private int $records = 0;

	/**
	 * Provider calls this run has made.
	 *
	 * Owned here rather than passed in: the orchestrator's iteration counter
	 * only advances on a continuation, and a turn that answers immediately
	 * never advances it at all. A trace that reported zero iterations for a
	 * one-call turn would be lying about the thing it exists to measure.
	 */
	private int $providerCalls = 0;

	private function __construct(
		private readonly TraceStoreInterface $store,
		private readonly int $assistantId,
		private readonly string $runId,
		private readonly int $retentionRuns,
	) {
	}

	/**
	 * Begin a run, or null when capture is off for this assistant.
	 *
	 * @param TraceStoreInterface  $store        Where records go.
	 * @param int                  $assistantId  Assistant the turn belongs to.
	 * @param array<string, mixed> $profile      Resolved harness profile.
	 * @param array<string, mixed> $meta         Startup facts: model, provider,
	 *                                           task_class, surface. Never text.
	 */
	public static function start( TraceStoreInterface $store, int $assistantId, array $profile, array $meta = array() ): ?self
	{
		if ( $assistantId <= 0 || ! TracePolicy::isEnabled( $profile ) ) {
			return null;
		}

		$capture = new self(
			$store,
			$assistantId,
			self::newRunId(),
			TracePolicy::retentionRuns( $profile ),
		);

		$capture->record(
			self::TYPE_START,
			array_merge(
				$meta,
				array(
					'assistant_id' => $assistantId,
					'started_at' => \time(),
				)
			)
		);

		return $capture;
	}

	public function runId(): string
	{
		return $this->runId;
	}

	public function assistantId(): int
	{
		return $this->assistantId;
	}

	/** How many records this run has written. */
	public function records(): int
	{
		return $this->records;
	}

	/**
	 * Append one record.
	 *
	 * @param string               $type   Record type.
	 * @param array<string, mixed> $record Record body.
	 * @return bool True when the store persisted it.
	 */
	public function record( string $type, array $record ): bool
	{
		$stored = $this->store->append(
			$this->assistantId,
			$this->runId,
			self::ARTIFACT,
			array_merge(
				$record,
				array(
					'type' => $type,
					'run_id' => $this->runId,
					'at' => \microtime( true ),
				)
			)
		);

		if ( $stored ) {
			++$this->records;
		}

		return $stored;
	}

	/**
	 * Record what the model was offered, and in what order.
	 *
	 * "Which tools did it see?" is the first question asked when a turn goes
	 * wrong, and with a scored router it is no longer the configured order. Tool
	 * slugs are identifiers, not message text, so they are safe to record.
	 *
	 * @param string             $router `fixed` or `scored`.
	 * @param array<int, string> $slugs  Slugs in the order they were offered.
	 */
	public function routing( string $router, array $slugs ): void
	{
		$this->record(
			self::TYPE_ROUTING,
			array(
				'router' => $router,
				'tool_count' => \count( $slugs ),
				'tools' => \array_values( $slugs ),
			)
		);
	}

	/**
	 * Record one provider call.
	 *
	 * @param string $finishReason Provider finish reason, when known.
	 * @param int    $toolCalls    Tool calls the response asked for.
	 */
	public function providerCall( string $finishReason = '', int $toolCalls = 0 ): void
	{
		++$this->providerCalls;

		$this->record(
			self::TYPE_ITERATION,
			array(
				'iteration' => $this->providerCalls,
				'finish_reason' => $finishReason,
				'tool_calls' => $toolCalls,
			)
		);
	}

	/** How many provider calls this run has recorded. */
	public function providerCalls(): int
	{
		return $this->providerCalls;
	}

	/**
	 * Close the run and prune to the retention window.
	 *
	 * @param array<string, mixed> $summary Turn outcome: iterations, cost,
	 *                                      cancelled, cancel_reason, duration_ms.
	 * @return int Runs removed by the prune.
	 */
	public function finish( array $summary = array() ): int
	{
		$this->record(
			self::TYPE_END,
			array_merge(
				array( 'provider_calls' => $this->providerCalls ),
				$summary,
				array(
					'records' => $this->records + 1,
					'finished_at' => \time(),
				)
			)
		);

		return $this->store->prune( $this->assistantId, $this->retentionRuns );
	}

	/**
	 * A sortable, collision-resistant run id.
	 *
	 * The timestamp prefix makes a directory listing or an `ORDER BY run_id`
	 * chronological without a join, which is what a retention sweep wants.
	 */
	private static function newRunId(): string
	{
		return \gmdate( 'Ymd\THis' ) . '-' . \bin2hex( \random_bytes( 4 ) );
	}
}
