<?php

namespace App\Support;

use App\Models\Assistant;
use Nvoos\Core\Application\Chat\ChatOrchestrator;

/**
 * Runs one workflow `agent` node through the chat orchestrator.
 *
 * A node addresses an assistant two ways: `data.agentId` as a record id, or
 * the site's default assistant when the node says `default` (what the canvas
 * writes). The assistant's own provider, model and tool set are used, so the
 * node runs with the same identity as that assistant's chat — and the
 * orchestrator's iteration cap and rate limiter apply unchanged.
 *
 * Extracted from the run controller because the single-node endpoint
 * (`POST /pro/workflow-builder/nodes/execute`) must behave identically.
 */
final class AgentNodeRunner
{
    public function __construct(private readonly int $userId)
    {
    }

    /**
     * @param array<string, mixed> $context Run context (unused today, kept for parity with the executor seam).
     * @return array{iterations: int, response: mixed}
     */
    public function __invoke(string $agentId, string $prompt, array $context = []): array
    {
        unset($context);

        $assistant = is_numeric($agentId)
            ? Assistant::query()->find((int) $agentId)
            : Assistant::query()->where('is_default', true)->first();

        if (null === $assistant) {
            throw new \RuntimeException(
                'This agent node needs an assistant: address one by id, or mark an assistant as default.'
            );
        }

        $record = $assistant->toRecord();

        $result = app(ChatOrchestrator::class)->handleChat(
            [['role' => 'user', 'content' => $prompt]],
            [
                'system_prompt' => (string) ($record['system_prompt'] ?? ''),
                'tools' => (array) ($record['tools'] ?? []),
                'provider' => (string) ($record['provider'] ?? ''),
                'model' => (string) ($record['model'] ?? ''),
                // A workflow node is one bounded step, not a whole session.
                'max_agentic_iterations' => 5,
            ],
            $this->userId,
            (int) $record['id'],
        );

        $response = $result['response'] ?? null;

        return [
            'iterations' => (int) ($result['iterations'] ?? 0),
            'response' => is_array($response) && array_key_exists('content', $response)
                ? $response['content']
                : $response,
        ];
    }
}
