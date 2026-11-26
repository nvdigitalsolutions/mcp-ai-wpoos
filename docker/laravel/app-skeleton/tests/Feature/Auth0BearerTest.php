<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Auth0 bearer authentication — Proposal 149 §102.
 *
 * Every test mints a real RS256 token against a locally generated keypair, so
 * the signature path is exercised rather than mocked: a stubbed verifier would
 * prove the wiring and nothing about the thing being wired. Only the JWKS fetch
 * is faked, which is the one step that would otherwise need the network.
 *
 * The refusals matter as much as the acceptances. Each one is a known attack —
 * `alg: none`, algorithm confusion, a token minted for another API, an expired
 * token, a subject nobody linked — and a verifier that accepts the happy path
 * while accepting one of those is worse than no verifier, because it looks like
 * authentication.
 */
class Auth0BearerTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'estate.auth0.test';

    private const AUDIENCE = 'https://api.estate.test/';

    private const KID = 'test-kid';

    /** @var \OpenSSLAsymmetricKey */
    private $signingKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signingKey = $this->generateKey();

        Cache::forget('nvoos:auth0:jwks:'.sha1(self::DOMAIN));

        $this->configureAuth0();
        $this->fakeJwks($this->signingKey);
    }

    public function test_a_valid_token_authenticates_a_linked_user(): void
    {
        $user = $this->linkedUser('auth0|abc');

        $this->bearer($this->token())
            ->getJson('/api/v1/chat-transcripts')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_capability_gate_still_applies_to_a_bearer_user(): void
    {
        $this->linkedUser('auth0|abc');

        // `/settings` sits behind `can:manage_options`, which is WordPress's
        // site role. A linked subject is not a licence to skip it.
        $this->bearer($this->token())
            ->getJson('/api/v1/settings')
            ->assertOk();
    }

    public function test_a_subscriber_is_still_refused_by_the_capability_gate(): void
    {
        $this->linkedUser('auth0|abc', 'subscriber');

        $this->bearer($this->token())
            ->getJson('/api/v1/settings')
            ->assertForbidden();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->linkedUser('auth0|abc');

        $this->bearer($this->token(array('exp' => time() - 5)))
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(401)
            ->assertJsonPath('error', 'auth0_token_expired');
    }

    public function test_a_token_for_another_audience_is_refused(): void
    {
        $this->linkedUser('auth0|abc');

        $this->bearer($this->token(array('aud' => 'https://someone-else.example/')))
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(403)
            ->assertJsonPath('error', 'auth0_audience_mismatch');
    }

    public function test_a_token_missing_a_required_scope_is_refused(): void
    {
        $this->linkedUser('auth0|abc');

        $this->bearer($this->token(array('scope' => 'read:mcp')))
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(403)
            ->assertJsonPath('error', 'auth0_scope_missing')
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'write:mcp'));
    }

    public function test_a_token_signed_by_another_key_is_refused(): void
    {
        $this->linkedUser('auth0|abc');

        $this->bearer($this->token(array(), $this->generateKey()))
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(401)
            ->assertJsonPath('error', 'auth0_token_invalid');
    }

    public function test_an_unsigned_token_is_refused(): void
    {
        $this->linkedUser('auth0|abc');

        $unsigned = $this->b64(json_encode(array('alg' => 'none', 'typ' => 'JWT', 'kid' => self::KID)))
            .'.'.$this->b64(json_encode($this->claims()))
            .'.';

        $this->bearer($unsigned)
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(401)
            ->assertJsonPath('error', 'auth0_token_invalid');
    }

    public function test_an_hs256_token_is_refused_rather_than_verified_with_the_public_key(): void
    {
        $this->linkedUser('auth0|abc');

        // The classic algorithm-confusion forgery: sign with the public key as
        // if it were an HMAC secret and hope the verifier honours `alg`.
        $header = $this->b64(json_encode(array('alg' => 'HS256', 'typ' => 'JWT', 'kid' => self::KID)));
        $body = $this->b64(json_encode($this->claims()));
        $signature = hash_hmac('sha256', $header.'.'.$body, 'anything', true);

        $this->bearer($header.'.'.$body.'.'.$this->b64($signature))
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(401)
            ->assertJsonPath('error', 'auth0_token_invalid');
    }

    public function test_an_unmapped_subject_is_refused_not_provisioned(): void
    {
        $this->linkedUser('auth0|abc');

        $this->bearer($this->token(array('sub' => 'auth0|somebody-else')))
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(401)
            ->assertJsonPath('error', 'auth0_subject_unmapped');

        // Nothing was created for the unknown subject.
        $this->assertDatabaseMissing('users', array('auth0_subject' => 'auth0|somebody-else'));
    }

    public function test_a_token_without_a_tenant_selector_is_refused(): void
    {
        $this->linkedUser('auth0|abc');

        $this->withHeader('Authorization', 'Bearer '.$this->token())
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(401)
            ->assertJsonPath('error', 'auth0_tenant_required');
    }

    public function test_an_estate_without_auth0_configured_refuses_the_token(): void
    {
        $this->linkedUser('auth0|abc');

        TenantContext::set(Tenant::DEFAULT_ID);
        app(SettingsService::class)->write(
            array('auth0_domain' => '', 'auth0_audience' => '', 'auth0_required_scope' => ''),
            SettingsService::SURFACE_ADMIN
        );
        TenantContext::forget();

        $this->bearer($this->token())
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(401)
            ->assertJsonPath('error', 'auth0_not_configured');
    }

    public function test_an_unreachable_jwks_fails_closed(): void
    {
        $this->linkedUser('auth0|abc');

        // A domain with no stubbed response, and stray requests refused: the
        // fetch throws, which is what an Auth0 outage looks like.
        $this->configureAuth0('unreachable.auth0.test');
        Http::preventStrayRequests();

        $this->bearer($this->token())
            ->getJson('/api/v1/chat-transcripts')
            ->assertStatus(503)
            ->assertJsonPath('error', 'auth0_jwks_unavailable');
    }

    public function test_the_jwks_is_fetched_once_for_two_requests(): void
    {
        $this->linkedUser('auth0|abc');

        $this->bearer($this->token())->getJson('/api/v1/chat-transcripts')->assertOk();
        $this->bearer($this->token())->getJson('/api/v1/chat-transcripts')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_a_linked_user_who_is_not_a_member_of_the_selected_estate_is_refused(): void
    {
        // The Auth0 configuration belongs to `default`, so the token verifies —
        // and then the membership rule refuses, in the one place that owns it.
        $user = User::factory()->admin()->withoutEstate()->create();
        $this->link($user, 'auth0|abc');

        $this->assertSame(0, $user->tenants()->count(), 'precondition: the user holds no membership');

        $this->bearer($this->token())
            ->getJson('/api/v1/chat-transcripts')
            ->assertNotFound();
    }

    public function test_a_sanctum_token_still_authenticates(): void
    {
        $user = $this->linkedUser('auth0|abc');
        $plaintext = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$plaintext)
            ->getJson('/api/v1/chat-transcripts')
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_three_settings_are_no_longer_inert(): void
    {
        $this->assertSame(
            array(),
            array_values(array_intersect(
                array('auth0_domain', 'auth0_audience', 'auth0_required_scope'),
                \App\Support\Settings\UnimplementedSettings::keys()
            )),
            'Slice I3 makes these keys real, so they must not be listed as inert.'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function linkedUser(string $subject, string $role = 'admin'): User
    {
        $user = 'admin' === $role
            ? User::factory()->admin()->create()
            : User::factory()->create();

        $this->link($user, $subject);

        return $user;
    }

    /**
     * Attach an Auth0 subject the way `nvoos:auth0-link` does.
     *
     * Deliberately after creation rather than through `create([...])`:
     * `Factory::create($attributes)` re-enters `state()`, which builds a new
     * factory instance and drops a fluent customisation like `withoutEstate()`.
     * A fixture that passed the subject as an attribute silently rejoined the
     * default estate, which is the opposite of what the test asserted.
     */
    private function link(User $user, string $subject): void
    {
        $user->forceFill(array('auth0_subject' => $subject))->save();
    }

    private function bearer(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Tenant-Id', Tenant::DEFAULT_ID);
    }

    private function configureAuth0(string $domain = self::DOMAIN): void
    {
        // Written through the same service the settings form uses, so this also
        // proves the keys became writable again when slice I3 removed them from
        // UnimplementedSettings.
        TenantContext::set(Tenant::DEFAULT_ID);

        app(SettingsService::class)->write(
            array(
                'auth0_domain' => $domain,
                'auth0_audience' => self::AUDIENCE,
                'auth0_required_scope' => 'read:mcp write:mcp',
            ),
            SettingsService::SURFACE_ADMIN
        );

        TenantContext::forget();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function token(array $overrides = array(), $key = null): string
    {
        $header = $this->b64(json_encode(array('alg' => 'RS256', 'typ' => 'JWT', 'kid' => self::KID)));
        $body = $this->b64(json_encode($this->claims($overrides)));

        $signed = $header.'.'.$body;
        $signature = '';

        openssl_sign($signed, $signature, $key ?? $this->signingKey, OPENSSL_ALGO_SHA256);

        return $signed.'.'.$this->b64($signature);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function claims(array $overrides = array()): array
    {
        return array_merge(array(
            'iss' => 'https://'.self::DOMAIN.'/',
            'aud' => self::AUDIENCE,
            'sub' => 'auth0|abc',
            'scope' => 'read:mcp write:mcp',
            'iat' => time() - 10,
            'exp' => time() + 3600,
        ), $overrides);
    }

    private function fakeJwks($key): void
    {
        $details = openssl_pkey_get_details($key);

        Http::fake(array(
            '*'.self::DOMAIN.'/*' => Http::response(array(
                'keys' => array(
                    array(
                        'kty' => 'RSA',
                        'kid' => self::KID,
                        'use' => 'sig',
                        'alg' => 'RS256',
                        'n' => $this->b64($details['rsa']['n']),
                        'e' => $this->b64($details['rsa']['e']),
                    ),
                ),
            ), 200),
        ));
    }

    private function generateKey()
    {
        $key = openssl_pkey_new(array(
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ));

        $this->assertNotFalse($key, 'Could not generate a test RSA key.');

        return $key;
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
