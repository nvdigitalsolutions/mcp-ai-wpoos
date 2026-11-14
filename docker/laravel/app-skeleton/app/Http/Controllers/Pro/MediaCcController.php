<?php

namespace App\Http\Controllers\Pro;

use App\Support\Media\MediaWorkerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The media command centre (`GET /api/v1/pro/media-cc/health`).
 *
 * A hybrid of the two things it can honestly report:
 *
 *  - **The local library** — counts, storage and next-gen coverage come from
 *    `nvoos_files`. That is four of the seven sections WordPress computes,
 *    because this store has no alt-text column, no original-size column and no
 *    scheduler.
 *  - **The media worker** — version and uptime from `GET /api/health`, plus
 *    the queue counts for `media.*` handlers in `nvoos_jobs`.
 *
 * The three uncomputable sections are returned as **null**, never zero: the
 * console renders `—` so a missing pipeline reads as unknown rather than as
 * "0% alt coverage, 0 B saved". `capabilities.sweep` is `false` until the
 * compression job ships, and the console hides the action.
 *
 * The route is always registered; availability comes from configuration —
 * a runtime with no worker reports `mediaCc` false and hides the page
 * (`App\Support\EndpointCapabilities`).
 */
class MediaCcController
{
    /**
     * The snapshot, in the shape `api/media-ops.ts` parses.
     */
    public function health(MediaWorkerClient $worker): JsonResponse
    {
        if (! $worker->configured()) {
            return response()->json(
                [
                    'code' => 'media_cc_not_configured',
                    'message' => 'The media worker is not configured on this runtime.',
                    'data' => ['status' => 503],
                ],
                503,
            );
        }

        [$total, $images, $storage, $nextgenPct] = $this->library();

        return response()->json([
            'attachments' => [
                'total' => $total,
                'image_count' => $images,
                // No alt-text column on `nvoos_files`.
                'with_alt' => null,
                'descriptive_filename_count' => null,
            ],
            'storage' => $storage,
            // Derived from `with_alt`, so unknown here.
            'alt_pct' => null,
            'nextgen_pct' => $nextgenPct,
            // No original-size column, so savings cannot be measured.
            'compression' => null,
            'queue' => $this->queue(),
            // The scheduler is code-defined; until the sweep ships there is
            // no media schedule to summarise.
            'schedule' => [
                'active' => 0,
                'ran_today' => 0,
                'overdue' => 0,
                'next_run' => '',
            ],
            'capabilities' => ['sweep' => false],
            'worker' => ['configured' => true] + $worker->health(),
        ]);
    }

    /**
     * Library totals from `nvoos_files`.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} total, image count, bytes, next-gen percentage
     */
    private function library(): array
    {
        if (! Schema::hasTable('nvoos_files')) {
            return [0, 0, 0, 0];
        }

        $total = (int) DB::table('nvoos_files')->count();
        $images = (int) DB::table('nvoos_files')->where('mime_type', 'like', 'image/%')->count();
        $storage = (int) DB::table('nvoos_files')->sum('size_bytes');
        $nextgen = (int) DB::table('nvoos_files')
            ->whereIn('mime_type', ['image/webp', 'image/avif'])
            ->count();

        return [$total, $images, $storage, $images > 0 ? (int) round($nextgen / $images * 100) : 0];
    }

    /**
     * Media job counts from the durable job store.
     *
     * An absent status is a true zero — the store groups real rows.
     *
     * @return array{pending: int, completed_today: int, failed: int, total_processed: int, recent_failures: list<array{id: string, handler: string, error: string}>}
     */
    private function queue(): array
    {
        $empty = [
            'pending' => 0,
            'completed_today' => 0,
            'failed' => 0,
            'total_processed' => 0,
            'recent_failures' => [],
        ];

        if (! Schema::hasTable('nvoos_jobs')) {
            return $empty;
        }

        $media = static fn () => DB::table('nvoos_jobs')->where('handler', 'like', 'media.%');

        $recent = $media()
            ->where('status', 'failed')
            ->orderByDesc('queued_at')
            ->limit(5)
            ->get(['id', 'handler', 'error']);

        return [
            'pending' => (int) $media()->whereIn('status', ['queued', 'running'])->count(),
            'completed_today' => (int) $media()
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->startOfDay())
                ->count(),
            'failed' => (int) $media()->where('status', 'failed')->count(),
            'total_processed' => (int) $media()->where('status', 'completed')->count(),
            'recent_failures' => array_map(
                static fn (object $row): array => [
                    'id' => (string) $row->id,
                    'handler' => (string) $row->handler,
                    'error' => (string) ($row->error ?? ''),
                ],
                $recent->all(),
            ),
        ];
    }
}
