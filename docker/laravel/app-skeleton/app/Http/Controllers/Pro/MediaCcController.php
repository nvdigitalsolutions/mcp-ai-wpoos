<?php

namespace App\Http\Controllers\Pro;

use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaSweeper;
use App\Support\Media\MediaWorkerClient;
use App\Support\TenantContext;
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
    /**
     * GET /media-cc/health — the snapshot, in the shape `api/media-ops.ts` parses.
     *
     * The library numbers come from {@see MediaLibrary} rather than a private
     * copy, because `/admin/{estate}/media` reads the same projection (§109).
     */
    public function health(MediaWorkerClient $worker): JsonResponse
    {
        if (! $worker->configured()) {
            return $this->notConfigured();
        }

        $library = app(MediaLibrary::class)->summary(TenantContext::current());

        return response()->json([
            'attachments' => [
                'total' => $library['total'],
                'image_count' => $library['image_count'],
                'with_alt' => $library['with_alt'],
                'descriptive_filename_count' => $library['descriptive'],
            ],
            'storage' => $library['storage'],
            'alt_pct' => $library['alt_pct'],
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
     * Recorded savings for this estate. Delegated to {@see MediaLibrary} so the
     * panel and the API report the same number.
     */
    private function compression(): int
    {
        return app(MediaLibrary::class)->derivativeBytes(TenantContext::current());
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
