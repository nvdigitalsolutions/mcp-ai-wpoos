<?php

namespace App\Support\Metrics;

use App\Support\Reports\TokenUsageReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Laravel\Adapter\TokenUsage;

/**
 * The catalogue with a value attached where one exists — Proposal 155 §4, M1.
 *
 * Two sources, no third store:
 *
 *  - **token metrics** are one aggregate over `nvoos_token_usage`, the same table
 *    the cost pages read;
 *  - **trace metrics** are `TraceMetrics`, which decodes the run records §84 and
 *    §98 write.
 *
 * A definition whose source this runtime does not produce keeps `value => null`
 * and its own `note`. Null is not zero: the page renders the reason, because a
 * zero is a claim and §33 already ruled that an empty section is worse than an
 * omitted one.
 *
 * **Cross-estate by design.** This is an operator surface (`measurement` is
 * `audience: operator`), so both reads are unscoped; an estate's own spend lives
 * on `/admin/budgets`, which is a different question with a different audience.
 */
final class MetricsReport
{
    /**
     * The catalogue, with `value` filled for the definitions this runtime can
     * produce and `note` carried for every row.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     window: array{start: string, end: string, days: int},
     *     tokens: array{turns: int},
     *     traces: array<string, mixed>,
     *     counts: array{total: int, derived: int, partial: int, unproduced: int}
     * }
     */
    public function report(int $days = TokenUsageReport::DEFAULT_PERIOD): array
    {
        $days = app(TokenUsageReport::class)->normalisePeriod($days);
        $since = app(TokenUsageReport::class)->windowStart($days);

        $tokens = $this->tokenTotals($since);
        $traces = app(TraceMetrics::class)->aggregate($since);

        $values = array(
            'chat.turn.count' => (float) $tokens['turns'],
            'token_usage.prompt_tokens' => (float) $tokens['prompt_tokens'],
            'token_usage.completion_tokens' => (float) $tokens['completion_tokens'],
            'token_usage.total_cost_usd' => (float) $tokens['cost'],
        );

        // Trace-derived figures are only offered when the window contains trace
        // records. With capture off, `0` would be a claim that nothing ran —
        // when the truth is that nothing was recorded. Null renders the reason.
        if (($traces['records'] ?? 0) > 0) {
            $values['chat.turn.error.count'] = (float) $traces['aborted'];
            $values['chat.turn.duration_ms'] = $traces['duration_avg_ms'];
            $values['chat.agentic.iterations'] = $traces['iterations_avg'];
            $values['tool.execution.count'] = (float) $traces['tools'];
            $values['tool.execution.success.count'] = (float) $traces['tool_success'];
            $values['tool.execution.error.count'] = (float) $traces['tool_error'];
            $values['tool.execution.duration_ms'] = $traces['tool_duration_avg_ms'];
        }

        $rows = array();
        $counts = array('total' => 0, 'derived' => 0, 'partial' => 0, 'unproduced' => 0);

        foreach (MetricCatalogue::definitions() as $definition) {
            $id = (string) $definition['id'];
            $state = (string) $definition['state'];

            ++$counts['total'];
            ++$counts[$state];

            $rows[] = array_merge($definition, array(
                'value' => array_key_exists($id, $values) ? $values[$id] : null,
            ));
        }

        return array(
            'rows' => $rows,
            'window' => array(
                'start' => $since->toDateTimeString(),
                'end' => CarbonImmutable::now()->toDateTimeString(),
                'days' => $days,
            ),
            'tokens' => $tokens,
            'traces' => $traces,
            'counts' => $counts,
        );
    }

    /**
     * The token totals the cost pages already aggregate.
     *
     * @return array{turns: int, prompt_tokens: int, completion_tokens: int, cost: float}
     */
    private function tokenTotals(CarbonImmutable $since): array
    {
        $empty = array('turns' => 0, 'prompt_tokens' => 0, 'completion_tokens' => 0, 'cost' => 0.0);

        if (! Schema::hasTable(TokenUsage::TABLE)) {
            return $empty;
        }

        $row = DB::table(TokenUsage::TABLE)
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as turns, COALESCE(SUM(prompt_tokens), 0) as prompt_tokens, COALESCE(SUM(completion_tokens), 0) as completion_tokens, COALESCE(SUM(cost), 0) as cost')
            ->first();

        if (null === $row) {
            return $empty;
        }

        return array(
            'turns' => (int) $row->turns,
            'prompt_tokens' => (int) $row->prompt_tokens,
            'completion_tokens' => (int) $row->completion_tokens,
            'cost' => (float) $row->cost,
        );
    }
}
