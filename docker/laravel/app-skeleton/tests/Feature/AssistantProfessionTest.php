<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-g Phase 4 — the profession selector's storage.
 *
 * WordPress keeps up to three profession post IDs in
 * `_wp_mcp_ai_primary_roles`. On Laravel the catalogue is reference data, so
 * the identity is the slug and it lives in `meta.primary_role_slugs`.
 */
class AssistantProfessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_professions_are_stored_in_meta_and_returned_flat(): void
    {
        $this->postJson('/api/v1/assistants', [
            'title' => 'Design Lead',
            'primary_role_slugs' => ['ux_designer', 'software_engineer'],
        ])
            ->assertCreated()
            ->assertJsonPath('primary_role_slugs', ['ux_designer', 'software_engineer']);

        $assistant = Assistant::query()->where('slug', 'design-lead')->firstOrFail();

        $this->assertSame(['ux_designer', 'software_engineer'], $assistant->meta['primary_role_slugs']);
    }

    public function test_duplicate_professions_are_collapsed(): void
    {
        $this->postJson('/api/v1/assistants', [
            'title' => 'Design Lead',
            'primary_role_slugs' => ['ux_designer', 'ux_designer'],
        ])->assertCreated();

        $assistant = Assistant::query()->where('slug', 'design-lead')->firstOrFail();

        $this->assertSame(['ux_designer'], $assistant->meta['primary_role_slugs']);
    }

    public function test_at_most_three_professions_are_accepted(): void
    {
        $this->postJson('/api/v1/assistants', [
            'title' => 'Overloaded',
            'primary_role_slugs' => [
                'ux_designer',
                'software_engineer',
                'data_scientist',
                'accountant',
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['primary_role_slugs']);
    }

    public function test_an_unknown_profession_is_rejected(): void
    {
        $this->postJson('/api/v1/assistants', [
            'title' => 'Bad role',
            'primary_role_slugs' => ['not_a_real_role'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['primary_role_slugs.0']);
    }

    public function test_update_replaces_the_selection(): void
    {
        $this->postJson('/api/v1/assistants', [
            'title' => 'Design Lead',
            'primary_role_slugs' => ['ux_designer'],
        ])->assertCreated();

        $assistant = Assistant::query()->where('slug', 'design-lead')->firstOrFail();

        $this->patchJson("/api/v1/assistants/{$assistant->id}", [
            'primary_role_slugs' => ['contract_lawyer', 'registered_nurse', 'accountant'],
        ])
            ->assertOk()
            ->assertJsonPath('data.primary_role_slugs', ['contract_lawyer', 'registered_nurse', 'accountant']);
    }

    public function test_update_without_the_key_keeps_the_selection(): void
    {
        $this->postJson('/api/v1/assistants', [
            'title' => 'Design Lead',
            'primary_role_slugs' => ['ux_designer'],
        ])->assertCreated();

        $assistant = Assistant::query()->where('slug', 'design-lead')->firstOrFail();

        $this->patchJson("/api/v1/assistants/{$assistant->id}", ['title' => 'Design Lead 2'])
            ->assertOk()
            ->assertJsonPath('data.primary_role_slugs', ['ux_designer']);
    }
}
