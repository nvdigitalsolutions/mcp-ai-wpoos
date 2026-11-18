<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Services\AssistantTestPlan;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

/**
 * Task 2.7 — assistant test / dry run.
 *
 * WordPress's Test Assistant page (Admin → AI Assistants → Test Assistant) is
 * the chat UI bound to one assistant. The console already owns the chat
 * surface, so what was missing is the part that page does silently: resolve
 * the assistant and report what a turn would actually send, then — only when
 * asked — run exactly one non-streaming turn.
 *
 * A dry run persists nothing. The orchestrator is constructed without a
 * session log or transcript store (`NvoosServiceProvider`), and this
 * controller writes no transcript: callers that want the conversation saved
 * use `/chat-transcripts`, exactly as the console does.
 *
 * `POST /api/v1/assistants/{assistant}/test`
 *   {}                          → the resolved plan only (`dry_run: true`)
 *   { "message": "hi" }         → plan + one turn
 *   { "messages": [ … ] }       → plan + one turn over a supplied conversation
 *   { "temperature": 0.2, "max_tokens": 400 } → per-request overrides
 */
class AssistantTestController
{
    public function __invoke(
        Request $request,
        Assistant $assistant,
        ChatOrchestrator $orchestrator,
        ProviderRouter $router,
        ErrorFactoryInterface $errors,
        AssistantTestPlan $planBuilder,
    ): JsonResponse {
        abort_if($assistant->tenant_id !== TenantContext::current(), 404);

        $validated = $request->validate([
            'message' => ['sometimes', 'string', 'max:8000'],
            'messages' => ['sometimes', 'array', 'max:100'],
            'messages.*.role' => ['required_with:messages', 'string', 'in:system,user,assistant,tool'],
            'messages.*.content' => ['required_with:messages'],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'max_tokens' => ['sometimes', 'integer', 'between:1,32000'],
        ]);

        $config = $assistant->toRecord();

        // The plan is shared with the `/ops/diagnostics` Test Assistant probe,
        // which renders it without spending a token (§48).
        $client = $router->resolveForChat([], $config);
        $plan = $planBuilder->build($assistant, $client);

        $messages = $planBuilder->messages($validated);

        if ([] === $messages) {
            return response()->json(['success' => true, 'data' => $plan]);
        }

        if (null === $client) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ai_unavailable',
                    'message' => 'No chat provider is configured for this assistant.',
                ],
            ], 503);
        }

        $options = array_filter(
            [
                'temperature' => $validated['temperature'] ?? $config['temperature'] ?? null,
                'max_tokens' => $validated['max_tokens'] ?? $config['max_tokens'] ?? null,
            ],
            static fn ($value): bool => null !== $value,
        );

        $started = microtime(true);
        $result = $orchestrator->handleChat(
            messages: $messages,
            assistantConfig: $config,
            userId: (int) ($request->user()?->id ?? 0),
            assistantId: (int) $assistant->id,
            options: $options,
        );
        $plan['latency_ms'] = (int) round((microtime(true) - $started) * 1000);

        $response = $result['response'] ?? null;

        if ($errors->isError($response)) {
            $normalized = $errors->normalize($response);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'provider_error',
                    'message' => (string) ($normalized['message'] ?? 'The provider request failed.'),
                    'provider_code' => (string) ($normalized['code'] ?? ''),
                ],
                'data' => $plan,
            ], 502);
        }

        $plan['dry_run'] = false;
        $plan['reply'] = $this->replyText($response);
        $plan['tool_calls'] = $this->toolCalls($result['tool_results'] ?? []);
        $plan['iterations'] = (int) ($result['iterations'] ?? 0);
        $plan['cost'] = $result['cost'] ?? null;

        return response()->json(['success' => true, 'data' => $plan]);
    }

    /**
     * Pull the assistant text out of an OpenAI-shaped response.
     *
     * Providers normalise to `choices[0].message`, but a bare assistant
     * message array is also accepted. Content may be a string or a list of
     * parts (text / image), so only text parts are joined.
     */
    private function replyText(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }

        $message = $response['choices'][0]['message']
            ?? (($response['role'] ?? null) === 'assistant' ? $response : null);

        if (! is_array($message)) {
            return null;
        }

        $content = $message['content'] ?? null;

        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return null;
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }

        return [] === $parts ? null : implode("\n", $parts);
    }

    /**
     * Tool slugs a run executed, in call order — enough to debug an assistant
     * without shipping the tool payloads back to the caller.
     *
     * @param mixed $toolResults
     * @return array<int, string>
     */
    private function toolCalls(mixed $toolResults): array
    {
        if (! is_array($toolResults)) {
            return [];
        }

        $calls = [];
        foreach ($toolResults as $entry) {
            $name = is_array($entry) ? ($entry['name'] ?? null) : null;
            if (is_string($name) && '' !== $name) {
                $calls[] = $name;
            }
        }

        return $calls;
    }
}
