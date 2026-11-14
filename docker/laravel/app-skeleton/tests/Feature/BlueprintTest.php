<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-b task 2.4 (read half) — the assistant blueprint catalogue.
 *
 * Mirrors `WP_MCP_AI_Pro_Blueprints_REST_Controller::get_list()` and
 * `get_details()`: same envelope, same normalised payload, so the console's
 * `BlueprintsPage` renders identically on either runtime.
 */
class BlueprintTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A blueprint that ships in the mirror and exercises every normalised
     * field: CRM-style `meta`, a profession, a framework, channels and a
     * non-empty tool list.
     */
    private const SAMPLE = ['toolkit' => 'crm', 'slug' => 'agency-account-manager'];

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_the_catalogue_groups_blueprints_by_toolkit(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/v1/blueprints');

        $response->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');

        $this->assertGreaterThanOrEqual(60, $data['total']);
        $this->assertNotEmpty($data['toolkits']);

        $toolkits = collect($data['toolkits'])->keyBy('slug');

        $this->assertTrue($toolkits->has('crm'), 'CRM toolkit should be listed.');
        $this->assertSame('CRM & Email Marketing', $toolkits['crm']['label']);

        $blueprint = collect($toolkits['crm']['blueprints'])->firstWhere('slug', self::SAMPLE['slug']);

        $this->assertNotNull($blueprint, 'The sample blueprint should be listed.');
        $this->assertNotEmpty($blueprint['name']);
        $this->assertSame(19, $blueprint['tool_count']);
        $this->assertFalse($blueprint['installed']);
        $this->assertSame(0, $blueprint['assistant_id']);
    }

    public function test_a_blueprint_detail_is_normalised(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson(
            '/api/v1/blueprints/'.self::SAMPLE['toolkit'].'/'.self::SAMPLE['slug']
        );

        $response->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');

        $this->assertSame(self::SAMPLE['toolkit'], $data['toolkit']);
        $this->assertSame(self::SAMPLE['slug'], $data['slug']);
        $this->assertNotEmpty($data['name']);
        $this->assertNotEmpty($data['description']);
        $this->assertNotEmpty($data['instructions']);

        $this->assertCount(19, $data['tools']);

        // The CRM schema carries these in `meta`; the details payload promotes
        // the ones the console needs into `defaults`.
        $this->assertIsArray($data['defaults']);
        $this->assertArrayHasKey('profession', $data['defaults']);
        $this->assertArrayHasKey('framework', $data['defaults']);
        $this->assertArrayHasKey('channels', $data['defaults']);

        // Not supplied by this blueprint, so it must not be invented.
        $this->assertArrayNotHasKey('model', $data['defaults']);
    }

    public function test_an_unknown_blueprint_is_a_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/blueprints/'.self::SAMPLE['toolkit'].'/not-a-blueprint')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_an_unknown_toolkit_is_a_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/blueprints/not-a-toolkit/whatever')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_the_catalogue_requires_authentication(): void
    {
        $this->getJson('/api/v1/blueprints')->assertUnauthorized();
    }

    public function test_installing_a_blueprint_creates_an_assistant(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson($this->installUrl());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.toolkit', self::SAMPLE['toolkit'])
            ->assertJsonPath('data.slug', self::SAMPLE['slug']);

        $assistantId = $response->json('data.assistant_id');

        $this->assertGreaterThan(0, $assistantId);

        $assistant = Assistant::findOrFail($assistantId);

        $this->assertSame('publish', $assistant->status);
        $this->assertSame(self::SAMPLE['slug'], $assistant->meta['_blueprint_source']);
        $this->assertNotEmpty($assistant->system_prompt);
        $this->assertCount(19, $assistant->tools);

        // The CRM schema's profession and framework survive the remap.
        $this->assertArrayHasKey('primary_role_slugs', $assistant->meta);
        $this->assertArrayHasKey('framework', $assistant->meta);

        // …and the catalogue now reports it as installed, by id.
        $catalogue = $this->getJson('/api/v1/blueprints')->json('data');
        $crm = collect($catalogue['toolkits'])->firstWhere('slug', self::SAMPLE['toolkit']);
        $blueprint = collect($crm['blueprints'])->firstWhere('slug', self::SAMPLE['slug']);

        $this->assertTrue($blueprint['installed']);
        $this->assertSame($assistantId, $blueprint['assistant_id']);
    }

    public function test_installing_twice_conflicts_without_overwrite(): void
    {
        $this->actingAsAdmin();

        $this->postJson($this->installUrl())->assertOk();

        $this->postJson($this->installUrl())
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(1, Assistant::query()->count());
    }

    public function test_overwrite_replaces_the_existing_assistant(): void
    {
        $this->actingAsAdmin();

        $first = $this->postJson($this->installUrl())->json('data.assistant_id');

        $second = $this->postJson($this->installUrl(), ['overwrite' => true])
            ->assertOk()
            ->json('data.assistant_id');

        $this->assertSame($first, $second, 'Overwrite should update in place, not duplicate.');
        $this->assertSame(1, Assistant::query()->count());
    }

    public function test_installing_an_unknown_blueprint_is_a_404(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/blueprints/'.self::SAMPLE['toolkit'].'/not-a-blueprint/install')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    private function installUrl(): string
    {
        return '/api/v1/blueprints/'.self::SAMPLE['toolkit'].'/'.self::SAMPLE['slug'].'/install';
    }
}
