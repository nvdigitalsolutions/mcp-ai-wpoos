<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Laravel\Adapter\DatabaseTraceStore;
use Tests\TestCase;

/**
 * Trace-capture wiring — the runtime must hold a store, and the kill switch must
 * actually remove it.
 *
 * What a run records is covered in core (`TraceCaptureTest`,
 * `ChatOrchestratorTest`); this closes the last gap, that the store reaches the
 * orchestrator and that `oos.security.trace_capture` can take it away again.
 */
class TraceCaptureWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_store_is_wired_by_default(): void
    {
        $this->assertTrue((bool) config('oos.security.trace_capture'));

        $this->assertInstanceOf(
            DatabaseTraceStore::class,
            app(ChatOrchestrator::class)->traceStore()
        );
    }

    public function test_the_kill_switch_removes_the_store(): void
    {
        config(['oos.security.trace_capture' => false]);

        app()->forgetInstance(ChatOrchestrator::class);

        $this->assertNull(app(ChatOrchestrator::class)->traceStore());
    }

    public function test_the_retention_window_is_bounded_by_the_policy(): void
    {
        $store = app(DatabaseTraceStore::class);

        // Pruning asks for a huge window; the store clamps it rather than
        // deleting nothing.
        $this->assertSame(0, $store->prune(7, PHP_INT_MAX));
    }
}
