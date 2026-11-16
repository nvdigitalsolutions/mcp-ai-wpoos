<?php

namespace Tests\Feature;

use App\Http\Middleware\AuditAdminRequests;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proposal 149 T-0.9 — one audit row per mutating admin request, and never a
 * secret in it.
 *
 * The routes below are declared inside the test rather than through a Filament
 * resource because neither panel has resources yet (they arrive in Wave 2).
 * That keeps this a real request → middleware → database test without
 * pretending a resource exists.
 */
class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(AuditAdminRequests::class)->group(function (): void {
            Route::post('/_audit/probe', static fn () => response()->json(array('ok' => true)))
                ->name('filament.admin.resources.probe.store');
            Route::get('/_audit/probe', static fn () => response()->json(array('ok' => true)))
                ->name('filament.admin.resources.probe.index');
            Route::post('/_audit/rejected', static fn () => abort(422, 'Rejected'))
                ->name('filament.admin.resources.probe.reject');
            Route::post('/livewire/update', static fn () => response('ok'));
        });
    }

    public function test_a_mutating_request_writes_exactly_one_row(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->postJson('/_audit/probe', array('name' => 'Probe'))->assertOk();

        $this->assertSame(1, DB::table('nvoos_admin_audit')->count());

        $row = DB::table('nvoos_admin_audit')->first();
        $this->assertSame((int) $user->getKey(), (int) $row->user_id);
        $this->assertSame('POST', $row->method);
        $this->assertSame('/_audit/probe', $row->path);
        $this->assertSame(200, (int) $row->status);
    }

    /**
     * The log answers "who changed what", not "who looked". Auditing reads
     * would bury the mutations.
     */
    public function test_a_read_request_writes_no_row(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/_audit/probe')
            ->assertOk();

        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
    }

    /**
     * Credentials must never reach the audit table, at any nesting depth.
     * Substring matching over-redacts on purpose.
     */
    public function test_credential_shaped_payload_keys_are_redacted(): void
    {
        $this->actingAs(User::factory()->admin()->create())->postJson('/_audit/probe', array(
            'name' => 'Visible',
            'api_key' => 'SUPER-SECRET-VALUE',
            'max_tokens' => 512,
            'nested' => array(
                'password' => 'hunter2',
                'licence_key' => 'PRO-KEY',
                'visible' => 'ok',
            ),
        ))->assertOk();

        $row = DB::table('nvoos_admin_audit')->first();
        $payload = json_decode((string) $row->payload, true);

        $this->assertStringNotContainsString('SUPER-SECRET-VALUE', (string) $row->payload);
        $this->assertStringNotContainsString('hunter2', (string) $row->payload);
        $this->assertStringNotContainsString('PRO-KEY', (string) $row->payload);

        $this->assertSame('[redacted]', $payload['api_key']);
        $this->assertSame('[redacted]', $payload['nested']['password']);
        $this->assertSame('[redacted]', $payload['nested']['licence_key']);
        // Non-secret context survives, or the log cannot explain the change.
        $this->assertSame('Visible', $payload['name']);
        $this->assertSame('ok', $payload['nested']['visible']);
    }

    /**
     * A rejected write is an attempt worth recording. The exception unwinds
     * past this middleware before Laravel renders it, so the catch branch is
     * what makes failed writes visible at all.
     */
    public function test_a_failed_mutation_is_recorded_with_its_status(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/_audit/rejected')
            ->assertStatus(422);

        $this->assertSame(1, DB::table('nvoos_admin_audit')->count());

        $row = DB::table('nvoos_admin_audit')->first();
        $this->assertSame(422, (int) $row->status);
        $this->assertSame('/_audit/rejected', $row->path);
    }

    /**
     * Filament renders forms as Livewire components; their transport calls are
     * UI mechanics, not operator actions.
     */
    public function test_livewire_transport_calls_are_not_audited(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/livewire/update', array('components' => array()))
            ->assertOk();

        $this->assertSame(0, DB::table('nvoos_admin_audit')->count());
    }

    public function test_the_panel_is_derived_from_the_route_name_when_no_panel_is_current(): void
    {
        $this->actingAs(User::factory()->admin()->create())->postJson('/_audit/probe');

        $row = DB::table('nvoos_admin_audit')->first();

        $this->assertSame('admin', $row->panel);
        $this->assertSame('filament.admin.resources.probe.store', $row->route);
    }

    public function test_the_pinned_estate_is_recorded(): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->actingAs(User::factory()->admin()->create())->postJson('/_audit/probe');

        $this->assertSame(
            Tenant::DEFAULT_ID,
            DB::table('nvoos_admin_audit')->value('tenant_id')
        );
    }

    public function test_an_operator_action_records_no_estate(): void
    {
        // The operator panel is not tenant-scoped, so its actions belong to no
        // single estate — a tenant id there would be a lie.
        TenantContext::forget();

        $this->actingAs(User::factory()->admin()->create())->postJson('/_audit/probe');

        $this->assertNull(DB::table('nvoos_admin_audit')->value('tenant_id'));
    }

    public function test_both_panels_carry_the_audit_middleware(): void
    {
        foreach (array('admin', 'operator') as $id) {
            $this->assertContains(
                AuditAdminRequests::class,
                Filament::getPanel($id)->getMiddleware(),
                "Panel '{$id}' does not audit mutating requests."
            );
        }
    }
}
