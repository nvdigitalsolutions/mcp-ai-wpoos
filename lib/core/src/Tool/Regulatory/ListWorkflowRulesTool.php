<?php

/**
 * List stored workflow automation rules.
 *
 * Port of `WP_MCP_AI_Tool_List_Workflow_Rules` (Proposal 164 section 164.159).
 * Reads every `mcp_ai_workflow_rule` content item and returns the rule rows,
 * with the execution counters the store holds.
 *
 * ## The arithmetic WordPress got wrong
 *
 * WordPress incremented `total` before applying either filter, then incremented
 * `enabled` and `disabled` after, so a filtered response could report
 * `total: 7, enabled: 2, disabled: 0` — three numbers describing two different
 * sets. The port counts every statistic over the returned set and reports the
 * unfiltered size separately as `store_total`, so `enabled + disabled` always
 * equals `total` and `total` always equals `count(rules)`.
 *
 * ## `filter_enabled` is three-valued
 *
 * WordPress defaulted it to `null` so that "not supplied" and "false" were
 * different requests. `boolParam()` cannot express that, because it collapses a
 * missing key to a boolean, so the port tests `array_key_exists()` and keeps the
 * null. A rule whose `enabled` flag was stored as the string `'false'` is read
 * through `flag()`; WordPress compared with `!==` and treated every non-boolean
 * as "not equal to either filter", so such a rule disappeared from both lists.
 *
 * ## The counters are not evidence of anything running
 *
 * `executions` is initialised to `0` by `create_workflow_rule` and nothing in
 * this runtime increments it, because nothing executes a rule. The field is
 * returned for shape compatibility and because a future runner will want it;
 * it is not a measure of automation. Recorded in §164.159.
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

class ListWorkflowRulesTool extends RegulatoryTool
{
    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'list_workflow_rules';
    }

    public function getName(): string
    {
        return 'List Workflow Rules';
    }

    public function getDescription(): string
    {
        return 'Lists stored workflow rules with their trigger, actions and counters. Filters by the enabled flag; statistics are counted over the returned set, and store_total reports the unfiltered size. Nothing in this runtime executes a rule, so executions stays at zero.';
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
                'filter_enabled'   => array(
                    'type'        => 'boolean',
                    'description' => 'Only rules whose enabled flag matches. Omit to return both.',
                ),
                'include_disabled' => array(
                    'type'        => 'boolean',
                    'description' => 'Include disabled rules. Default true.',
                    'default'     => true,
                ),
                'include_stats'    => array(
                    'type'        => 'boolean',
                    'description' => 'Include the executions and last_executed fields. Default true.',
                    'default'     => true,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        // Absent means "no filter", which is not the same request as false.
        $filterEnabled = array_key_exists('filter_enabled', $arguments)
            ? $this->boolParam($arguments, 'filter_enabled', false)
            : null;

        $includeDisabled = $this->boolParam($arguments, 'include_disabled', true);
        $includeStats    = $this->boolParam($arguments, 'include_stats', true);

        $rules = $this->workflowRules();

        $storeTotal = count($rules);
        $rows       = array();
        $enabled    = 0;
        $disabled   = 0;
        $executions = 0;

        foreach ($rules as $rule) {
            $isEnabled = $this->flag($rule->meta['enabled'] ?? false);

            if (null !== $filterEnabled && $isEnabled !== $filterEnabled) {
                continue;
            }

            if (! $includeDisabled && ! $isEnabled) {
                continue;
            }

            if ($isEnabled) {
                ++$enabled;
            } else {
                ++$disabled;
            }

            $executions += $this->intValue($rule->meta['executions'] ?? null);

            $rows[] = $this->workflowRuleRow($rule, $includeStats);
        }

        return $this->success(
            sprintf('Retrieved %d workflow rules.', count($rows)),
            array(
                'total'       => count($rows),
                'store_total' => $storeTotal,
                'statistics'  => array(
                    'enabled'          => $enabled,
                    'disabled'         => $disabled,
                    'total_executions' => $executions,
                ),
                'filters'     => array(
                    'filter_enabled'   => $filterEnabled,
                    'include_disabled' => $includeDisabled,
                ),
                'rules'       => $rows,
                'retrieved_at' => $this->nowStamp(),
            )
        );
    }
}
