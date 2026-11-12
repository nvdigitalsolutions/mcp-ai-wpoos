<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-g Phase 1 — assistants are tenant-scoped.
 *
 * These two properties fail against the original schema, which had a global
 * unique slug and a global default flag.
 */
class AssistantTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_api_created_assistants_land_in_the_default_tenant(): void
    {
        $this->postJson('/api/v1/assistants', ['title' => 'Ops'])->assertCreated();

        $this->assertDatabaseHas('assistants', [
            'slug' => 'ops',
            'tenant_id' => Assistant::DEFAULT_TENANT,
        ]);
    }

    public function test_same_slug_is_allowed_in_different_tenants(): void
    {
        $this->makeAssistant('tenant-a', 'ops');
        $this->makeAssistant('tenant-b', 'ops');

        $this->assertSame(2, Assistant::query()->where('slug', 'ops')->count());
    }

    public function test_same_slug_is_rejected_within_a_tenant(): void
    {
        $this->makeAssistant('tenant-a', 'ops');

        $this->expectException(QueryException::class);

        $this->makeAssistant('tenant-a', 'ops');
    }

    public function test_slug_collision_suffix_is_per_tenant(): void
    {
        // Another tenant already owns "ops"; the API must still be able to
        // create "ops" for the default tenant.
        $this->makeAssistant('tenant-b', 'ops');

        $this->postJson('/api/v1/assistants', ['title' => 'Ops'])
            ->assertCreated()
            ->assertJsonPath('slug', 'ops');
    }

    public function test_default_is_scoped_to_the_tenant(): void
    {
        $other = $this->makeAssistant('tenant-b', 'other', true);

        $this->postJson('/api/v1/assistants', [
            'title' => 'Mine',
            'is_default' => true,
        ])->assertCreated();

        // Clearing defaults for the default tenant must not touch tenant-b.
        $this->assertDatabaseHas('assistants', [
            'id' => $other->id,
            'is_default' => true,
        ]);
    }

    private function makeAssistant(string $tenant, string $slug, bool $isDefault = false): Assistant
    {
        $assistant = new Assistant([
            'title' => ucfirst($slug),
            'slug' => $slug,
            'status' => 'draft',
            'is_default' => $isDefault,
        ]);

        // Not mass-assignable on purpose: the tenant never comes from a payload.
        $assistant->tenant_id = $tenant;
        $assistant->save();

        return $assistant;
    }
}
