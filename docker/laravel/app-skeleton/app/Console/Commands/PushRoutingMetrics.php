<?php

namespace App\Console\Commands;

use App\Support\Diagnostics\RoutingEventPusher;
use App\Support\Diagnostics\RoutingExportStore;
use Illuminate\Console\Command;

/**
 * Push routing events to the configured OTLP collector — Proposal 149 §74.
 *
 * The safety property is the cursor: rows are sent oldest-first and the
 * watermark only advances on a 2xx. A failed push records itself and leaves the
 * watermark alone, so the next run retries exactly the rows the collector did
 * not acknowledge. Retention refuses to delete rows above the watermark while
 * an endpoint is configured (§74.4), which is what makes the promise real.
 *
 * Repeated failures back off exponentially (base × 2^(n-1), capped) so a down
 * collector is not hammered on every tick; `--force` is the operator override.
 * Backoff is derived from the run history, so a success resets it by existing.
 *
 * Exit code is `FAILURE` on a failed push, so a scheduler wrapper that watches
 * exit codes notices. A backoff wait exits `SUCCESS`: nothing is wrong with the
 * command, the collector is having a bad day.
 */
class PushRoutingMetrics extends Command
{
    protected $signature = 'nvoos:push-routing-metrics
                            {--dry-run : Show what would be sent without sending}
                            {--force : Push even while the exporter is backing off}';

    protected $description = 'Push unexported routing events to the configured OTLP endpoint.';

    public function handle(
        RoutingExportStore $store,
        RoutingEventPusher $pusher
    ): int {
        if (! $pusher->isConfigured()) {
            $this->warn('Routing export is not configured (oos.routing.export.endpoint); nothing pushed.');

            return self::SUCCESS;
        }

        $batch = max(1, (int) config('oos.routing.export.batch', 500));
        $pending = $store->pending($batch);

        if ([] === $pending) {
            $this->info('No unexported routing events.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $line = sprintf(
                '%d routing event(s) pending; would send %d to %s.',
                count($pending),
                count($pending),
                $this->endpointHost()
            );

            $backoffUntil = $store->backoffUntil();

            if (null !== $backoffUntil && now()->lt($backoffUntil)) {
                $line .= sprintf(' Backing off until %s.', $backoffUntil->toDateTimeString());
            }

            $this->line($line);

            return self::SUCCESS;
        }

        $backoffUntil = $store->backoffUntil();

        if (null !== $backoffUntil && now()->lt($backoffUntil) && ! $this->option('force')) {
            $this->line(sprintf(
                'Backing off until %s after %d consecutive failed push(es); --force overrides.',
                $backoffUntil->toDateTimeString(),
                $store->consecutiveFailures()
            ));

            return self::SUCCESS;
        }

        $watermark = $store->watermark();
        $result = $pusher->push($pending);

        if (! $result['ok']) {
            // The watermark does not move: the rows stay pending, and that is
            // the whole failure policy.
            $store->record('failed', 0, $watermark, $result['error']);

            $this->error(sprintf(
                'Push failed (%s); %d event(s) remain pending.',
                (string) ($result['error'] ?? 'unknown error'),
                count($pending)
            ));

            return self::FAILURE;
        }

        $lastId = (int) ($pending[count($pending) - 1]->id ?? $watermark);
        $store->record('ok', count($pending), $lastId, null);

        $this->info(sprintf(
            '%d routing event(s) pushed to %s (HTTP %s).',
            count($pending),
            $this->endpointHost(),
            null === $result['status'] ? '?' : (string) $result['status']
        ));

        return self::SUCCESS;
    }

    /**
     * The endpoint's host and path, without its query or credentials.
     */
    private function endpointHost(): string
    {
        $endpoint = (string) config('oos.routing.export.endpoint', '');
        $host = parse_url($endpoint, PHP_URL_HOST);
        $path = parse_url($endpoint, PHP_URL_PATH);

        return (is_string($host) && '' !== $host ? $host : 'endpoint')
            .(is_string($path) ? $path : '');
    }
}
