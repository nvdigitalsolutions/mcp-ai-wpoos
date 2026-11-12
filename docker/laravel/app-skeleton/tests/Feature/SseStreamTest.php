<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SseStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_stream_emits_sse_frames(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->post('/chat/stream', [
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

        $response->assertOk();

        $content = $response->streamedContent();

        $this->assertStringContainsString('event: message', $content);
        $this->assertStringContainsString('[DONE]', $content);
    }
}
