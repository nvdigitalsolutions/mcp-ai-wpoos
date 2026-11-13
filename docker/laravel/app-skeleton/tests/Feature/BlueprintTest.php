<?php

namespace Tests\Feature;

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
}
