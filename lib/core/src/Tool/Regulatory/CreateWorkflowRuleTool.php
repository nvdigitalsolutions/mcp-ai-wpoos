<?php

/**
 * Create a workflow automation rule.
 *
 * Port of `WP_MCP_AI_Tool_Create_Workflow_Rule` (Proposal 164 section 164.159).
 * Stores a rule with a trigger and one or more actions, and records the creation
 * in the workflow audit log.
 *
 * ## Rules moved from an option to the content store
 *
 * WordPress kept every rule in the `wp_mcp_ai_workflow_rules` option. This
 * runtime has no options store, and every other entity in the family is a
 * content item, so a rule is a `mcp_ai_workflow_rule` item: title is the name,
 * content is the description, and the trigger, actions, flags and audit fields
 * are meta. The operator-facing key (`rule_xxxxxxxxxxxx`) is kept in `rule_key`
 * meta rather than becoming the content id, because an export carries keys and
 * rewriting them would break every stored reference.
 *
 * ## Validation WordPress did not do
 *
 * Neither create nor update checked the trigger event or the action types, so a
 * rule could be stored with `event: "nonsense"` and nothing objected until — in
 * practice, never, since nothing evaluates a rule either. The port validates
 * both here and on update, through `ruleDefinition()`, which is the same code
 * the dry run uses.
 *
 * ## What this rule will not do
 *
 * Nothing executes workflow rules in WordPress: no hook, no cron, no dispatcher
 * reads this table, and the `executions` counter is initialised here and never
 * incremented. The port keeps that shape and says so, rather than implying a
 * runner exists. The gap is recorded in §164.159.
 *
 * Gated on `manage_options`, matching the WordPress handler.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;

class CreateWorkflowRuleTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'create_workflow_rule';
    }

    public function getName(): string
    {
        return 'Create Workflow Rule';
    }

    public function getDescription(): string
    {
        return 'Stores a workflow rule with a trigger event, optional conditions and one or more actions, and records it in the audit log. The rule is stored and validated; nothing in this runtime executes it, and trigger conditions are stored rather than evaluated.';
    }

    public function getRequiredCapability(): string
    {
        return 'manage_options';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'name'        => array(
                    'type'        => 'string',
                    'description' => 'Rule name.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'What the rule is for.',
                    'maxLength'   => 2000,
                ),
                'trigger'     => array(
                    'type'        => 'object',
                    'description' => 'The event that would fire the rule, and any conditions stored with it.',
                    'properties'  => array(
                        'event'      => array(
                            'type' => 'string',
                            'enum' => self::TRIGGER_EVENTS,
                        ),
                        'conditions' => array('type' => 'object'),
                    ),
                    'required'    => array('event'),
                ),
                'actions'     => array(
                    'type'        => 'array',
                    'description' => 'What the rule would do.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'type'   => array(
                                'type' => 'string',
                                'enum' => self::ACTION_TYPES,
                            ),
                            'params' => array('type' => 'object'),
                        ),
                        'required'   => array('type'),
                    ),
                    'minItems'    => 1,
                ),
                'enabled'     => array(
                    'type'        => 'boolean',
                    'description' => 'Enable the rule. Default true.',
                    'default'     => true,
                ),
            ),
            'required'             => array('name', 'trigger', 'actions'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $userId = (int) ( $context['user_id'] ?? 0 );

        $this->authorize($context, 'manage_options');

        $name = trim($this->stringParam($arguments, 'name'));

        $fields = array();

        if ('' === $name) {
            $fields['name'] = array('A rule name.');
        }

        $definition = $this->ruleDefinition(
            $arguments['trigger'] ?? null,
            $arguments['actions'] ?? null
        );

        $fields = array_merge($fields, $definition['fields']);

        if (array() !== $fields) {
            return $this->errors->validationFailed('The workflow rule cannot be created.', $fields);
        }

        $description = trim($this->stringParam($arguments, 'description'));
        $enabled     = $this->boolParam($arguments, 'enabled', true);
        $ruleKey     = 'rule_' . bin2hex(random_bytes(6));

        $meta = array(
            'rule_key'   => $ruleKey,
            'trigger'    => $definition['trigger'],
            'actions'    => $definition['actions'],
            'enabled'    => $enabled,
            'executions' => 0,
            'created_at' => $this->nowStamp(),
            'created_by' => $userId,
        );

        try {
            $item = $this->content->create(
                new CreateContentCommand(
                    title: $name,
                    type: self::WORKFLOW_RULE_TYPE,
                    authorId: $userId,
                    status: 'publish',
                    content: $description,
                    meta: $meta,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('create_workflow_rule_failed', $e->getMessage());
        }

        $logged = $this->logWorkflowAction('create_rule', $ruleKey, $name, $userId);

        return $this->success(
            sprintf('Workflow rule "%s" created.', $name),
            array(
                'rule_id'       => $ruleKey,
                'content_id'    => $item->id,
                'name'          => $name,
                'enabled'       => $enabled,
                'trigger_event' => $definition['trigger']['event'],
                'action_count'  => count($definition['actions']),
                'warnings'      => $definition['warnings'],
                'audit_logged'  => $logged,
                'created_at'    => $meta['created_at'],
            )
        );
    }
}
