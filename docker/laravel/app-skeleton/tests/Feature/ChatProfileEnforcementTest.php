<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ChatProfileGuard;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ChatProfileResolverInterface;
use Tests\TestCase;
use Throwable;

/**
 * Chat-profile enforcement — the half that makes the preference mean something.
 *
 * Every assertion here runs through the real application registry, so it pins
 * the wiring (resolver → guard → monotonic pipeline) rather than the guard's
 * unit behaviour, which `ChatProfileGuardTest` already covers.
 *
 * The load-bearing case is the last one: a `chat_profile` the caller drops into
 * the context is ignored. The profile is resolved from `user_id`, which the
 * orchestrator sets from the authenticated session — never from a slug the
 * client chose. That is the difference between a boundary and a suggestion.
 */
class ChatProfileEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** A real tool that carries `write` and `state-changing`. */
    private const WRITE_TOOL = 'delete_cache';

    /** A real tool that carries only local, read-only flags. */
    private const READ_TOOL = 'color_convert';

    public function test_a_read_only_user_is_denied_a_write_tool(): void
    {
        $registry = $this->registry();

        $result = $registry->execute(
            self::WRITE_TOOL,
            array('key' => 'chat-profile-probe'),
            $this->context($this->userWith('read-only'))
        );

        $this->assertInstanceOf(Throwable::class, $result);
        $this->assertStringContainsString('read-only profile', $result->getMessage());
    }

    public function test_a_write_user_is_not_blocked_by_the_profile_guard(): void
    {
        $registry = $this->registry();

        $result = $registry->execute(
            self::WRITE_TOOL,
            array('key' => 'chat-profile-probe'),
            $this->context($this->userWith(null))
        );

        $this->assertStringNotContainsString('read-only profile', $this->message($result));
    }

    public function test_a_read_only_tool_still_runs_under_a_read_only_profile(): void
    {
        $guard = new ChatProfileGuard(
            $this->registry(),
            $this->app->make(ChatProfileResolverInterface::class)
        );

        $this->assertNull(
            $guard->evaluate(self::READ_TOOL, array(), array('user_id' => $this->userWith('read-only')->getKey())),
            'A read-only profile must not block reads, or it is a denial-of-service rather than a boundary.'
        );
    }

    public function test_a_client_supplied_profile_slug_is_ignored(): void
    {
        $registry = $this->registry();

        $result = $registry->execute(
            self::WRITE_TOOL,
            array('key' => 'chat-profile-probe'),
            $this->context($this->userWith('read-only'), array(
                // Claiming write access in the context must change nothing.
                'chat_profile' => 'write',
            ))
        );

        $this->assertInstanceOf(Throwable::class, $result);
        $this->assertStringContainsString('read-only profile', $result->getMessage());
    }

    public function test_an_unauthenticated_context_is_governed_by_the_deployment_switch_not_the_profile(): void
    {
        $guard = new ChatProfileGuard(
            $this->registry(),
            $this->app->make(ChatProfileResolverInterface::class)
        );

        $this->assertNull(
            $guard->evaluate(self::WRITE_TOOL, array(), array()),
            'No principal is a machine credential or a queued job, not a guest chat.'
        );
    }

    private function registry(): ToolRegistry
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue($registry->has(self::WRITE_TOOL), 'The probe tool must be registered here.');
        $this->assertTrue($registry->has(self::READ_TOOL), 'The read probe tool must be registered here.');

        return $registry;
    }

    /**
     * The context the orchestrator builds for a tool call.
     *
     * `auth_provider` is not optional: `ToolRegistry` authorises through it, so
     * a context without one fails the capability check before any guard runs.
     *
     * @param array<string, mixed> $extra Extra context keys.
     *
     * @return array<string, mixed>
     */
    private function context(User $user, array $extra = array()): array
    {
        return $extra + array(
            'user_id'       => $user->getKey(),
            'auth_provider' => $this->app->make(AuthProviderInterface::class),
            'agentic_loop'  => true,
        );
    }

    /**
     * @param string|null $profile Stored preference; null = inherit the default.
     */
    private function userWith(?string $profile): User
    {
        // An administrator in both cases: `delete_cache` requires
        // `manage_options`, so the profile must be the only variable between
        // the denied and the allowed assertions.
        $user = User::factory()->admin()->create();

        if (null !== $profile) {
            $user->forceFill(array('chat_profile' => $profile))->save();
        }

        return $user;
    }

    private function message(mixed $result): string
    {
        return $result instanceof Throwable ? $result->getMessage() : '';
    }
}
