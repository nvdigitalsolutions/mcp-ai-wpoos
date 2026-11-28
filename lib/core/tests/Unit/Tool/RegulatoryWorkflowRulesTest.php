<?php

/**
 * The twelfth regulatory-registration slice: workflow rules.
 *
 * Proposal 164 section 164.159. What is pinned: a rule is stored and read back
 * by its operator key; creation, update and deletion each write an audit entry,
 * and a deletion's entry outlives the rule; listing counts every statistic over
 * the returned set; the dry run reports no match and says conditions were not
 * evaluated; and the log filter excludes entries that lack the field being
 * filtered instead of keeping them, which is what WordPress did.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Tool\Regulatory\CreateWorkflowRuleTool;
use Nvoos\Core\Tool\Regulatory\DeleteWorkflowRuleTool;
use Nvoos\Core\Tool\Regulatory\GetWorkflowExecutionLogTool;
use Nvoos\Core\Tool\Regulatory\ListWorkflowRulesTool;
use Nvoos\Core\Tool\Regulatory\TestWorkflowRuleTool;
use Nvoos\Core\Tool\Regulatory\UpdateWorkflowRuleTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryWorkflowRulesTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message = 'Resource not found.'): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Forbidden.'): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message),
            )
        );
    }

    // ─── Creating ────────────────────────────────────────────────────────

    public function test_create_stores_the_rule_and_logs_the_creation(): void
    {
        $store = new WorkflowRuleStore();

        $result = $this->create($store)->execute(
            array(
                'name'        => 'Warn on expiry',
                'description' => 'Tell the team before a licence lapses.',
                'trigger'     => array(
                    'event'      => 'expiry_approaching',
                    'conditions' => array('days_before' => 30),
                ),
                'actions'     => array(
                    array(
                        'type'   => 'send_email',
                        'params' => array('recipients' => array('ops@example.test')),
                    ),
                ),
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('rule_id', $result['data']);
        $this->assertTrue($result['data']['audit_logged']);
        $this->assertSame('expiry_approaching', $result['data']['trigger_event']);
        $this->assertSame(array(), $result['data']['warnings']);

        $rules = $store->byType('mcp_ai_workflow_rule');

        $this->assertCount(1, $rules);
        $this->assertSame($result['data']['rule_id'], $rules[0]->meta['rule_key']);
        $this->assertSame(array('days_before' => 30), $rules[0]->meta['trigger']['conditions']);
        $this->assertSame('Tell the team before a licence lapses.', $rules[0]->content);

        $log = $store->byType('mcp_ai_workflow_log');

        $this->assertCount(1, $log);
        $this->assertSame('create_rule', $log[0]->meta['action']);
        $this->assertSame($result['data']['rule_id'], $log[0]->meta['rule_id']);
    }

    public function test_create_refuses_an_unknown_trigger_event(): void
    {
        $store = new WorkflowRuleStore();

        $result = $this->create($store)->execute(
            array(
                'name'    => 'Bad rule',
                'trigger' => array('event' => 'nonsense'),
                'actions' => array(array('type' => 'webhook')),
            ),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('trigger.event', $result['error']['data']);
        $this->assertCount(0, $store->byType('mcp_ai_workflow_rule'));
    }

    public function test_create_warns_about_an_email_action_without_recipients(): void
    {
        $store = new WorkflowRuleStore();

        $result = $this->create($store)->execute(
            array(
                'name'    => 'Mail nobody',
                'trigger' => array('event' => 'status_change'),
                'actions' => array(array('type' => 'send_email')),
            ),
            array('user_id' => 7)
        );

        // A rule may legitimately be saved before its recipients exist, which is
        // why WordPress reported this as a warning and why the port keeps it.
        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['warnings']);
        $this->assertStringContainsString('no email recipients', $result['data']['warnings'][0]);
    }

    // ─── Updating ────────────────────────────────────────────────────────

    public function test_update_refuses_a_call_that_changes_nothing(): void
    {
        $store = new WorkflowRuleStore();
        $store->add($this->rule(1, 'rule_abc', 'Existing'));

        $result = $this->update($store)->execute(
            array('rule_id' => 'rule_abc'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('Nothing to update', $result['error']['message']);
    }

    public function test_update_can_disable_a_rule(): void
    {
        $store = new WorkflowRuleStore();
        $store->add($this->rule(1, 'rule_abc', 'Existing'));

        $result = $this->update($store)->execute(
            array('rule_id' => 'rule_abc', 'enabled' => false),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($store->byType('mcp_ai_workflow_rule')[0]->meta['enabled']);
    }

    public function test_update_refuses_an_unknown_trigger_event_and_leaves_the_rule(): void
    {
        $store = new WorkflowRuleStore();
        $store->add($this->rule(1, 'rule_abc', 'Existing'));

        $result = $this->update($store)->execute(
            array('rule_id' => 'rule_abc', 'trigger' => array('event' => 'nonsense')),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('trigger.event', $result['error']['data']);
        $this->assertSame(
            'status_change',
            $store->byType('mcp_ai_workflow_rule')[0]->meta['trigger']['event']
        );
    }

    // ─── Deleting ────────────────────────────────────────────────────────

    public function test_delete_requires_confirmation(): void
    {
        $store = new WorkflowRuleStore();
        $store->add($this->rule(1, 'rule_abc', 'Existing'));

        $result = $this->delete($store)->execute(
            array('rule_id' => 'rule_abc'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('confirm', $result['error']['data']);
        $this->assertCount(1, $store->byType('mcp_ai_workflow_rule'));
    }

    public function test_the_deletion_entry_outlives_the_rule(): void
    {
        $store = new WorkflowRuleStore();
        $store->add($this->rule(1, 'rule_abc', 'Doomed'));

        $result = $this->delete($store)->execute(
            array('rule_id' => 'rule_abc', 'confirm' => true),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertCount(0, $store->byType('mcp_ai_workflow_rule'));

        // This is why entries are separate items rather than rule meta: the
        // record an auditor wants is the one about the rule being removed.
        $log = $store->byType('mcp_ai_workflow_log');

        $this->assertCount(1, $log);
        $this->assertSame('delete_rule', $log[0]->meta['action']);
        $this->assertSame('Doomed', $log[0]->meta['rule_name']);
    }

    // ─── Listing ─────────────────────────────────────────────────────────

    public function test_list_statistics_add_up_under_a_filter(): void
    {
        $store = new WorkflowRuleStore();

        $store->add($this->rule(1, 'rule_a', 'A'));
        $store->add($this->rule(2, 'rule_b', 'B'));
        $store->add($this->rule(3, 'rule_c', 'C'));
        $store->add($this->rule(4, 'rule_d', 'D', array('enabled' => false)));

        $result = $this->list($store)->execute(
            array('filter_enabled' => true),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['total']);
        $this->assertSame(4, $result['data']['store_total']);
        $this->assertCount(3, $result['data']['rules']);

        // WordPress counted total before the filter and enabled/disabled after,
        // so the three numbers described two different sets.
        $this->assertSame(
            $result['data']['total'],
            $result['data']['statistics']['enabled'] + $result['data']['statistics']['disabled']
        );
        $this->assertSame(0, $result['data']['statistics']['disabled']);

        $disabled = $this->list($store)->execute(
            array('include_disabled' => false),
            array('user_id' => 7)
        );

        $this->assertSame(3, $disabled['data']['total']);
        $this->assertSame(3, $disabled['data']['statistics']['enabled']);
    }

    public function test_list_treats_an_absent_filter_as_both_values(): void
    {
        $store = new WorkflowRuleStore();

        $store->add($this->rule(1, 'rule_a', 'A'));
        $store->add($this->rule(2, 'rule_b', 'B', array('enabled' => false)));

        $result = $this->list($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $result['data']['total']);
        $this->assertSame(1, $result['data']['statistics']['enabled']);
        $this->assertSame(1, $result['data']['statistics']['disabled']);
        $this->assertNull($result['data']['filters']['filter_enabled']);

        // The absence of the filter is not the same request as filter=false.
        $onlyDisabled = $this->list($store)->execute(
            array('filter_enabled' => false),
            array('user_id' => 7)
        );

        $this->assertSame(1, $onlyDisabled['data']['total']);
        $this->assertFalse($onlyDisabled['data']['filters']['filter_enabled']);
    }

    // ─── The dry run ─────────────────────────────────────────────────────

    public function test_the_dry_run_reports_no_match(): void
    {
        $store = new WorkflowRuleStore();
        $store->add(
            $this->rule(
                1,
                'rule_abc',
                'Expiry',
                array(
                    'trigger' => array(
                        'event'      => 'expiry_approaching',
                        'conditions' => array('days_before' => 30),
                    ),
                )
            )
        );

        $result = $this->test_rule($store)->execute(
            array(
                'rule_id'   => 'rule_abc',
                'test_data' => array('event_type' => 'expiry_approaching'),
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['data']['trigger_matched']);
        $this->assertFalse($result['data']['conditions_evaluated']);
        $this->assertTrue($result['data']['report']['validation_valid']);

        // The conditions are echoed precisely because nothing evaluates them;
        // the caller can see what the report is not evidence about.
        $this->assertSame(
            array('days_before' => 30),
            $result['data']['report']['trigger_conditions']
        );
        $this->assertTrue($result['data']['report']['event_matches']);
    }

    public function test_a_missing_registration_is_a_warning(): void
    {
        $store = new WorkflowRuleStore();
        $store->add($this->rule(1, 'rule_abc', 'Expiry'));

        $missing = $this->test_rule($store)->execute(
            array('rule_id' => 'rule_abc', 'test_data' => array('registration_id' => 99)),
            array('user_id' => 7)
        );

        $this->assertFalse($missing['data']['report']['test_registration']['found']);
        $this->assertContains('Test registration not found.', $missing['data']['report']['warnings']);

        $store->add($this->registration(5));

        $found = $this->test_rule($store)->execute(
            array('rule_id' => 'rule_abc', 'test_data' => array('registration_id' => 5)),
            array('user_id' => 7)
        );

        $this->assertTrue($found['data']['report']['test_registration']['found']);
        $this->assertNotContains('Test registration not found.', $found['data']['report']['warnings']);
    }

    public function test_disabling_action_validation_silences_only_action_errors(): void
    {
        $store = new WorkflowRuleStore();
        $store->add(
            $this->rule(
                1,
                'rule_abc',
                'Broken',
                array(
                    'trigger' => array('event' => 'nonsense', 'conditions' => array()),
                    'actions' => array(array('type' => 'also_nonsense', 'params' => array())),
                )
            )
        );

        $strict = $this->test_rule($store)->execute(
            array('rule_id' => 'rule_abc'),
            array('user_id' => 7)
        );

        $this->assertFalse($strict['data']['report']['validation_valid']);
        $this->assertArrayHasKey('trigger.event', $strict['data']['report']['field_errors']);
        $this->assertArrayHasKey('actions', $strict['data']['report']['field_errors']);

        $relaxed = $this->test_rule($store)->execute(
            array('rule_id' => 'rule_abc', 'validate_actions' => false),
            array('user_id' => 7)
        );

        // The trigger is still wrong; only the action complaint is suppressed.
        $this->assertFalse($relaxed['data']['report']['validation_valid']);
        $this->assertArrayHasKey('trigger.event', $relaxed['data']['report']['field_errors']);
        $this->assertArrayNotHasKey('actions', $relaxed['data']['report']['field_errors']);
        $this->assertSame(array(), $relaxed['data']['report']['warnings']);
    }

    // ─── The log ─────────────────────────────────────────────────────────

    public function test_filtering_by_rule_drops_entries_without_the_field(): void
    {
        $store = new WorkflowRuleStore();

        $store->add($this->logEntry(1, 'rule_abc', 'create_rule', '2026-01-05 10:00:00'));
        $store->add($this->logEntry(2, 'rule_other', 'create_rule', '2026-01-05 11:00:00'));
        $store->add($this->logEntry(3, '', 'execute_rule', '2026-01-05 12:00:00', false));

        $result = $this->log($store)->execute(
            array('rule_id' => 'rule_abc'),
            array('user_id' => 7)
        );

        // WordPress kept the third entry: its isset() guard failed on a missing
        // rule_id and the row was never excluded.
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('rule_abc', $result['data']['log'][0]['rule_id']);
    }

    public function test_log_statistics_describe_the_matched_set(): void
    {
        $store = new WorkflowRuleStore();

        $store->add($this->logEntry(1, 'rule_abc', 'create_rule', '2026-01-05 10:00:00'));
        $store->add($this->logEntry(2, 'rule_abc', 'update_rule', '2026-01-05 11:00:00'));
        $store->add($this->logEntry(3, 'rule_other', 'delete_rule', '2026-01-05 12:00:00'));

        $result = $this->log($store)->execute(
            array('rule_id' => 'rule_abc', 'limit' => 1),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(2, $result['data']['matched']);
        $this->assertSame(3, $result['data']['store_entries']);
        $this->assertTrue($result['data']['truncated']);

        // Counted over the matched set, which is what sits beside it, not over
        // the whole store as WordPress did.
        $this->assertSame(
            array('create_rule' => 1, 'update_rule' => 1),
            $result['data']['statistics']['by_action_type']
        );

        // Newest first.
        $this->assertSame('2026-01-05 11:00:00', $result['data']['log'][0]['timestamp']);
    }

    public function test_an_unreadable_timestamp_is_counted_not_dated(): void
    {
        $store = new WorkflowRuleStore();

        $store->add($this->logEntry(1, 'rule_abc', 'create_rule', '2026-01-05 10:00:00'));
        $store->add($this->logEntry(2, 'rule_abc', 'update_rule', 'not-a-date'));

        $all = $this->log($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $all['data']['total']);
        $this->assertSame(1, $all['data']['unreadable_timestamps']);

        // Unreadable entries sort last rather than as if they were from 1970.
        $this->assertSame('2026-01-05 10:00:00', $all['data']['log'][0]['timestamp']);
        $this->assertSame('not-a-date', $all['data']['log'][1]['timestamp']);

        $dated = $this->log($store)->execute(
            array('start_date' => '2026-01-01'),
            array('user_id' => 7)
        );

        // A date range is a claim about when something happened, so an entry
        // with no readable date cannot satisfy it.
        $this->assertSame(1, $dated['data']['total']);
        $this->assertSame(1, $dated['data']['matched']);
        $this->assertSame(1, $dated['data']['unreadable_timestamps']);
    }

    public function test_a_malformed_date_is_refused(): void
    {
        $store = new WorkflowRuleStore();

        $malformed = $this->log($store)->execute(
            array('start_date' => '2026-13-99'),
            array('user_id' => 7)
        );

        $this->assertFalse($malformed['success']);
        $this->assertArrayHasKey('start_date', $malformed['error']['data']);

        $reversed = $this->log($store)->execute(
            array('start_date' => '2026-02-01', 'end_date' => '2026-01-01'),
            array('user_id' => 7)
        );

        $this->assertFalse($reversed['success']);
        $this->assertArrayHasKey('end_date', $reversed['error']['data']);
    }

    // ─── Tools ───────────────────────────────────────────────────────────

    private function create(WorkflowRuleStore $store): CreateWorkflowRuleTool
    {
        return new CreateWorkflowRuleTool($this->errors, $store);
    }

    private function update(WorkflowRuleStore $store): UpdateWorkflowRuleTool
    {
        return new UpdateWorkflowRuleTool($this->errors, $store);
    }

    private function delete(WorkflowRuleStore $store): DeleteWorkflowRuleTool
    {
        return new DeleteWorkflowRuleTool($this->errors, $store);
    }

    private function list(WorkflowRuleStore $store): ListWorkflowRulesTool
    {
        return new ListWorkflowRulesTool($this->errors, $store);
    }

    private function test_rule(WorkflowRuleStore $store): TestWorkflowRuleTool
    {
        return new TestWorkflowRuleTool($this->errors, $store);
    }

    private function log(WorkflowRuleStore $store): GetWorkflowExecutionLogTool
    {
        return new GetWorkflowExecutionLogTool($this->errors, $store);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $meta
     */
    private function rule(int $id, string $key, string $name, array $meta = array()): ContentItem
    {
        return $this->item(
            $id,
            $name,
            'mcp_ai_workflow_rule',
            array_merge(
                array(
                    'rule_key'   => $key,
                    'trigger'    => array('event' => 'status_change', 'conditions' => array()),
                    'actions'    => array(
                        array(
                            'type'   => 'send_email',
                            'params' => array('recipients' => array('ops@example.test')),
                        ),
                    ),
                    'enabled'    => true,
                    'executions' => 0,
                    'created_at' => '2026-01-01 09:00:00',
                    'created_by' => 7,
                ),
                $meta
            )
        );
    }

    private function registration(int $id): ContentItem
    {
        return $this->item($id, 'Registration ' . $id, 'mcp_ai_registration', array('country' => 'LK'));
    }

    private function logEntry(
        int $id,
        string $ruleKey,
        string $action,
        string $timestamp,
        bool $withRuleKey = true
    ): ContentItem {
        $meta = array(
            'timestamp' => $timestamp,
            'user_id'   => 7,
            'action'    => $action,
            'rule_name' => 'Rule ' . $id,
        );

        if ($withRuleKey) {
            $meta['rule_id'] = $ruleKey;
        }

        return $this->item($id, $action . ' entry', 'mcp_ai_workflow_log', $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function item(int $id, string $title, string $type, array $meta = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
        );
    }
}
