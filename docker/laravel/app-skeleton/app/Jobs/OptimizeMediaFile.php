<?php

namespace App\Jobs;

use App\Support\ActivityRecorder;
use App\Support\Media\MediaWorkerClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nvoos\Laravel\Adapter\JobStatusStore;
use RuntimeException;
use Throwable;

/**
 * Optimise one media file through the worker (090-j slice 3).
 *
 * The worker returns the optimised bytes as base64. Storing them is what makes
 * `compression` honest: the saving is only reported once the derivative exists
 * on disk and its row is written.
 *
 * Lifecycle rows use the `media.optimize` handler so the command centre's
 * queue counts are real. A failure is rethrown — the job's `failed()` writes
 * the terminal status after the last attempt.
 */
class OptimizeMediaFile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** The worker caps uploads at 50 MB; larger files are skipped, not retried. */
    private const MAX_SOURCE_BYTES = 52428800;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    public function __construct(
        public readonly int $fileId,
        public readonly string $format = 'webp',
        public readonly int $quality = 80,
    ) {
    }

    /**
     * The stable job id, shared with the sweep that queued it.
     */
    public static function jobId(int $fileId, string $format = 'webp'): string
    {
        return 'media.optimize:'.$fileId.':'.$format;
    }

    public function handle(
        MediaWorkerClient $worker,
        JobStatusStore $jobs,
        ActivityRecorder $activity,
    ): void {
        $jobId = self::jobId($this->fileId, $this->format);
        $row = DB::table('nvoos_files')->find($this->fileId);

        if ($row === null) {
            $jobs->markFailed($jobId, new RuntimeException('Media file '.$this->fileId.' no longer exists.'));

            return;
        }

        $mime = (string) ($row->mime_type ?? '');

        if (! str_starts_with($mime, 'image/')) {
            // Not an image: nothing to optimise, and nothing went wrong.
            $jobs->markCancelled($jobId);

            return;
        }

        $disk = Storage::disk((string) ($row->disk ?? 'public'));
        $path = (string) ($row->path ?? '');

        if (! $disk->exists($path)) {
            throw new RuntimeException('Source file is missing from the disk: '.$path);
        }

        if ((int) $disk->size($path) > self::MAX_SOURCE_BYTES) {
            $jobs->markCancelled($jobId);
            $activity->record(
                'media.skipped',
                sprintf('%s is larger than 50 MB and was skipped.', (string) ($row->filename ?? '')),
                null,
                ['file_id' => $this->fileId],
            );

            return;
        }

        $jobs->markRunning($jobId);

        $result = $worker->optimize(
            $disk->path($path),
            (string) ($row->filename ?? basename($path)),
            $this->format,
            $this->quality,
        );

        $derivedPath = 'derived/'.$this->fileId.'.'.$this->format;
        $disk->put($derivedPath, base64_decode($result['b64'], true) ?: '');

        DB::table('nvoos_media_derivatives')->updateOrInsert(
            ['file_id' => $this->fileId, 'format' => $this->format],
            [
                'path' => $derivedPath,
                'size_bytes' => $result['optimized_size'],
                'savings_bytes' => $result['savings_bytes'],
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        $jobs->markCompleted($jobId, ['savings_bytes' => $result['savings_bytes']]);

        $activity->record(
            'media.optimized',
            sprintf(
                '%s optimised to %s (%d bytes saved).',
                (string) ($row->filename ?? ''),
                $this->format,
                $result['savings_bytes'],
            ),
            null,
            ['file_id' => $this->fileId, 'format' => $this->format],
        );
    }

    /**
     * Terminal failure after the last attempt.
     */
    public function failed(?Throwable $exception): void
    {
        app(JobStatusStore::class)->markFailed(
            self::jobId($this->fileId, $this->format),
            $exception ?? new RuntimeException('Optimisation failed.'),
        );
    }
}
