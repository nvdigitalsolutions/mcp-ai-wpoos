<?php

namespace App\Console\Commands;

use App\Support\ActivityRecorder;
use App\Support\HealthSampler;
use Illuminate\Console\Command;

/**
 * Record one health sample and prune expired telemetry.
 *
 * Both stores are time-series; the sample is what gives `uptime` a
 * denominator, and the prune keeps them from growing without bound on an
 * install whose scheduler actually runs.
 */
final class SampleHealth extends Command
{
    protected $signature = 'nvoos:sample-health';

    protected $description = 'Record a health sample and prune expired telemetry.';

    public function handle(HealthSampler $sampler, ActivityRecorder $activity): int
    {
        $healthy = $sampler->sample();

        $sampler->prune();
        $activity->prune();

        $this->line($healthy ? 'healthy' : 'degraded');

        return self::SUCCESS;
    }
}
