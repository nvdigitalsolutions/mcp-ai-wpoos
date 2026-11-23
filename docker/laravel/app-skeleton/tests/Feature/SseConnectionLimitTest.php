<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\Streaming\SseConnectionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Proposal 149 §185 — the per-user SSE connection cap.
 *
 * `max_sse_connections_per_user` was writable on `/ops/settings` and read by
 * nothing, while a stream holds a PHP worker for its entire life. Two levels are
 * tested: the registry (the counting contract) and the controller (a refusal is
 * an HTTP 429 rather than an error frame inside a 200 stream).
 */
final class SseConnectionLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(array('cache.default' => 'array'));
    }

    public function test_the_cap_refuses_the_connection_after_the_limit(): void
    {
        $this->write(array('max_sse_connections_per_user' => 2));

        $registry = $this->app->make(SseConnectionRegistry::class);

        $this->assertTrue($registry->acquire(7));
        $this->assertTrue($registry->acquire(7));
        $this->assertFalse($registry->acquire(7), 'The third connection is over a limit of two.');
        $this->assertSame(2, $registry->active(7));
    }

    public function test_releasing_a_slot_allows_another_connection(): void
    {
        $this->write(array('max_sse_connections_per_user' => 1));

        $registry = $this->app->make(SseConnectionRegistry::class);

        $this->assertTrue($registry->acquire(9));
        $this->assertFalse($registry->acquire(9));

        $registry->release(9);

        $this->assertTrue($registry->acquire(9), 'A released slot must be reusable.');
    }

    public function test_zero_means_unlimited(): void
    {
        $this->write(array('max_sse_connections_per_user' => 0));

        $registry = $this->app->make(SseConnectionRegistry::class);

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($registry->acquire(3));
        }
    }

    public function test_anonymous_callers_are_not_counted(): void
    {
        $this->write(array('max_sse_connections_per_user' => 1));

        $registry = $this->app->make(SseConnectionRegistry::class);

        // Sharing one bucket across every unidentified caller would let one
        // client block all of them.
        $this->assertTrue($registry->acquire(0));
        $this->assertTrue($registry->acquire(0));
        $this->assertSame(0, $registry->active(0));
    }

    public function test_the_stream_endpoint_refuses_with_429_when_the_cap_is_reached(): void
    {
        $this->write(array('max_sse_connections_per_user' => 1));

        $tenant = Tenant::query()->firstOrCreate(
            array('id' => Tenant::DEFAULT_ID),
            array(
                'name' => 'Estate '.Tenant::DEFAULT_ID,
                'slug' => Tenant::DEFAULT_ID,
                'is_operator' => false,
            )
        );

        $user = User::factory()->create();
        $tenant->users()->syncWithoutDetaching(array($user->id => array('role' => 'member')));

        // Prime the counter as though this user already holds the one slot.
        Cache::put('sse-connections:'.$user->id, 1, SseConnectionRegistry::TTL);

        $this->actingAs($user)
            ->postJson('/api/v1/chat/stream', array(
                'messages' => array(array('role' => 'user', 'content' => 'hello')),
            ))
            ->assertStatus(429)
            ->assertJsonPath('code', 'sse_connection_limit');
    }

    /**
     * @param array<string, mixed> $values
     */
    private function write(array $values): void
    {
        $applied = $this->app->make(SettingsService::class)->write(
            $values,
            SettingsService::SURFACE_OPS
        );

        $this->assertSame(array_keys($values), $applied, 'The writer refused a key this test needs.');
    }
}
