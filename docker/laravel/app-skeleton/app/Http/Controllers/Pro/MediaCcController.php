<?php

namespace App\Http\Controllers\Pro;

use App\Jobs\OptimizeMediaFile;
use App\Support\ActivityRecorder;
use App\Support\Media\MediaWorkerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Laravel\Adapter\JobStatusStore;

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
    /** WordPress's image mime list (`get_attachment_stats()`). */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

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

    /** Files queued per sweep; keeps the queue visible instead of dumping a library. */
    private const SWEEP_BATCH = 50;

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
            // No recurring media job exists yet, so there is no schedule to
            // summarise. Zeros here are measured, not invented.
            'schedule' => [
                'active' => 0,
                'ran_today' => 0,
                'overdue' => 0,
                'next_run' => '',
            ],
            'capabilities' => ['sweep' => true],
            'worker' => ['configured' => true] + $worker->health(),
        ]);
    }

    /**
     * POST /media-cc/compression-sweep — queue optimisation for unprocessed images.
     *
     * Deduped, like WordPress's `as_has_scheduled_action` check: a second click
     * while work is in flight is refused rather than stacking a duplicate run.
     */
    public function sweep(MediaWorkerClient $worker, JobStatusStore $jobs, ActivityRecorder $activity): JsonResponse
    {
        if (! $worker->configured()) {
            return $this->notConfigured();
        }

        if ($this->sweepInFlight()) {
            return response()->json([
                'queued' => false,
                'message' => 'A compression sweep is already running.',
            ]);
        }

        $query = DB::table('nvoos_files')
            ->whereIn('mime_type', self::IMAGE_MIMES)
            ->orderBy('id')
            ->limit(self::SWEEP_BATCH);

        if (Schema::hasTable('nvoos_media_derivatives')) {
            $query->whereNotIn('id', DB::table('nvoos_media_derivatives')
                ->where('format', 'webp')
                ->select('file_id'));
        }

        $files = $query->get(['id']);

        foreach ($files as $file) {
            $fileId = (int) $file->id;

            $jobs->queue(OptimizeMediaFile::jobId($fileId), 'media.optimize', [
                'file_id' => $fileId,
                'format' => 'webp',
            ]);

            OptimizeMediaFile::dispatch($fileId);
        }

        $queued = $files->count();

        if ($queued > 0) {
            $activity->record(
                'media.sweep.queued',
                sprintf('%d file%s queued for optimisation.', $queued, 1 === $queued ? '' : 's'),
            );
        }

        return response()->json([
            'queued' => $queued > 0,
            'message' => $queued > 0
                ? sprintf('%d file%s queued for optimisation.', $queued, 1 === $queued ? '' : 's')
                : 'Nothing to optimise.',
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

        $images = static fn () => DB::table('nvoos_files')->whereIn('mime_type', self::IMAGE_MIMES);

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
     * Whether an optimisation is already queued or running.
     */
    private function sweepInFlight(): bool
    {
        if (! Schema::hasTable('nvoos_jobs')) {
            return false;
        }

        return DB::table('nvoos_jobs')
            ->where('handler', 'media.optimize')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
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
