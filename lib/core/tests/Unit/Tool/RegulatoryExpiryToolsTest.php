<?php

/**
 * The tenth regulatory-registration slice: expiry and monitoring, part one.
 *
 * Proposal 164 section 164.153. What is pinned: a document or registration with
 * an unreadable date is reported as expired with a null day count and named,
 * never given a day count the value does not support; the overdue list leads
 * with the longest-invalid record; a document with no expiry date is not
 * counted valid; and the warning window is a bounded question rather than a
 * silently clamped one.
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
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Regulatory\CheckDocumentExpiryTool;
use Nvoos\Core\Tool\Regulatory\ListExpiringRegistrationsTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryExpiryToolsTest extends TestCase
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

    // ─── Document expiry ────────────────────────────────────────────────

    public function test_documents_are_bucketed_against_the_window(): void
    {
        $store = $this->store(array(
            $this->document(1, $this->inDays(-10)),
            $this->document(2, $this->inDays(30)),
            $this->document(3, $this->inDays(200)),
        ));

        $result = $this->expiry($store)->execute(array(), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('critical', $result['data']['alert_level']);
        $this->assertSame(1, $result['data']['expired_count']);
        $this->assertSame(1, $result['data']['expiring_soon_count']);
        $this->assertSame(1, $result['data']['valid_count']);
        $this->assertSame(3, $result['data']['total_documents']);
        $this->assertSame('expired', $result['data']['expired_documents'][0]['status']);
        $this->assertSame('expiring_soon', $result['data']['expiring_soon'][0]['status']);
        $this->assertStringContainsString('1 expired', $result['message']);
    }

    public function test_only_expiring_soon_is_a_warning(): void
    {
        $store = $this->store(array($this->document(1, $this->inDays(30))));

        $result = $this->expiry($store)->execute(array(), array('user_id' => 7));

        $this->assertSame('warning', $result['data']['alert_level']);
        $this->assertSame(0, $result['data']['expired_count']);
    }

    public function test_nothing_to_report_is_ok(): void
    {
        $result = $this->expiry($this->store())->execute(array(), array('user_id' => 7));

        $this->assertSame('ok', $result['data']['alert_level']);
        $this->assertSame(0, $result['data']['total_documents']);
    }

    public function test_the_overdue_list_leads_with_the_longest_invalid(): void
    {
        $store = $this->store(array(
            $this->document(1, $this->inDays(-5)),
            $this->document(2, $this->inDays(-400)),
        ));

        $result = $this->expiry($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $result['data']['expired_documents'][0]['id']);
        $this->assertSame(-400, $result['data']['expired_documents'][0]['days_to_expiry']);
        $this->assertSame(1, $result['data']['expired_documents'][1]['id']);
    }

    public function test_an_unreadable_date_is_expired_and_named(): void
    {
        $store = $this->store(array($this->document(1, 'n/a')));

        $result = $this->expiry($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(1, $result['data']['expired_count']);
        $this->assertNull($result['data']['expired_documents'][0]['days_to_expiry']);
        $this->assertSame('n/a', $result['data']['unparsed_expiry_dates'][0]['expiry_date']);
        $this->assertSame(1, $result['data']['unparsed_expiry_dates'][0]['document_id']);
    }

    public function test_an_unreadable_date_sorts_ahead_of_an_overdue_one(): void
    {
        $store = $this->store(array(
            $this->document(1, $this->inDays(-400)),
            $this->document(2, 'n/a'),
        ));

        $result = $this->expiry($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $result['data']['expired_documents'][0]['id']);
    }

    public function test_a_document_without_an_expiry_is_not_counted_valid(): void
    {
        $store = $this->store(array($this->document(1, '')));

        $result = $this->expiry($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(0, $result['data']['valid_count']);
        $this->assertSame(0, $result['data']['total_documents']);
    }

    public function test_a_product_filter_narrows_the_documents(): void
    {
        $store = $this->store(array(
            $this->document(1, $this->inDays(-1), array('product_id' => 5)),
            $this->document(2, $this->inDays(-1), array('product_id' => 6)),
        ));

        $result = $this->expiry($store)->execute(array('product_id' => 5), array('user_id' => 7));

        $this->assertSame(1, $result['data']['expired_count']);
        $this->assertSame(5, $result['data']['expired_documents'][0]['product_id']);
    }

    public function test_the_warning_window_is_bounded(): void
    {
        $store = $this->store();

        foreach (array(0, 366) as $days) {
            $result = $this->expiry($store)->execute(
                array('warning_days' => $days),
                array('user_id' => 7)
            );

            $this->assertSame('validation_failed', $result['error']['code']);
            $this->assertArrayHasKey('warning_days', $result['error']['data']);
        }
    }

    public function test_the_expiry_capability_is_read(): void
    {
        $this->assertSame('read', $this->expiry($this->store())->getRequiredCapability());
    }

    // ─── Expiring registrations ─────────────────────────────────────────

    public function test_registrations_are_listed_and_the_horizon_excludes_the_rest(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(-3), 'LK'),
            $this->registration(2, $this->inDays(40), 'AE'),
            $this->registration(3, $this->inDays(300), 'AE'),
        ));

        $result = $this->expiring($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(1, $result['data']['expired_count']);
        $this->assertSame(1, $result['data']['expiring_soon_count']);
        $this->assertSame(2, $result['data']['total_returned']);
        $this->assertTrue($result['data']['expired'][0]['is_expired']);
        $this->assertFalse($result['data']['expiring_soon'][0]['is_expired']);
    }

    public function test_expired_is_included_by_default_and_removable(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(-3), 'LK'),
            $this->registration(2, $this->inDays(40), 'LK'),
        ));

        $byDefault = $this->expiring($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(1, $byDefault['data']['expired_count']);
        $this->assertTrue($byDefault['data']['include_expired']);

        $forward = $this->expiring($store)->execute(
            array('include_expired' => false),
            array('user_id' => 7)
        );

        $this->assertSame(0, $forward['data']['expired_count']);
        $this->assertSame(1, $forward['data']['expiring_soon_count']);
    }

    public function test_a_country_filter_narrows_the_list(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(-3), 'LK'),
            $this->registration(2, $this->inDays(-3), 'AE'),
        ));

        $result = $this->expiring($store)->execute(array('country' => 'AE'), array('user_id' => 7));

        $this->assertSame(1, $result['data']['expired_count']);
        $this->assertSame('AE', $result['data']['expired'][0]['country']);
    }

    public function test_the_overdue_registration_list_leads_with_the_longest_invalid(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(-4), 'LK'),
            $this->registration(2, $this->inDays(-200), 'LK'),
        ));

        $result = $this->expiring($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $result['data']['expired'][0]['id']);
    }

    public function test_an_unreadable_date_stays_named_even_when_expired_rows_are_hidden(): void
    {
        $store = $this->store(array($this->registration(1, 'soon', 'LK')));

        $result = $this->expiring($store)->execute(
            array('include_expired' => false),
            array('user_id' => 7)
        );

        $this->assertSame(0, $result['data']['expired_count']);
        $this->assertSame('soon', $result['data']['unparsed_expiry_dates'][0]['expiry_date']);
        $this->assertSame(1, $result['data']['unparsed_expiry_dates'][0]['registration_id']);
    }

    public function test_the_product_name_and_status_are_attached(): void
    {
        $store = $this->store(array(
            $this->product(5, 'Rose'),
            $this->registration(
                1,
                $this->inDays(10),
                'LK',
                array('product_id' => 5),
                array('mcp_ai_reg_status' => array('Pending Documents'))
            ),
        ));

        $row = $this->expiring($store)->execute(array(), array('user_id' => 7))['data']['expiring_soon'][0];

        $this->assertSame('Rose', $row['product_name']);
        $this->assertSame('Pending Documents', $row['status']);
    }

    public function test_the_expiring_window_is_bounded(): void
    {
        $result = $this->expiring($this->store())->execute(
            array('warning_days' => 999),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_the_expiring_capability_is_read(): void
    {
        $this->assertSame('read', $this->expiring($this->store())->getRequiredCapability());
    }

    // ─── Fixtures ───────────────────────────────────────────────────────

    private function expiry(ContentStoreInterface $store): CheckDocumentExpiryTool
    {
        return new CheckDocumentExpiryTool($this->errors, $store);
    }

    private function expiring(ContentStoreInterface $store): ListExpiringRegistrationsTool
    {
        return new ListExpiringRegistrationsTool($this->errors, $store);
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
    private function document(int $id, string $expiryDate, array $meta = array()): ContentItem
    {
        $meta['expiry_date'] = $expiryDate;

        return $this->item($id, 'Document ' . $id, 'mcp_ai_reg_document', $meta);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $taxonomy
     */
    private function registration(
        int $id,
        string $expiryDate,
        string $country,
        array $meta = array(),
        array $taxonomy = array()
    ): ContentItem {
        return $this->item(
            $id,
            'Registration ' . $id,
            'mcp_ai_registration',
            array_merge(array('expiry_date' => $expiryDate, 'country' => $country), $meta),
            $taxonomy
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function product(int $id, string $title, array $meta = array()): ContentItem
    {
        return $this->item($id, $title, 'mcp_ai_reg_product', $meta);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $taxonomy
     */
    private function item(
        int $id,
        string $title,
        string $type,
        array $meta = array(),
        array $taxonomy = array()
    ): ContentItem {
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
            taxonomy: $taxonomy,
        );
    }

    /**
     * A store that answers type and equality meta clauses.
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

                    $key    = (string) $clause['key'];
                    $stored = $item->meta[ $key ] ?? null;

                    if ((string) $stored !== (string) ( $clause['value'] ?? '' )) {
                        return false;
                    }
                }

                return true;
            }
        };
    }
}
