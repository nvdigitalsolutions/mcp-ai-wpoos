<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Assistants\AssistantResource;
use App\Filament\Admin\Resources\Assistants\Pages\ManageAssistants;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AssistantPolicy;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149, Wave 2 — the second tenant-scoped `/admin` resource.
 *
 * `/admin/assistants` brings its own cross-tenant 404 test, as the T-0.7
 * commitment requires. Three layers are pinned because any one alone leaves a
 * hole:
 *
 *  - **The tenant route** — an estate-B member asking for estate A's panel gets
 *    a 404 (Filament resolves the tenant through `canAccessTenant()`).
 *  - **The record query** — a site administrator inside estate A cannot resolve
 *    estate B's assistant by id, because `manage_options` is a *site*
 *    capability and would otherwise cross the estate boundary.
 *  - **The action** — the edit action is not offered for a foreign record.
 *
 * The second half of this file pins the five write invariants the resource
 * inherits from `AssistantWriter`. They used to live only in
 * `AssistantController`; `AssistantTenancyTest`, `AssistantProfessionTest` and
 * `AssistantAdvancedFieldsTest` still cover the REST surface, and these cover
 * the panel path through the same code.
 */
class AdminAssistantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_an_estate_member_reaches_the_assistants_surface(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $this->assistantIn(Tenant::DEFAULT_ID, 'Support bot');

        $this->actingAs($alice)
            ->get('/admin/default/assistants')
            ->assertOk()
            ->assertSee('Support bot');
    }

    public function test_a_guest_is_sent_to_the_admin_login(): void
    {
        $this->get('/admin/default/assistants')->assertRedirect('/admin/login');
    }

    /**
     * THE T-0.7 COMMITMENT (route half). Carol belongs to estate B only; asking
     * for estate A's panel must not render, not even read-only.
     */
    public function test_a_member_of_another_estate_gets_a_404_for_the_tenant(): void
    {
        $this->estate('estate-b');
        $carol = $this->estateAdmin('estate-b');

        $this->actingAs($carol)
            ->get('/admin/default/assistants')
            ->assertNotFound();
    }

    /**
     * The estate scope is the only read rule assistants have — unlike teams
     * there is no per-record membership — so it has to hold on the list itself.
     */
    public function test_the_list_only_shows_the_current_estate(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $this->estate('estate-b');
        $this->estateAdmin('estate-b');

        $this->assistantIn(Tenant::DEFAULT_ID, 'Estate A bot');
        $this->assistantIn('estate-b', 'Estate B bot');

        $this->actingAs($alice)
            ->get('/admin/default/assistants')
            ->assertOk()
            ->assertSee('Estate A bot')
            ->assertDontSee('Estate B bot');
    }

    /**
     * THE T-0.7 COMMITMENT (record half). Alice is a site administrator — the
     * strongest customer role — and still must not resolve another estate's
     * assistant, because tenancy, not the site capability, is the boundary.
     */
    public function test_an_assistant_belonging_to_another_estate_is_not_resolvable(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $estateB = $this->estate('estate-b');
        $this->estateAdmin('estate-b');

        $foreign = $this->assistantIn('estate-b', 'Estate B bot');
        $own = $this->assistantIn(Tenant::DEFAULT_ID, 'Estate A bot');

        $this->actingAs($alice);
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
        TenantContext::set(Tenant::DEFAULT_ID);

        $scoped = AssistantResource::getEloquentQuery();

        $this->assertNotNull($scoped->find($own->getKey()));
        $this->assertNull(
            $scoped->find($foreign->getKey()),
            'Estate A must not resolve estate B\'s assistant, even for a site administrator.'
        );

        // And the policy agrees, so the two layers cannot drift apart.
        $policy = app(AssistantPolicy::class);

        $this->assertFalse($policy->update($alice, $foreign));
        $this->assertFalse($policy->view($alice, $foreign));

        // Belt and braces, at the table layer: the record is visible from its
        // own estate and invisible from estate A's. Asserting the edit action is
        // *hidden* would be the weaker statement — for a record the table
        // cannot resolve, Filament cannot even offer the action.
        $estateA = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        Filament::setTenant($estateB);
        TenantContext::set('estate-b');

        Livewire::test(ManageAssistants::class, array('tenant' => $estateB))
            ->assertCanSeeTableRecords(array($foreign))
            ->assertCanNotSeeTableRecords(array($own));

        Filament::setTenant($estateA);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageAssistants::class, array('tenant' => $estateA))
            ->assertCanSeeTableRecords(array($own))
            ->assertCanNotSeeTableRecords(array($foreign));

        $this->assertSame('Estate B bot', $foreign->fresh()->title);
    }

    public function test_creating_an_assistant_assigns_the_estate_and_derives_a_slug(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->callAction('create', data: array(
                'title' => 'New bot',
                'status' => 'draft',
            ));

        $assistant = Assistant::query()->where('title', 'New bot')->firstOrFail();

        $this->assertSame(Tenant::DEFAULT_ID, $assistant->tenant_id);
        $this->assertSame('new-bot', $assistant->slug);
        $this->assertSame('draft', $assistant->status);
    }

    public function test_slug_collisions_get_a_suffix_within_the_estate(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        $page = Livewire::test(ManageAssistants::class, array('tenant' => $tenant));

        $page->callAction('create', data: array('title' => 'Ops', 'status' => 'draft'));
        $page->callAction('create', data: array('title' => 'Ops', 'status' => 'draft'));

        $this->assertSame(
            array('ops', 'ops-2'),
            Assistant::query()
                ->where('tenant_id', Tenant::DEFAULT_ID)
                ->orderBy('id')
                ->pluck('slug')
                ->all()
        );
    }

    /**
     * The unique index is `(tenant_id, slug)`, so a second estate may reuse a
     * slug. A global uniqueness check would push estate B's assistant to
     * `ops-2` for no reason.
     */
    public function test_the_same_slug_is_allowed_in_another_estate(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $this->estate('estate-b');

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->callAction('create', data: array('title' => 'Ops', 'status' => 'draft'));

        $other = $this->assistantIn('estate-b', 'Ops');

        $this->assertSame('ops', $other->slug);
        // The assertion is about the `(tenant_id, slug)` index, not about the
        // reader's scope, so it spans both estates deliberately (Proposal 153).
        $this->assertSame(2, Assistant::query()->withoutGlobalScopes()->where('slug', 'ops')->count());
    }

    /**
     * The estate is a server-side fact, not a form field: a hand-crafted
     * payload must not be able to create an assistant in another estate.
     */
    public function test_the_form_cannot_choose_the_estate(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $this->estate('estate-b');

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->callAction('create', data: array(
                'title' => 'Sneaky bot',
                'status' => 'draft',
                'tenant_id' => 'estate-b',
            ));

        $assistant = Assistant::query()->where('title', 'Sneaky bot')->firstOrFail();

        $this->assertSame(Tenant::DEFAULT_ID, $assistant->tenant_id);
    }

    /**
     * `is_default` is per-estate. Setting it must clear the flag on this
     * estate's other assistants — and only this estate's.
     */
    public function test_setting_the_default_flag_is_exclusive_within_the_estate(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $this->estate('estate-b');

        $first = $this->assistantIn(Tenant::DEFAULT_ID, 'First bot', array('is_default' => true));
        $second = $this->assistantIn(Tenant::DEFAULT_ID, 'Second bot');
        $foreign = $this->assistantIn('estate-b', 'Estate B bot', array('is_default' => true));

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->callTableAction(EditAction::class, $second, data: array('is_default' => true));

        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($foreign->fresh()->is_default, 'Another estate\'s default must survive.');
    }

    public function test_updating_an_assistant_writes_a_record_level_audit_row(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Support bot');

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->callTableAction(EditAction::class, $assistant, data: array('title' => 'Renamed bot'));

        $this->assertSame('Renamed bot', $assistant->fresh()->title);

        $row = DB::table('nvoos_admin_audit')->where('resource', 'assistant')->first();

        $this->assertNotNull($row, 'An assistant edit must produce a record-level audit row.');
        $this->assertSame('updated', $row->action);
        $this->assertSame((string) $assistant->getKey(), $row->record_id);
        $this->assertSame(Tenant::DEFAULT_ID, $row->tenant_id);

        $this->assertSame('Support bot', json_decode((string) $row->before, true)['title']);
        $this->assertSame('Renamed bot', json_decode((string) $row->after, true)['title']);
    }

    /**
     * A row with `user_id = null` would name an action nobody took. Seeder and
     * queue writes are covered by the record's own `updated_at`.
     */
    public function test_no_audit_row_is_written_without_an_actor(): void
    {
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Support bot');

        app(AssistantWriter::class)->update($assistant, array('title' => 'Renamed bot'));

        $this->assertSame('Renamed bot', $assistant->fresh()->title);
        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
    }

    /**
     * A panel edit does not carry the fourteen `advanced` keys, and must not
     * drop them: they are the console's and the REST surface's to edit.
     */
    public function test_a_panel_edit_preserves_the_advanced_meta_keys(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Research bot', array(
            'advanced' => array(
                'vector_store_id' => 'vs_keep',
                'fallback_chain' => array('openai', 'anthropic'),
                'prompt_caching' => true,
            ),
        ));

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->callTableAction(EditAction::class, $assistant, data: array('title' => 'Renamed bot'));

        $meta = $assistant->fresh()->meta;

        $this->assertSame('vs_keep', $meta['vector_store_id']);
        $this->assertSame(array('openai', 'anthropic'), $meta['fallback_chain']);
        $this->assertTrue($meta['prompt_caching']);
    }

    /**
     * The profession selection lives in `meta`, so the edit form has to
     * hydrate it through the model's appended accessor. Without that, every
     * edit would silently wipe the selection on save.
     */
    public function test_the_edit_form_hydrates_and_preserves_the_profession_selection(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Design lead', array(
            'primary_role_slugs' => array('ux_designer'),
        ));

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->mountTableAction(EditAction::class, $assistant)
            ->assertTableActionDataSet(array('primary_role_slugs' => array('ux_designer')))
            ->callMountedTableAction();

        $this->assertSame(array('ux_designer'), $assistant->fresh()->primaryRoleSlugs());
    }

    public function test_deleting_an_assistant_removes_it(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Support bot');

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->callTableAction(DeleteAction::class, $assistant);

        $this->assertDatabaseMissing('assistants', array('id' => $assistant->getKey()));
    }

    /**
     * A viewer is refused the panel outright (T-0.5), so the resource must not
     * be the place that quietly re-admits them.
     */
    public function test_a_viewer_cannot_reach_the_resource(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$viewer->id => array('role' => 'viewer')]);

        $this->actingAs($viewer)
            ->get('/admin/default/assistants')
            ->assertForbidden();
    }

    /**
     * Create a second estate, idempotently.
     */
    private function estate(string $id): Tenant
    {
        return Tenant::query()->firstOrCreate(
            array('id' => $id),
            array('name' => ucfirst($id), 'slug' => $id, 'is_operator' => false)
        );
    }

    private function estateAdmin(string $tenantId): User
    {
        // `withoutEstate()`: a cross-estate fixture must belong ONLY to the
        // estate it is testing. The factory's default membership would otherwise
        // make "a member of another estate" also a member of this one, and the
        // 404 assertion would pass or fail for the wrong reason (149 §21).
        $user = User::factory()->admin()->withoutEstate()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    /**
     * Create an assistant in an estate through the writer, so the fixture obeys
     * the same rules the resource does.
     *
     * @param array<string, mixed> $data
     */
    private function assistantIn(string $tenantId, string $title, array $data = array()): Assistant
    {
        TenantContext::set($tenantId);

        $assistant = app(AssistantWriter::class)->create($data + array('title' => $title));

        TenantContext::forget();

        return $assistant;
    }
}
