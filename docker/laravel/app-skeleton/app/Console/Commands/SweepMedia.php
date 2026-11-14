<?php

namespace App\Console\Commands;

use App\Support\Media\MediaSweeper;
use Illuminate\Console\Command;
use Nvoos\Laravel\Adapter\JobStatusStore;

/**
 * Queue a media compression sweep.
 *
 * The scheduled counterpart of the console's "Run compression sweep" action —
 * both call {@see MediaSweeper}, so a nightly run and a manual click do the
 * same thing.
 *
 * Each invocation writes a `media.sweep` row. That row *is* the schedule's
 * history: `schedule.ran_today` and `schedule.overdue` in the command centre
 * read it, so a manual sweep deliberately does not write one — it would make
 * the schedule look healthier than it is.
 */
final class SweepMedia extends Command
{
    protected $signature = 'nvoos:sweep-media';

    protected $description = 'Queue optimisation for media that has no derivative yet.';

    public function handle(MediaSweeper $sweeper, JobStatusStore $jobs): int
    {
        $recordId = 'media.sweep:'.now()->format('YmdHis');
        $jobs->queue($recordId, 'media.sweep', []);

        $result = $sweeper->sweep();

        if ($result['refused']) {
            // Another sweep was already running: this slot was skipped, not
            // missed, so it must not count as a completed run.
            $jobs->markCancelled($recordId);
        } else {
            $jobs->markCompleted($recordId, ['queued' => $result['queued']]);
        }

        $this->line($result['message']);

        return self::SUCCESS;
    }
}
