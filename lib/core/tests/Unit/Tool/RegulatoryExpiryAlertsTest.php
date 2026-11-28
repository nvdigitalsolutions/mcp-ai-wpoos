<?php

/**
 * The twelfth regulatory-registration slice: expiry alerts.
 *
 * Proposal 164 section 164.155. What is pinned: the alert lists only
 * registrations inside the window, an already-expired one is excluded and
 * counted, an invalid address is reported and dropped rather than attempted, a
 * call with no usable recipient is refused instead of guessing, a host without
 * a transport still gets the report, and a test run sends nothing.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Regulatory\SendExpiryAlertsTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryExpiryAlertsTest extends TestCase
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

    public function test_an_alert_lists_the_window_and_reaches_every_recipient(): void
    {
        $mail  = $this->mail();
        $store = $this->store(array(
            $this->registration(1, $this->inDays(10), 'LK'),
            $this->registration(2, $this->inDays(60), 'AE'),
        ));

        $result = $this->alerts($store, $mail)->execute(
            array('recipients' => array('ops@example.com', 'qa@example.com')),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['expiring_registrations']);
        $this->assertSame(2, $result['data']['emails_sent']);
        $this->assertTrue($result['data']['transport_available']);
        $this->assertCount(2, $mail->sent);
        $this->assertSame('ops@example.com', $mail->sent[0]['to']);
        $this->assertStringContainsString('Registration 1', $mail->sent[0]['body']);
        $this->assertStringContainsString('2 registrations expiring soon', $mail->sent[0]['subject']);
    }

    public function test_registrations_are_listed_soonest_first(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(60)),
            $this->registration(2, $this->inDays(5)),
        ));

        $result = $this->alerts($store, $this->mail())->execute(
            array('recipients' => array('ops@example.com')),
            array('user_id' => 7)
        );

        $this->assertSame(2, $result['data']['registrations'][0]['id']);
    }

    public function test_an_expired_registration_is_excluded_and_counted(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(-20)),
            $this->registration(2, $this->inDays(10)),
        ));

        $result = $this->alerts($store, $this->mail())->execute(
            array('recipients' => array('ops@example.com')),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['expiring_registrations']);
        $this->assertSame(1, $result['data']['expired_excluded']);
    }

    public function test_a_registration_beyond_the_window_is_not_alerted(): void
    {
        $store = $this->store(array($this->registration(1, $this->inDays(200))));

        $result = $this->alerts($store, $this->mail())->execute(
            array('recipients' => array('ops@example.com')),
            array('user_id' => 7)
        );

        $this->assertSame(0, $result['data']['expiring_registrations']);
        $this->assertSame(0, $result['data']['emails_sent']);
        $this->assertStringContainsString('no alert was due', $result['message']);
    }

    public function test_test_mode_sends_nothing_and_still_reports(): void
    {
        $mail  = $this->mail();
        $store = $this->store(array($this->registration(1, $this->inDays(10))));

        $result = $this->alerts($store, $mail)->execute(
            array('recipients' => array('ops@example.com'), 'test_mode' => true),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['test_mode']);
        $this->assertSame(1, $result['data']['expiring_registrations']);
        $this->assertSame(0, $result['data']['emails_sent']);
        $this->assertSame(array(), $mail->sent);
        $this->assertStringContainsString('Test mode', $result['message']);
    }

    public function test_a_host_without_a_transport_still_gets_the_report(): void
    {
        $store = $this->store(array($this->registration(1, $this->inDays(10))));

        $result = $this->alerts($store, null)->execute(
            array('recipients' => array('ops@example.com')),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['transport_available']);
        $this->assertSame(0, $result['data']['emails_sent']);
        $this->assertSame(1, $result['data']['expiring_registrations']);
        $this->assertStringContainsString('no email service', $result['message']);
    }

    public function test_an_invalid_address_is_reported_and_dropped(): void
    {
        $mail  = $this->mail();
        $store = $this->store(array($this->registration(1, $this->inDays(10))));

        $result = $this->alerts($store, $mail)->execute(
            array('recipients' => array('ops@example.com', 'not-an-address')),
            array('user_id' => 7)
        );

        $this->assertSame(array('ops@example.com'), $result['data']['recipients']);
        $this->assertSame(array('not-an-address'), $result['data']['invalid_recipients']);
        $this->assertSame(1, $result['data']['emails_sent']);
        $this->assertCount(1, $mail->sent);
    }

    public function test_no_usable_recipient_refuses_the_call(): void
    {
        $store = $this->store(array($this->registration(1, $this->inDays(10))));

        $none = $this->alerts($store, $this->mail())->execute(
            array('recipients' => array('nobody')),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $none['error']['code']);
        $this->assertArrayHasKey('recipients', $none['error']['data']);

        $absent = $this->alerts($store, $this->mail())->execute(array(), array('user_id' => 7));

        $this->assertSame('validation_failed', $absent['error']['code']);
    }

    public function test_the_host_default_recipients_are_used_when_none_are_given(): void
    {
        $mail  = $this->mail();
        $store = $this->store(array($this->registration(1, $this->inDays(10))));

        $result = ( new SendExpiryAlertsTool(
            $this->errors,
            $store,
            $mail,
            array('team@example.com')
        ) )->execute(array(), array('user_id' => 7));

        $this->assertSame(array('team@example.com'), $result['data']['recipients']);
        $this->assertSame(1, $result['data']['emails_sent']);
    }

    public function test_a_transport_refusal_is_reported_per_recipient(): void
    {
        $mail = $this->mail(array('qa@example.com'));

        $store = $this->store(array($this->registration(1, $this->inDays(10))));

        $result = $this->alerts($store, $mail)->execute(
            array('recipients' => array('ops@example.com', 'qa@example.com')),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['emails_sent']);
        $this->assertArrayHasKey('qa@example.com', $result['data']['failed_recipients']);
        $this->assertStringContainsString('refused', (string) $result['data']['failed_recipients']['qa@example.com']);
    }

    public function test_an_unreadable_expiry_is_named(): void
    {
        $store = $this->store(array($this->registration(1, 'n/a')));

        $result = $this->alerts($store, $this->mail())->execute(
            array('recipients' => array('ops@example.com')),
            array('user_id' => 7)
        );

        $this->assertSame('n/a', $result['data']['unreadable_expiry_dates'][0]['expiry_date']);
        $this->assertSame(0, $result['data']['expiring_registrations']);
    }

    public function test_the_window_is_bounded(): void
    {
        $result = $this->alerts($this->store(), $this->mail())->execute(
            array('days_threshold' => 0, 'recipients' => array('ops@example.com')),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('days_threshold', $result['error']['data']);
    }

    public function test_the_alert_capability_is_edit_posts(): void
    {
        $this->assertSame('edit_posts', $this->alerts($this->store(), $this->mail())->getRequiredCapability());
    }

    // ─── Fixtures ───────────────────────────────────────────────────────

    /**
     * @param list<string> $refuse
     */
    private function mail(array $refuse = array()): EmailServiceInterface
    {
        return new class ($refuse) implements EmailServiceInterface {
            /** @var list<array<string, mixed>> */
            public array $sent = array();

            /** @var list<string> */
            private array $refuse;

            /**
             * @param list<string> $refuse
             */
            public function __construct(array $refuse)
            {
                $this->refuse = $refuse;
            }

            public function send(array $message): array
            {
                $to = (string) ( $message['to'] ?? '' );

                if (in_array($to, $this->refuse, true)) {
                    return array('success' => false, 'error' => 'The transport refused this address.');
                }

                $this->sent[] = $message;

                return array('success' => true, 'message_id' => 'msg-' . count($this->sent));
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function validateRecipient(string $email): bool
            {
                return false !== filter_var($email, FILTER_VALIDATE_EMAIL);
            }
        };
    }

    private function alerts(ContentStoreInterface $store, ?EmailServiceInterface $mail): SendExpiryAlertsTool
    {
        return new SendExpiryAlertsTool($this->errors, $store, $mail);
    }

    /**
     * A date a given number of days from today, as the toolkit stores dates.
     */
    private function inDays(int $days): string
    {
        return gmdate('Y-m-d', time() + ( $days * 86400 ));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function registration(int $id, string $expiryDate, string $country = 'LK', array $meta = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: 'Registration ' . $id,
            content: '',
            status: 'publish',
            type: 'mcp_ai_registration',
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: array_merge(array('expiry_date' => $expiryDate, 'country' => $country), $meta),
            taxonomy: array(),
        );
    }

    /**
     * A store that answers type, equality and IN meta clauses.
     *
     * @param array<int, ContentItem> $items
     */
    private function store(array $items = array()): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items = array();

            /**
             * @param array<int, ContentItem> $items
             */
            public function __construct(array $items)
            {
                foreach ($items as $item) {
                    $this->items[ $item->id ] = $item;
                }
            }

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return $this->items[ $id ] ?? null;
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $matched = array();

                foreach ($this->items as $item) {
                    if (array() !== $query->types && ! in_array($item->type, $query->types, true)) {
                        continue;
                    }

                    if (! $this->matchesMeta($item, $query->metaQuery)) {
                        continue;
                    }

                    $matched[] = $item;
                }

                usort($matched, static fn (ContentItem $a, ContentItem $b): int => $a->id <=> $b->id);

                $perPage = $query->perPage > 0 ? $query->perPage : 20;
                $offset  = ( max(1, $query->page) - 1 ) * $perPage;
                $page    = array_slice($matched, $offset, $perPage);

                return new ContentCollection(
                    $page,
                    count($matched),
                    max(1, $query->page),
                    $perPage,
                    (int) ceil(count($matched) / $perPage)
                );
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                throw new \LogicException('not used here.');
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                throw new \LogicException('not used here.');
            }

            public function delete(int $id, int $userId): void
            {
                throw new \LogicException('not used here.');
            }

            public function getMeta(int $id): array
            {
                return $this->items[ $id ]->meta ?? array();
            }

            public function getTaxonomyTerms(int $id): array
            {
                return $this->items[ $id ]->taxonomy ?? array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return isset($this->items[ $id ]);
            }

            /**
             * @param array<int|string, mixed> $clauses
             */
            private function matchesMeta(ContentItem $item, array $clauses): bool
            {
                if (array() === $clauses) {
                    return true;
                }

                foreach ($clauses as $name => $clause) {
                    if ('relation' === $name || ! is_array($clause) || ! isset($clause['key'])) {
                        continue;
                    }

                    $stored  = $item->meta[ (string) $clause['key'] ] ?? null;
                    $compare = strtoupper((string) ( $clause['compare'] ?? '=' ));
                    $value   = $clause['value'] ?? null;

                    if ('IN' === $compare) {
                        $values = is_array($value) ? array_map('strval', $value) : array((string) $value);

                        if (! in_array((string) $stored, $values, true)) {
                            return false;
                        }

                        continue;
                    }

                    if ((string) $stored !== (string) $value) {
                        return false;
                    }
                }

                return true;
            }
        };
    }
}
