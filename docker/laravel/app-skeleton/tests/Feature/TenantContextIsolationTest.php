<?php

namespace Tests\Feature;

use App\Listeners\ForgetTenantContext;
use App\Models\Assistant;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;
use Mockery;
use Tests\TestCase;

/**
 * Proposal 149 T-0.2 — the pinned tenant must not survive the operation.
 *
 * The defect this pins: TenantContext holds process-wide static state, and
 * before this change nothing reset it. Under a single tenant that is invisible.
 * The moment panel tenancy starts calling set(), the value survives an Octane
 * request or a queue job and the next operation reads it — and because every
 * assistant/team query is scoped by TenantContext::current(), that is a
 * cross-tenant data leak created by enabling a feature.
 */
class TenantContextIsolationTest extends TestCase
{
    public function test_it_falls_back_to_the_default_tenant(): void
    {
        $this->assertFalse(TenantContext::isPinned());
        $this->assertSame(Assistant::DEFAULT_TENANT, TenantContext::current());
    }

    public function test_set_pins_and_forget_restores_the_default(): void
    {
        TenantContext::set('tenant-a');

        $this->assertTrue(TenantContext::isPinned());
        $this->assertSame('tenant-a', TenantContext::current());

        TenantContext::forget();

        $this->assertFalse(TenantContext::isPinned());
        $this->assertSame(Assistant::DEFAULT_TENANT, TenantContext::current());
    }

    /**
     * The listener is the reset for every Octane operation. It is typed
     * `object` on purpose so one class serves request, task and tick events —
     * this test exercises the handler without depending on Octane's event
     * constructor signatures, which differ per event.
     */
    public function test_the_octane_listener_clears_the_pinned_tenant(): void
    {
        TenantContext::set('tenant-a');

        (new ForgetTenantContext)->handle(new \stdClass);

        $this->assertFalse(TenantContext::isPinned());
        $this->assertSame(Assistant::DEFAULT_TENANT, TenantContext::current());
    }

    /**
     * Task and tick workers reuse the process exactly like request workers but
     * never run HTTP terminating callbacks, so the config registration is the
     * only reset they get. A reset point that exists in code but is not wired
     * is the same as no reset at all.
     */
    public function test_every_octane_termination_event_is_wired(): void
    {
        $listeners = (array) config('octane.listeners');

        foreach ([RequestTerminated::class, TaskTerminated::class, TickTerminated::class] as $event) {
            $this->assertContains(
                ForgetTenantContext::class,
                $listeners[$event] ?? [],
                "{$event} must reset the tenant context or Octane leaks it across operations."
            );
        }
    }

    /**
     * The oos-laravel-worker container runs `queue:work redis`, which is a
     * long-lived process that fires no Octane events. The queue listeners in
     * AppServiceProvider are its only reset.
     */
    public function test_a_processed_job_clears_the_pinned_tenant(): void
    {
        TenantContext::set('tenant-a');

        event(new JobProcessed('redis', Mockery::mock(Job::class)));

        $this->assertSame(Assistant::DEFAULT_TENANT, TenantContext::current());
    }

    /**
     * A job that throws must not leave the pin behind for the retry or for the
     * next job picked up by the same worker.
     */
    public function test_a_failed_job_clears_the_pinned_tenant(): void
    {
        TenantContext::set('tenant-a');

        event(new JobExceptionOccurred('redis', Mockery::mock(Job::class), new \RuntimeException('boom')));

        $this->assertSame(Assistant::DEFAULT_TENANT, TenantContext::current());
    }

    /**
     * Guards Tests\TestCase::setUp(). If the harness reset is removed, a test
     * that pins a tenant leaks it into every later test in the process — the
     * production bug reproduced in the suite.
     */
    public function test_the_harness_begins_unpinned(): void
    {
        $this->assertFalse(TenantContext::isPinned());
    }
}
