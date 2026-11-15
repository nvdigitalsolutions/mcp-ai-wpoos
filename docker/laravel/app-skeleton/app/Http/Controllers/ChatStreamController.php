<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Domain\ValueObject\CancellationToken;
use Nvoos\Core\Infrastructure\Streaming\SseHandler;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatStreamController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $request->validate([
            'messages' => ['required', 'array'],
            'assistant' => ['sometimes', 'array'],
            'options' => ['sometimes', 'array'],
        ]);

        $headers = [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ];

        return response()->stream(function () use ($request): void {
            /** @var SseHandler $sse */
            $sse = app(SseHandler::class);
            $sse->markHeadersSent();

            $token = new CancellationToken(
                probe: static fn (): bool => connection_aborted() !== 0,
            );

            try {
                app(ChatOrchestrator::class)->handleChatStreaming(
                    messages: $request->input('messages', []),
                    assistantConfig: $request->input('assistant', []),
                    userId: (int) ($request->user()?->id ?? 0),
                    assistantId: (int) $request->input('assistant_id', 0),
                    options: $request->input('options', []),
                    cancellation: $token,
                );
            } catch (\Throwable $e) {
                $sse->sendEvent('error', [
                    'code' => 'stream_failed',
                    'message' => $e->getMessage(),
                ]);
                $sse->sendDone();
            }
        }, 200, $headers);
    }
}
