<?php

/**
 * Agent runner — one bounded assistant turn.
 *
 * This is the seam between orchestration and the chat pipeline. A workflow
 * `agent` node and a delegated subtask are the same operation: run one turn as
 * an assistant, with that assistant's provider, model and tool set, bounded,
 * as the calling user. Both callers share one implementation so their guard
 * rails cannot drift.
 *
 * The user id is a parameter rather than constructor state because a delegated
 * task must execute with the **caller's** identity: a sub-agent's capabilities
 * are a subset of the person who asked, never a service identity.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface AgentRunnerInterface
{
    /**
     * Run one bounded turn for an assistant.
     *
     * @param int                  $assistantId Assistant to run.
     * @param string               $prompt      The subtask instruction.
     * @param int                  $userId      Caller identity; capability checks run as this user.
     * @param array<string, mixed> $context     Execution context (passed through).
     *
     * @return array{iterations: int, response: mixed}
     */
    public function run(int $assistantId, string $prompt, int $userId, array $context = array()): array;
}
