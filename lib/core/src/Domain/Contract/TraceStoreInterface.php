<?php

/**
 * Trace store — where a harness run's artifacts are written.
 *
 * WordPress keeps traces under `wp-content/uploads/mcp-ai-harness-traces/` and
 * has no post meta for them; Laravel has a database. The contract is the
 * minimum that makes a trace reproducible on both: append a record to a named
 * artifact of a run, and prune old runs.
 *
 * The retention window is *not* trusted from the caller. Every implementation
 * routes the requested count through `TracePolicy::clampRuns()`, which is what
 * makes `trace_capture.retention_runs` (10–200) an enforced bound rather than a
 * suggestion. Retrieval is deliberately out of scope: a trace is read by the
 * operator tooling of the runtime that wrote it, not by the harness.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface TraceStoreInterface
{
    /**
     * Append one record to a named artifact of a run.
     *
     * @param int    $assistantId Assistant the run belongs to. Pruning is
     *                            per-assistant, so a store that forgets it
     *                            cannot enforce a retention window.
     * @param string $runId       Run identifier.
     * @param string $artifact    Artifact name, e.g. `refine.jsonl`.
     * @param array  $record      One record — a row, not a whole artifact.
     * @return bool True when the record was persisted.
     */
    public function append(int $assistantId, string $runId, string $artifact, array $record): bool;

    /**
     * Drop all but the newest runs for an assistant.
     *
     * @param int $assistantId Assistant to prune.
     * @param int $keepRuns    Requested window; clamped to
     *                         `TracePolicy::MIN_RUNS..MAX_RUNS`.
     * @return int Number of runs removed, not records.
     */
    public function prune(int $assistantId, int $keepRuns): int;
}
