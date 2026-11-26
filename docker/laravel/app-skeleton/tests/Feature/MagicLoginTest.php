<?php

namespace Tests\Feature;

use App\Mail\MagicLoginLink;
use App\Models\MagicLoginToken;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\Settings\UnimplementedSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Magic-link sign-in — Proposal 149 §103.
 *
 * The properties under test are the ones that make a passwordless endpoint safe
 * to expose, not the happy path alone:
 *
 *  - uniform answers, so the endpoint is not an account-enumeration oracle;
 *  - single use, enforced by the consume path rather than by the UI;
 *  - an expiry that actually expires;
 *  - at most one outstanding link per user;
 *  - a link that points at configured configuration, never at request input;
 *  - two-dimensional rate limiting on the request endpoint;
 *  - invisibility (404) when no estate has opted in.
 *
 * Tokens are read from the mailed link, because that is the only place the raw
 * value exists — the database holds a hash, which is the point.
 */
class MagicLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `EnsureFrontendRequestsAreStateful` only opens a session for a
        // request it recognises as first-party, and it decides that from the
        // Referer/Origin. The console always has one; without it every confirm
        // is a 419 "no session to sign in to", which is correct behaviour and
        // not what these tests are about.
        $this->withHeader('Referer', 'http://localhost');

        Mail::fake();
    }

    public function test_both_routes_are_invisible_when_no_estate_opted_in(): void
    {
        $this->postJson('/api/v1/magic-login/request', array('email' => 'nobody@example.test'))
            ->assertNotFound();

        $this->postJson('/api/v1/magic-login/confirm', array('token' => 'whatever'))
            ->assertNotFound();
    }

    public function test_an_unknown_account_gets_the_same_answer_and_no_mail(): void
    {
        $this->enable();

        $this->postJson('/api/v1/magic-login/request', array('email' => 'ghost@example.test'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('success', true);

        Mail::assertNothingSent();
        $this->assertSame(0, MagicLoginToken::query()->count());
    }

    public function test_a_known_account_is_emailed_a_hashed_link(): void
    {
        $this->enable();
        $user = $this->user('owner@example.test');

        // `identifier` is the WordPress controller's key and what the shared
        // console sends; `email` is the alias. This covers the one the client
        // actually uses.
        $this->postJson('/api/v1/magic-login/request', array('identifier' => 'owner@example.test'))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $token = $this->sentToken($user);

        $this->assertNotSame('', $token);

        $row = MagicLoginToken::query()->sole();

        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame(Tenant::DEFAULT_ID, (string) $row->tenant_id);
        // The raw token must not be recoverable from storage.
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertNull($row->consumed_at);
    }

    public function test_issuing_a_second_link_revokes_the_first(): void
    {
        $this->enable();
        $this->user('owner@example.test');

        $this->postJson('/api/v1/magic-login/request', array('email' => 'owner@example.test'))->assertOk();
        $first = $this->latestSentToken();

        Mail::fake();

        $this->postJson('/api/v1/magic-login/request', array('email' => 'owner@example.test'))->assertOk();
        $second = $this->latestSentToken();

        $this->assertSame(1, MagicLoginToken::query()->count(), 'At most one outstanding link per user.');

        $this->confirm($first)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'magic_link_invalid');

        $this->confirm($second)
            ->assertOk();
    }

    public function test_the_link_points_at_the_configured_origin_not_the_request(): void
    {
        $this->enable('https://console.example.test');
        $user = $this->user('owner@example.test');

        $this->withHeader('Origin', 'https://attacker.example')
            ->withHeader('Referer', 'https://attacker.example/')
            ->postJson('/api/v1/magic-login/request', array(
                'email' => 'owner@example.test',
                'origin' => 'https://attacker.example',
                'magic_login_app_origin' => 'https://attacker.example',
            ))
            ->assertOk();

        Mail::assertSent(MagicLoginLink::class, function (MagicLoginLink $mail) use ($user): bool {
            $this->assertStringStartsWith('https://console.example.test/#/magic-login?token=', $mail->link);
            $this->assertSame((string) $user->name, $mail->recipientName);

            // `Mail::fake()` records without rendering, so a broken template
            // would pass every other assertion here. Render it. The link has to
            // survive into the body — a mail that names the expiry but omits
            // the URL is worse than no mail.
            $body = $mail->render();
            $this->assertStringContainsString($mail->link, $body);
            $this->assertStringContainsString('15 minutes', $body);
            $this->assertStringContainsString('can only be used once', $body);

            return true;
        });
    }

    public function test_confirming_a_valid_token_starts_the_session(): void
    {
        $this->enable();
        $user = $this->user('owner@example.test');

        $this->postJson('/api/v1/magic-login/request', array('email' => 'owner@example.test'))->assertOk();
        $token = $this->sentToken($user);

        $this->confirm($token)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('login', 'owner@example.test');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(MagicLoginToken::query()->sole()->consumed_at);
    }

    public function test_a_token_can_only_be_used_once(): void
    {
        $this->enable();
        $user = $this->user('owner@example.test');

        $this->postJson('/api/v1/magic-login/request', array('email' => 'owner@example.test'))->assertOk();
        $token = $this->sentToken($user);

        $this->confirm($token)->assertOk();

        $this->confirm($token)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'magic_link_invalid');
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->enable();
        $user = $this->user('owner@example.test');

        $this->postJson('/api/v1/magic-login/request', array('email' => 'owner@example.test'))->assertOk();
        $token = $this->sentToken($user);

        MagicLoginToken::query()->update(array('expires_at' => now()->subMinute()));

        $this->confirm($token)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'magic_link_invalid');
    }

    public function test_unknown_and_expired_tokens_are_indistinguishable(): void
    {
        $this->enable();
        $user = $this->user('owner@example.test');

        $this->postJson('/api/v1/magic-login/request', array('email' => 'owner@example.test'))->assertOk();
        $token = $this->sentToken($user);

        MagicLoginToken::query()->update(array('expires_at' => now()->subMinute()));

        $expired = $this->confirm($token);
        $unknown = $this->confirm(str_repeat('a', 43));

        $this->assertSame($expired->status(), $unknown->status());
        $this->assertSame($expired->json(), $unknown->json());
    }

    public function test_a_failed_confirm_carries_a_top_level_message(): void
    {
        $this->enable();

        // The shared console reads `message`, not `error.message`.
        $this->confirm('does-not-exist')
            ->assertStatus(401)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'This sign-in link is invalid or has expired.')
            ->assertJsonPath('error.code', 'magic_link_invalid');
    }

    public function test_the_request_endpoint_is_rate_limited_per_identifier(): void
    {
        $this->enable();

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            $this->postJson('/api/v1/magic-login/request', array('email' => 'owner@example.test'))
                ->assertOk();
        }

        $this->postJson('/api/v1/magic-login/request', array('email' => 'owner@example.test'))
            ->assertStatus(429);
    }

    public function test_the_request_endpoint_is_rate_limited_per_address(): void
    {
        $this->enable();

        // Ten distinct identifiers from one address: the per-identifier limit
        // never trips, so only the address limit can stop the eleventh.
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->postJson('/api/v1/magic-login/request', array('email' => 'user'.$attempt.'@example.test'))
                ->assertOk();
        }

        $this->postJson('/api/v1/magic-login/request', array('email' => 'user11@example.test'))
            ->assertStatus(429);
    }

    public function test_the_confirm_endpoint_is_rate_limited_per_address(): void
    {
        $this->enable();

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->confirm('guess'.$attempt)
                ->assertStatus(401);
        }

        $this->confirm('guess11')
            ->assertStatus(429);
    }

    public function test_a_mail_transport_failure_leaves_no_live_token(): void
    {
        $this->enable();
        $this->user('owner@example.test');

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/v1/magic-login/request', array('email' => 'owner@example.test'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, MagicLoginToken::query()->count(), 'A link nobody received must not stay live.');
    }

    public function test_the_feature_flag_is_published(): void
    {
        $this->getJson('/api/v1/features')->assertOk()->assertJsonPath('data.magic_login', false);

        $this->enable();

        $this->getJson('/api/v1/features')->assertOk()->assertJsonPath('data.magic_login', true);
    }

    public function test_the_two_settings_are_no_longer_inert(): void
    {
        $this->assertSame(
            array(),
            array_values(array_intersect(
                array('enable_magic_login', 'magic_login_app_origin'),
                UnimplementedSettings::keys()
            )),
            'Slice I4 makes these keys real, so they must not be listed as inert.'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Post a confirm from a first-party origin (the header is set in setUp).
     */
    private function confirm(string $token): TestResponse
    {
        return $this->postJson('/api/v1/magic-login/confirm', array('token' => $token));
    }

    private function enable(string $origin = ''): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        app(SettingsService::class)->write(
            array('enable_magic_login' => true, 'magic_login_app_origin' => $origin),
            SettingsService::SURFACE_ADMIN
        );

        TenantContext::forget();
    }

    private function user(string $email): User
    {
        return User::factory()->create(array('email' => $email));
    }

    /**
     * The token from the link mailed to a user.
     */
    private function sentToken(User $user): string
    {
        $token = '';

        Mail::assertSent(MagicLoginLink::class, function (MagicLoginLink $mail) use ($user, &$token): bool {
            if ($mail->recipientName !== (string) $user->name) {
                return false;
            }

            if (1 === preg_match('/token=([A-Za-z0-9]+)$/', $mail->link, $matches)) {
                $token = $matches[1];
            }

            return true;
        });

        return $token;
    }

    private function latestSentToken(): string
    {
        $token = '';

        Mail::assertSent(MagicLoginLink::class, function (MagicLoginLink $mail) use (&$token): bool {
            if (1 === preg_match('/token=([A-Za-z0-9]+)$/', $mail->link, $matches)) {
                $token = $matches[1];
            }

            return true;
        });

        return $token;
    }
}
