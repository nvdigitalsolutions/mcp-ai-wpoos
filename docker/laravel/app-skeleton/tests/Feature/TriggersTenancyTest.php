<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveApiTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Proposal 149 §29 — triggers are estate data.
 *
 * `nvoos_triggers` had no tenant column and every controller method named the
 * table directly, while the routes already resolved an estate and ignored it.
 * Any tenant user could list, rewrite, delete and **fire** another estate's
 * triggers. `fire` is the sharp edge: it runs the trigger's workflow with the
 * caller's user id, so a cross-estate fire executed someone else's automation
 * under the caller's identity.
 *
 * The command test at the end covers the other half of the fix: the scheduler is
 * unscoped on purpose (it must see every estate's due triggers) and must set the
 * estate context before firing, or every non-default estate's trigger looked up
 * its workflow in the default one.
 */
class TriggersTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantContext::set(null);

        parent::tearDown();
    }

    public function test_another_estates_trigger_is_not_listed(): void
    {
        $this->triggerIn('estate-b', 'Foreign trigger');

        $this->actAsDefaultAdmin();

        $response = $this->getJson('/api/v1/triggers')->assertOk();

        $this->assertStringNotContainsString('Foreign trigger', $response->getContent());
        $this->assertSame([], $response->json('triggers'));
    }

    public function test_another_estates_trigger_cannot_be_updated(): void
    {
        $id = $this->triggerIn('estate-b', 'Foreign trigger');

        $this->actAsDefaultAdmin();

        $this->putJson('/api/v1/triggers/'.$id, ['name' => 'Hijacked'])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame(
            'Foreign trigger',
            (string) DB::table('nvoos_triggers')->where('id', $id)->value('name')
        );
    }

    public function test_another_estates_trigger_cannot_be_deleted(): void
    {
        $id = $this->triggerIn('estate-b', 'Foreign trigger');

        $this->actAsDefaultAdmin();

        $this->deleteJson('/api/v1/triggers/'.$id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertSame(1, DB::table('nvoos_triggers')->where('id', $id)->count());
    }

    /**
     * The sharp edge: `fire` executes a workflow with the caller's user id.
     */
    public function test_another_estates_trigger_cannot_be_fired(): void
    {
        $id = $this->triggerIn('estate-b', 'Foreign trigger');

        $this->actAsDefaultAdmin();

        $this->postJson('/api/v1/triggers/'.$id.'/fire')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $this->assertNull(
            DB::table('nvoos_triggers')->where('id', $id)->value('last_fired_at'),
            'A refused fire must not touch the row.'
        );
    }

    public function test_a_created_trigger_records_its_estate(): void
    {
        $this->actAsDefaultAdmin();

        $this->postJson('/api/v1/triggers', [
            'name' => 'Own trigger',
            'type' => 'webhook',
        ])->assertCreated();

        $this->assertSame(
            'default',
            (string) DB::table('nvoos_triggers')->value('tenant_id')
        );
    }

    /**
     * The scheduler sees every estate's due triggers, so it must fire each one
     * inside its own estate — otherwise the workflow lookup happens in the
     * default estate and every non-default trigger skips as `workflow_not_found`.
     */
    public function test_the_command_fires_a_foreign_estate_trigger_in_its_own_estate(): void
    {
        $this->estate('estate-b');

        $member = User::factory()->admin()->inEstate('estate-b', 'owner')->create();
        Sanctum::actingAs($member);

        $workflowId = (int) $this->withHeader(ResolveApiTenant::HEADER, 'estate-b')
            ->postJson('/api/v1/orchestration/workflows', [
                'name' => 'Estate B flow',
                'graph' => [
                    'nodes' => [
                        ['id' => 'node_1', 'type' => 'tool', 'data' => ['toolSlug' => 'generate_uuid']],
                    ],
                    'edges' => [],
                ],
            ])
            ->assertOk()
            ->json('id');

        $triggerId = (int) $this->withHeader(ResolveApiTenant::HEADER, 'estate-b')
            ->postJson('/api/v1/triggers', [
                'name' => 'Estate B nightly',
                'type' => 'cron_schedule',
                'config' => ['schedule' => 'every_minute'],
                'workflow_id' => $workflowId,
                'enabled' => true,
            ])
            ->assertCreated()
            ->json('id');

        TenantContext::set(null);

        $this->artisan('nvoos:fire-triggers')->assertSuccessful();

        $this->assertNotNull(
            DB::table('nvoos_triggers')->where('id', $triggerId)->value('last_fired_at'),
            'The trigger should have fired rather than skipped as workflow_not_found.'
        );

        $run = DB::table('nvoos_workflow_runs')->where('workflow_id', $workflowId)->first();

        $this->assertNotNull($run, 'The run must exist for the trigger\'s own estate.');
        $this->assertSame('estate-b', (string) $run->tenant_id);
    }

    private function actAsDefaultAdmin(): void
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        Sanctum::actingAs($user);
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    private function estate(string $id): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['id' => $id],
            ['name' => ucfirst($id), 'slug' => $id, 'is_operator' => false]
        );
    }

    /**
     * Insert a trigger straight into another estate.
     *
     * `fire` is not exercised by the insert itself: the point is that a
     * trigger row that belongs elsewhere is unreachable, whatever its workflow.
     */
    private function triggerIn(string $tenantId, string $name): int
    {
        $this->estate($tenantId);

        return (int) DB::table('nvoos_triggers')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'type' => 'webhook',
            'config' => json_encode([]),
            'workflow_id' => null,
            'enabled' => true,
            'webhook_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
