<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\RateLimiterInterface;
use Nvoos\Laravel\Adapter\TokenUsage;
use Tests\TestCase;

class CoreOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['name' => 'Owner']));
    }

    public function test_transcripts_round_trip_and_upsert(): void
    {
        $this->postJson('/api/v1/chat-transcripts', [
            'session_key' => 'session-1',
            'assistant_id' => 7,
            'messages' => [
                ['role' => 'user', 'content' => 'Hello there'],
                ['role' => 'assistant', 'content' => 'Hi'],
            ],
        ])->assertCreated();

        $this->getJson('/api/v1/chat-transcripts')
            ->assertOk()
            ->assertJsonPath('sessions.0.session_key', 'session-1')
            ->assertJsonPath('sessions.0.turn_count', 2)
            ->assertJsonPath('sessions.0.preview', 'Hello there');

        $this->getJson('/api/v1/chat-transcripts/session-1')
            ->assertOk()
            ->assertJsonPath('session.session_key', 'session-1')
            ->assertJsonCount(2, 'session.messages');

        $this->postJson('/api/v1/chat-transcripts', [
            'session_key' => 'session-1',
            'assistant_id' => 7,
            'messages' => [
                ['role' => 'user', 'content' => 'Hello there'],
                ['role' => 'assistant', 'content' => 'Hi'],
                ['role' => 'user', 'content' => 'Again'],
            ],
        ])->assertCreated();

        $this->getJson('/api/v1/chat-transcripts')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('sessions.0.turn_count', 3);

        $this->deleteJson('/api/v1/chat-transcripts/session-1')->assertNoContent();
        $this->getJson('/api/v1/chat-transcripts')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_memory_store_recall_update_delete_and_preferences(): void
    {
        $this->getJson('/api/v1/chat-memory/preferences')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('autosummarize', false);

        $this->postJson('/api/v1/chat-memory/preferences', ['autosummarize' => true])
            ->assertOk()
            ->assertJsonPath('autosummarize', true);

        $stored = $this->postJson('/api/v1/chat-memory/store', [
            'agent_id' => '7',
            'content' => 'The client prefers concise replies.',
            'title' => 'Client preference',
            'importance' => 'high',
            'tags' => ['preference'],
            'wing' => 'client',
            'room' => 'tone',
        ])->assertCreated()->assertJsonPath('importance', 0.75);

        $id = $stored->json('context_id');

        $this->getJson('/api/v1/chat-memory/recall?agent_id=7&wing=client')
            ->assertOk()
            ->assertJsonPath('contexts.0.context_id', $id)
            ->assertJsonPath('contexts.0.title', 'Client preference');

        $this->getJson('/api/v1/chat-memory/recall?agent_id=7&query=concise')
            ->assertOk()
            ->assertJsonPath('contexts.0.context_id', $id);

        $this->putJson("/api/v1/chat-memory/{$id}", [
            'title' => 'Client tone preference',
        ])->assertNoContent();

        $this->getJson('/api/v1/chat-memory/recall?agent_id=7')
            ->assertOk()
            ->assertJsonPath('contexts.0.title', 'Client tone preference');

        $this->deleteJson("/api/v1/chat-memory/{$id}")->assertNoContent();
        $this->getJson('/api/v1/chat-memory/recall?agent_id=7')
            ->assertOk()
            ->assertJsonCount(0, 'contexts');
    }

    public function test_analytics_summary_trend_and_provider_breakdown(): void
    {
        $usage = app(TokenUsage::class);
        $usage->trackUsage(1, 'gpt-4o-mini', 100, 50, [
            'provider' => 'openai',
            'cost' => 0.02,
            'tool' => 'color_convert',
        ]);
        $usage->trackUsage(1, 'gemini-2.0-flash', 200, 100, [
            'provider' => 'gemini',
            'cost' => 0.01,
        ]);

        $this->getJson('/api/v1/cost/dashboard-summary?days=30')
            ->assertOk()
            ->assertJsonPath('summary.total_tokens', 450)
            ->assertJsonPath('summary.by_provider.openai', 0.02)
            ->assertJsonPath('summary.by_provider.gemini', 0.01)
            ->assertJsonPath('summary.top_tools.color_convert', 0.02);

        $this->getJson('/api/v1/cost/trend?days=7')
            ->assertOk()
            ->assertJsonCount(7, 'data.labels')
            ->assertJsonCount(7, 'data.datasets.0.data');

        $this->getJson('/api/v1/cost/by-provider?days=30')
            ->assertOk()
            ->assertJsonPath('data.labels.0', 'openai')
            ->assertJsonPath('data.labels.1', 'gemini');
    }

    public function test_rate_limiter_contract(): void
    {
        $limiter = app(RateLimiterInterface::class);
        $limiter->reset('test-key');

        $this->assertTrue($limiter->isAllowed('test-key', 2, 60));

        $limiter->record('test-key', 60);
        $limiter->record('test-key', 60);

        $this->assertFalse($limiter->isAllowed('test-key', 2, 60));
        $this->assertSame(0, $limiter->remaining('test-key', 2, 60));

        $limiter->reset('test-key');
        $this->assertTrue($limiter->isAllowed('test-key', 2, 60));
    }
}
