<?php

namespace Tests\Support;

use Nvoos\Core\Domain\Contract\AgentRunnerInterface;

/**
 * Agent runner that records the turn it was asked to run.
 *
 * Proposal 149 §163: orchestration's model call is the one seam the host tests
 * stub, so every other layer — catalogue, cache, estate scope, tool envelope —
 * is exercised for real.
 */
final class RecordingAgentRunner implements AgentRunnerInterface
{
    /**
     * @var list<array{assistantId: int, prompt: string, userId: int, context: array<string, mixed>}>
     */
    public array $calls = array();

    public function run(int $assistantId, string $prompt, int $userId, array $context = array()): array
    {
        $this->calls[] = array(
            'assistantId' => $assistantId,
            'prompt' => $prompt,
            'userId' => $userId,
            'context' => $context,
        );

        return array(
            'iterations' => 1,
            'response' => 'done:'.$assistantId,
        );
    }
}
