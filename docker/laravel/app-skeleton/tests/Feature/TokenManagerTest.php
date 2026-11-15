<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Tests\TestCase;

/**
 * 090-B — the token manager.
 *
 * The security assertions matter more than the shape ones: the token hash must
 * never leave the endpoint, revocation must actually stop the token working,
 * and the route must not allow revoking another assistant's credential.
 */
class TokenManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();

        Sanctum::actingAs($this->admin);

        $this->assistant = Assistant::query()->create([
            'title' => 'Token Agent',
            'slug' => 'token-agent',
            'status' => 'publish',
            'tenant_id' => Assistant::DEFAULT_TENANT,
        ]);
    }

    /**
     * Mint a credential the way the runtime does.
     */
    private function issue(): string
    {
        $credential = $this->app->make(AuthProviderInterface::class)
            ->issueCredential($this->assistant->id);

        return (string) $credential->token;
    }

    private function overview(): array
    {
        $response = $this->getJson('/api/v1/token-manager/overview');

        $response->assertOk()->assertJsonPath('success', true);

        return (array) $response->json('data');
    }

    public function test_it_requires_manage_options(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'subscriber']));

        $this->getJson('/api/v1/token-manager/overview')->assertForbidden();
    }

    public function test_it_lists_an_issued_credential_without_leaking_the_hash(): void
    {
        $this->issue();

        $overview = $this->overview();
        $credentials = $overview['credentials'];

        $this->assertCount(1, $credentials);

        $credential = $credentials[0];

        $this->assertSame(
            [
                'id',
                'status',
                'assistant_id',
                'assistant_title',
                'created_at',
                'created_by',
                'created_by_name',
                'revoked_at',
                'revoked_by',
                'expires_at',
            ],
            array_keys($credential)
        );

        $this->assertSame('active', $credential['status']);
        $this->assertSame($this->assistant->id, $credential['assistant_id']);
        $this->assertSame('Token Agent', $credential['assistant_title']);
        $this->assertSame($this->admin->id, $credential['created_by']);
        $this->assertSame($this->admin->name, $credential['created_by_name']);
        $this->assertSame('', $credential['revoked_at']);
        $this->assertSame('', $credential['expires_at']);

        // The hash is in the table but must never appear in the payload.
        $hash = (string) DB::table('personal_access_tokens')->value('token');

        $this->assertNotSame('', $hash);
        $this->assertStringNotContainsString($hash, json_encode($overview));
    }

    public function test_the_stats_count_totals_and_assistants(): void
    {
        $this->issue();
        $this->issue();

        $stats = $this->overview()['stats'];

        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['active']);
        $this->assertSame(0, $stats['revoked']);
        $this->assertSame(1, $stats['assistants']);
    }

    public function test_an_expired_credential_is_reported_as_revoked(): void
    {
        $this->issue();

        DB::table('personal_access_tokens')->update([
            'expires_at' => now()->subDay()->toDateTimeString(),
        ]);

        $overview = $this->overview();

        $this->assertSame('revoked', $overview['credentials'][0]['status']);
        $this->assertSame(1, $overview['stats']['revoked']);
        $this->assertSame(0, $overview['stats']['active']);
        $this->assertNotSame('', $overview['credentials'][0]['revoked_at']);
    }

    public function test_tokens_from_other_features_are_not_listed(): void
    {
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $this->admin->id,
            'name' => 'some-other-integration',
            'token' => hash('sha256', 'unrelated'),
            'abilities' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertCount(0, $this->overview()['credentials']);
    }

    public function test_revoking_deletes_the_token_and_it_stops_resolving(): void
    {
        $plain = $this->issue();

        $id = (string) $this->overview()['credentials'][0]['id'];

        $this->postJson("/api/v1/token-manager/credentials/{$this->assistant->id}/{$id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        // The bearer token no longer authenticates anywhere.
        $this->assertNull(
            \Laravel\Sanctum\PersonalAccessToken::findToken($plain),
            'A deleted token must not resolve.'
        );
    }

    public function test_delete_behaves_the_same_as_revoke(): void
    {
        $this->issue();

        $id = (string) $this->overview()['credentials'][0]['id'];

        $this->postJson("/api/v1/token-manager/credentials/{$this->assistant->id}/{$id}/delete")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_a_credential_of_another_assistant_cannot_be_revoked(): void
    {
        $other = Assistant::query()->create([
            'title' => 'Other Agent',
            'slug' => 'other-agent',
            'status' => 'publish',
            'tenant_id' => Assistant::DEFAULT_TENANT,
        ]);

        $this->issue();

        $id = (string) $this->overview()['credentials'][0]['id'];

        $this->postJson("/api/v1/token-manager/credentials/{$other->id}/{$id}/revoke")
            ->assertNotFound()
            ->assertJsonPath('code', 'credential_not_found');

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    public function test_an_unknown_credential_is_not_found(): void
    {
        $this->postJson("/api/v1/token-manager/credentials/{$this->assistant->id}/999999/revoke")
            ->assertNotFound()
            ->assertJsonPath('code', 'credential_not_found');
    }

    public function test_restrictions_are_empty_and_lift_is_not_found(): void
    {
        $this->assertSame([], $this->overview()['restrictions']);

        $this->postJson('/api/v1/token-manager/restrictions/1/lift')
            ->assertNotFound()
            ->assertJsonPath('code', 'restriction_not_found');
    }
}
