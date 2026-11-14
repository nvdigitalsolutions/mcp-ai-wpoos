<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Tests\TestCase;

/**
 * 090-b task 2.7 — the assistant test / dry run.
 *
 * The endpoint mirrors WordPress's Test Assistant page: resolve what a turn
 * would send, then optionally run one turn. It must never persist a
 * conversation — saving is `/chat-transcripts`, exactly as in the console.
 */
class AssistantTestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function createAssistant(array $overrides = []): int
    {
        return $this->postJson('/api/v1/assistants', array_merge([
            'title' => 'Test Subject',
            'system_prompt' => 'Be brief.',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'tools' => ['list_ecas'],
        ], $overrides))->assertCreated()->json('id');
    }

    private function resolveTo(?string $slug): void
    {
        $client = null;

        if (null !== $slug) {
            /** @var AbstractProviderClient&MockInterface $client */
            $client = Mockery::mock(AbstractProviderClient::class);
            $client->shouldReceive('getProviderSlug')->andReturn($slug);
        }

        $this->mock(ProviderRouter::class, function (MockInterface $mock) use ($client): void {
            $mock->shouldReceive('resolveForChat')->andReturn($client);
        });
    }

    private function completion(string $content): array
    {
        return [
            'model' => 'gpt-test',
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ];
    }

    public function test_an_empty_body_resolves_the_plan_without_calling_a_provider(): void
    {
        $this->resolveTo('openai');
        $id = $this->createAssistant();

        $response = $this->postJson("/api/v1/assistants/{$id}/test")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assistant.id', $id)
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.reply', null)
            ->assertJsonPath('data.provider.configured', 'openai')
            ->assertJsonPath('data.provider.resolved', 'openai')
            ->assertJsonPath('data.model', 'gpt-4o-mini')
            ->assertJsonPath('data.system_prompt.present', true)
            ->assertJsonPath('data.tools.assigned.0', 'list_ecas')
            ->assertJsonPath('data.tools.missing', [])
            ->assertJsonPath('data.warnings', [])
            ->assertJsonPath('data.iterations', 0);

        $this->assertGreaterThan(0, $response->json('data.system_prompt.characters'));
    }

    public function test_the_plan_names_every_reason_a_turn_would_not_work(): void
    {
        $this->resolveTo(null);

        $id = $this->createAssistant([
            'system_prompt' => '',
            'provider' => '',
            'model' => '',
            'tools' => ['not_a_tool'],
        ]);

        $this->postJson("/api/v1/assistants/{$id}/test")
            ->assertOk()
            ->assertJsonPath('data.warnings', [
                'no_provider',
                'provider_unavailable',
                'no_model',
                'no_system_prompt',
                'tool_not_registered:not_a_tool',
            ])
            ->assertJsonPath('data.tools.missing.0', 'not_a_tool')
            ->assertJsonPath('data.tools.registered', []);
    }

    public function test_a_message_runs_exactly_one_turn_and_persists_nothing(): void
    {
        $id = $this->createAssistant();

        /** @var AbstractProviderClient&MockInterface $client */
        $client = Mockery::mock(AbstractProviderClient::class);
        $client->shouldReceive('getProviderSlug')->andReturn('openai');

        $this->mock(ProviderRouter::class, function (MockInterface $mock) use ($client): void {
            $mock->shouldReceive('resolveForChat')->andReturn($client);
            $mock->shouldReceive('chat')->once()->andReturn([
                'model' => 'gpt-test',
                'choices' => [[
                    'message' => ['content' => 'Hello from the test turn.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]);
        });

        $this->postJson("/api/v1/assistants/{$id}/test", ['message' => 'Say hello'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.dry_run', false)
            ->assertJsonPath('data.reply', 'Hello from the test turn.')
            // Agentic iterations = tool rounds. A plain reply completes
            // before the loop runs, so zero is correct here.
            ->assertJsonPath('data.iterations', 0)
            ->assertJsonPath('data.tool_calls', []);

        // The dry run is side-effect free: no transcript row was written.
        $this->assertSame(0, DB::table('nvoos_transcripts')->count());
    }

    public function test_a_message_without_a_provider_is_a_typed_503(): void
    {
        $this->resolveTo(null);
        $id = $this->createAssistant();

        $this->postJson("/api/v1/assistants/{$id}/test", ['message' => 'Say hello'])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'ai_unavailable');
    }

    public function test_messages_are_validated(): void
    {
        $this->resolveTo('openai');
        $id = $this->createAssistant();

        $this->postJson("/api/v1/assistants/{$id}/test", [
            'messages' => [['role' => 'wizard', 'content' => 'hi']],
        ])->assertStatus(422);
    }

    public function test_another_tenants_assistant_is_not_found(): void
    {
        $this->resolveTo('openai');

        $assistant = new Assistant(['title' => 'Other', 'slug' => 'other', 'status' => 'draft']);
        $assistant->tenant_id = 'tenant-b';
        $assistant->save();

        $this->postJson("/api/v1/assistants/{$assistant->id}/test")->assertNotFound();
    }

    public function test_non_admins_cannot_reach_the_test_surface(): void
    {
        // Assistant writes are admin-only, so seed the subject first.
        $id = $this->createAssistant();

        Sanctum::actingAs(User::factory()->editor()->create());

        $this->postJson("/api/v1/assistants/{$id}/test")->assertForbidden();
    }
}
