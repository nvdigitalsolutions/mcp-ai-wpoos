<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Laravel\Adapter\TokenUsage;

/**
 * Cost endpoints — one estate at a time, always.
 *
 * These routes sit in the estate-administrator group, so the answer has to be
 * the caller's estate and nothing else. `TokenUsage`'s readers have no default
 * scope precisely so a fetch like `$usage->summary($days)` cannot compile into a
 * cross-estate read (Proposal 152 §3 D5); the estate comes from
 * `TenantContext`, which `ResolveApiTenant` pinned from `X-Tenant-Id`.
 */
class AnalyticsController
{
    public function summary(Request $request, TokenUsage $usage): JsonResponse
    {
        return response()->json([
            'summary' => $usage->summary($this->days($request), TenantContext::current()),
        ]);
    }

    public function trend(Request $request, TokenUsage $usage): JsonResponse
    {
        return response()->json([
            'data' => $usage->trend($this->days($request), TenantContext::current()),
        ]);
    }

    public function byProvider(Request $request, TokenUsage $usage): JsonResponse
    {
        return response()->json([
            'data' => $usage->byProvider($this->days($request), TenantContext::current()),
        ]);
    }

    private function days(Request $request): int
    {
        return min(365, max(1, (int) $request->query('days', 30)));
    }
}
