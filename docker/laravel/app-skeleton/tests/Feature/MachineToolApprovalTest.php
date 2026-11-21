<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Propose → approve → retry — Proposal 159 slice 4.
 *
 * A machine may not run a tool the operator has not allowlisted for unattended
 * use. It may *ask*: the first call queues an approval, and the same call
 * executes once a person approves it. Three properties carry the safety of that
 * exchange, and each has a test:
 *
 *  - the approval is **single-use** (consumed on execution, never replayed),
 *  - it is bound to a **hash of the arguments** (approving one payload cannot
 *    authorise another),
 *  - it **expires**, so a stale decision cannot be acted on later.
 */
final class MachineToolApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_queued_approval_is_idempotent_while_pending(): void
    {
        [$user, $assistant] = $this->fixture();

        $first = $this->postTool($user, $assistant, array('color' => '#fff'));
        $second = $this->postTool($user, $assistant, array('color' => '#fff'));

        $this->assertSame(202, $first->status());
        $this->assertSame(202, $second->status());
        $this->assertSame($first->json('approval_id'), $second->json('approval_id'));

        $this->assertSame(1, DB::table('nvoos_approvals')->count());
    }

    public function test_an_approved_call_executes_and_consumes_the_approval(): void
    {
        [$user, $assistant] = $this->fixture();

        $id = (int) $this->postTool($user, $assistant, array('color' => '#fff'))->json('approval_id');

        DB::table('nvoos_approvals')->where('id', $id)->update(array(
            'status' => 'approved',
            'resolved_at' => now(),
            'resolved_by' => $user->getKey(),
        ));

        $this->postTool($user, $assistant, array('color' => '#fff'))
            ->assertOk()
            ->assertJsonPath('success', true);

        $row = DB::table('nvoos_approvals')->where('id', $id)->first();

        $this->assertSame('consumed', $row->status);
        $this->assertNotNull($row->consumed_at);
    }

    public function test_a_consumed_approval_cannot_be_replayed(): void
    {
        [$user, $assistant] = $this->fixture();

        $id = (int) $this->postTool($user, $assistant, array('color' => '#fff'))->json('approval_id');
        DB::table('nvoos_approvals')->where('id', $id)->update(array('status' => 'approved'));

        $this->postTool($user, $assistant, array('color' => '#fff'))->assertOk();

        // The second retry must ask again rather than run twice on one decision.
        $replay = $this->postTool($user, $assistant, array('color' => '#fff'));

        $this->assertSame(202, $replay->status());
        $this->assertNotSame($id, $replay->json('approval_id'));
        $this->assertSame(2, DB::table('nvoos_approvals')->count());
    }

    public function test_a_denied_call_is_refused_and_not_requeued(): void
    {
        [$user, $assistant] = $this->fixture();

        $id = (int) $this->postTool($user, $assistant, array('color' => '#fff'))->json('approval_id');
        DB::table('nvoos_approvals')->where('id', $id)->update(array('status' => 'denied'));

        $this->postTool($user, $assistant, array('color' => '#fff'))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'approval_denied');

        $this->assertSame(1, DB::table('nvoos_approvals')->count(), 'A denial must not re-queue.');
    }

    public function test_an_expired_approval_is_not_reused(): void
    {
        [$user, $assistant] = $this->fixture();

        $id = (int) $this->postTool($user, $assistant, array('color' => '#fff'))->json('approval_id');

        DB::table('nvoos_approvals')->where('id', $id)->update(array(
            'status' => 'approved',
            'expires_at' => Carbon::now()->subMinute(),
        ));

        $this->postTool($user, $assistant, array('color' => '#fff'))->assertStatus(202);

        $this->assertNull(DB::table('nvoos_approvals')->where('id', $id)->value('consumed_at'));
    }

    public function test_approval_is_bound_to_the_arguments(): void
    {
        [$user, $assistant] = $this->fixture();

        $id = (int) $this->postTool($user, $assistant, array('color' => '#fff'))->json('approval_id');
        DB::table('nvoos_approvals')->where('id', $id)->update(array('status' => 'approved'));

        // Same tool, different payload: not covered by that decision.
        $this->postTool($user, $assistant, array('color' => '#000'))->assertStatus(202);

        $this->assertNull(DB::table('nvoos_approvals')->where('id', $id)->value('consumed_at'));
    }

    public function test_the_approval_is_recorded_in_the_estate(): void
    {
        [$user, $assistant] = $this->fixture();

        $this->postTool($user, $assistant, array('color' => '#fff'))->assertStatus(202);

        $this->assertDatabaseHas('nvoos_approvals', array(
            'tool' => 'color_convert',
            'assistant_id' => $assistant->getKey(),
            'requester_id' => $user->getKey(),
            'tenant_id' => Tenant::DEFAULT_ID,
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function postTool(User $user, Assistant $assistant, array $arguments): \Illuminate\Testing\TestResponse
    {
        $token = $user->createToken('machine', array('assistant:' . $assistant->getKey()))->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/tools/color_convert', array('arguments' => $arguments));
    }

    /**
     * An owner with an assistant that lists `color_convert`, which is registered
     * but deliberately left off the machine allowlist so every call proposes.
     *
     * @return array{0: User, 1: Assistant}
     */
    private function fixture(): array
    {
        config(array('oos.machine_tools' => array('base64')));

        $user = User::factory()->admin()->withoutEstate()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        TenantContext::set(Tenant::DEFAULT_ID);

        $assistant = app(AssistantWriter::class)->create(array(
            'title' => 'Approval fixture',
            'tools' => array('color_convert'),
        ));

        TenantContext::forget();

        return array($user, $assistant);
    }
}
