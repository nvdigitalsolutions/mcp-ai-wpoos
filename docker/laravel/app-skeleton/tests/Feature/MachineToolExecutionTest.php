<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Machine tool execution — Proposal 159 slice 4.
 *
 * The three layers are tested separately, because a single "403" test cannot
 * tell which one refused and the layers exist precisely so one can catch what
 * the others miss:
 *
 *  1. the principal is a machine (a session and a `['*']` token are not),
 *  2. the assistant is assigned the tool,
 *  3. an operator allowlisted the tool for machines at all.
 *
 * `base64` is the happy path: it is registered, takes no required arguments, and
 * returns a deterministic value.
 */
final class MachineToolExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_machine_token_executes_an_assigned_allowlisted_tool(): void
    {
        config(array('oos.machine_tools' => array('base64')));

        [$user, $assistant] = $this->fixture(array('base64'));

        $this->withHeader('Authorization', 'Bearer ' . $this->token($user, $assistant))
            ->postJson('/api/v1/tools/base64', array('arguments' => array('text' => 'hi')))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.output', 'aGk=');
    }

    public function test_a_console_session_cannot_use_the_machine_route(): void
    {
        // The console has its own execution paths and its own guards. This route
        // exists for machine principals, so an unrestricted caller is refused
        // even though the scope middleware lets it through.
        [$user] = $this->fixture(array('base64'));

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tools/base64', array('arguments' => array('text' => 'hi')))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_principal_required');
    }

    public function test_a_star_token_cannot_use_the_machine_route(): void
    {
        [$user] = $this->fixture(array('base64'));

        $token = $user->createToken('personal', array('*'))->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/tools/base64', array('arguments' => array('text' => 'hi')))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'machine_principal_required');
    }

    public function test_an_estate_scoped_token_is_refused_by_the_scope_guard(): void
    {
        [$user] = $this->fixture(array('base64'));

        $token = $user->createToken('estate', array('estate:assistants.list'))->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/tools/base64', array('arguments' => array('text' => 'hi')))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'principal_scope_required')
            ->assertJsonPath('error.granted.0', 'estate:assistants.list');
    }

    public function test_an_unassigned_tool_is_refused(): void
    {
        config(array('oos.machine_tools' => array('base64', 'color_convert')));

        [$user, $assistant] = $this->fixture(array('base64'));

        $this->withHeader('Authorization', 'Bearer ' . $this->token($user, $assistant))
            ->postJson('/api/v1/tools/color_convert', array('arguments' => array('color' => '#fff')))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tool_not_assigned');
    }

    public function test_an_unallowlisted_tool_asks_for_approval_instead(): void
    {
        // Denying outright kept machines read-only forever. The guidance for an
        // agent action is propose -> approve -> execute, so an assigned but
        // unallowlisted tool queues an approval rather than failing.
        config(array('oos.machine_tools' => array('base64')));

        [$user, $assistant] = $this->fixture(array('base64', 'color_convert'));

        $this->withHeader('Authorization', 'Bearer ' . $this->token($user, $assistant))
            ->postJson('/api/v1/tools/color_convert', array('arguments' => array('color' => '#fff')))
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending_approval');

        $this->assertDatabaseHas('nvoos_approvals', array(
            'tool' => 'color_convert',
            'assistant_id' => $assistant->getKey(),
            'status' => 'pending',
        ));
    }

    public function test_a_tool_that_is_not_registered_is_refused(): void
    {
        config(array('oos.machine_tools' => array('unregistered_tool')));

        [$user, $assistant] = $this->fixture(array('unregistered_tool'));

        $this->withHeader('Authorization', 'Bearer ' . $this->token($user, $assistant))
            ->postJson('/api/v1/tools/unregistered_tool', array('arguments' => array()))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'tool_not_registered');
    }

    public function test_arguments_must_be_an_object(): void
    {
        config(array('oos.machine_tools' => array('base64')));

        [$user, $assistant] = $this->fixture(array('base64'));

        $this->withHeader('Authorization', 'Bearer ' . $this->token($user, $assistant))
            ->postJson('/api/v1/tools/base64', array('arguments' => 'not-an-object'))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_arguments');
    }

    public function test_every_denial_is_recorded(): void
    {
        config(array('oos.machine_tools' => array('base64', 'color_convert')));

        [$user, $assistant] = $this->fixture(array('base64'));

        $this->withHeader('Authorization', 'Bearer ' . $this->token($user, $assistant))
            ->postJson('/api/v1/tools/color_convert', array('arguments' => array('color' => '#fff')))
            ->assertForbidden();

        $row = DB::table('nvoos_activity_events')
            ->where('type', 'tool.machine_denied')
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'A denial must leave a record.');
        $this->assertSame($assistant->getKey(), (int) $row->agent_id);
        $this->assertStringContainsString('tool_not_assigned', (string) $row->context);
    }

    /**
     * @param list<string> $tools
     * @return array{0: User, 1: Assistant}
     */
    private function fixture(array $tools): array
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        TenantContext::set(Tenant::DEFAULT_ID);

        $assistant = app(AssistantWriter::class)->create(array(
            'title' => 'Machine fixture',
            'tools' => $tools,
        ));

        TenantContext::forget();

        return array($user, $assistant);
    }

    private function token(User $user, Assistant $assistant): string
    {
        return $user->createToken('machine', array('assistant:' . $assistant->getKey()))->plainTextToken;
    }
}
