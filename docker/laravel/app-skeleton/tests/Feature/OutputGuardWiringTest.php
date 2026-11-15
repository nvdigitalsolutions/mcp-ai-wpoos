<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Security\OutputGuard;
use Tests\TestCase;

/**
 * Output-guard wiring — the safety posture card says "output validation", so
 * the runtime must actually hold a guard when the flag is on.
 *
 * Behaviour is covered twice over: `OutputGuardTest` for the collaborator and
 * `ChatOrchestratorTest` for the chat path, including the buffering rule. This
 * test closes the last gap — that the config an operator flips reaches the
 * orchestrator at all.
 */
class OutputGuardWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guard_is_not_wired_by_default(): void
    {
        $this->assertFalse((bool) config('oos.security.output_guard'));

        $this->assertNull(app(ChatOrchestrator::class)->outputGuard());
    }

    public function test_an_enabled_guard_is_wired_with_its_configured_mode(): void
    {
        config([
            'oos.security.output_guard' => true,
            'oos.security.output_guard_mode' => 'block',
            'oos.security.output_guard_strictness' => 'high',
        ]);

        app()->forgetInstance(ChatOrchestrator::class);

        $guard = app(ChatOrchestrator::class)->outputGuard();

        $this->assertInstanceOf(OutputGuard::class, $guard);
        $this->assertTrue($guard->enabled());
        $this->assertSame('block', $guard->mode());
        $this->assertSame('high', $guard->strictness());
    }

    public function test_an_out_of_range_mode_falls_back_to_warn(): void
    {
        config([
            'oos.security.output_guard' => true,
            'oos.security.output_guard_mode' => 'nuke',
            'oos.security.output_guard_strictness' => 'paranoid',
        ]);

        app()->forgetInstance(ChatOrchestrator::class);

        $guard = app(ChatOrchestrator::class)->outputGuard();

        $this->assertNotNull($guard);
        $this->assertSame('warn', $guard->mode());
        $this->assertSame('medium', $guard->strictness());
    }
}
