<?php

/**
 * The fourteenth regulatory-registration slice: notifications.
 *
 * Proposal 164 section 164.161. What is pinned: a rule is one item per type and
 * a second configuration updates it in place; an unknown type, an all-invalid
 * recipient list and a missing `enabled` are all refused; the history filter
 * excludes entries lacking the field instead of keeping them and its statistics
 * describe the matched set; and the sender resolves recipients in three named
 * steps, reports where they came from, drops invalid addresses, and records the
 * attempt even when nothing could be delivered.
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
use Nvoos\Core\Tests\Support\FakeEmailService;
use Nvoos\Core\Tool\Regulatory\ConfigureEmailNotificationsTool;
use Nvoos\Core\Tool\Regulatory\GetNotificationHistoryTool;
use Nvoos\Core\Tool\Regulatory\SendStatusChangeNotificationTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryNotificationToolsTest extends TestCase
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
    }

    // ─── Configuration ───────────────────────────────────────────────────

    public function test_configuring_a_type_creates_one_rule(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->configure($store)->execute(
            array(
                'notification_type' => 'status_change',
                'enabled'           => true,
                'recipients'        => array('ops@example.test'),
                'conditions'        => array('status_to' => 'approved'),
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['created']);
        $this->assertSame(1, $result['data']['recipients_count']);
        $this->assertFalse($result['data']['dispatch_configured']);

        $rules = $store->byType('mcp_ai_notification_rule');

        $this->assertCount(1, $rules);
        $this->assertSame('status_change', $rules[0]->meta['notification_type']);
        $this->assertTrue($rules[0]->meta['enabled']);
        $this->assertSame(array('ops@example.test'), $rules[0]->meta['recipients']);
        $this->assertSame(array('status_to' => 'approved'), $rules[0]->meta['conditions']);
        $this->assertSame(7, $rules[0]->meta['created_by']);
    }

    public function test_configuring_the_same_type_twice_updates_in_place(): void
    {
        $store = new InMemoryContentStore();
        $tool  = $this->configure($store);

        $tool->execute(
            array(
                'notification_type' => 'expiry_alert',
                'enabled'           => true,
                'recipients'        => array('first@example.test'),
            ),
            array('user_id' => 7)
        );

        $second = $tool->execute(
            array(
                'notification_type' => 'expiry_alert',
                'enabled'           => false,
                'recipients'        => array('second@example.test'),
            ),
            array('user_id' => 9)
        );

        $this->assertFalse($second['data']['created']);

        $rules = $store->byType('mcp_ai_notification_rule');

        $this->assertCount(1, $rules);
        $this->assertSame(array('second@example.test'), $rules[0]->meta['recipients']);
        $this->assertFalse($rules[0]->meta['enabled']);
        $this->assertSame(7, $rules[0]->meta['created_by']);
        $this->assertSame(9, $rules[0]->meta['updated_by']);
    }

    public function test_an_unknown_notification_type_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->configure($store)->execute(
            array('notification_type' => 'nonsense', 'enabled' => true),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('notification_type', $result['error']['data']);
        $this->assertCount(0, $store->byType('mcp_ai_notification_rule'));
    }

    public function test_enabled_is_required(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->configure($store)->execute(
            array('notification_type' => 'status_change'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('enabled', $result['error']['data']);
    }

    public function test_a_string_false_does_not_enable(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->configure($store)->execute(
            array('notification_type' => 'status_change', 'enabled' => 'false'),
            array('user_id' => 7)
        );

        // WordPress cast with (bool), where the string false is true.
        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['enabled']);
        $this->assertFalse($store->byType('mcp_ai_notification_rule')[0]->meta['enabled']);
    }

    public function test_a_bad_address_is_dropped_and_reported(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->configure($store)->execute(
            array(
                'notification_type' => 'status_change',
                'enabled'           => true,
                'recipients'        => array('good@example.test', 'not-an-address'),
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('good@example.test'), $result['data']['recipients']);
        $this->assertSame(array('not-an-address'), $result['data']['invalid_recipients']);
        $this->assertSame(
            array('good@example.test'),
            $store->byType('mcp_ai_notification_rule')[0]->meta['recipients']
        );
    }

    public function test_a_list_with_no_valid_address_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->configure($store)->execute(
            array(
                'notification_type' => 'status_change',
                'enabled'           => true,
                'recipients'        => array('nope', 'also-nope'),
            ),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('recipients', $result['error']['data']);
        $this->assertCount(0, $store->byType('mcp_ai_notification_rule'));
    }

    public function test_a_zero_day_condition_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->configure($store)->execute(
            array(
                'notification_type' => 'expiry_alert',
                'enabled'           => true,
                'conditions'        => array('days_before_expiry' => 0),
            ),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('conditions.days_before_expiry', $result['error']['data']);
    }

    // ─── History ─────────────────────────────────────────────────────────

    public function test_an_empty_log_returns_nothing(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->history($store)->execute(array(), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['history']);
        $this->assertSame(0, $result['data']['total']);
        $this->assertSame(0, $result['data']['store_entries']);
    }

    public function test_filtering_by_type_drops_entries_without_the_field(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->logEntry(1, 'status_change', '2026-01-05 10:00:00'));
        $store->add($this->logEntry(2, '', '2026-01-05 11:00:00', false));

        $result = $this->history($store)->execute(
            array('notification_type' => 'status_change'),
            array('user_id' => 7)
        );

        // WordPress kept the second: isset() failed on the missing key and the
        // row was never excluded.
        $this->assertSame(1, $result['data']['total']);
        $this->assertSame('status_change', $result['data']['history'][0]['type']);
    }

    public function test_statistics_describe_the_matched_set(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->logEntry(1, 'status_change', '2026-01-05 10:00:00'));
        $store->add($this->logEntry(2, 'status_change', '2026-01-05 11:00:00'));
        $store->add($this->logEntry(3, 'expiry_alert', '2026-01-05 12:00:00'));

        $result = $this->history($store)->execute(
            array('notification_type' => 'status_change', 'limit' => 1),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(2, $result['data']['matched']);
        $this->assertSame(3, $result['data']['store_entries']);
        $this->assertTrue($result['data']['truncated']);
        $this->assertSame(
            array('status_change' => 2),
            $result['data']['statistics']['by_type']
        );
        $this->assertSame('2026-01-05 11:00:00', $result['data']['history'][0]['timestamp']);
    }

    public function test_an_unreadable_timestamp_is_counted_not_dated(): void
    {
        $store = new InMemoryContentStore();

        $store->add($this->logEntry(1, 'status_change', '2026-01-05 10:00:00'));
        $store->add($this->logEntry(2, 'status_change', 'not-a-date'));

        $all = $this->history($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $all['data']['total']);
        $this->assertSame(1, $all['data']['unreadable_timestamps']);
        $this->assertSame('not-a-date', $all['data']['history'][1]['timestamp']);

        $dated = $this->history($store)->execute(
            array('start_date' => '2026-01-01'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $dated['data']['total']);
        $this->assertSame(1, $dated['data']['unreadable_timestamps']);
    }

    public function test_a_malformed_date_is_refused(): void
    {
        $store = new InMemoryContentStore();

        $result = $this->history($store)->execute(
            array('start_date' => '2026-13-99'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('start_date', $result['error']['data']);
    }

    // ─── Sending ─────────────────────────────────────────────────────────

    public function test_a_send_uses_the_argument_recipients_and_logs_the_attempt(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10, array('country' => 'LK', 'authority' => 'NMRA')));
        $email = new FakeEmailService();

        $result = $this->send($store, $email)->execute(
            array(
                'registration_id' => 10,
                'old_status'      => 'draft',
                'new_status'      => 'submitted',
                'recipients'      => array('ops@example.test'),
                'notes'           => 'Filed by hand.',
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('argument', $result['data']['recipients_source']);
        $this->assertSame(1, $result['data']['emails_sent']);
        $this->assertTrue($result['data']['transport_available']);
        $this->assertTrue($result['data']['logged']);

        $this->assertCount(1, $email->sent);
        $this->assertSame('ops@example.test', $email->sent[0]['to']);
        $this->assertStringContainsString('Status Change: Registration 10', $email->sent[0]['subject']);
        $this->assertStringContainsString('Previous Status: draft', $email->sent[0]['body']);
        $this->assertStringContainsString('New Status: submitted', $email->sent[0]['body']);
        $this->assertStringContainsString('Filed by hand.', $email->sent[0]['body']);

        // No admin screen exists to link to, so the WordPress trailing line is gone.
        $this->assertStringNotContainsString('View Registration', $email->sent[0]['body']);

        $log = $store->byType('mcp_ai_notification_log');

        $this->assertCount(1, $log);
        $this->assertSame('status_change', $log[0]->meta['type']);
        $this->assertSame(10, $log[0]->meta['registration_id']);
        $this->assertSame(1, $log[0]->meta['emails_sent']);
        $this->assertSame(array('ops@example.test'), $log[0]->meta['recipients']);
    }

    public function test_recipients_fall_back_to_the_configured_rule(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));
        $email = new FakeEmailService();

        // The two tools connect through the store: this is the one field of a
        // rule that anything reads.
        $this->configure($store)->execute(
            array(
                'notification_type' => 'status_change',
                'enabled'           => false,
                'recipients'        => array('team@example.test'),
            ),
            array('user_id' => 7)
        );

        $result = $this->send($store, $email)->execute(
            array('registration_id' => 10, 'old_status' => 'draft', 'new_status' => 'submitted'),
            array('user_id' => 7)
        );

        $this->assertSame('configuration', $result['data']['recipients_source']);
        $this->assertSame(array('team@example.test'), $result['data']['recipients']);
        $this->assertCount(1, $email->sent);
    }

    public function test_the_host_default_is_the_last_resort(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));
        $email = new FakeEmailService();

        $result = $this->send($store, $email, array('host@example.test'))->execute(
            array('registration_id' => 10, 'old_status' => 'draft', 'new_status' => 'submitted'),
            array('user_id' => 7)
        );

        $this->assertSame('default', $result['data']['recipients_source']);
        $this->assertSame(array('host@example.test'), $result['data']['recipients']);
    }

    public function test_no_recipients_anywhere_is_a_refusal(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));
        $email = new FakeEmailService();

        $result = $this->send($store, $email)->execute(
            array('registration_id' => 10, 'old_status' => 'draft', 'new_status' => 'submitted'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('recipients', $result['error']['data']);
        $this->assertCount(0, $email->sent);
        $this->assertCount(0, $store->byType('mcp_ai_notification_log'));
    }

    public function test_a_bad_address_is_dropped_and_the_rest_are_sent(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));
        $email = new FakeEmailService();

        $result = $this->send($store, $email)->execute(
            array(
                'registration_id' => 10,
                'old_status'      => 'draft',
                'new_status'      => 'submitted',
                'recipients'      => array('good@example.test', 'not-an-address'),
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['emails_sent']);
        $this->assertSame(array('not-an-address'), $result['data']['invalid_recipients']);
        $this->assertSame(array('good@example.test'), $result['data']['recipients']);
    }

    public function test_a_send_list_with_no_valid_address_is_refused(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));
        $email = new FakeEmailService();

        $result = $this->send($store, $email)->execute(
            array(
                'registration_id' => 10,
                'old_status'      => 'draft',
                'new_status'      => 'submitted',
                'recipients'      => array('not-an-address'),
            ),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('recipients', $result['error']['data']);
        $this->assertCount(0, $email->sent);
        $this->assertCount(0, $store->byType('mcp_ai_notification_log'));
    }

    public function test_a_missing_transport_still_records_the_attempt(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));

        $result = $this->send($store, null)->execute(
            array(
                'registration_id' => 10,
                'old_status'      => 'draft',
                'new_status'      => 'submitted',
                'recipients'      => array('ops@example.test'),
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['transport_available']);
        $this->assertSame(0, $result['data']['emails_sent']);
        $this->assertTrue($result['data']['logged']);
        $this->assertStringContainsString('no mail transport is available', $result['message']);
        $this->assertCount(1, $store->byType('mcp_ai_notification_log'));
    }

    public function test_a_transport_failure_is_reported_without_throwing(): void
    {
        $store = new InMemoryContentStore();
        $store->add($this->registration(10));

        $email            = new FakeEmailService();
        $email->succeed   = false;

        $result = $this->send($store, $email)->execute(
            array(
                'registration_id' => 10,
                'old_status'      => 'draft',
                'new_status'      => 'submitted',
                'recipients'      => array('ops@example.test'),
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['emails_sent']);
        $this->assertArrayHasKey('ops@example.test', $result['data']['failed_recipients']);
        $this->assertStringContainsString('not delivered', $result['message']);
        $this->assertCount(1, $store->byType('mcp_ai_notification_log'));
    }

    public function test_an_unknown_registration_is_not_found(): void
    {
        $store = new InMemoryContentStore();
        $email = new FakeEmailService();

        $result = $this->send($store, $email)->execute(
            array(
                'registration_id' => 99,
                'old_status'      => 'draft',
                'new_status'      => 'submitted',
                'recipients'      => array('ops@example.test'),
            ),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
        $this->assertCount(0, $email->sent);
    }

    // ─── Tools ───────────────────────────────────────────────────────────

    private function configure(InMemoryContentStore $store): ConfigureEmailNotificationsTool
    {
        return new ConfigureEmailNotificationsTool($this->errors, $store);
    }

    private function history(InMemoryContentStore $store): GetNotificationHistoryTool
    {
        return new GetNotificationHistoryTool($this->errors, $store);
    }

    /**
     * @param array<int, string>|null $defaults
     */
    private function send(
        InMemoryContentStore $store,
        ?FakeEmailService $email,
        ?array $defaults = null
    ): SendStatusChangeNotificationTool {
        return new SendStatusChangeNotificationTool(
            $this->errors,
            $store,
            $email,
            null === $defaults ? array() : $defaults
        );
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $meta
     */
    private function registration(int $id, array $meta = array()): ContentItem
    {
        return $this->item(
            $id,
            'Registration ' . $id,
            'mcp_ai_registration',
            array_merge(array('country' => 'LK', 'authority' => 'NMRA', 'cos_number' => 'COS-1'), $meta)
        );
    }

    private function logEntry(int $id, string $type, string $timestamp, bool $withType = true): ContentItem
    {
        $meta = array(
            'timestamp'       => $timestamp,
            'user_id'         => 7,
            'registration_id' => 10,
            'old_status'      => 'draft',
            'new_status'      => 'submitted',
            'recipients'      => array('ops@example.test'),
            'emails_sent'     => 1,
        );

        if ($withType) {
            $meta['type'] = $type;
        }

        return $this->item($id, 'Notification ' . $id, 'mcp_ai_notification_log', $meta);
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
