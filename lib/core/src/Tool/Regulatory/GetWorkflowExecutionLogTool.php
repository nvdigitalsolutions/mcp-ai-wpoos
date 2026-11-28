<?php

/**
 * Read the workflow audit log.
 *
 * Port of `WP_MCP_AI_Tool_Get_Workflow_Execution_Log` (Proposal 164 section
 * 164.159). Returns the audit entries the workflow write tools recorded.
 *
 * ## The log is an edit log, not an execution log
 *
 * `create_rule`, `update_rule` and `delete_rule` are the only actions anything
 * writes. `execute_rule` is in the filter enum and in
 * `WORKFLOW_AUDIT_ACTIONS`, because WordPress listed it there, but no code path
 * produces it — nothing executes a rule. The name of the tool is therefore
 * wider than the contents. Recorded in §164.159.
 *
 * ## Two filter guards that kept what they should have dropped
 *
 * WordPress wrote `if ( $rule_id && isset( $entry['rule_id'] ) && $entry['rule_id'] !== $rule_id )`.
 * An entry with no `rule_id` key failed the `isset()` test and was **kept**, so
 * filtering by a rule returned that rule's entries plus every entry missing the
 * field. The same shape appeared for `action`. The port compares unconditionally,
 * so an entry without the field is excluded from a filtered result, which is the
 * only reading of "filter by rule" that holds.
 *
 * ## Dates are compared as dates
 *
 * `timestamp` is written by `nowStamp()` as `Y-m-d H:i:s` in UTC, and both date
 * arguments are `Y-m-d`, so the comparison is on the first ten characters and
 * never goes through `strtotime()`. The old code called `strtotime()` on both
 * sides and compared the results to `time()`-shaped integers; an unreadable
 * stored timestamp became `false`, which read as 1970 and was silently sorted to
 * the end. Entries whose timestamp cannot be read are counted in
 * `unreadable_timestamps` instead of being sorted as if they were ancient, and a
 * date filter excludes them because a date range is a claim about when the entry
 * happened.
 *
 * ## Statistics describe one set
 *
 * WordPress counted `by_action_type` over the whole store while printing it next
 * to `filtered_entries`, so the breakdown did not add up to the total beside it.
 * Every statistic here is counted over the matched set, and the three sizes are
 * reported separately: `total` (returned), `matched` (passed the filters, before
 * the limit), `store_entries` (the whole log).
 *
 * The 200-entry cap WordPress enforced by `array_slice( $log, -200 )` is not
 * ported. It was an option-size decision — an option loads on every request — and
 * this store does not. The log grows, and `store_entries` makes that visible
 * rather than silent.
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

class GetWorkflowExecutionLogTool extends RegulatoryTool
{
    /** The largest number of entries one call returns. */
    protected const MAX_LIMIT = 500;

    /** The number of entries returned when no limit is given. */
    protected const DEFAULT_LIMIT = 100;

    /** The meta keys an entry always carries. */
    protected const KNOWN_KEYS = array('timestamp', 'user_id', 'action', 'rule_id', 'rule_name');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'get_workflow_execution_log';
    }

    public function getName(): string
    {
        return 'Get Workflow Execution Log';
    }

    public function getDescription(): string
    {
        return 'Reads the workflow audit log: rule creations, updates and deletions, newest first. Nothing in this runtime executes a rule, so execute_rule entries are never written. Filters by rule, action and date range; statistics are counted over the matched set.';
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
                'rule_id'     => array(
                    'type'        => 'string',
                    'description' => 'Only entries for this rule key, including entries for a rule that has since been deleted.',
                ),
                'action_type' => array(
                    'type'        => 'string',
                    'description' => 'Only entries for this audit action.',
                    'enum'        => self::WORKFLOW_AUDIT_ACTIONS,
                ),
                'start_date'  => array(
                    'type'        => 'string',
                    'description' => 'Earliest entry date, inclusive (YYYY-MM-DD).',
                    'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
                ),
                'end_date'    => array(
                    'type'        => 'string',
                    'description' => 'Latest entry date, inclusive (YYYY-MM-DD).',
                    'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
                ),
                'limit'       => array(
                    'type'        => 'integer',
                    'description' => 'Maximum entries to return. Default 100.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LIMIT,
                    'default'     => self::DEFAULT_LIMIT,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $ruleId     = trim($this->stringParam($arguments, 'rule_id'));
        $actionType = trim($this->stringParam($arguments, 'action_type'));
        $startDate  = trim($this->stringParam($arguments, 'start_date'));
        $endDate    = trim($this->stringParam($arguments, 'end_date'));
        $limit      = $this->intParam($arguments, 'limit', self::DEFAULT_LIMIT);

        $fields = array();

        if ('' !== $actionType && ! in_array($actionType, self::WORKFLOW_AUDIT_ACTIONS, true)) {
            $fields['action_type'] = array(
                'One of: ' . implode(', ', self::WORKFLOW_AUDIT_ACTIONS) . '.',
            );
        }

        if ('' !== $startDate && ! $this->isIsoDate($startDate)) {
            $fields['start_date'] = array('A date in YYYY-MM-DD form.');
        }

        if ('' !== $endDate && ! $this->isIsoDate($endDate)) {
            $fields['end_date'] = array('A date in YYYY-MM-DD form.');
        }

        if ('' !== $startDate && '' !== $endDate && $startDate > $endDate) {
            $fields['end_date'] = array('The end date cannot precede the start date.');
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $fields['limit'] = array(
                sprintf('A whole number from 1 to %d.', self::MAX_LIMIT),
            );
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The workflow log cannot be read.', $fields);
        }

        $items = $this->logEntries();

        $matched    = 0;
        $unreadable = 0;
        $byAction   = array();
        $pairs      = array();

        foreach ($items as $item) {
            $meta = $item->meta;

            if ('' !== $ruleId && $this->text($meta['rule_id'] ?? null) !== $ruleId) {
                continue;
            }

            $action = $this->text($meta['action'] ?? null);

            if ('' !== $actionType && $action !== $actionType) {
                continue;
            }

            $stamp    = $this->text($meta['timestamp'] ?? null);
            $readable = $this->stampDate($stamp);

            if ('' === $readable) {
                ++$unreadable;

                if ('' !== $startDate || '' !== $endDate) {
                    continue;
                }
            } else {
                if ('' !== $startDate && $readable < $startDate) {
                    continue;
                }

                if ('' !== $endDate && $readable > $endDate) {
                    continue;
                }
            }

            ++$matched;

            if ('' !== $action) {
                $byAction[ $action ] = ( $byAction[ $action ] ?? 0 ) + 1;
            }

            $pairs[] = array(
                'sort' => $readable,
                'id'   => $item->id,
                'row'  => $this->entryRow($item, $stamp, $action),
            );
        }

        // Newest first. Timestamps are `Y-m-d H:i:s`, so a string comparison is
        // chronological; entries without a readable timestamp sort last. The
        // content id breaks ties so the order does not depend on the store's
        // return order.
        usort(
            $pairs,
            static function (array $a, array $b): int {
                if ($a['sort'] !== $b['sort']) {
                    return $b['sort'] <=> $a['sort'];
                }

                return $b['id'] <=> $a['id'];
            }
        );

        $rows = array();

        foreach (array_slice($pairs, 0, $limit) as $pair) {
            $rows[] = $pair['row'];
        }

        ksort($byAction);

        return $this->success(
            sprintf('Retrieved %d workflow log entries.', count($rows)),
            array(
                'total'                 => count($rows),
                'matched'               => $matched,
                'store_entries'         => count($items),
                'truncated'             => $matched > count($rows),
                'unreadable_timestamps' => $unreadable,
                'limit'                 => $limit,
                'filters'               => array(
                    'rule_id'     => $ruleId,
                    'action_type' => $actionType,
                    'start_date'  => $startDate,
                    'end_date'    => $endDate,
                ),
                'statistics'            => array('by_action_type' => $byAction),
                'log'                   => $rows,
                'retrieved_at'          => $this->nowStamp(),
            )
        );
    }

    /**
     * Every stored log entry.
     *
     * @return list<ContentItem>
     */
    protected function logEntries(): array
    {
        return $this->collectContent(array(self::WORKFLOW_LOG_TYPE));
    }

    /**
     * One returned log row.
     *
     * Meta keys this tool does not know about are carried through under
     * `details` rather than dropped: `update_rule` writes `updated_fields`, and a
     * future writer may add more.
     *
     * @return array<string, mixed>
     */
    protected function entryRow(ContentItem $item, string $stamp, string $action): array
    {
        $meta = $item->meta;

        $row = array(
            'timestamp'  => $stamp,
            'user_id'    => $this->intValue($meta['user_id'] ?? null),
            'action'     => $action,
            'rule_id'    => $this->text($meta['rule_id'] ?? null),
            'rule_name'  => $this->text($meta['rule_name'] ?? null),
            'content_id' => $item->id,
        );

        $extras = array_diff_key($meta, array_flip(self::KNOWN_KEYS));

        if (array() !== $extras) {
            $row['details'] = $extras;
        }

        return $row;
    }
}
