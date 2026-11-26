<?php

namespace App\Support\ToolQuota;

/**
 * Writes a tool's estimated cost into its bucket — Proposal 149 §176.
 *
 * Runs from `AfterToolExecution`, so it sees what the tool actually returned.
 * A denied call never reaches this point (the waterfall returns before the body
 * runs), which is deliberate: a refusal costs the loop nothing, and counting it
 * would let a model exhaust a quota by retrying.
 *
 * Recording happens whether or not the tool has a configured limit. The page
 * needs a baseline to help choose a limit, and a limit chosen without knowing the
 * current spend is a guess. This is the per-call upsert the master switch exists
 * to turn off.
 */
final class ToolQuotaRecorder
{
    public function __construct(private readonly ToolQuotaStore $store)
    {
    }

    /**
     * @param string               $slug    the tool that ran
     * @param array<string, mixed> $context the execution context
     * @param mixed                $result  whatever the tool returned
     */
    public function record(string $slug, array $context, mixed $result): void
    {
        if (! $this->store->enabled()) {
            return;
        }

        $userId = (int) ($context['user_id'] ?? 0);
        $tokens = ToolQuotaStore::estimate($result);

        $this->store->record($slug, $userId, $tokens);
    }
}
