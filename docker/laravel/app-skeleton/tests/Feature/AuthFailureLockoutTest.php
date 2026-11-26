<?php

namespace Tests\Feature;

use App\Support\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposal 149 §185 — the failed-authentication lockout.
 *
 * `enable_auth_rate_limiting`, `auth_rate_limit_threshold` and
 * `auth_rate_limit_window` were writable on `/ops/settings` and read by nothing.
 * They are a **lockout**, not a request throttle: the field counts failed
 * attempts per address. The existing `throttle:` limiters are a different
 * control that already worked, so this suite drives real 401s through the real
 * stack rather than asserting a number was read.
 *
 * The counter is also cleared by a successful response, which is the trade-off
 * the middleware documents: behind a shared NAT a legitimate success resets an
 * attacker's count. The test pins the behaviour so it cannot drift silently.
 */
final class AuthFailureLockoutTest extends TestCase
{
    use RefreshDatabase;

    /** A route in the `api` group that requires authentication. */
    private const PROTECTED = '/api/v1/chat-transcripts';

    protected function setUp(): void
    {
        parent::setUp();

        // The counter must survive between calls inside one test; the array store
        // is the only driver that does without a cache table.
        config(array('cache.default' => 'array'));
    }

    public function test_failures_below_the_threshold_are_just_401(): void
    {
        $this->write(array(
            'enable_auth_rate_limiting' => '1',
            'auth_rate_limit_threshold' => 3,
            'auth_rate_limit_window' => 300,
        ));

        $this->getJson(self::PROTECTED)->assertStatus(401);
        $this->getJson(self::PROTECTED)->assertStatus(401);
    }

    public function test_reaching_the_threshold_blocks_with_429(): void
    {
        $this->write(array(
            'enable_auth_rate_limiting' => '1',
            'auth_rate_limit_threshold' => 3,
            'auth_rate_limit_window' => 300,
        ));

        for ($i = 0; $i < 3; $i++) {
            $this->getJson(self::PROTECTED)->assertStatus(401);
        }

        $blocked = $this->getJson(self::PROTECTED)->assertStatus(429);

        $this->assertSame('300', $blocked->headers->get('Retry-After'));
        $this->assertSame('auth_rate_limited', $blocked->json('code'));
    }

    public function test_a_successful_response_clears_the_counter(): void
    {
        $this->write(array(
            'enable_auth_rate_limiting' => '1',
            'auth_rate_limit_threshold' => 3,
            'auth_rate_limit_window' => 300,
        ));

        $this->getJson(self::PROTECTED)->assertStatus(401);
        $this->getJson(self::PROTECTED)->assertStatus(401);

        // A public endpoint answers 200: the address held a working request.
        $this->getJson('/api/v1/features')->assertOk();

        // Two more failures are below the threshold again.
        $this->getJson(self::PROTECTED)->assertStatus(401);
        $this->getJson(self::PROTECTED)->assertStatus(401);
    }

    public function test_disabling_the_control_never_blocks(): void
    {
        $this->write(array(
            'enable_auth_rate_limiting' => '0',
            'auth_rate_limit_threshold' => 3,
            'auth_rate_limit_window' => 300,
        ));

        for ($i = 0; $i < 6; $i++) {
            $this->getJson(self::PROTECTED)->assertStatus(401);
        }
    }

    public function test_a_non_401_response_does_not_count(): void
    {
        $this->write(array(
            'enable_auth_rate_limiting' => '1',
            'auth_rate_limit_threshold' => 3,
            'auth_rate_limit_window' => 300,
        ));

        // Session login answers 419 without a first-party session. That is not an
        // authentication failure, and counting it would let any caller fill the
        // counter without presenting a credential.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/session/login', array())->assertStatus(419);
        }

        $this->getJson(self::PROTECTED)->assertStatus(401);
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

        $this->assertSame(
            array_keys($values),
            $applied,
            'The writer refused a key this test needs; the assertion would pass for the wrong reason.'
        );
    }
}
