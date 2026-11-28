<?php

/**
 * Delete a workflow rule.
 *
 * Port of `WP_MCP_AI_Tool_Delete_Workflow_Rule` (Proposal 164 section 164.159).
 *
 * ## Confirmation is part of the contract
 *
 * WordPress refused the call unless `confirm` was truthy, with its own error
 * code. The port keeps the refusal and expresses it through the canonical
 * envelope, naming the field, because a delete that can be called by accident is
 * a delete that will be.
 *
 * ## The audit entry outlives the rule
 *
 * The entry is written **after** the delete succeeds, and it is its own content
 * item, so it survives the rule it describes. That is the whole reason the log
 * is a separate type: an entry stored on the rule would be deleted with it, and
 * "rule deleted" is precisely the record an auditor is looking for.
 *
 * The entry carries the rule's name because after the delete there is nothing
 * left to look it up in.
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
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\NotFoundException;

class DeleteWorkflowRuleTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'delete_workflow_rule';
    }

    public function getName(): string
    {
        return 'Delete Workflow Rule';
    }

    public function getDescription(): string
    {
        return 'Deletes a stored workflow rule. Requires confirm true, and records the deletion in the audit log, which outlives the rule.';
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
                'rule_id' => array(
                    'type'        => 'string',
                    'description' => 'The rule key.',
                    'minLength'   => 1,
                    'maxLength'   => 64,
                ),
                'confirm' => array(
                    'type'        => 'boolean',
                    'description' => 'Must be true. Deleting a rule cannot be undone.',
                ),
            ),
            'required'             => array('rule_id', 'confirm'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $userId = (int) ( $context['user_id'] ?? 0 );

        $this->authorize($context, 'manage_options');

        $ruleKey = trim($this->stringParam($arguments, 'rule_id'));

        $fields = array();

        if ('' === $ruleKey) {
            $fields['rule_id'] = array('The rule key.');
        }

        if (! $this->boolParam($arguments, 'confirm', false)) {
            $fields['confirm'] = array('Confirm true to delete a rule. The deletion cannot be undone.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The workflow rule cannot be deleted.', $fields);
        }

        $rule = $this->workflowRule($ruleKey);

        if (null === $rule) {
            return $this->errors->notFound('Workflow rule not found.');
        }

        $name = $rule->title;

        try {
            $this->content->delete($rule->id, $userId);
        } catch (AccessDeniedException $e) {
            return $this->errors->forbidden($e->getMessage());
        } catch (NotFoundException $e) {
            return $this->errors->notFound('Workflow rule not found.');
        } catch (\Throwable $e) {
            return $this->errors->create('delete_workflow_rule_failed', $e->getMessage());
        }

        $logged = $this->logWorkflowAction('delete_rule', $ruleKey, $name, $userId);

        return $this->success(
            sprintf('Workflow rule "%s" deleted.', $name),
            array(
                'rule_id'      => $ruleKey,
                'rule_name'    => $name,
                'audit_logged' => $logged,
                'deleted_at'   => $this->nowStamp(),
            )
        );
    }
}
