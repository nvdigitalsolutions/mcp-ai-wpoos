<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Nvoos\Core\Application\Provider\ProviderRouter;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 4.5 — toolkit research (create-from-query) service.
 *
 * The draft is never written here: the console saves it through storeEntity,
 * so the manifest's column validation remains the only gate into the table.
 */
class ToolkitResearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * @param array<string, mixed> $completion
     */
    private function providerReturns(array $completion): void
    {
        $this->mock(ProviderRouter::class, function (MockInterface $mock) use ($completion): void {
            $mock->shouldReceive('chat')->once()->andReturn($completion);
        });
    }

    private function completion(string $content): array
    {
        return [
            'model' => 'gpt-test',
            'choices' => [
                ['message' => ['content' => $content]],
            ],
        ];
    }

    public function test_research_drafts_only_the_declared_columns(): void
    {
        // The model invents `ghost` and wraps the JSON in a markdown fence.
        $this->providerReturns($this->completion(
            "```json\n{\"sku\":\"A-100\",\"name\":\"Widget\",\"ghost\":\"drop me\"}\n```"
        ));

        $response = $this->postJson('/api/v1/pro/toolkits/commerce/research', [
            'entity' => 'product',
            'query' => 'Add the A-100 widget',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.entity', 'product')
            ->assertJsonPath('data.provider', 'gpt-test')
            ->assertJsonPath('data.draft.sku', 'A-100')
            ->assertJsonPath('data.draft.name', 'Widget');

        $this->assertArrayNotHasKey('ghost', $response->json('data.draft'));
    }

    public function test_research_rejects_an_unknown_entity(): void
    {
        $this->postJson('/api/v1/pro/toolkits/commerce/research', [
            'entity' => 'spaceship',
            'query' => 'anything',
        ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'entity_not_found');
    }

    public function test_research_validates_the_request(): void
    {
        $this->postJson('/api/v1/pro/toolkits/commerce/research', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['entity', 'query']);
    }

    public function test_research_reports_an_unavailable_provider(): void
    {
        $this->mock(ProviderRouter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('chat')->once()->andReturn(new RuntimeException('No provider configured.'));
        });

        $this->postJson('/api/v1/pro/toolkits/commerce/research', [
            'entity' => 'product',
            'query' => 'Add a widget',
        ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'ai_unavailable')
            ->assertJsonPath('error.message', 'No provider configured.');
    }

    public function test_research_rejects_a_non_json_completion(): void
    {
        $this->providerReturns($this->completion('Sure! Here is your product.'));

        $this->postJson('/api/v1/pro/toolkits/commerce/research', [
            'entity' => 'product',
            'query' => 'Add a widget',
        ])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'ai_invalid_response');
    }

    public function test_research_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/pro/toolkits/commerce/research', [
            'entity' => 'product',
            'query' => 'Add a widget',
        ])
            ->assertStatus(401);
    }
}
