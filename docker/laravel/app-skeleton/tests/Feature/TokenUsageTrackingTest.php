<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Event\CostCalculated;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Tests\TestCase;

/**
 * 149 §81 — recording chat token usage.
 *
 * The table was read by five surfaces and written by nobody: only tests
 * inserted rows, so every cost view was empty on a real deployment. These
 * tests pin the writer and the two rules that make it correct:
 *
 *  - an **agentic** turn is recorded at its accumulated total, not the last
 *    iteration's usage;
 *  - a turn with no tokens, or a deployment with tracking switched off,
 *    writes nothing at all.
 */
class TokenUsageTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'nvoos_token_usage';

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');

        $this->operator = User::factory()->admin()->create();
        Sanctum::actingAs($this->operator);
    }

    public function test_a_completed_turn_writes_a_usage_row(): void
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
                    'message' => ['content' => 'Hello.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]);
        });

        $this->postJson("/api/v1/assistants/{$id}/test", ['message' => 'Say hello'])
            ->assertOk()
            ->assertJsonPath('data.dry_run', false);

        $row = DB::table(self::TABLE)->first();

        $this->assertNotNull($row, 'A completed turn must leave exactly one usage row.');
        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertSame($this->operator->id, (int) $row->user_id);
        $this->assertSame(10, (int) $row->prompt_tokens);
        $this->assertSame(5, (int) $row->completion_tokens);
        $this->assertSame(15, (int) $row->total_tokens);

        $metadata = json_decode((string) $row->metadata, true);

        $this->assertSame('openai', $metadata['provider']);
        $this->assertSame($id, $metadata['assistant_id']);
        $this->assertArrayHasKey('cost', $metadata);
    }

    public function test_the_accumulated_figures_win_over_the_last_iteration(): void
    {
        event(new CostCalculated(
            costData: [
                'prompt_tokens' => 300,
                'completion_tokens' => 90,
                'cost_usd' => 0.0123,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'agentic_iterations_count' => 3,
            ],
            assistantId: 7,
            userId: 1,
            response: ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30]],
        ));

        $row = DB::table(self::TABLE)->first();

        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertSame(300, (int) $row->prompt_tokens);
        $this->assertSame(90, (int) $row->completion_tokens);
        $this->assertSame(390, (int) $row->total_tokens);
        $this->assertEqualsWithDelta(0.0123, (float) $row->cost, 1e-6);

        $metadata = json_decode((string) $row->metadata, true);
        $this->assertSame(3, $metadata['agentic_iterations']);
    }

    public function test_a_turn_without_tokens_writes_nothing(): void
    {
        $this->assertCount(
            1,
            app('events')->getRawListeners()[CostCalculated::class] ?? array(),
            'The listener must be registered exactly once (discovery owns it).'
        );

        event(new CostCalculated(
            costData: [],
            assistantId: 7,
            userId: 1,
            response: [],
        ));

        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_tracking_can_be_disabled(): void
    {
        config(['oos.token_usage.track' => false]);

        event(new CostCalculated(
            costData: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'provider' => 'openai', 'model' => 'gpt-4o-mini'],
            assistantId: 7,
            userId: 1,
            response: [],
        ));

        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    private function createAssistant(): int
    {
        return $this->postJson('/api/v1/assistants', [
            'title' => 'Usage Subject',
            'system_prompt' => 'Be brief.',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
        ])->assertCreated()->json('id');
    }
}
