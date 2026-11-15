<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SseStreamTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The console's transport path. `useChatSpoke` → `createChatFetch` POSTs
     * to `api/v1/chat-client` and translates these OpenAI-compatible frames
     * into the AI SDK data-stream protocol client-side, so `event: message` +
     * `choices[].delta` and the `[DONE]` marker are the whole contract.
     */
    public function test_chat_client_emits_sse_frames(): void
    {
        $response = $this->postChat('/api/v1/chat-client');

        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: message', $content);
        $this->assertStringContainsString('"delta"', $content);
        $this->assertStringContainsString('[DONE]', $content);
    }

    /**
     * The earlier name for the same stream, now under the `api/v1` prefix it
     * always belonged beneath.
     */
    public function test_chat_stream_emits_sse_frames(): void
    {
        $response = $this->postChat('/api/v1/chat/stream');

        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: message', $content);
        $this->assertStringContainsString('[DONE]', $content);
    }

    public function test_the_chat_client_route_requires_authentication(): void
    {
        $this->postJson('/api/v1/chat-client', [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])->assertStatus(401);
    }

    public function test_the_chat_client_route_validates_the_payload(): void
    {
        $response = $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/chat-client', []);

        $response->assertStatus(422)->assertJsonValidationErrors('messages');
    }

    private function postChat(string $url)
    {
        return $this->actingAs(User::factory()->create(), 'sanctum')->post($url, [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
            ],
            'assistant' => [
                'provider' => 'spike',
                'model' => 'spike-1',
                'tools' => [],
            ],
            'options' => [],
        ]);
    }
}
