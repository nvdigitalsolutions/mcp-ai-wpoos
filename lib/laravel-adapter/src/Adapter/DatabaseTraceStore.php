<?php

/**
 * Trace store — database-backed storage for harness trace records.
 *
 * WordPress writes each run to a directory under `wp-content/uploads/` and
 * keeps the newest 50. Laravel has no uploads tree in the trace path, so this
 * adapter keeps one row per record and prunes by run, which gives the same
 * observable contract: the newest N runs survive, the rest are gone.
 *
 * The window is clamped by `TracePolicy` before it is used, so a caller cannot
 * ask for "keep everything". An un-migrated database degrades to the
 * `NullTraceStore` behaviour — `append()` reports `false`, `prune()` reports
 * `0` — rather than throwing inside a chat turn.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\TraceStoreInterface;
use Nvoos\Core\Domain\Service\Harness\TracePolicy;

final class DatabaseTraceStore implements TraceStoreInterface
{
	public const TABLE = 'nvoos_harness_trace_records';

	/** Matches the `artifact` column width. */
	public const MAX_ARTIFACT_LENGTH = 64;

	/** Matches the `run_id` column width. */
	public const MAX_RUN_ID_LENGTH = 64;

	/**
	 * How many stale runs one prune call will delete. A runaway backlog is
	 * swept over successive calls rather than in one unbounded statement.
	 */
	public const PRUNE_BATCH = 1000;

	public function append(int $assistantId, string $runId, string $artifact, array $record): bool
	{
		$runId = substr(trim($runId), 0, self::MAX_RUN_ID_LENGTH);
		$artifact = substr(trim($artifact), 0, self::MAX_ARTIFACT_LENGTH);

		if ($assistantId <= 0 || '' === $runId || '' === $artifact) {
			return false;
		}

		if (! Schema::hasTable(self::TABLE)) {
			return false;
		}

		// Encode here rather than letting the query builder do it, so a record
		// that cannot be encoded is reported instead of stored as `null`.
		$encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

		if (false === $encoded) {
			return false;
		}

		return (bool) DB::table(self::TABLE)->insert(
			array(
				'assistant_id' => $assistantId,
				'run_id' => $runId,
				'artifact' => $artifact,
				'payload' => $encoded,
				'created_at' => now(),
			)
		);
	}

	public function prune(int $assistantId, int $keepRuns): int
	{
		if ($assistantId <= 0) {
			return 0;
		}

		if (! Schema::hasTable(self::TABLE)) {
			return 0;
		}

		$keep = TracePolicy::clampRuns($keepRuns);

		// Order runs by their most recent write, keep the newest `$keep`, and
		// take the remainder. `MAX(created_at)` is portable across the engines
		// the app supports (SQLite in tests, MySQL/Postgres in production).
		$stale = DB::table(self::TABLE)
			->where('assistant_id', $assistantId)
			->select('run_id')
			->groupBy('run_id')
			->orderByRaw('MAX(created_at) DESC')
			->offset($keep)
			->limit(self::PRUNE_BATCH)
			->pluck('run_id')
			->all();

		if (array() === $stale) {
			return 0;
		}

		DB::table(self::TABLE)
			->where('assistant_id', $assistantId)
			->whereIn('run_id', $stale)
			->delete();

		return count($stale);
	}
}
