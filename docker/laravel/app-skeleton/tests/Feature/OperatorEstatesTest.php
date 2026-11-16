<?php

namespace Tests\Feature;

use App\Filament\Operator\Resources\Estates\Pages\ManageEstates;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149, Wave 2 — the first `/ops` surface: estates and licences.
 *
 * The suite exists to pin four things that are easy to lose in a UI port:
 *
 *  1. **The audience split survives the first resource.** `/ops` is operator
 *     only (Q-1), so a customer estate administrator — an `administrator` by
 *     site role — must be refused the page, not merely shown a read-only one.
 *  2. **A stored credential is never rendered.** The licence key is encrypted
 *     at rest, but the `encrypted` cast decrypts on read: a bound column or a
 *     prefilled password input would put it in the HTML and in Livewire's
 *     snapshot. The list shows a status; the form is write-only.
 *  3. **The record-level audit actually records.** Wave 0 logged requests;
 *     this is the first surface where a diff exists, and the diff must not
 *     carry the credential either.
 *  4. **Privilege is not a form field.** `is_operator` grants `/ops`; a form
 *     post must not be able to set it.
 */
class OperatorEstatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Filament resolves the resource's panel from this, and the page's
        // access check reads it. Without it the Livewire component has no
        // panel to authorize against.
        Filament::setCurrentPanel(Filament::getPanel('operator'));
    }

    public function test_an_operator_reaches_the_estates_surface(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/estates')
            ->assertOk()
            ->assertSee('Default estate');
    }

    /**
     * THE REGRESSION. A customer estate owner is an `administrator` by site
     * role, so any predicate built on the role would admit them to licence
     * writes for every estate on the platform.
     */
    public function test_a_customer_administrator_is_refused_the_estates_surface(): void
    {
        $this->actingAs($this->customerAdmin())
            ->get('/ops/estates')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_operator_login(): void
    {
        $this->get('/ops/estates')->assertRedirect('/ops/login');
    }

    public function test_the_estate_list_never_renders_a_stored_licence_key(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $tenant->forceFill(array('licence_key' => 'PRO-CANARY-NEVER-RENDER'))->save();

        $this->actingAs($this->operator())
            ->get('/ops/estates')
            ->assertOk()
            ->assertSee('Set')
            ->assertDontSee('PRO-CANARY-NEVER-RENDER');
    }

    /**
     * The edit modal is the second way a stored key could reach the browser:
     * Filament prefills a field from the record, and a password input hides
     * characters without removing the value from the HTML or Livewire's
     * snapshot. The field must be blanked server-side before render.
     */
    public function test_the_edit_form_is_blank_so_the_key_cannot_be_prefilled(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $tenant->forceFill(array('licence_key' => 'PRO-CANARY-IN-FORM'))->save();

        $this->actingAs($this->operator());

        Livewire::test(ManageEstates::class)
            ->mountTableAction(EditAction::class, $tenant)
            ->assertSchemaStateSet(array('licence_key' => null))
            ->assertDontSee('PRO-CANARY-IN-FORM');
    }

    public function test_an_operator_sets_a_licence_key_from_the_surface(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->operator());

        Livewire::test(ManageEstates::class)
            ->callTableAction(EditAction::class, $tenant, data: array(
                'name' => $tenant->name,
                'plan' => 'professional',
                'licence_key' => 'PRO-NEW-KEY',
            ));

        $fresh = $tenant->fresh();

        $this->assertSame('PRO-NEW-KEY', $fresh->licence_key);
        $this->assertSame('professional', $fresh->plan);

        // Encrypted at rest: the raw column must not be the plaintext.
        $raw = DB::table('tenants')->where('id', $tenant->id)->value('licence_key');
        $this->assertNotSame('PRO-NEW-KEY', $raw);
    }

    /**
     * The field is a credential input, not a display: leaving it blank is how
     * an operator edits a name without being forced to re-enter the key.
     */
    public function test_a_blank_licence_field_keeps_the_stored_key(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $tenant->forceFill(array('licence_key' => 'PRO-KEEP-ME'))->save();

        $this->actingAs($this->operator());

        Livewire::test(ManageEstates::class)
            ->callTableAction(EditAction::class, $tenant, data: array(
                'name' => 'Renamed estate',
            ));

        $fresh = $tenant->fresh();

        $this->assertSame('Renamed estate', $fresh->name);
        $this->assertSame('PRO-KEEP-ME', $fresh->licence_key);
    }

    public function test_updating_an_estate_writes_a_record_level_audit_row(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->operator());

        Livewire::test(ManageEstates::class)
            ->callTableAction(EditAction::class, $tenant, data: array(
                'plan' => 'professional',
                'licence_key' => 'PRO-AUDIT-KEY',
            ));

        $row = DB::table('nvoos_admin_audit')->where('resource', 'estate')->first();

        $this->assertNotNull($row, 'A resource write must produce a record-level audit row.');
        $this->assertSame('updated', $row->action);
        $this->assertSame($tenant->id, $row->record_id);
        $this->assertSame('PATCH', $row->method);

        $after = json_decode((string) $row->after, true);
        $this->assertSame('professional', $after['plan']);

        // The diff must not become a second place the credential leaks to.
        $this->assertSame('[redacted]', $after['licence_key']);
        $this->assertStringNotContainsString('PRO-AUDIT-KEY', (string) $row->before.(string) $row->after);
    }

    /**
     * Console seeds and queue jobs mutate estates with nobody signed in. An
     * operator audit row for those would name an actor who does not exist.
     */
    public function test_a_change_with_no_actor_is_not_audited(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $tenant->forceFill(array('plan' => 'compliance'))->save();

        $this->assertSame(0, DB::table('nvoos_admin_audit')->where('resource', 'estate')->count());
    }

    /**
     * `is_operator` is the vendor-panel flag. If a form post could set it, an
     * operator could hand a customer `/ops` — or an attacker with a licence form
     * could promote their own estate.
     */
    public function test_the_operator_flag_cannot_be_changed_from_the_surface(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->operator());

        Livewire::test(ManageEstates::class)
            ->callTableAction(EditAction::class, $tenant, data: array(
                'name' => $tenant->name,
                'is_operator' => true,
            ));

        $this->assertFalse($tenant->fresh()->is_operator);
    }

    /**
     * The policy, not the UI, is the enforcement point. Create and delete are
     * denied outright: an estate is an authorization boundary, and deleting one
     * would orphan every assistant and team scoped to its `tenant_id`.
     */
    public function test_the_policy_denies_create_and_delete_for_even_an_operator(): void
    {
        $policy = app(TenantPolicy::class);
        $operator = $this->operator();
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->assertTrue($policy->viewAny($operator));
        $this->assertTrue($policy->update($operator, $tenant));
        $this->assertFalse($policy->create($operator));
        $this->assertFalse($policy->delete($operator, $tenant));
    }

    public function test_the_policy_refuses_a_customer_administrator(): void
    {
        $policy = app(TenantPolicy::class);
        $customer = $this->customerAdmin();
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->assertFalse($policy->viewAny($customer));
        $this->assertFalse($policy->view($customer, $tenant));
        $this->assertFalse($policy->update($customer, $tenant));
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->attach($user->id, array('role' => 'owner'));

        return $user;
    }

    private function customerAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::DEFAULT_ID)
            ->users()
            ->attach($user->id, array('role' => 'owner'));

        return $user;
    }
}
