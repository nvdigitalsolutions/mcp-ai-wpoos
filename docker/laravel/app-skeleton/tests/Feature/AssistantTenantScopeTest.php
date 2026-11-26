<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `Assistant` carries a global tenant scope — Proposal 153.
 *
 * Why the model rather than each call site: twenty-six hand-written `tenant_id`
 * filters had accumulated across eighteen files, one per leak (§27, §39, §40,
 * §42, §47, §89). The assistant is the estate-owned record — its provider, model
 * and prompt are the estate's configuration — so the invariant belongs on the
 * model, as it already does on `Approval`, `Memory`, `Transcript` and
 * `WorkflowRun`.
 *
 * The tests pin the scope, the documented opt-out, the consequence for
 * route-model binding, and the bug the scope closes.
 */
class AssistantTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_scope_hides_another_estates_assistant(): void
    {
        $this->estate('estate-b', 'Estate B');
        $foreign = $this->assistant('estate-b', 'Foreign agent');

        TenantContext::set(Assistant::DEFAULT_TENANT);

        $this->assertNull(Assistant::query()->find($foreign->id));
        $this->assertSame(array(), Assistant::query()->whereKey($foreign->id)->get()->all());
    }

    public function test_without_global_scopes_still_reaches_it(): void
    {
        $this->estate('estate-b', 'Estate B');
        $foreign = $this->assistant('estate-b', 'Foreign agent');

        TenantContext::set(Assistant::DEFAULT_TENANT);

        $this->assertNotNull(
            Assistant::query()->withoutGlobalScopes()->find($foreign->id),
            'The opt-out has to work, or the operator surfaces lose rows silently.'
        );
    }

    public function test_each_estate_sees_exactly_its_own(): void
    {
        $this->estate('estate-b', 'Estate B');
        $home = $this->assistant(Assistant::DEFAULT_TENANT, 'Home agent');
        $foreign = $this->assistant('estate-b', 'Foreign agent');

        TenantContext::set(Assistant::DEFAULT_TENANT);
        $this->assertSame(array($home->id), $this->ids());

        TenantContext::set('estate-b');
        $this->assertSame(array($foreign->id), $this->ids());
    }

    /**
     * Binding runs through `newQuery()`, so the scope applies without a
     * controller remembering to ask — which is the §89 fix, structurally.
     */
    public function test_route_binding_answers_404_for_a_foreign_assistant(): void
    {
        $this->estate('estate-b', 'Estate B');
        $foreign = $this->assistant('estate-b', 'Foreign agent');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/assistants/'.$foreign->id)->assertNotFound();
    }

    /**
     * The bug the scope closes: `where('is_default', true)->first()` does not care
     * whose row it returns, so an estate with no default of its own could be
     * handed another estate's — which is what `AgentNodeRunner` did.
     */
    public function test_a_default_assistant_is_not_taken_from_another_estate(): void
    {
        $this->estate('estate-b', 'Estate B');

        $this->assistant('estate-b', 'B default', true);
        $own = $this->assistant(Assistant::DEFAULT_TENANT, 'A default', true);

        TenantContext::set(Assistant::DEFAULT_TENANT);

        $this->assertSame(
            $own->id,
            (int) Assistant::query()->where('is_default', true)->value('id')
        );
    }

    /**
     * An assistant with no default of its own must resolve to nothing rather than
     * to a neighbour's — the same query with a different fixture.
     */
    public function test_an_estate_without_a_default_gets_none(): void
    {
        $this->estate('estate-b', 'Estate B');

        $this->assistant('estate-b', 'B default', true);

        TenantContext::set(Assistant::DEFAULT_TENANT);

        $this->assertNull(Assistant::query()->where('is_default', true)->first());
    }

    /**
     * @return list<int>
     */
    private function ids(): array
    {
        return Assistant::query()->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function estate(string $id, string $name): void
    {
        Tenant::query()->create(array(
            'id' => $id,
            'name' => $name,
            'slug' => $id,
        ));
    }

    /**
     * Written directly rather than through the API: several tests need a row in
     * an estate the request context is not pinned to, which the writer would
     * refuse to build.
     */
    private function assistant(string $tenantId, string $title, bool $isDefault = false): Assistant
    {
        $assistant = new Assistant(array(
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'status' => 'publish',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'system_prompt' => 'Be brief.',
            'is_default' => $isDefault,
        ));

        $assistant->tenant_id = $tenantId;
        $assistant->save();

        return $assistant;
    }
}
