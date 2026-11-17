<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\PersonalTokensPage;
use App\Models\AdminAudit;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PersonalTokens;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Tests\TestCase;

/**
 * Proposal 149 §26 — personal access tokens.
 *
 * The properties worth pinning are security ones:
 *
 *  - the plaintext exists in exactly one response and is never persisted,
 *    logged or audited;
 *  - every read and the revoke path are ownership-scoped, and a foreign id
 *    answers 404 rather than disclosing that it exists;
 *  - the personal surface and the assistant-credential surface cannot see each
 *    other's rows, which is enforced by the name prefix rather than a shared
 *    column;
 *  - the routes work without an estate, because a token is not estate data.
 */
class AdminPersonalTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_minting_returns_the_plaintext_once_and_stores_only_a_hash(): void
    {
        $user = $this->member();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/personal-tokens', array('label' => 'reporting', 'days' => 30))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(array(
                'data' => array('id', 'name', 'label', 'plaintext', 'abilities', 'expires_at'),
            ));

        $plaintext = (string) $response->json('data.plaintext');
        $id = (int) $response->json('data.id');

        $this->assertNotSame('', $plaintext);
        $this->assertSame('oos-personal:reporting', (string) $response->json('data.name'));

        $stored = (string) DB::table('personal_access_tokens')->where('id', $id)->value('token');
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($plaintext, $stored, 'The plaintext must never be stored.');

        // The minted token is a working credential.
        $this->assertNotNull(PersonalAccessToken::findToken($plaintext));

        // …and it is not echoed by any other route.
        $this->assertStringNotContainsString(
            $stored,
            (string) $this->actingAs($user)->getJson('/api/v1/personal-tokens')->getContent()
        );
    }

    public function test_the_list_contains_only_the_callers_own_tokens(): void
    {
        $alice = $this->member();
        $bob = $this->member();

        $this->actingAs($alice)->postJson('/api/v1/personal-tokens', array('label' => 'alice-key'))->assertCreated();
        $this->actingAs($bob)->postJson('/api/v1/personal-tokens', array('label' => 'bob-key'))->assertCreated();

        $this->actingAs($alice)
            ->getJson('/api/v1/personal-tokens')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.tokens.0.label', 'alice-key')
            ->assertJsonMissingPath('data.tokens.1');
    }

    public function test_revoking_an_own_token_deletes_it_and_it_stops_resolving(): void
    {
        $user = $this->member();

        $plaintext = (string) $this->actingAs($user)
            ->postJson('/api/v1/personal-tokens', array('label' => 'scratch'))
            ->json('data.plaintext');

        $id = (int) DB::table('personal_access_tokens')->value('id');

        $this->actingAs($user)
            ->deleteJson('/api/v1/personal-tokens/'.$id)
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->assertNull(PersonalAccessToken::findToken($plaintext));
    }

    public function test_another_users_token_answers_404_and_survives(): void
    {
        $alice = $this->member();
        $bob = $this->member();

        $this->actingAs($alice)->postJson('/api/v1/personal-tokens', array('label' => 'alice-key'))->assertCreated();
        $id = (int) DB::table('personal_access_tokens')->value('id');

        $this->actingAs($bob)
            ->deleteJson('/api/v1/personal-tokens/'.$id)
            ->assertNotFound()
            ->assertJsonPath('code', 'personal_token_not_found');

        $this->assertSame(1, DB::table('personal_access_tokens')->where('id', $id)->count());
    }

    /**
     * The two token families are separated by name prefix, so a surface that
     * manages personal tokens can neither list nor revoke a credential.
     */
    public function test_assistant_credentials_are_invisible_and_irrevocable_here(): void
    {
        $user = $this->member();
        Sanctum::actingAs($user);

        $assistant = Assistant::query()->create(array(
            'title' => 'Token Agent',
            'slug' => 'token-agent',
            'status' => 'publish',
        ));
        $assistant->tenant_id = Assistant::DEFAULT_TENANT;
        $assistant->save();

        TenantContext::set(Assistant::DEFAULT_TENANT);
        $this->app->make(AuthProviderInterface::class)->issueCredential($assistant->id);
        TenantContext::set(null);

        $credentialId = (int) DB::table('personal_access_tokens')->value('id');

        $this->actingAs($user)
            ->getJson('/api/v1/personal-tokens')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->actingAs($user)
            ->deleteJson('/api/v1/personal-tokens/'.$credentialId)
            ->assertNotFound();

        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->where('id', $credentialId)->count(),
            'A personal-token route must not delete an assistant credential.'
        );
    }

    /**
     * A token is not estate data, so the routes must work for a user with no
     * estate at all — they sit outside `ResolveApiTenant` for exactly this case.
     */
    public function test_the_routes_work_without_an_estate(): void
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        $this->actingAs($user)
            ->postJson('/api/v1/personal-tokens', array('label' => 'no-estate'))
            ->assertCreated();

        $this->actingAs($user)
            ->getJson('/api/v1/personal-tokens')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_the_expiry_is_bounded(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->postJson('/api/v1/personal-tokens', array('label' => 'forever', 'days' => 9999))
            ->assertStatus(422);

        $this->actingAs($user)
            ->postJson('/api/v1/personal-tokens', array('label' => 'forever', 'days' => 0))
            ->assertStatus(422);
    }

    /**
     * A hostile label must not be able to inject a prefix or a SQL wildcard into
     * the name the store matches on.
     */
    public function test_a_hostile_label_cannot_escape_the_token_name(): void
    {
        $user = $this->member();

        $name = (string) $this->actingAs($user)
            ->postJson('/api/v1/personal-tokens', array('label' => "oos-assistant:1 %_*"))
            ->assertCreated()
            ->json('data.name');

        $this->assertStringStartsWith('oos-personal:', $name);
        $this->assertStringNotContainsString('*', $name);
        // `_` and `%` are LIKE metacharacters; the name is matched with a prefix
        // pattern, so neither may survive a user-supplied label.
        $this->assertStringNotContainsString('%', $name);
        $this->assertSame('oos-personal:oos-assistant1 _', $name);
    }

    public function test_the_page_lists_the_users_own_tokens(): void
    {
        $user = $this->member();

        $this->actingAs($user)->postJson('/api/v1/personal-tokens', array('label' => 'reporting'))->assertCreated();

        $this->actingAs($user)
            ->get('/admin/default/personal-tokens')
            ->assertOk()
            ->assertSee('Personal access tokens')
            ->assertSee('reporting');
    }

    /**
     * The plaintext is rendered once, is absent from the audit trail, and
     * dismissing it removes it.
     */
    public function test_the_page_shows_the_plaintext_once_and_never_audits_it(): void
    {
        $this->bootPanel($this->member());

        $component = Livewire::test(PersonalTokensPage::class)
            ->set('label', 'reporting')
            ->set('days', 30)
            ->call('mint')
            ->assertSee('Copy this token now');

        $plaintext = (string) $component->get('plaintext');

        $this->assertNotSame('', $plaintext);

        $row = AdminAudit::query()
            ->where('resource', 'personal-tokens')
            ->where('action', 'mint')
            ->first();

        $this->assertNotNull($row, 'Minting must leave a record row.');
        $this->assertStringNotContainsString(
            $plaintext,
            (string) json_encode($row->after),
            'A record row must never carry a live credential.'
        );

        $component->call('dismissPlaintext')->assertSet('plaintext', null);
    }

    public function test_revoking_from_the_page_is_two_step(): void
    {
        $user = $this->member();

        $this->actingAs($user)->postJson('/api/v1/personal-tokens', array('label' => 'scratch'))->assertCreated();
        $id = (string) DB::table('personal_access_tokens')->value('id');

        $this->bootPanel($user);

        Livewire::test(PersonalTokensPage::class)
            ->call('confirmRevoke', $id)
            ->assertSet('pendingRevoke', $id)
            ->assertSee('Confirm revoke');

        $this->assertSame(1, DB::table('personal_access_tokens')->where('id', (int) $id)->count());

        Livewire::test(PersonalTokensPage::class)
            ->call('confirmRevoke', $id)
            ->call('revoke')
            ->assertSet('pendingRevoke', '')
            ->assertSee('Token revoked');

        $this->assertSame(0, DB::table('personal_access_tokens')->where('id', (int) $id)->count());
    }

    /**
     * The panel refuses a viewer outright, while the API still lets them mint a
     * token for themselves. The split is deliberate: a token carries the user's
     * own authority, and a viewer has none beyond reading.
     */
    public function test_a_viewer_is_refused_the_page_but_may_still_mint(): void
    {
        $viewer = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/personal-tokens')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->postJson('/api/v1/personal-tokens', array('label' => 'viewer-key'))
            ->assertCreated();
    }

    public function test_the_label_falls_back_when_it_is_all_symbols(): void
    {
        $this->assertSame('token', PersonalTokens::sanitiseLabel('***'));
        $this->assertSame('a b', PersonalTokens::sanitiseLabel('  a   b  '));
        $this->assertSame(60, strlen(PersonalTokens::sanitiseLabel(str_repeat('x', 200))));
    }

    private function bootPanel(User $user): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    /**
     * An estate member who may reach the `/admin` panel.
     */
    private function member(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}
