<?php

namespace App\Support\Metrics;

/**
 * The metric vocabulary — Proposal 155 §4, implemented as slice M1 (§99).
 *
 * The list is the **WordPress vocabulary, copied verbatim** (its four core packs:
 * chat, tool, stream, eval). It is code rather than rows on purpose: nobody
 * creates a metric definition at runtime, and a `nvoos_metrics` table would be a
 * second place to edit a fact that ships in a release — the same argument §92
 * used against a stored spend counter.
 *
 * Every definition carries the shape WordPress's registry stores — id, label,
 * unit, direction, counter-metric and privacy tier — because that vocabulary is
 * the thing worth porting. What it *does not* carry is a promise: `state` says
 * whether this runtime can produce the number, and `note` says why when it
 * cannot. A catalogue that hid its gaps would be the empty-list page §85.2
 * warned about.
 *
 * `privacy` is kept and is uniformly `internal`: the tier exists on WordPress to
 * gate redaction and export where a metric may carry prompt text. Nothing in
 * this runtime's metric path touches message content (§84), so a higher tier
 * would be a label with no behaviour.
 */
final class MetricCatalogue
{
    /** The runtime can produce this figure from a store it already writes. */
    public const STATE_DERIVED = 'derived';

    /** The runtime can produce *part* of it; the note says which part is missing. */
    public const STATE_PARTIAL = 'partial';

    /** Nothing produces it. The note says what would. */
    public const STATE_UNPRODUCED = 'unproduced';

    /** Derived from `nvoos_token_usage`. */
    public const SOURCE_TOKENS = 'token_usage';

    /** Derived from `nvoos_harness_trace_records`. */
    public const SOURCE_TRACES = 'traces';

    /** No store: this runtime does not write it. */
    public const SOURCE_NONE = 'none';

