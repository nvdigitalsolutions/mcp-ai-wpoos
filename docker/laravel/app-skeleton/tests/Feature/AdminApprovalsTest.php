<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Approvals;
use App\Models\Approval;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §41 — the read-only approval queue.
 *
 * The reason this slice touched the schema: `nvoos_approvals` had no tenant, and
 * `ApprovalsController` named the table in `index`, `store` and `resolve`. An
 * approval is the human gate in front of a destructive tool call, so a
 * cross-estate `resolve` let one estate's administrator authorise another
 * estate's gated action — the §29 `fire` defect, one layer up.
 *
 * Four layers, because any one alone leaves a hole: the controller (read scope,
 * write estate, a foreign resolve answering 404), the model's global scope, the
 * console routes, and the `/admin` table.
 */
class AdminApprovalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_approval_queue_lists_only_the_current_estate(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $this->estate('estate-b');

        $own = $this->seedApproval(Tenant::DEFAULT_ID, 'post_publish', 'own request');
        $foreign = $this->seedApproval('estate-b', 'delete_all_posts', 'foreign request');

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(Approvals::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign])
            ->assertSee('post_publish')
            ->assertDontSee('delete_all_posts');
    }

    public function test_the_approvals_page_is_reachable_from_the_panel(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/approvals')
            ->assertOk();
    }

    public function test_the_console_index_is_estate_scoped(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->estate('estate-b');

        $own = $this->seedApproval(Tenant::DEFAULT_ID, 'post_publish', 'own request');
        $this->seedApproval('estate-b', 'delete_all_posts', 'foreign request');

        TenantContext::set(Tenant::DEFAULT_ID);

        $rows = $this->getJson('/api/v1/approvals')->assertOk()->json();

        $this->assertSame([(int) $own->getKey()], array_column($rows['approvals'], 'id'));
        $this->assertSame(1, $rows['total']);
    }

    public function test_resolving_another_estates_approval_is_a_404(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->estate('estate-b');

        $foreign = $this->seedApproval('estate-b', 'delete_all_posts', 'foreign request');

        TenantContext::set(Tenant::DEFAULT_ID);

        $this->postJson('/api/v1/approvals/'.(int) $foreign->getKey().'/approve')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        // R4: a foreign id must be indistinguishable from a missing one.
        $this->postJson('/api/v1/approvals/999999/approve')->assertNotFound();

        // And the row is still undecided and still owned by estate-b.
        $row = DB::table('nvoos_approvals')->where('id', $foreign->getKey())->first();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->resolved_by);
        $this->assertSame('estate-b', $row->tenant_id);
    }

    public function test_storing_an_approval_records_the_current_estate(): void
    {
        $user = User::factory()->admin()->create();

        // ResolveApiTenant resolves the estate from membership, so the caller has
        // to actually be a member of the estate they are queueing into; the header
        // is the selector, never the authority (149 §21).
        $this->estate('estate-b');
        Tenant::query()->findOrFail('estate-b')
            ->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        Sanctum::actingAs($user);

        $id = $this->withHeader('X-Tenant-Id', 'estate-b')
            ->postJson('/api/v1/approvals', [
                'tool' => 'post_publish',
                'arguments' => ['post_id' => 42],
                'reason' => 'publishing outside hours',
            ])->assertCreated()->json('id');

        $this->assertSame(
            'estate-b',
            DB::table('nvoos_approvals')->where('id', $id)->value('tenant_id')
        );
    }

    public function test_the_model_scope_hides_another_estates_approval(): void
    {
        $this->estate('estate-b');

        $own = $this->seedApproval(Tenant::DEFAULT_ID, 'post_publish', 'own');
        $foreign = $this->seedApproval('estate-b', 'delete_all_posts', 'foreign');

        TenantContext::set(Tenant::DEFAULT_ID);

        $this->assertNotNull(Approval::query()->find($own->getKey()));
        $this->assertNull(
            Approval::query()->find($foreign->getKey()),
            'The global scope is the single isolation rule for the panel; if it fails, the page leaks.'
        );
    }

    public function test_an_expired_pending_approval_is_flagged(): void
    {
        $expired = $this->seedApproval(Tenant::DEFAULT_ID, 'post_publish', 'stale', now()->subDay());

        $this->assertTrue($expired->is_expired);

        // Once decided, the timestamp stops mattering: the decision was made.
        DB::table('nvoos_approvals')->where('id', $expired->getKey())->update([
            'status' => 'approved',
            'resolved_at' => now(),
        ]);

        $this->assertFalse(
            Approval::withoutGlobalScope('tenant')->findOrFail($expired->getKey())->is_expired
        );
    }

    public function test_the_page_is_read_only(): void
    {
        $own = $this->seedApproval(Tenant::DEFAULT_ID, 'post_publish', 'own');

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(Approvals::class)
            ->assertSee('Inspect')
            ->assertDontSee('Delete');

        // A page that accepts no payload is the structural half of read-only:
        // there is no form to submit, so no decision path exists to forget to
        // audit. Approve/deny stays the console's write until it gets one.
        $this->assertFalse(
            is_subclass_of(Approvals::class, HasForms::class),
            'The approvals page must not expose a form.'
        );

        $row = DB::table('nvoos_approvals')->where('id', $own->getKey())->first();
        $this->assertSame('pending', $row->status);
        $this->assertNull($row->resolved_by);
    }

    private function seedApproval(
        string $tenantId,
        string $tool,
        string $reason,
        mixed $expiresAt = null
    ): Approval {
        $id = DB::table('nvoos_approvals')->insertGetId([
            'tenant_id' => $tenantId,
            'status' => 'pending',
            'tool' => $tool,
            'arguments' => json_encode(['post_id' => 42], JSON_UNESCAPED_SLASHES),
            'requester_id' => 7,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Approval::withoutGlobalScope('tenant')->findOrFail($id);
    }

    private function estate(string $tenantId): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['id' => $tenantId],
            [
                'name' => 'Estate '.$tenantId,
                'slug' => $tenantId,
                'is_operator' => false,
            ]
        );
    }

    private function estateAdmin(string $tenantId): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }
}
