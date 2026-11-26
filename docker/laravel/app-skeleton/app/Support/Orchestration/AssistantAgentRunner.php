<?php

namespace App\Support\Orchestration;

use App\Support\AgentNodeRunner;
use Nvoos\Core\Domain\Contract\AgentRunnerInterface;

/**
 * The orchestration runner, backed by the workflow node runner — Proposal 149 §163.
 *
 * A workflow `agent` node and a delegated subtask are the same operation, so
 * they share one implementation. That is the point: the node runner already
 * applies the assistant's own provider/model/tool set, bounds the turn to five
 * agentic iterations, and asserts the estate budget. A second runner here
 * would be a second set of guard rails to keep in step.
 *
 * The identity is per call because a delegated subtask must run as the person
 * who asked, not as whoever owns the queue.
 */
final class AssistantAgentRunner implements AgentRunnerInterface
{
    /**
     * {@inheritDoc}
     */
    public function run(int $assistantId, string $prompt, int $userId, array $context = array()): array
    {
        return (new AgentNodeRunner($userId))((string) $assistantId, $prompt, $context);
    }
}
