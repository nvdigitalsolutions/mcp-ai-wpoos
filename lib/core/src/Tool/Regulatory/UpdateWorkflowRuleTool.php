<?php

/**
 * Update a workflow rule.
 *
 * Port of `WP_MCP_AI_Tool_Update_Workflow_Rule` (Proposal 164 section 164.159).
 * A partial update: only the fields supplied are changed, and `trigger` and
 * `actions` are replaced wholesale when supplied.
 *
 * ## An update with nothing to update reported success
 *
 * WordPress wrote the rule back unchanged, bumped `updated_at`, and appended an
 * audit entry with `updated_fields: []`. That is a write and an audit record for
 * a request that changed nothing, and it makes the log — which is the only
 * history this feature has — say "someone edited this rule" when nobody did. The
 * port refuses a request that names no field to change.
 *
 * ## Validation now happens here too
 *
 * The WordPress handler copied `trigger` and `actions` in without checking the
 * event or the action types, so an update could store `event: "nonsense"` in a
 * rule that create would also have accepted. Both writes now run
 * `ruleDefinition()`, which is the same code the dry run uses, so a rule that
 * validates on create cannot be invalidated by an update.
 *
 * ## Replacing, not merging
 *
 * `trigger` and `actions` are complete values: sending an `actions` list
 * replaces the stored list. That is deliberate — a partial action list would be
 * ambiguous about which action an index refers to — and it is stated in the
 * schema so a caller is not surprised by an action disappearing.
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
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

class UpdateWorkflowRuleTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'update_workflow_rule';
    }

    public function getName(): string
    {
        return 'Update Workflow Rule';
    }

    public function getDescription(): string
    {
        return 'Changes the name, description, trigger, actions or enabled flag of a stored workflow rule. Only the fields supplied change; trigger and actions are replaced wholesale. Refuses a request that names no field to change.';
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
                'rule_id'     => array(
                    'type'        => 'string',
                    'description' => 'The rule key.',
                    'minLength'   => 1,
                    'maxLength'   => 64,
                ),
                'name'        => array(
                    'type'        => 'string',
                    'description' => 'A new name.',
                    'minLength'   => 1,
                    'maxLength'   => 200,
                ),
                'description' => array(
                    'type'        => 'string',
                    'description' => 'A new description. An empty string clears it.',
                    'maxLength'   => 2000,
                ),
                'trigger'     => array(
                    'type'        => 'object',
                    'description' => 'A complete replacement trigger.',
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
                    'description' => 'A complete replacement action list.',
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
                    'description' => 'Enable or disable the rule.',
                ),
            ),
            'required'             => array('rule_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $userId = (int) ( $context['user_id'] ?? 0 );

        $this->authorize($context, 'manage_options');

        $ruleKey = trim($this->stringParam($arguments, 'rule_id'));

        if ('' === $ruleKey) {
            return $this->errors->validationFailed(
                'The workflow rule cannot be updated.',
                array('rule_id' => array('The rule key.'))
            );
        }

        $rule = $this->workflowRule($ruleKey);

        if (null === $rule) {
            return $this->errors->notFound('Workflow rule not found.');
        }

        $meta   = $rule->meta;
        $fields = array();
        $change = array();

        if (array_key_exists('name', $arguments)) {
            $name = trim($this->stringParam($arguments, 'name'));

            if ('' === $name) {
                $fields['name'] = array('A rule name cannot be empty.');
            } else {
                $change['title'] = $name;
            }
        }

        if (array_key_exists('description', $arguments)) {
            $change['content'] = trim($this->stringParam($arguments, 'description'));
        }

        $warnings = array();

        if (array_key_exists('trigger', $arguments) || array_key_exists('actions', $arguments)) {
            $definition = $this->ruleDefinition(
                array_key_exists('trigger', $arguments)
                    ? $arguments['trigger']
                    : ( $meta['trigger'] ?? null ),
                array_key_exists('actions', $arguments)
                    ? $arguments['actions']
                    : ( $meta['actions'] ?? null )
            );

            $fields   = array_merge($fields, $definition['fields']);
            $warnings = $definition['warnings'];

            if (array_key_exists('trigger', $arguments)) {
                $change['trigger'] = $definition['trigger'];
            }

            if (array_key_exists('actions', $arguments)) {
                $change['actions'] = $definition['actions'];
            }
        }

        if (array_key_exists('enabled', $arguments)) {
            $change['enabled'] = $this->boolParam($arguments, 'enabled', false);
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The workflow rule cannot be updated.', $fields);
        }

        if (array() === $change) {
            return $this->errors->validationFailed(
                'Nothing to update.',
                array('rule_id' => array('Name at least one field to change.'))
            );
        }

        if (array_key_exists('enabled', $change)) {
            $meta['enabled'] = $change['enabled'];
        }

        if (array_key_exists('trigger', $change)) {
            $meta['trigger'] = $change['trigger'];
        }

        if (array_key_exists('actions', $change)) {
            $meta['actions'] = $change['actions'];
        }

        $meta['updated_at'] = $this->nowStamp();
        $meta['updated_by'] = $userId;

        // The WordPress audit entry named the fields that changed; the port
        // keeps that vocabulary ('name', 'description', ...) rather than the
        // column names it happens to write to.
        $updated = array();

        foreach (array('name', 'description', 'trigger', 'actions', 'enabled') as $field) {
            if (array_key_exists($field, $arguments)) {
                $updated[] = $field;
            }
        }

        $updated[] = 'updated_at';

        try {
            $this->content->update(
                $rule->id,
                new UpdateContentCommand(
                    userId: $userId,
                    title: $change['title'] ?? null,
                    content: $change['content'] ?? null,
                    meta: $meta,
                )
            );
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Workflow rule not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('update_workflow_rule_failed', $e->getMessage());
        }

        $logged = $this->logWorkflowAction(
            'update_rule',
            $ruleKey,
            $change['title'] ?? $rule->title,
            $userId,
            array('updated_fields' => array_values(array_unique($updated)))
        );

        return $this->success(
            sprintf('Workflow rule "%s" updated.', $change['title'] ?? $rule->title),
            array(
                'rule_id'        => $ruleKey,
                'name'           => $change['title'] ?? $rule->title,
                'updated_fields' => array_values(array_unique($updated)),
                'warnings'       => $warnings,
                'audit_logged'   => $logged,
                'updated_at'     => $meta['updated_at'],
            )
        );
    }
}
