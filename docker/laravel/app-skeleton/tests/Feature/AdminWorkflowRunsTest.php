<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\WorkflowRunLog;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §18 — the read-only workflow run log.
 *
 * The reason this slice touched the schema: `nvoos_workflow_runs` had no tenant,
 * and a read-only list over an unscoped table would show one estate another
 * estate's runs — including the per-node `events` payload. That is API3 (BOLA
 * on object properties), not a display bug.
 *
 * Three layers are pinned, because any one alone leaves a hole:
 *
 *  - the model's global scope (the rule both readers inherit);
 *  - the console endpoints (index and show);
 *  - the `/admin` table.
 */
class AdminWorkflowRunsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_run_log_lists_only_the_current_estate(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $this->estate('estate-b');

        $own = $this->seedRun(Tenant::DEFAULT_ID, 'completed');
        $foreign = $this->seedRun('estate-b', 'failed');

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(WorkflowRunLog::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_the_run_log_is_reachable_from_the_panel(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/workflow-runs')
            ->assertOk();
    }

    public function test_the_console_index_is_estate_scoped(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->estate('estate-b');

        $own = $this->seedRun(Tenant::DEFAULT_ID, 'completed');
        $this->seedRun('estate-b', 'completed');

        TenantContext::set(Tenant::DEFAULT_ID);

        $rows = $this->getJson('/api/v1/orchestration/runs')->assertOk()->json();

        $this->assertSame([(int) $own->id], array_column($rows, 'id'));
    }

    public function test_a_run_belonging_to_another_estate_is_a_404(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->estate('estate-b');

        $foreign = $this->seedRun('estate-b', 'completed');

        TenantContext::set(Tenant::DEFAULT_ID);

        $this->getJson('/api/v1/orchestration/runs/'.(int) $foreign->id)
            ->assertNotFound();

        // R4: the refusal must not disclose that the row exists, so the body is
        // the same one a genuinely missing id produces.
        $this->getJson('/api/v1/orchestration/runs/999999')->assertNotFound();
    }

    public function test_the_model_scope_hides_another_estates_runs(): void
    {
        $this->estate('estate-b');

        $own = $this->seedRun(Tenant::DEFAULT_ID, 'completed');
        $foreign = $this->seedRun('estate-b', 'completed');

        TenantContext::set(Tenant::DEFAULT_ID);

        $this->assertNotNull(WorkflowRun::query()->find($own->id));
        $this->assertNull(
            WorkflowRun::query()->find($foreign->id),
            'The global scope is the single isolation rule; if it fails, both readers fail.'
        );
    }

    /**
     * Insert a run directly. `WorkflowRunner` is the production writer; this
     * fixture exists so the test is about isolation rather than about graph
     * execution, which `WorkflowRunTest` covers.
     */
    private function seedRun(string $tenantId, string $status): WorkflowRun
    {
        $id = DB::table('nvoos_workflow_runs')->insertGetId([
            'workflow_id' => 1234,
            'tenant_id' => $tenantId,
            'status' => $status,
            'source' => 'manual',
            'tool_calls' => 2,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'duration' => 1.25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WorkflowRun::withoutGlobalScope('tenant')->findOrFail($id);
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
            ->users()->attach($user->id, ['role' => 'owner']);

        return $user;
    }
}
