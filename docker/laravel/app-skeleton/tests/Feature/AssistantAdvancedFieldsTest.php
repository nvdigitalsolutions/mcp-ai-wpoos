<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-B assistant edit parity — the additive `advanced` object.
 *
 * WordPress exposes these settings through 16 edit-screen metaboxes backed by
 * `_wp_mcp_ai_*` post meta. Laravel stores the same values as flat keys in the
 * `meta` JSON column and promotes them to `advanced` on read, so the shared
 * console form writes one payload against either runtime.
 */
class AssistantAdvancedFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_create_stores_advanced_and_returns_it(): void
    {
        $this->postJson('/api/v1/assistants', [
            'title' => 'Deep Research',
            'advanced' => [
                'fallback_chain' => ['openai', 'anthropic'],
                'adaptive_tool_cap' => 'on',
                'memory_files' => [12, '34'],
                'vector_store_id' => 'vs_123',
                'corpus_name' => 'corpus-a',
                'prompt_caching' => true,
                'disable_tool_shortcuts' => false,
                'external_action_id' => 'action-9',
                'external_action_type' => 'workflow',
                'required_capability' => 'manage_options',
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('advanced.fallback_chain', ['openai', 'anthropic'])
            ->assertJsonPath('advanced.adaptive_tool_cap', 'on')
            ->assertJsonPath('advanced.memory_files', [12, 34])
            ->assertJsonPath('advanced.vector_store_id', 'vs_123')
            ->assertJsonPath('advanced.prompt_caching', true)
            ->assertJsonPath('advanced.external_action_type', 'workflow')
            ->assertJsonPath('advanced.required_capability', 'manage_options');

        $assistant = Assistant::query()->where('slug', 'deep-research')->firstOrFail();

        $this->assertSame(['openai', 'anthropic'], $assistant->meta['fallback_chain']);
        $this->assertSame([12, 34], $assistant->meta['memory_files']);
    }

    public function test_show_returns_type_defaults_for_unset_keys(): void
    {
        $created = $this->postJson('/api/v1/assistants', [
            'title' => 'Bare Assistant',
        ])->assertCreated();

        $this->getJson('/api/v1/assistants/'.$created->json('id'))
            ->assertOk()
            ->assertJsonPath('data.advanced.fallback_chain', [])
            ->assertJsonPath('data.advanced.memory_files', [])
            ->assertJsonPath('data.advanced.vector_store_id', '')
            ->assertJsonPath('data.advanced.corpus_name', '')
            ->assertJsonPath('data.advanced.prompt_caching', false)
            ->assertJsonPath('data.advanced.disable_tool_shortcuts', false)
            ->assertJsonPath('data.advanced.required_capability', '');
    }

    public function test_update_sets_and_clears_advanced_keys(): void
    {
        $created = $this->postJson('/api/v1/assistants', [
            'title' => 'Editable Assistant',
            'advanced' => [
                'fallback_chain' => ['openai'],
                'corpus_name' => 'corpus-a',
                'prompt_caching' => true,
            ],
        ])->assertCreated();

        $id = $created->json('id');

        // Present-but-empty clears; a key absent from the payload is untouched.
        $this->putJson("/api/v1/assistants/{$id}", [
            'advanced' => [
                'fallback_chain' => [],
                'corpus_name' => '',
                'prompt_caching' => false,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.advanced.fallback_chain', [])
            ->assertJsonPath('data.advanced.corpus_name', '')
            ->assertJsonPath('data.advanced.prompt_caching', false);

        $assistant = Assistant::query()->findOrFail($id);

        $this->assertArrayNotHasKey('fallback_chain', $assistant->meta);
        $this->assertArrayNotHasKey('corpus_name', $assistant->meta);
        $this->assertArrayNotHasKey('prompt_caching', $assistant->meta);
    }

    public function test_unknown_keys_are_ignored_and_credentials_never_exposed(): void
    {
        $created = $this->postJson('/api/v1/assistants', [
            'title' => 'Safe Assistant',
            'advanced' => [
                'not_a_real_field' => 'ignored',
                'credentials' => ['token' => 'cred_test.secret'],
                'vector_store_id' => 'vs_ok',
            ],
        ])->assertCreated();

        $assistant = Assistant::query()->findOrFail($created->json('id'));

        $this->assertArrayNotHasKey('not_a_real_field', $assistant->meta);
        $this->assertArrayNotHasKey('credentials', $assistant->meta);
        $this->assertSame('vs_ok', $assistant->meta['vector_store_id']);

        $record = $this->getJson('/api/v1/assistants/'.$created->json('id'))
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('credentials', $record['advanced']);
        $this->assertArrayNotHasKey('not_a_real_field', $record['advanced']);
    }

    public function test_advanced_merges_with_primary_roles_and_meta(): void
    {
        $created = $this->postJson('/api/v1/assistants', [
            'title' => 'Merged Assistant',
            'primary_role_slugs' => ['ux_designer'],
            'meta' => ['custom_key' => 'kept'],
            'advanced' => ['vector_store_id' => 'vs_merge'],
        ])->assertCreated();

        $assistant = Assistant::query()->findOrFail($created->json('id'));

        $this->assertSame(['ux_designer'], $assistant->meta['primary_role_slugs']);
        $this->assertSame('kept', $assistant->meta['custom_key']);
        $this->assertSame('vs_merge', $assistant->meta['vector_store_id']);
    }
}
