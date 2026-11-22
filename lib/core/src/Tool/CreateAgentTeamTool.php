<?php

/** Create Agent Team. @package Nvoos\Core @since 2.0.0 @license MIT
 *
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class CreateAgentTeamTool extends AbstractTool
{
    public function __construct(ErrorFactoryInterface $e, private readonly AgentOrchestrationInterface $o)
    {
        parent::__construct($e);
    }
    public function getSlug(): string
    {
        return 'create_agent_team';
    }
    public function getName(): string
    {
        return 'Create Agent Team';
    }
    public function getDescription(): string
    {
        return 'Composes an agent team for a task with specific roles and requirements.';
    }
    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'task_description' => array(
                    'type' => 'string',
                    'description' => 'What the team must accomplish. Required unless task_type is given.',
                ),
                'task_type' => array(
                    'type' => 'string',
                    'description' => 'Task category, recorded on the team. Accepted as an alternative to task_description so a WordPress-shaped call works here; it does not select a role template.',
                    'enum' => array('research', 'content', 'ecommerce', 'development', 'analysis', 'creative', 'technical', 'generic'),
                ),
                'roles' => array(
                    'type' => 'array',
                    'description' => 'Required agent roles, resolved by an explicit agents map, then assistant slug, then primary_role_slugs, then title.',
                    'items' => array('type' => 'string'),
                ),
                'agents' => array(
                    'type' => 'object',
                    'description' => 'Optional map of role => assistant id. Pins a member explicitly instead of relying on slug or role matching.',
                ),
                'priority' => array(
                    'type' => 'string',
                    'enum' => array('low', 'medium', 'high', 'critical'),
                    'default' => 'medium',
                ),
            ),
            'required' => array(),
            'additionalProperties' => false,
        );
    }
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $desc = $this->stringParam($arguments, 'task_description');
        $type = $this->stringParam($arguments, 'task_type');

        // WordPress requires `task_type`; this runtime composes from roles and a
        // description. Accepting either keeps one payload usable on both, and
        // rejecting both keeps a call from composing a team out of nothing.
        if ('' === $desc && '' === $type) {
            return $this->errors->validationFailed(
                'A task description or task type is required.',
                array(
                    'task_description' => array('Required unless task_type is given.'),
                    'task_type' => array('Required unless task_description is given.'),
                )
            );
        }

        try {
            $r = $this->o->composeTeam(
                array(
                    'task' => $desc,
                    'task_type' => $type,
                    'roles' => $this->arrayParam($arguments, 'roles'),
                    'agents' => $this->objectParam($arguments, 'agents'),
                    'priority' => $this->stringParam($arguments, 'priority', 'medium'),
                )
            );

            return $r['success'] ? $this->success('Team composed.', $r['team'] ?? array()) : $this->errors->create('team_failed', $r['error'] ?? 'Unknown error.');
        } catch (\Throwable $e) {
            return $this->errors->create('team_failed', $e->getMessage());
        }
    }
}
