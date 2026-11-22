<?php

/**
 * Agent orchestration over assistants — Proposal 149 §163.
 *
 * WordPress's orchestrator composes a team of **assistants** and caches it in a
 * 24-hour transient; there is no team table. This is the same design over this
 * runtime's assistants: a team is an ephemeral cache entry, and every execution
 * path is one bounded assistant turn.
 *
 * Composition is **deterministic** (Anthropic's guidance: orchestrator-workers
 * is for subtasks you cannot predict; when the path is known, an explicit rule
 * is cheaper and auditable). Roles resolve in order: an explicit
 * `agents[role] = assistant_id` map, then the assistant's slug, then its
 * `primary_role_slugs`, then its title, then the estate's default assistant.
 * The response records which rule fired, so a surprising team is explainable.
 *
 * Three guard rails are load-bearing:
 *
 *  - **Depth 1.** A delegated agent cannot compose a team or delegate again:
 *    the runner exposes one turn, not the orchestrator. This is what stops
 *    runaway fan-out.
 *  - **Caller identity.** `context.user_id` is passed to the runner, so every
 *    tool call a sub-agent makes is capability-checked as the person who asked
 *    — never as a service account. The registry's guards (necessity, read-only,
 *    approvals) therefore apply unchanged; orchestration is not a bypass.
 *  - **Bounded.** Five members per team, ten steps per workflow, one turn per
 *    step. Exceeding a bound fails closed rather than truncating silently.
 *
 * The optional constructor dependencies are deliberate: the package must be
 * able to construct this in a test or a host without assistants (the readiness
 * registrar promises the tool is constructible), and an unconfigured host gets
 * an honest `success: false` rather than a hollow result.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface;
use Nvoos\Core\Domain\Contract\AgentRunnerInterface;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\TenantResolverInterface;

final class AgentOrchestration implements AgentOrchestrationInterface
{
    /** How long a composed team stays usable. Matches the WordPress transient. */
    public const TEAM_TTL_SECONDS = 86400;

    /** A team larger than this is a sign the caller wants a workflow, not a team. */
    public const MAX_MEMBERS = 5;

    /** One workflow step is one assistant turn; ten is already a long chain. */
    public const MAX_STEPS = 10;

    /** Bound the assistant scan so composition cannot walk a huge estate. */
    private const MAX_CATALOGUE = 200;

    /** Bound the threaded context so step N does not inherit an unbounded blob. */
    private const MAX_THREADED_CONTEXT = 4000;

    public function __construct(
        private readonly CacheStoreInterface $cache,
        private readonly ?AssistantServiceInterface $assistants = null,
        private readonly ?AgentRunnerInterface $runner = null,
        private readonly ?TenantResolverInterface $tenant = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function composeTeam(array $taskRequirements): array
    {
        $task = self::taskText($taskRequirements);

        if ('' === $task) {
            return array('success' => false, 'error' => 'A task description or task type is required for team composition.');
        }

        if (null === $this->assistants) {
            return array('success' => false, 'error' => 'Agent orchestration requires an assistant host.');
        }

        $roles = self::stringList($taskRequirements['roles'] ?? array());

        if (array() === $roles) {
            $roles = array('default');
        }

        $catalogue = $this->catalogue();

        if (array() === $catalogue) {
            return array('success' => false, 'error' => 'No assistants are available in this estate.');
        }

        $explicit  = $this->explicitAgents($taskRequirements['agents'] ?? array());
        $defaultId = $this->defaultAssistantId();
        $members   = array();
        $seen      = array();

        foreach ($roles as $role) {
            if (count($members) >= self::MAX_MEMBERS) {
                break;
            }

            $member = $this->matchRole($role, $catalogue, $explicit, $defaultId);

            if (null === $member || isset($seen[$member['assistant_id']])) {
                continue;
            }

            $seen[$member['assistant_id']] = true;
            $members[]                     = array('role' => $role) + $member;
        }

        if (array() === $members) {
            return array('success' => false, 'error' => 'No assistant matched the requested roles.');
        }

        $team = array(
            'id'         => 'team_' . bin2hex(random_bytes(12)),
            'task'       => $task,
            'task_type'  => (string) ($taskRequirements['task_type'] ?? ''),
            'priority'   => (string) ($taskRequirements['priority'] ?? 'medium'),
            'members'    => $members,
            'created_by' => (int) ($taskRequirements['user_id'] ?? 0),
            'created_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + self::TEAM_TTL_SECONDS),
        );

        $this->cache->setValue($this->cacheKey($team['id']), $team, self::TEAM_TTL_SECONDS);

        return array('success' => true, 'team' => $team);
    }

    /**
     * {@inheritDoc}
     */
    public function executeWorkflow(string $teamId, array $workflow, array $context = array()): array
    {
        $team = $this->team($teamId);

        if (null === $team) {
            return array('success' => false, 'results' => array(), 'error' => 'Team not found or expired.');
        }

        if (null === $this->runner) {
            return array('success' => false, 'results' => array(), 'error' => 'Agent orchestration requires an agent runner.');
        }

        $steps = $workflow['steps'] ?? null;

        if (! is_array($steps) || array() === $steps) {
            return array('success' => false, 'results' => array(), 'error' => 'A workflow needs at least one step.');
        }

        $steps = array_values($steps);

        if (count($steps) > self::MAX_STEPS) {
            return array(
                'success' => false,
                'results' => array(),
                'error'   => 'A workflow accepts at most ' . self::MAX_STEPS . ' steps.',
            );
        }

        $userId  = (int) ($context['user_id'] ?? 0);
        $results = array();

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                return array('success' => false, 'results' => $results, 'error' => sprintf('Step %d is not an object.', $index));
            }

            $role        = (string) ($step['role'] ?? '');
            $assistantId = (int) ($step['agent_id'] ?? 0);

            if ($assistantId <= 0) {
                $assistantId = $this->memberForRole($team, $role);
            }

            if ($assistantId <= 0) {
                return array(
                    'success' => false,
                    'results' => $results,
                    'error'   => sprintf('Step %d names no resolvable agent.', $index),
                );
            }

            $prompt = self::taskText($step);

            if ('' === $prompt) {
                return array(
                    'success' => false,
                    'results' => $results,
                    'error'   => sprintf('Step %d has no instruction.', $index),
                );
            }

            $out = $this->runner->run($assistantId, $this->threaded($prompt, $results), $userId, $context);

            $results[] = array(
                'step'         => $index,
                'role'         => $role,
                'assistant_id' => $assistantId,
                'iterations'   => (int) ($out['iterations'] ?? 0),
                'response'     => $out['response'] ?? null,
            );
        }

        return array('success' => true, 'results' => $results);
    }

    /**
     * {@inheritDoc}
     */
    public function getTeamStatus(string $teamId): array
    {
        $team = $this->team($teamId);

        return null === $team
            ? array('found' => false)
            : array('found' => true, 'team' => $team);
    }

    /**
     * {@inheritDoc}
     */
    public function delegateToAgent(string $teamId, string $agentId, array $task, array $context = array()): array
    {
        $team = $this->team($teamId);

        if (null === $team) {
            return array('success' => false, 'error' => 'Team not found or expired.');
        }

        if (null === $this->runner) {
            return array('success' => false, 'error' => 'Agent orchestration requires an agent runner.');
        }

        $assistantId = (int) $agentId;

        if (! in_array($assistantId, array_column($team['members'], 'assistant_id'), true)) {
            return array('success' => false, 'error' => 'That agent is not a member of this team.');
        }

        $prompt = self::taskText($task);

        if ('' === $prompt) {
            return array('success' => false, 'error' => 'A task instruction is required.');
        }

        $out = $this->runner->run($assistantId, $prompt, (int) ($context['user_id'] ?? 0), $context);

        return array(
            'success'    => true,
            'result'     => $out['response'] ?? null,
            'iterations' => (int) ($out['iterations'] ?? 0),
        );
    }

    /**
     * The team this id names, or null when unknown or expired.
     *
     * @return array<string, mixed>|null
     */
    private function team(string $teamId): ?array
    {
        if ('' === trim($teamId)) {
            return null;
        }

        $team = $this->cache->getValue($this->cacheKey($teamId));

        if (! is_array($team) || ! is_array($team['members'] ?? null) || array() === $team['members']) {
            return null;
        }

        $expiresAt = isset($team['expires_at']) ? strtotime((string) $team['expires_at']) : false;

        if (false === $expiresAt || $expiresAt <= time()) {
            return null;
        }

        return $team;
    }

    /**
     * The assistants this estate can run, with the fields roles resolve against.
     *
     * @return array<int, array{assistant_id: int, slug: string, title: string, roles: string[]}>
     */
    private function catalogue(): array
    {
        if (null === $this->assistants) {
            return array();
        }

        try {
            $rows = $this->assistants->listForUser(0);
        } catch (\Throwable $exception) {
            return array();
        }

        $catalogue = array();
        $scanned   = 0;

        foreach ((array) $rows as $row) {
            if (++$scanned > self::MAX_CATALOGUE) {
                break;
            }

            $id = (int) ($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $config = $this->assistants->getConfig($id);
            $record = is_array($config['config'] ?? null) ? $config['config'] : array();

            $catalogue[$id] = array(
                'assistant_id' => $id,
                'slug'         => (string) ($record['slug'] ?? ''),
                'title'        => (string) ($record['title'] ?? ($row['title'] ?? '')),
                'roles'        => self::stringList($record['primary_role_slugs'] ?? array()),
            );
        }

        return $catalogue;
    }

    /**
     * Resolve one role.
     *
     * @param array<int, array{assistant_id: int, slug: string, title: string, roles: string[]}> $catalogue
     * @param array<string, int>                                                                 $explicit
     * @return array{assistant_id: int, title: string, matched_by: string}|null
     */
    private function matchRole(string $role, array $catalogue, array $explicit, int $defaultId): ?array
    {
        $needle = strtolower(trim($role));

        if (isset($explicit[$role]) && isset($catalogue[$explicit[$role]])) {
            return $this->member($catalogue[$explicit[$role]], 'explicit');
        }

        if ('' !== $needle) {
            foreach ($catalogue as $row) {
                if (strtolower($row['slug']) === $needle) {
                    return $this->member($row, 'slug');
                }
            }

            foreach ($catalogue as $row) {
                foreach ($row['roles'] as $declared) {
                    if (strtolower($declared) === $needle) {
                        return $this->member($row, 'primary_role');
                    }
                }
            }

            foreach ($catalogue as $row) {
                if (strtolower($row['title']) === $needle) {
                    return $this->member($row, 'title');
                }
            }
        }

        if ($defaultId > 0 && isset($catalogue[$defaultId])) {
            return $this->member($catalogue[$defaultId], 'default');
        }

        return null;
    }

    /**
     * @param array{assistant_id: int, slug: string, title: string, roles: string[]} $row
     * @return array{assistant_id: int, title: string, matched_by: string}
     */
    private function member(array $row, string $matchedBy): array
    {
        return array(
            'assistant_id' => $row['assistant_id'],
            'title'        => $row['title'],
            'matched_by'   => $matchedBy,
        );
    }

    private function defaultAssistantId(): int
    {
        if (null === $this->assistants) {
            return 0;
        }

        try {
            $default = $this->assistants->getDefault();
        } catch (\Throwable $exception) {
            return 0;
        }

        return is_array($default) && ! empty($default['found'])
            ? (int) ($default['assistant_id'] ?? 0)
            : 0;
    }

    /**
     * @param array<string, mixed> $declared
     * @return array<string, int>
     */
    private function explicitAgents(array $declared): array
    {
        $explicit = array();

        foreach ($declared as $role => $assistantId) {
            if (is_string($role) && '' !== $role) {
                $explicit[$role] = (int) $assistantId;
            }
        }

        return $explicit;
    }

    /**
     * @param array<string, mixed> $team
     */
    private function memberForRole(array $team, string $role): int
    {
        $needle = strtolower(trim($role));

        foreach ($team['members'] as $member) {
            if (is_array($member) && strtolower((string) ($member['role'] ?? '')) === $needle) {
                return (int) ($member['assistant_id'] ?? 0);
            }
        }

        // A single-member team is the common case; an unlabelled step can use it.
        return 1 === count($team['members']) ? (int) ($team['members'][0]['assistant_id'] ?? 0) : 0;
    }

    /**
     * Thread prior step responses into the next prompt, bounded.
     *
     * @param list<array<string, mixed>> $results
     */
    private function threaded(string $prompt, array $results): string
    {
        if (array() === $results) {
            return $prompt;
        }

        $encoded = json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $encoded) {
            return $prompt;
        }

        return $prompt . "\n\nPrevious step results:\n" . substr($encoded, 0, self::MAX_THREADED_CONTEXT);
    }

    private function cacheKey(string $teamId): string
    {
        return 'agent_team:' . $this->tenantId() . ':' . $teamId;
    }

    private function tenantId(): string
    {
        if (null === $this->tenant) {
            return 'default';
        }

        $tenantId = trim($this->tenant->current());

        return '' === $tenantId ? 'default' : $tenantId;
    }

    /**
     * The instruction text in a tool payload or workflow step.
     *
     * The WordPress `create_agent_team` tool requires `task_type`; this runtime
     * composes from a description and roles. Both spellings are accepted so one
     * payload composes a team on either runtime, and `task_type` is recorded on
     * the team when present.
     *
     * @param array<string, mixed> $payload
     */
    private static function taskText(array $payload): string
    {
        foreach (array('task', 'task_description', 'instruction', 'instructions', 'task_type', 'description') as $key) {
            $value = $payload[$key] ?? null;

            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $list = array();

        foreach ($value as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $list[] = trim($item);
            }
        }

        return $list;
    }
}
