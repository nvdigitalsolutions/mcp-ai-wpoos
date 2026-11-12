<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Laravel\Jobs\NvoosToolJob;

class OperationsController
{
    /**
     * Laravel's scheduler is code-defined, so there is no tracked-job table to
     * list. The page renders an empty state; the Vue-style WP cron list has no
     * direct equivalent on this runtime.
     */
    public function cron(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'jobs' => [],
                'available' => false,
            ],
        ]);
    }

    public function removeCron(string $jobId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'not_found',
                'message' => 'No such scheduled job on this runtime.',
            ],
        ], 404);
    }

    /**
     * Failed jobs, mapped to the console's dead-letter shape.
     */
    public function dlq(JobStatusStore $jobs): JsonResponse
    {
        $items = array_map(
            static fn (object $row): array => [
                'item_id' => (string) $row->id,
                'type' => (string) ($row->handler ?? 'job'),
                'identifier' => (string) $row->id,
                'failure_reason' => (string) ($row->error ?? ''),
                'dismissed' => false,
                'created_at' => (string) ($row->queued_at ?? ''),
            ],
            $jobs->rows(['status' => 'failed'], 100),
        );

        return response()->json([
            'success' => true,
            'data' => ['items' => $items],
        ]);
    }

    /**
     * Re-dispatch a failed job. The handler/payload are read from the durable
     * status row, so retry survives a worker restart.
     */
    public function retry(string $itemId, JobStatusStore $jobs): JsonResponse
    {
        $row = $jobs->row($itemId);

        if ($row === null || ($row->status ?? '') !== 'failed') {
            return $this->missing($itemId);
        }

        $payload = json_decode((string) ($row->payload ?? ''), true);

        $jobs->markQueued($itemId);
        NvoosToolJob::dispatch(
            (string) $row->id,
            (string) $row->handler,
            is_array($payload) ? $payload : [],
        );

        return response()->json([
            'success' => true,
            'data' => ['retried' => true, 'item_id' => $itemId],
        ]);
    }

    public function dismiss(string $itemId, JobStatusStore $jobs): JsonResponse
    {
        if (! $jobs->dismiss($itemId)) {
            return $this->missing($itemId);
        }

        return response()->json([
            'success' => true,
            'data' => ['dismissed' => true, 'item_id' => $itemId],
        ]);
    }

    /**
     * Paged run summaries derived from completed durable job rows.
     */
    public function timeline(Request $request, JobStatusStore $jobs): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $completed = array_values(array_filter(
            $jobs->rows([], 100),
            static fn (object $row): bool => ($row->status ?? '') === 'completed',
        ));

        $slice = array_slice($completed, ($page - 1) * $perPage, $perPage);

        $runs = array_map(static function (object $row): array {
            $payload = json_decode((string) ($row->payload ?? ''), true);
            $result = json_decode((string) ($row->result ?? ''), true);
            $started = isset($row->started_at) ? strtotime((string) $row->started_at) : null;
            $finished = isset($row->completed_at) ? strtotime((string) $row->completed_at) : null;

            return [
                'run_id' => (string) $row->id,
                'assistant_id' => (int) ($payload['assistant_id'] ?? 0),
                'started_at' => $started,
                'total_tokens' => isset($result['total_tokens']) ? (int) $result['total_tokens'] : null,
                'cost_usd' => isset($result['cost_usd']) ? (float) $result['cost_usd'] : null,
                'latency_ms' => ($started && $finished) ? max(0, ($finished - $started) * 1000) : null,
                'trace' => null,
            ];
        }, $slice);

        return response()->json([
            'success' => true,
            'data' => [
                'runs' => $runs,
                'total' => count($completed),
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    private function missing(string $itemId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'not_found',
                'message' => sprintf('No failed job with id %s.', $itemId),
            ],
        ], 404);
    }
}
