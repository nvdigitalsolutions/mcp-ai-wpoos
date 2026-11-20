<?php

namespace App\Support\Media;

use App\Jobs\OptimizeMediaFile;
use App\Support\ActivityRecorder;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Laravel\Adapter\JobStatusStore;

/**
 * Queue optimisation for images that have no derivative yet (090-j).
 *
 * Shared by the console's sweep action and the scheduled command, so a manual
 * run and a nightly run cannot diverge. The work itself is the job's; this
 * class only decides *what* to queue.
 *
 * Deduped the way WordPress dedupes with `as_has_scheduled_action`: while any
 * `media.optimize` job is queued or running, a second sweep is refused rather
 * than stacking a duplicate pass over the same library.
 */
final class MediaSweeper
{
    /** WordPress's image mime list; shared with the health snapshot. */
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    /** Files queued per sweep; keeps the queue visible instead of dumping a library. */
    private const SWEEP_BATCH = 50;

    public function __construct(
        private readonly JobStatusStore $jobs,
        private readonly ActivityRecorder $activity,
    ) {
    }

    /**
     * Queue a sweep.
     *
     * @return array{queued: int, refused: bool, message: string}
     */
    public function sweep(): array
    {
        if ($this->inFlight()) {
            return [
                'queued' => 0,
                'refused' => true,
                'message' => 'A compression sweep is already running.',
            ];
        }

        if (! Schema::hasTable('nvoos_files')) {
            return ['queued' => 0, 'refused' => false, 'message' => 'Nothing to optimise.'];
        }

        $query = DB::table('nvoos_files')
            // The sweep is an estate's own action: an administrator compressing
            // "my library" must not queue another estate's files (§109). Before
            // the column existed this selected the whole table, which was only
            // harmless because a deployment served one estate.
            ->where('tenant_id', TenantContext::current())
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

            $this->jobs->queue(OptimizeMediaFile::jobId($fileId), 'media.optimize', [
                'file_id' => $fileId,
                'format' => 'webp',
            ]);

            OptimizeMediaFile::dispatch($fileId);
        }

        $queued = $files->count();
        $message = $queued > 0
            ? sprintf('%d file%s queued for optimisation.', $queued, 1 === $queued ? '' : 's')
            : 'Nothing to optimise.';

        if ($queued > 0) {
            $this->activity->record('media.sweep.queued', $message);
        }

        return ['queued' => $queued, 'refused' => false, 'message' => $message];
    }

    /**
     * Whether an optimisation is already queued or running.
     *
     * Deliberately **platform-wide**: `nvoos_jobs` carries no estate and the
     * queue is one worker pool, so the honest question is "is a sweep running
     * anywhere". The cost of that reading is a spurious refusal when two estates
     * sweep at once; the alternative — a per-estate count that cannot be
     * computed — would refuse nothing and stack two passes over one library.
     */
    public function inFlight(): bool
    {
        if (! Schema::hasTable('nvoos_jobs')) {
            return false;
        }

        return DB::table('nvoos_jobs')
            ->where('handler', 'media.optimize')
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }
}
