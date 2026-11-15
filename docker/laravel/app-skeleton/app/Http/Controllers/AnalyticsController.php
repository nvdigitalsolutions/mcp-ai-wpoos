<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Laravel\Adapter\TokenUsage;

class AnalyticsController
{
    public function summary(Request $request, TokenUsage $usage): JsonResponse
    {
        return response()->json([
            'summary' => $usage->summary($this->days($request)),
        ]);
    }

    public function trend(Request $request, TokenUsage $usage): JsonResponse
    {
        return response()->json([
            'data' => $usage->trend($this->days($request)),
        ]);
    }

    public function byProvider(Request $request, TokenUsage $usage): JsonResponse
    {
        return response()->json([
            'data' => $usage->byProvider($this->days($request)),
        ]);
    }

    private function days(Request $request): int
    {
        return min(365, max(1, (int) $request->query('days', 30)));
    }
}
