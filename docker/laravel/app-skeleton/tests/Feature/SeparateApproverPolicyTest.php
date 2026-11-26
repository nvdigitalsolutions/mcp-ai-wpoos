<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The four-eyes policy on approval resolution — Proposal 149 §158.
 *
 * Research is unambiguous that self-approval must be blocked *by the system*
 * rather than by convention — an advisory control fails segregation-of-duties
 * scrutiny. But in agent HITL the human is the principal: the model cannot
 * approve its own work, and the same human clearing their own session's request
 * is the design. A second person is a stronger control where an estate has two
 * operators and an impossibility where it has one.
 *
 * So it is policy, not a hard rule: off by default, on per deploy. These tests
 * pin both sides of the switch, plus the two cases that must never be caught by
 * it — a different approver, and a ticket with no requester at all.
 */
final class SeparateApproverPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_approval_is_allowed_by_default(): void
    {
        config(['oos.approvals.require_separate_approver' => false]);

        $user = User::factory()->admin()->create();
        $id = $this->seedApproval($user->getKey());

        Sanctum::actingAs($user);
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->postJson('/api/v1/approvals/'.$id.'/approve')->assertOk();

        $row = DB::table('nvoos_approvals')->where('id', $id)->first();

        $this->assertSame('approved', $row->status);
        $this->assertSame((int) $user->getKey(), (int) $row->resolved_by);
    }

    public function test_self_approval_is_forbidden_when_the_policy_is_on(): void
    {
        config(['oos.approvals.require_separate_approver' => true]);

        $user = User::factory()->admin()->create();
        $id = $this->seedApproval($user->getKey());

        Sanctum::actingAs($user);
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->postJson('/api/v1/approvals/'.$id.'/approve')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'self_approval_forbidden');

        $row = DB::table('nvoos_approvals')->where('id', $id)->first();

        $this->assertSame('pending', $row->status, 'A refused approval must not decide the ticket.');
        $this->assertNull($row->resolved_by);
    }

    public function test_a_different_approver_is_allowed_when_the_policy_is_on(): void
    {
        config(['oos.approvals.require_separate_approver' => true]);

        $requester = User::factory()->admin()->create();
        $approver = User::factory()->admin()->create();

        // `requester_id` is set explicitly: the requester is a person, not the
        // acting session, and self-approval is judged against that person.
        $id = $this->seedApproval($requester->getKey());

        Sanctum::actingAs($approver);
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->postJson('/api/v1/approvals/'.$id.'/approve')->assertOk();

        $row = DB::table('nvoos_approvals')->where('id', $id)->first();

        $this->assertSame('approved', $row->status);
        $this->assertSame((int) $approver->getKey(), (int) $row->resolved_by);
    }

    public function test_a_ticket_with_no_requester_can_be_resolved(): void
    {
        config(['oos.approvals.require_separate_approver' => true]);

        $user = User::factory()->admin()->create();
        $id = $this->seedApproval(null);

        Sanctum::actingAs($user);
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->postJson('/api/v1/approvals/'.$id.'/deny')->assertOk();

        $this->assertSame(
            'denied',
            DB::table('nvoos_approvals')->where('id', $id)->value('status')
        );
    }

    private function seedApproval(?int $requesterId): int
    {
        return (int) DB::table('nvoos_approvals')->insertGetId([
            'tenant_id' => Tenant::DEFAULT_ID,
            'status' => 'pending',
            'tool' => 'send_group_email',
            'arguments' => json_encode(['to' => 'a@b.c'], JSON_UNESCAPED_SLASHES),
            'requester_id' => $requesterId,
            'reason' => 'four-eyes fixture',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
