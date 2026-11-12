<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_create_list_show_update_delete(): void
    {
        $create = $this->postJson('/api/v1/assistants', [
            'title' => 'Ops Assistant',
            'system_prompt' => 'Be helpful.',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.3,
            'max_tokens' => 1000,
            'tools' => ['color_convert'],
            'is_default' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('title', 'Ops Assistant')
            ->assertJsonPath('slug', 'ops-assistant');

        $id = $create->json('id');

        $this->getJson('/api/v1/assistants')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('assistants.0.id', $id);

        $this->getJson("/api/v1/assistants/{$id}")
            ->assertOk()
            ->assertJsonPath('data.tools.0', 'color_convert');

        $this->putJson("/api/v1/assistants/{$id}", [
            'title' => 'Ops Assistant v2',
            'temperature' => 0.5,
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Ops Assistant v2')
            ->assertJsonPath('data.temperature', 0.5);

        $this->deleteJson("/api/v1/assistants/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('assistants', ['id' => $id]);
    }

    public function test_only_one_default_assistant(): void
    {
        $first = $this->postJson('/api/v1/assistants', ['title' => 'A', 'is_default' => true])->json('id');
        $second = $this->postJson('/api/v1/assistants', ['title' => 'B', 'is_default' => true])->json('id');

        $this->assertDatabaseHas('assistants', ['id' => $first, 'is_default' => false]);
        $this->assertDatabaseHas('assistants', ['id' => $second, 'is_default' => true]);
    }

    public function test_slug_collisions_get_a_suffix(): void
    {
        $this->postJson('/api/v1/assistants', ['title' => 'Ops'])->assertCreated();
        $this->postJson('/api/v1/assistants', ['title' => 'Ops'])
            ->assertCreated()
            ->assertJsonPath('slug', 'ops-2');
    }

    public function test_export_then_import_round_trip(): void
    {
        $this->postJson('/api/v1/assistants', [
            'title' => 'Bundle Bot',
            'system_prompt' => 'Prompt.',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'tools' => ['color_convert'],
        ])->assertCreated();

        $bundle = $this->postJson('/api/v1/assistants/export', [])
            ->assertOk()
            ->json('data');

        $this->assertSame('nvoos-assistant', $bundle['format']);
        $this->assertSame(1, $bundle['format_version']);
        $this->assertCount(1, $bundle['assistants']);

        $json = json_encode($bundle);

        $this->postJson('/api/v1/assistants/import', ['json' => $json, 'dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data.skipped', 1);

        $this->postJson('/api/v1/assistants/import', ['json' => $json, 'mode' => 'duplicate'])
            ->assertOk()
            ->assertJsonPath('data.imported', 1);

        $this->assertDatabaseCount('assistants', 2);
    }

    public function test_import_rejects_a_foreign_payload(): void
    {
        $this->postJson('/api/v1/assistants/import', ['json' => '{"format":"other"}'])
            ->assertStatus(422);
    }
}