    /**
     * Every definition, in the order the WordPress registry declares its packs.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return array(
            // ── chat: seven, six of them complete ───────────────────────────
            self::definition(
                'chat.turn.count',
                'Chat turns',
                'turns',
                'neutral',
                'chat.turn.error.count',
                self::STATE_DERIVED,
                self::SOURCE_TOKENS,
                'One row per completed turn that reported tokens.'
            ),
            self::definition(
                'chat.turn.error.count',
                'Failed chat turns',
                'turns',
                'lower_is_better',
                'chat.turn.count',
                self::STATE_PARTIAL,
                self::SOURCE_TRACES,
                'Aborted and incomplete runs are counted. A provider error that still returned a message is not recorded as one, so this under-reports rather than over-reports.'
            ),
            self::definition(
                'chat.turn.duration_ms',
                'Chat turn duration',
                'ms',
                'lower_is_better',
                'chat.turn.error.count',
                self::STATE_DERIVED,
                self::SOURCE_TRACES,
                "Mean of the run's `end.duration_ms`."
            ),
            self::definition(
                'token_usage.prompt_tokens',
                'Prompt tokens',
                'tokens',
                'lower_is_better',
                'chat.turn.count',
                self::STATE_DERIVED,
                self::SOURCE_TOKENS,
                'Summed over the window.'
            ),
            self::definition(
                'token_usage.completion_tokens',
                'Completion tokens',
                'tokens',
                'neutral',
                'token_usage.total_cost_usd',
                self::STATE_DERIVED,
                self::SOURCE_TOKENS,
                'Summed over the window.'
            ),
            self::definition(
                'token_usage.total_cost_usd',
                'Realised cost',
                'usd',
                'lower_is_better',
                'chat.turn.error.count',
                self::STATE_DERIVED,
                self::SOURCE_TOKENS,
                'Summed over the window; the same column the cost pages read.'
            ),
            self::definition(
                'chat.agentic.iterations',
                'Agentic iterations',
                'iterations',
                'neutral',
                'chat.turn.error.count',
                self::STATE_DERIVED,
                self::SOURCE_TRACES,
                "Mean of the run's `end.iterations`."
            ),

            // ── tool: five, four now recorded (§98) ─────────────────────────
            self::definition(
                'tool.execution.count',
                'Tool executions',
                'calls',
                'neutral',
                'tool.execution.error.count',
                self::STATE_DERIVED,
                self::SOURCE_TRACES,
                'One `tool` trace record per execution.'
            ),
            self::definition(
                'tool.execution.success.count',
                'Successful tool executions',
                'calls',
                'higher_is_better',
                'tool.execution.error.count',
                self::STATE_DERIVED,
                self::SOURCE_TRACES,
                'Records whose outcome is `success`.'
            ),
            self::definition(
                'tool.execution.error.count',
                'Failed tool executions',
                'calls',
                'lower_is_better',
                'tool.execution.success.count',
                self::STATE_DERIVED,
                self::SOURCE_TRACES,
                'Records whose outcome is `error`.'
            ),
            self::definition(
                'tool.execution.duration_ms',
                'Tool execution duration',
                'ms',
                'lower_is_better',
                'tool.execution.error.count',
                self::STATE_DERIVED,
                self::SOURCE_TRACES,
                'Mean of the `tool` records’ `duration_ms`.'
            ),
            self::definition(
                'tool.execution.in_flight',
                'Tool executions in flight',
                'calls',
                'neutral',
                'tool.execution.error.count',
                self::STATE_UNPRODUCED,
                self::SOURCE_NONE,
                'A gauge, not a counter: it needs a start record. The `tool` record is written when the call is over (§98.5).'
            ),

            // ── stream: seven, none persisted ───────────────────────────────
            self::definition('stream.count', 'Streams opened', 'streams', 'neutral', 'stream.error.count', self::STATE_UNPRODUCED, self::SOURCE_NONE, 'The SSE handler observes this but persists nothing; persisting on the hottest path in the runtime is its own decision (M2).'),
            self::definition('stream.chunks.count', 'Stream chunks', 'chunks', 'neutral', 'stream.error.count', self::STATE_UNPRODUCED, self::SOURCE_NONE, 'Observed by the SSE handler, not persisted (M2).'),
            self::definition('stream.error.count', 'Stream errors', 'errors', 'lower_is_better', 'stream.count', self::STATE_UNPRODUCED, self::SOURCE_NONE, 'Observed by the SSE handler, not persisted (M2).'),
            self::definition('stream.cancelled.count', 'Cancelled streams', 'streams', 'lower_is_better', 'stream.count', self::STATE_UNPRODUCED, self::SOURCE_NONE, 'Observed by the SSE handler, not persisted (M2).'),
            self::definition('stream.chunk_interval_ms', 'Chunk interval', 'ms', 'lower_is_better', 'stream.error.count', self::STATE_UNPRODUCED, self::SOURCE_NONE, 'Observed by the SSE handler, not persisted (M2).'),
            self::definition('stream.ttfb_ms', 'Time to first byte', 'ms', 'lower_is_better', 'stream.error.count', self::STATE_UNPRODUCED, self::SOURCE_NONE, 'Observed by the SSE handler, not persisted (M2).'),
            self::definition('stream.total_duration_ms', 'Stream duration', 'ms', 'lower_is_better', 'stream.count', self::STATE_UNPRODUCED, self::SOURCE_NONE, 'Observed by the SSE handler, not persisted (M2).'),

            // ── eval: two, no harness anywhere ──────────────────────────────
            self::definition('eval.suite.pass_rate', 'Eval pass rate', 'ratio', 'higher_is_better', 'eval.suite.regression.count', self::STATE_UNPRODUCED, self::SOURCE_NONE, 'No eval harness on either runtime; a suite has to run an answer to produce this (Proposal 155 D5).'),
            self::definition('eval.suite.regression.count', 'Eval regressions', 'suites', 'lower_is_better', 'eval.suite.pass_rate', self::STATE_UNPRODUCED, self::SOURCE_NONE, 'No eval harness on either runtime (Proposal 155 D5).'),
        );
    }

    /**
     * How many definitions there are — the number the header prints.
     */
    public static function count(): int
    {
        return count(self::definitions());
    }

    /**
     * The closed state vocabulary.
     *
     * @return array<string, string>
     */
    public static function states(): array
    {
        return array(
            self::STATE_DERIVED => 'Derived',
            self::STATE_PARTIAL => 'Partial',
            self::STATE_UNPRODUCED => 'Not produced',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function definition(
        string $id,
        string $label,
        string $unit,
        string $direction,
        string $counterMetric,
        string $state,
        string $source,
        string $note,
    ): array {
        return array(
            'id' => $id,
            'label' => $label,
            'unit' => $unit,
            'direction' => $direction,
            'counter_metric' => $counterMetric,
            // Uniform, and deliberately so — see the class docblock.
            'privacy' => 'internal',
            'state' => $state,
            'source' => $source,
            'note' => $note,
        );
    }
}
