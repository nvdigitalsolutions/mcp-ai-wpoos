<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Application\Chat\ChatOrchestrator;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
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
        ToolRegistry $tools,
        ErrorFactoryInterface $errors,
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

        // What a turn would send. `resolveForChat()` falls back to the site
        // default provider, so the resolved slug is reported separately from
        // the assistant's own setting rather than conflated with it.
        $client = $router->resolveForChat([], $config);
        $configuredProvider = (string) ($config['provider'] ?? '');
        $assigned = array_values(array_filter(
            (array) ($config['tools'] ?? []),
            static fn ($slug): bool => is_string($slug) && '' !== $slug,
        ));
        $registered = array_values(array_filter($assigned, static fn (string $slug): bool => $tools->has($slug)));
        $missing = array_values(array_diff($assigned, $registered));
        $prompt = (string) ($config['system_prompt'] ?? '');

        $warnings = [];
        if ('' === $configuredProvider) {
            $warnings[] = 'no_provider';
        }
        if (null === $client) {
            $warnings[] = 'provider_unavailable';
        }
        if ('' === (string) ($config['model'] ?? '')) {
            $warnings[] = 'no_model';
        }
        if ('' === trim($prompt)) {
            $warnings[] = 'no_system_prompt';
        }
        if ([] === $assigned) {
            $warnings[] = 'no_tools';
        }
        foreach ($missing as $slug) {
            $warnings[] = 'tool_not_registered:' . $slug;
        }

        $plan = [
            'assistant' => [
                'id' => (int) $assistant->id,
                'title' => (string) $assistant->title,
                'status' => (string) $assistant->status,
            ],
            'provider' => [
                'configured' => $configuredProvider,
                'resolved' => $client?->getProviderSlug() ?? '',
            ],
            'model' => (string) ($config['model'] ?? ''),
            'system_prompt' => [
                'present' => '' !== trim($prompt),
                'characters' => strlen($prompt),
            ],
            'tools' => [
                'assigned' => $assigned,
                'registered' => $registered,
                'missing' => $missing,
            ],
            'warnings' => $warnings,
            'dry_run' => true,
            'reply' => null,
            'tool_calls' => [],
            'iterations' => 0,
            'latency_ms' => null,
            'cost' => null,
        ];

        $messages = $this->messages($validated);

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
     * Normalise the two request shapes into one conversation.
     *
     * `messages` wins when both are supplied — it is the explicit form — and a
     * bare `message` becomes a single user turn.
     *
     * @param array<string, mixed> $validated
     * @return array<int, array<string, mixed>>
     */
    private function messages(array $validated): array
    {
        if (! empty($validated['messages'])) {
            return array_values($validated['messages']);
        }

        $message = trim((string) ($validated['message'] ?? ''));

        return '' === $message ? [] : [['role' => 'user', 'content' => $message]];
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
