<?php

namespace App\Listeners;

use App\Support\TenantContext;
use Nvoos\Core\Domain\Contract\TokenUsageInterface;
use Nvoos\Core\Domain\Event\CostCalculated;

/**
 * Persist one chat turn's token usage — Proposal 149 §81.
 *
 * The runtime has read `nvoos_token_usage` since 090-i — the analytics pages,
 * the diagnostics summary, the slash commands — but until this listener nothing
 * in production wrote to it. Only tests did. Every cost view was therefore
 * empty on a real deployment while looking healthy in the suite.
 *
 * `CostCalculated` is the right seam for three reasons:
 *
 *  - it fires from **both** `handleChat()` and `handleChatStreaming()`, so a
 *    streaming turn is recorded exactly like a blocking one;
 *  - it carries the accumulated figures for an agentic turn (`prompt_tokens` /
 *    `completion_tokens` override the last iteration's usage), so a multi-round
 *    turn is billed once, at its real total;
 *  - it is dispatched after the cost is computed, so the row can carry the same
 *    `cost_usd` the analytics pages show — one number, not two.
 *
 * The listener never throws: `TokenUsageInterface::trackUsage()` is a
 * best-effort write that guards on the table existing, and a telemetry failure
 * must not fail the chat that already succeeded.
 *
 * **Registration is by event discovery**, which scans `app/Listeners` and binds
 * `Class@handle` to the type-hinted event. Do not also call `Event::listen()`
 * for this class: that registers a second listener and writes every turn twice
 * — the failure looks like a duplicate-row bug in the adapter, not a wiring one.
 */
class RecordTokenUsage
{
    public function __construct(private readonly TokenUsageInterface $usage)
    {
    }

    public function handle(CostCalculated $event): void
    {
        if (! (bool) config('oos.token_usage.track', true)) {
            return;
        }

        $cost = is_array($event->costData) ? $event->costData : array();
        $responseUsage = is_array($event->response['usage'] ?? null) ? $event->response['usage'] : array();

        // Prefer the accumulated figures: for an agentic turn they are the
        // whole conversation, while `response.usage` is the last iteration.
        $promptTokens = (int) ($cost['prompt_tokens'] ?? $responseUsage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($cost['completion_tokens'] ?? $responseUsage['completion_tokens'] ?? 0);

        if ($promptTokens <= 0 && $completionTokens <= 0) {
            return;
        }

        $model = trim((string) ($cost['model'] ?? $event->response['model'] ?? ''));
        $provider = trim((string) ($cost['provider'] ?? ''));

        $metadata = array(
            // The estate travels as metadata, not as a parameter: the adapter
            // lives outside the app and must not reach for TenantContext
            // (Proposal 152 §3 D4).
            'tenant_id' => TenantContext::current(),
            'provider' => '' !== $provider ? $provider : 'unknown',
            'cost' => (float) ($cost['cost_usd'] ?? 0),
            'assistant_id' => $event->assistantId,
        );

        if (isset($cost['is_estimated'])) {
            $metadata['estimated'] = (bool) $cost['is_estimated'];
        }

        if (isset($cost['agentic_iterations_count'])) {
            $metadata['agentic_iterations'] = (int) $cost['agentic_iterations_count'];
        }

        $this->usage->trackUsage(
            max(0, $event->userId),
            '' !== $model ? $model : 'unknown',
            max(0, $promptTokens),
            max(0, $completionTokens),
            $metadata
        );
    }
}
