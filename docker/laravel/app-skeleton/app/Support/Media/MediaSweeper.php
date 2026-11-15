<?php

namespace App\Support\Media;

use App\Jobs\OptimizeMediaFile;
use App\Support\ActivityRecorder;
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
