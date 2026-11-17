<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\EstateCredentialsPage;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\EstateCredentials;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Tests\TestCase;

/**
 * Proposal 149 §24 — estate credentials on `/admin`.
 *
 * The panel presentation is a SUBSET of the operator surface: list and revoke,
 * no issue/rotate. These tests exist mostly for the security properties, which
 * are the same ones `/api/v1/token-manager` carries, now reachable from a second
 * surface:
 *
 *  - the panel tenant, not ambient request state, decides what is listed;
 *  - a credential belonging to another estate can never be revoked, even with a
 *    tampered armed state;
 *  - revocation is two-step, so a single misclick cannot delete a live token;
 *  - the row shape is the console's, because both read one class.
 */
class AdminEstateCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_the_estates_credentials(): void
    {
        $assistant = $this->assistantIn(Assistant::DEFAULT_TENANT, 'Estate Agent');
        $this->issue($assistant);

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/credentials')
            ->assertOk()
            ->assertSee('Estate credentials')
            ->assertSee('Estate Agent')
            ->assertSee('active')
            ->assertSee('Scoped to this estate only');
    }

    /**
     * The listing is scoped by the panel tenant, not by `TenantContext`.
     */
    public function test_the_page_does_not_list_another_estates_credentials(): void
    {
        $foreign = $this->assistantIn('estate-b', 'Foreign Agent');
        $this->issue($foreign);

        $this->assertCount(1, DB::table('personal_access_tokens')->get());

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/credentials')
            ->assertOk()
            ->assertDontSee('Foreign Agent');
    }

    public function test_revoking_is_two_step_and_deletes_on_confirm(): void
    {
        $assistant = $this->assistantIn(Assistant::DEFAULT_TENANT, 'Estate Agent');
        $this->issue($assistant);

        $credentialId = (string) DB::table('personal_access_tokens')->value('id');

        $this->bootPanel();

        Livewire::test(EstateCredentialsPage::class)
            ->call('confirmRevoke', $credentialId, (int) $assistant->id)
            ->assertSet('pendingRevoke', $credentialId)
            ->assertSet('pendingAssistant', (int) $assistant->id)
            ->assertSee('Confirm revoke');

        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->where('id', (int) $credentialId)->count(),
            'Arming must not delete anything.'
        );

        Livewire::test(EstateCredentialsPage::class)
            ->call('confirmRevoke', $credentialId, (int) $assistant->id)
            ->call('revoke')
            ->assertSet('pendingRevoke', '')
            ->assertSee('Credential revoked');

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')->where('id', (int) $credentialId)->count()
        );
    }

    public function test_revoking_with_nothing_armed_is_a_no_op(): void
    {
        $assistant = $this->assistantIn(Assistant::DEFAULT_TENANT, 'Estate Agent');
        $this->issue($assistant);

        $this->bootPanel();

        Livewire::test(EstateCredentialsPage::class)
            ->call('revoke')
            ->assertSet('pendingRevoke', '');

        $this->assertSame(1, DB::table('personal_access_tokens')->count());
    }

    /**
     * A tampered armed state must not reach across estates: the estate is
     * re-resolved from the panel tenant at revoke time, not trusted from state.
     */
    public function test_a_credential_from_another_estate_cannot_be_revoked(): void
    {
        $foreign = $this->assistantIn('estate-b', 'Foreign Agent');
        $this->issue($foreign);

        $credentialId = (string) DB::table('personal_access_tokens')->value('id');

        $this->bootPanel();

        Livewire::test(EstateCredentialsPage::class)
            ->call('confirmRevoke', $credentialId, (int) $foreign->id)
            ->call('revoke')
            ->assertSee('was not found in this estate');

        $this->assertSame(
            1,
            DB::table('personal_access_tokens')->where('id', (int) $credentialId)->count(),
            'A cross-estate refusal must not delete the row.'
        );
    }

    /**
     * `credentials.assistant` (rotation) is the operator surface and declares
     * `writer: ops`. This page must never grow that control.
     */
    public function test_the_page_offers_no_issuing_action(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/credentials')
            ->assertOk()
            ->assertDontSee('Issue credential')
            ->assertDontSee('Rotate');
    }

    /**
     * Both presentations read `EstateCredentials`, so the row shape the console
     * consumes is the row shape the panel renders.
     */
    public function test_the_row_shape_is_the_console_contract(): void
    {
        $assistant = $this->assistantIn(Assistant::DEFAULT_TENANT, 'Estate Agent');
        $this->issue($assistant);

        $overview = EstateCredentials::overview(Assistant::DEFAULT_TENANT);

        $this->assertSame(
            array('total' => 1, 'active' => 1, 'revoked' => 0, 'assistants' => 1),
            $overview['stats']
        );

        $this->assertSame(
            array(
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
            ),
            array_keys($overview['credentials'][0])
        );

        $this->assertSame(array(), $overview['restrictions']);
    }

    /**
     * An unknown estate reads nothing rather than falling back to the default
     * one. This is the fail-closed path the page takes when the panel has no
     * tenant.
     */
    public function test_an_empty_estate_reads_nothing(): void
    {
        $assistant = $this->assistantIn(Assistant::DEFAULT_TENANT, 'Estate Agent');
        $this->issue($assistant);

        $overview = EstateCredentials::overview('');

        $this->assertSame(0, $overview['stats']['total']);
        $this->assertSame(array(), $overview['credentials']);
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/credentials')
            ->assertForbidden();
    }

    private function bootPanel(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    /**
     * An assistant in an estate that may not exist yet in the fixtures.
     */
    private function assistantIn(string $tenantId, string $title): Assistant
    {
        if (Assistant::DEFAULT_TENANT !== $tenantId) {
            Tenant::query()->firstOrCreate(
                array('id' => $tenantId),
                array('name' => ucfirst($tenantId), 'slug' => $tenantId, 'is_operator' => false)
            );
        }

        $assistant = Assistant::query()->create(array(
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'status' => 'publish',
        ));

        // `tenant_id` is not fillable by design; assigning it after create keeps
        // this fixture honest rather than silently landing in the default estate.
        $assistant->tenant_id = $tenantId;
        $assistant->save();

        return $assistant;
    }

    /**
     * Mint a credential the way the runtime does, so the token name follows the
     * `oos-assistant-{id}` pattern the store resolves.
     */
    private function issue(Assistant $assistant): void
    {
        // `issueCredential()` records the issuing user as the token's
        // `tokenable`, so it refuses to run unauthenticated.
        if (null === auth()->user()) {
            $this->actingAs(User::factory()->admin()->create());
        }

        TenantContext::set((string) $assistant->tenant_id);

        $this->app->make(AuthProviderInterface::class)->issueCredential($assistant->id);

        TenantContext::set(null);
    }
}
