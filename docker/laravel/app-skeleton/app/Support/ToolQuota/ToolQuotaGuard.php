<?php

namespace App\Support\ToolQuota;

use Illuminate\Support\Facades\Log;

/**
 * The per-tool quota check — Proposal 149 §176.
 *
 * Runs **before** the tool body, from the `tools/pre_execute` waterfall, because
 * a quota applied after the call is a report rather than a ceiling. Counting the
 * result is the only measurement available (`ToolQuotaStore::estimate()`), so one
 * call can overshoot by its own result size; that is the same allowance
 * `BudgetGuard` documents, and the alternative — pre-estimating an arbitrary
 * tool's output — would be inventing a number.
 *
 * **Two bands, one refusal.** Over the warn band the call proceeds and a warning
 * is logged; at or over the limit it is refused. A warn band that also refused
 * would make the configured number advisory and a second, lower number the real
 * one.
 *
 * **Fails closed.** If the counter cannot be read the call is refused rather than
 * allowed: an unreadable counter under-reports, and under-reporting is
 * over-spending — the one error a quota exists to prevent.
 */
final class ToolQuotaGuard
{
    public function __construct(private readonly ToolQuotaStore $store)
    {
    }

    /**
     * @param string               $slug    the tool about to run
     * @param array<string, mixed> $context the execution context; `user_id` is
     *                                      the server-built caller, never a
     *                                      client-supplied value
     *
     * @throws ToolQuotaExceededException     the caller is at or over the limit
     * @throws ToolQuotaUnverifiableException the counter could not be read
     */
    public function assertWithinQuota(string $slug, array $context): void
    {
        if (! $this->store->enabled()) {
            return;
        }

        try {
            $this->check($slug, (int) ($context['user_id'] ?? 0));
        } catch (ToolQuotaExceededException | ToolQuotaUnverifiableException $error) {
            throw $error;
        } catch (\Throwable $error) {
            report($error);

            throw new ToolQuotaUnverifiableException(
                'The tool quota could not be verified.',
                0,
                $error
            );
        }
    }

    private function check(string $slug, int $userId): void
    {
        $limit = $this->store->limitFor($slug);

        if (null === $limit) {
            return;
        }

        $used = $this->store->usageToday($slug, $userId);

        if ($used >= $limit) {
            Log::warning('tool_quota.exhausted', array(
                'tenant_id' => \App\Support\TenantContext::current(),
                'tool_slug' => $slug,
                'user_id' => $userId,
                'used' => $used,
                'limit' => $limit,
            ));

            throw new ToolQuotaExceededException(
                $slug,
                $used,
                $limit,
                ToolQuotaStore::resetsAt()
            );
        }

        if ($used >= (int) ceil($limit * ToolQuotaStore::WARN_AT)) {
            Log::warning('tool_quota.warn', array(
                'tenant_id' => \App\Support\TenantContext::current(),
                'tool_slug' => $slug,
                'user_id' => $userId,
                'used' => $used,
                'limit' => $limit,
                'percent' => round(($used / $limit) * 100, 1),
            ));
        }
    }
}
