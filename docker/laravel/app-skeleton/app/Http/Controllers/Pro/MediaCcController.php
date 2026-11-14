<?php

namespace App\Http\Controllers\Pro;

use App\Support\Media\MediaSweeper;
use App\Support\Media\MediaWorkerClient;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The media command centre (`/api/v1/pro/media-cc`).
 *
 * A hybrid of the two things it can honestly report:
 *
 *  - **The local library** — counts, storage, alt coverage and next-gen
 *    coverage come from `nvoos_files`. The image list and the filename
 *    heuristic mirror WordPress's own gatherers, so the same library reports
 *    the same percentages on either runtime.
 *  - **The worker** — version and uptime from `GET /api/health`, plus the
 *    queue counts for `media.*` handlers in `nvoos_jobs`.
 *
 * `compression` is the sum of recorded derivative savings, so a saving is only
 * ever reported once the optimised bytes exist. `schedule` is the one section
 * still without a source: the scheduler is code-defined and no recurring media
 * job exists yet, so zeros are truthful.
 *
 * The route is always registered; availability comes from configuration —
 * a runtime with no worker reports `mediaCc` false and hides the page
 * (`App\Support\EndpointCapabilities`).
 */
class MediaCcController
{
    /** Filename prefixes WordPress treats as generic (`IMG_1234`, `screenshot-…`). */
    private const GENERIC_PREFIXES = [
        'img_',
        'IMG_',
        'screenshot-',
        'Screenshot_',
        'image-',
        'Image_',
        'photo-',
        'Photo_',
        'dsc_',
        'DSC_',
    ];

    /**
     * GET /media-cc/health — the snapshot, in the shape `api/media-ops.ts` parses.
     */
    public function health(MediaWorkerClient $worker): JsonResponse
    {
        if (! $worker->configured()) {
            return $this->notConfigured();
        }

        $library = $this->library();

        return response()->json([
            'attachments' => [
                'total' => $library['total'],
                'image_count' => $library['image_count'],
                'with_alt' => $library['with_alt'],
                'descriptive_filename_count' => $library['descriptive'],
            ],
            'storage' => $library['storage'],
            // WordPress reports 100 when there is nothing to measure; mirror it.
            'alt_pct' => $library['image_count'] < 1
                ? 100
                : (int) round($library['with_alt'] / $library['image_count'] * 100),
            'nextgen_pct' => $library['nextgen_pct'],
            'compression' => $this->compression(),
            'queue' => $this->queue(),
            'schedule' => $this->schedule(),
            'capabilities' => ['sweep' => true],
            'worker' => ['configured' => true] + $worker->health(),
        ]);
    }

    /**
     * POST /media-cc/compression-sweep — queue optimisation for unprocessed images.
     *
     * The decision of what to queue lives in {@see MediaSweeper} so this and the
     * scheduled command cannot drift.
     */
    public function sweep(MediaWorkerClient $worker, MediaSweeper $sweeper): JsonResponse
    {
        if (! $worker->configured()) {
            return $this->notConfigured();
        }

        $result = $sweeper->sweep();

        return response()->json([
            'queued' => $result['queued'] > 0,
            'message' => $result['message'],
        ]);
    }

    /**
     * Library totals from `nvoos_files`.
     *
     * @return array{total: int, image_count: int, with_alt: int, descriptive: int, storage: int, nextgen_pct: int}
     */
    private function library(): array
    {
        if (! Schema::hasTable('nvoos_files')) {
            return [
                'total' => 0,
                'image_count' => 0,
                'with_alt' => 0,
                'descriptive' => 0,
                'storage' => 0,
                'nextgen_pct' => 0,
            ];
        }

        $images = static fn () => DB::table('nvoos_files')->whereIn('mime_type', MediaSweeper::IMAGE_MIMES);

        $imageCount = (int) $images()->count();
        $withAlt = (int) $images()
            ->whereNotNull('alt_text')
            ->where('alt_text', '!=', '')
            ->count();

        $descriptive = (int) $images()
            ->where(static function ($query): void {
                foreach (self::GENERIC_PREFIXES as $prefix) {
                    $query->where('filename', 'not like', $prefix.'%');
                }
            })
            ->count();

        $nextgen = (int) $images()
            ->whereIn('mime_type', ['image/webp', 'image/avif'])
            ->count();

        return [
            'total' => (int) DB::table('nvoos_files')->count(),
            'image_count' => $imageCount,
            'with_alt' => $withAlt,
            'descriptive' => $descriptive,
            'storage' => (int) DB::table('nvoos_files')->sum('size_bytes'),
            'nextgen_pct' => $imageCount > 0 ? (int) round($nextgen / $imageCount * 100) : 0,
        ];
    }

    /**
     * Recorded savings across every derivative.
     *
     * Only positive savings count: an "optimised" file that came out larger is
     * a miss, not a negative saving that offsets the wins.
     */
    private function compression(): int
    {
        if (! Schema::hasTable('nvoos_media_derivatives')) {
            return 0;
        }

        return (int) DB::table('nvoos_media_derivatives')
            ->where('savings_bytes', '>', 0)
            ->sum('savings_bytes');
    }

    /**
     * The media schedule summary.
     *
     * `active` and `next_run` come from the configured cron expression; the
     * command itself is registered in `bootstrap/app.php`, so nothing is
     * scheduled when the config is empty. `ran_today` counts completed
     * `media.sweep` rows — only the scheduled command writes those, so a manual
     * sweep does not inflate the number.
     *
     * `overdue` is a real comparison: the last completed run is earlier than
     * the most recent scheduled slot. With no run on record there is nothing to
     * be overdue, which is why a fresh install reports zero.
     *
     * @return array{active: int, ran_today: int, overdue: int, next_run: string}
     */
    private function schedule(): array
    {
        $cron = (string) config('oos.media_worker.sweep_schedule', '');
        $configured = '' !== $cron && CronExpression::isValidExpression($cron);

        $ranToday = 0;
        $last = null;

        if (Schema::hasTable('nvoos_jobs')) {
            $ranToday = (int) DB::table('nvoos_jobs')
                ->where('handler', 'media.sweep')
                ->where('status', 'completed')
                ->where('completed_at', '>=', now()->startOfDay())
                ->count();

            $last = DB::table('nvoos_jobs')
                ->where('handler', 'media.sweep')
                ->where('status', 'completed')
                ->max('completed_at');
        }

        if (! $configured) {
            return ['active' => 0, 'ran_today' => $ranToday, 'overdue' => 0, 'next_run' => ''];
        }

        $expression = new CronExpression($cron);

        $overdue = null !== $last
            && strtotime((string) $last) < $expression->getPreviousRunDate(now())->getTimestamp();

        return [
            'active' => 1,
            'ran_today' => $ranToday,
            'overdue' => $overdue ? 1 : 0,
            'next_run' => $expression->getNextRunDate(now())->format('Y-m-d H:i:s'),
        ];
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

    private function notConfigured(): JsonResponse
    {
        return response()->json(
            [
                'code' => 'media_cc_not_configured',
                'message' => 'The media worker is not configured on this runtime.',
                'data' => ['status' => 503],
            ],
            503,
        );
    }
}
