<?php

/**
 * Dry-run a stored workflow rule without executing it.
 *
 * Port of `WP_MCP_AI_Tool_Test_Workflow_Rule` (Proposal 164 section 164.159).
 * Re-validates a rule's stored definition and reports what it holds. It does not
 * execute anything, because nothing in this runtime can.
 *
 * ## WordPress reported a match it never computed
 *
 * `$trigger_matched = true;` was set unconditionally whenever `test_data` was
 * non-empty, and the only way it became false was a `registration_id` that named
 * a missing post. `trigger.conditions` was never read by any code in the plugin,
 * so the "dry run" could report that a rule with arbitrary conditions matched
 * arbitrary data and the report would look like evidence. A caller that trusted
 * it would enable a rule on a false premise.
 *
 * The port reports `trigger_matched: null` and `conditions_evaluated: false`,
 * and returns the stored conditions verbatim so the caller can see what is not
 * being evaluated. This is the whole of the tool's honesty: it validates, it
 * does not simulate.
 *
 * ## What it still checks
 *
 * - The stored trigger and actions pass `ruleDefinition()`, the same validation
 *   the write tools use, so a rule written before validation existed is
 *   diagnosed here.
 * - A supplied `registration_id` is looked up in the registration store, which
 *   replaces WordPress's `get_post()` probe. A missing registration is a
 *   warning, as it was there.
 * - A supplied `event_type` is compared to the rule's trigger event. That is a
 *   string comparison, not an evaluation, and is labelled `event_matches` so it
 *   cannot be mistaken for one.
 *
 * Gated on `read`, matching the capability the WordPress handler checked.
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
use Nvoos\Core\Domain\Entity\ContentItem;

class TestWorkflowRuleTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'test_workflow_rule';
    }

    public function getName(): string
    {
        return 'Test Workflow Rule';
    }

    public function getDescription(): string
    {
        return 'Validates a stored workflow rule definition and echoes its trigger and actions. Conditions are not evaluated and nothing is executed: trigger_matched is null and conditions_evaluated is false. A supplied registration_id is checked for existence; a supplied event_type is compared to the trigger event as a string.';
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'rule_id'          => array(
                    'type'        => 'string',
                    'description' => 'The rule key to test.',
                    'minLength'   => 1,
                ),
                'test_data'        => array(
                    'type'        => 'object',
                    'description' => 'Optional input to check against the rule. Nothing is evaluated; the fields are probed individually.',
                    'properties'  => array(
                        'registration_id' => array(
                            'type'        => 'integer',
                            'description' => 'A registration id to confirm exists.',
                            'minimum'     => 1,
                        ),
                        'event_type'      => array(
                            'type'        => 'string',
                            'description' => 'An event label to compare with the rule trigger event.',
                        ),
                    ),
                ),
                'validate_actions' => array(
                    'type'        => 'boolean',
                    'description' => 'Validate the stored actions. Default true.',
                    'default'     => true,
                ),
            ),
            'required'             => array('rule_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $ruleKey = trim($this->stringParam($arguments, 'rule_id'));

        if ('' === $ruleKey) {
            return $this->errors->validationFailed(
                'The workflow rule cannot be tested.',
                array('rule_id' => array('The rule key.'))
            );
        }

        $rule = $this->workflowRule($ruleKey);

        if (null === $rule) {
            return $this->errors->notFound('Workflow rule not found.');
        }

        $testData = is_array($arguments['test_data'] ?? null) ? $arguments['test_data'] : array();

        $validateActions = $this->boolParam($arguments, 'validate_actions', true);

        $meta    = $rule->meta;
        $trigger = is_array($meta['trigger'] ?? null) ? $meta['trigger'] : array();
        $actions = is_array($meta['actions'] ?? null) ? $meta['actions'] : array();

        $definition = $this->ruleDefinition($trigger, $actions);

        $fieldErrors = $definition['fields'];
        $warnings    = $definition['warnings'];

        // WordPress gated only the action checks behind validate_actions, so a
        // caller that switched it off saw no action complaints. Every warning
        // this validation raises is an action warning, so they go with them.
        if (! $validateActions) {
            foreach (array_keys($fieldErrors) as $key) {
                if (0 === strpos((string) $key, 'actions')) {
                    unset($fieldErrors[$key]);
                }
            }

            $warnings = array();
        }

        $errors = array();

        foreach ($fieldErrors as $messages) {
            foreach ($messages as $message) {
                $errors[] = $message;
            }
        }

        $eventMatches = null;

        $testEvent = trim($this->stringParam($testData, 'event_type'));
        $ruleEvent = $this->text($trigger['event'] ?? null);

        if ('' !== $testEvent && '' !== $ruleEvent) {
            $eventMatches = $testEvent === $ruleEvent;
        }

        $registration = null;

        $registrationId = $this->intValue($testData['registration_id'] ?? null);

        if ($registrationId > 0) {
            $found = $this->registrationExists($registrationId);

            $registration = array(
                'registration_id' => $registrationId,
                'found'           => $found,
            );

            if (! $found) {
                $warnings[] = 'Test registration not found.';
            }
        }

        $report = array(
            'rule_id'              => $ruleKey,
            'rule_name'            => $rule->title,
            'content_id'           => $rule->id,
            'trigger_event'        => $ruleEvent,
            'trigger_conditions'   => is_array($trigger['conditions'] ?? null) ? $trigger['conditions'] : array(),
            'action_count'         => count($actions),
            'validation_valid'     => array() === $errors,
            'errors'               => $errors,
            'field_errors'         => $fieldErrors,
            'warnings'             => $warnings,
            'trigger_matched'      => null,
            'conditions_evaluated' => false,
            'event_matches'        => $eventMatches,
            'test_registration'    => $registration,
        );

        if (array() === $errors) {
            $summary = 'Workflow rule validation passed.';

            if (array() !== $warnings) {
                $summary .= sprintf(' %d warnings found.', count($warnings));
            }
        } else {
            $summary = sprintf('Validation failed: %d errors found.', count($errors));
        }

        return $this->success(
            $summary,
            array(
                'summary'              => $summary,
                'report'               => $report,
                'trigger_matched'      => null,
                'conditions_evaluated' => false,
                'tested_at'            => $this->nowStamp(),
            )
        );
    }

    /**
     * Whether a registration id resolves to a stored registration.
     *
     * A store that throws is treated as not answering, which matches the
     * registration readers in this family: an unreadable store must not turn a
     * warning into a fatal.
     */
    protected function registrationExists(int $id): bool
    {
        try {
            $item = $this->content->find($id);
        } catch (\Throwable $e) {
            return false;
        }

        return $item instanceof ContentItem
            && self::REGISTRATION_TYPE === $item->type;
    }
}
