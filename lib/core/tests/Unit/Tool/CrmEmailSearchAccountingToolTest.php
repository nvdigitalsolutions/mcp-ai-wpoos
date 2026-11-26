<?php

/**
 * The accounting email search: the accounting key family, overdue arithmetic,
 * fiscal windows, summary totals, and the pinned behaviours.
 *
 * The assertions that matter are the measured ones:
 *
 * - The transaction filter reads `accounting_transaction_type`, not
 *   `transaction_type`.
 * - A default call still returns contacts on a manager-written store (unlike
 *   the lead search), but any non-`all` accounting filter finds nothing there.
 * - `days_overdue` is null for closed statuses, missing due dates and
 *   unparsable dates (the last is a documented deviation).
 * - "Fiscal" quarters are calendar quarters.
 * - `get_cached` is a not-found error on a miss, unlike the lead search.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
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
use Nvoos\Core\Tool\Crm\CrmEmailSearchAccountingTool;
use PHPUnit\Framework\TestCase;

final class CrmEmailSearchAccountingToolTest extends TestCase
{
    /** 2026-06-01T00:00:00Z. */
    private const NOW = 1780272000;

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
            static fn (string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message),
            )
        );
    }

    public function test_the_tool_keeps_the_wordpress_surface(): void
    {
        $tool = $this->tool($this->store());

        $this->assertSame('crm_email_search_accounting', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('action'), $schema['required']);
        $this->assertSame(
            array('search', 'get_cached', 'clear_cache', 'schedule', 'unschedule'),
            $schema['properties']['action']['enum']
        );
        $this->assertSame('all', $schema['properties']['transaction_type']['default']);
        $this->assertSame('all', $schema['properties']['billing_status']['default']);
        $this->assertSame('all', $schema['properties']['service_category']['default']);
        $this->assertSame('all', $schema['properties']['fiscal_quarter']['default']);
        $this->assertSame('date', $schema['properties']['orderby']['default']);
        $this->assertSame(20, $schema['properties']['per_page']['default']);
    }

    public function test_a_default_search_returns_contacts_without_accounting_keys(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Acme Overdue', array('billing_status' => 'overdue')),
            $this->contact(11, 'Manager written', array()),
        ));

        $result = $this->tool($store)->execute(array(), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['total']);
        $this->assertSame(array(10, 11), array_column($result['data']['records'], 'id'));
    }

    public function test_an_accounting_filter_finds_nothing_on_a_manager_written_store(): void
    {
        $store = $this->store(array(
            $this->contact(11, 'Manager written', array()),
        ));

        $result = $this->tool($store)->execute(
            array('transaction_type' => 'invoice'),
            array('user_id' => 7)
        );

        $this->assertSame(0, $result['data']['total']);
    }

    public function test_the_transaction_filter_reads_the_accounting_key(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Invoice row', array('accounting_transaction_type' => 'invoice')),
            $this->contact(11, 'Payment row', array('accounting_transaction_type' => 'payment')),
        ));

        $result = $this->tool($store)->execute(
            array('transaction_type' => 'invoice'),
            array('user_id' => 7)
        );

        $this->assertSame(array(10), array_column($result['data']['records'], 'id'));
        $this->assertSame('invoice', $result['data']['records'][0]['transaction_type']);
    }

    public function test_the_billing_status_and_service_filters_are_anded(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Match', array('billing_status' => 'overdue', 'service_category' => 'tax')),
            $this->contact(11, 'Wrong status', array('billing_status' => 'paid', 'service_category' => 'tax')),
            $this->contact(12, 'Wrong service', array('billing_status' => 'overdue', 'service_category' => 'payroll')),
        ));

        $result = $this->tool($store)->execute(
            array('billing_status' => 'overdue', 'service_category' => 'tax'),
            array('user_id' => 7)
        );

        $this->assertSame(array(10), array_column($result['data']['records'], 'id'));
    }

    public function test_the_invoice_amount_range_is_numeric(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Small', array('invoice_amount' => 50)),
            $this->contact(11, 'Middle', array('invoice_amount' => 300)),
            $this->contact(12, 'Large', array('invoice_amount' => 1200)),
        ));

        $result = $this->tool($store)->execute(
            array('invoice_amount_min' => 100, 'invoice_amount_max' => 500),
            array('user_id' => 7)
        );

        $this->assertSame(array(11), array_column($result['data']['records'], 'id'));
    }

    public function test_an_invoice_amount_sort_excludes_rows_without_the_key(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Small', array('invoice_amount' => 50)),
            $this->contact(11, 'Large', array('invoice_amount' => 1200)),
            $this->contact(12, 'No amount', array()),
        ));

        $result = $this->tool($store)->execute(
            array('orderby' => 'invoice_amount', 'order' => 'DESC'),
            array('user_id' => 7)
        );

        $this->assertSame(array(11, 10), array_column($result['data']['records'], 'id'));
        $this->assertSame(2, $result['data']['total']);
    }

    public function test_days_overdue_is_computed_and_null_for_closed_statuses(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Overdue', array('billing_status' => 'overdue', 'invoice_due_date' => '2026-05-22 00:00:00')),
            $this->contact(11, 'Paid', array('billing_status' => 'paid', 'invoice_due_date' => '2026-05-01 00:00:00')),
            $this->contact(12, 'No due date', array('billing_status' => 'pending')),
        ));

        $records = $this->recordsById($this->tool($store)->execute(array(), array('user_id' => 7)));

        $this->assertSame(10, $records[10]['days_overdue']);
        $this->assertNull($records[11]['days_overdue']);
        $this->assertNull($records[12]['days_overdue']);
    }

    public function test_an_unparsable_due_date_is_null(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Bad date', array('billing_status' => 'overdue', 'invoice_due_date' => 'not-a-date')),
        ));

        $records = $this->recordsById($this->tool($store)->execute(array(), array('user_id' => 7)));

        $this->assertNull($records[10]['days_overdue']);
    }

    public function test_the_days_overdue_minimum_filters(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Ten days', array('billing_status' => 'overdue', 'invoice_due_date' => '2026-05-22 00:00:00')),
            $this->contact(11, 'Two days', array('billing_status' => 'overdue', 'invoice_due_date' => '2026-05-30 00:00:00')),
            $this->contact(12, 'Paid', array('billing_status' => 'paid', 'invoice_due_date' => '2026-05-01 00:00:00')),
        ));

        $result = $this->tool($store)->execute(
            array('days_overdue_min' => 5),
            array('user_id' => 7)
        );

        $this->assertSame(array(10), array_column($result['data']['records'], 'id'));
    }

    public function test_a_fiscal_quarter_is_a_calendar_quarter(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'May', array(), '2026-05-01 09:00:00'),
            $this->contact(11, 'January', array(), '2026-01-15 09:00:00'),
            $this->contact(12, 'Last year', array(), '2025-12-31 09:00:00'),
        ));

        $result = $this->tool($store)->execute(
            array('fiscal_year' => 2026, 'fiscal_quarter' => 'Q2'),
            array('user_id' => 7)
        );

        $this->assertSame(array(10), array_column($result['data']['records'], 'id'));
        $this->assertSame(1, $result['data']['total']);
    }

    public function test_the_currency_filter_uses_the_stored_or_default_code(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Dollars', array('invoice_currency' => 'USD')),
            $this->contact(11, 'Euros', array('invoice_currency' => 'EUR')),
            $this->contact(12, 'Default', array()),
        ));

        $euros = $this->tool($store)->execute(
            array('currency_code' => 'EUR'),
            array('user_id' => 7)
        );

        $this->assertSame(array(11), array_column($euros['data']['records'], 'id'));

        $default = $this->tool($store)->execute(
            array('currency_code' => 'USD'),
            array('user_id' => 7)
        );

        $this->assertSame(array(10, 12), array_column($default['data']['records'], 'id'));
    }

    public function test_the_email_domain_filter_matches_after_the_at_sign(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Acme', array('email' => 'ap@acme.test')),
            $this->contact(11, 'Other', array('email' => 'ap@other.test')),
        ));

        $result = $this->tool($store)->execute(
            array('email_domain' => 'acme.test'),
            array('user_id' => 7)
        );

        $this->assertSame(array(10), array_column($result['data']['records'], 'id'));
    }

    public function test_relevance_ranks_after_the_query(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Beta Corp', array('email' => 'ap@beta.test')),
            $this->contact(11, 'Acme Overdue', array('email' => 'ap@acme.test')),
        ));

        $result = $this->tool($store)->execute(
            array('orderby' => 'relevance', 'search' => 'Acme'),
            array('user_id' => 7)
        );

        $this->assertSame(array(11, 10), array_column($result['data']['records'], 'id'));
        $this->assertGreaterThan(0, $result['data']['records'][0]['relevance_score']);
    }

    public function test_the_summary_totals_the_returned_page(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Overdue', array('billing_status' => 'overdue', 'invoice_amount' => 1200)),
            $this->contact(11, 'Paid', array('billing_status' => 'paid', 'invoice_amount' => 300)),
            $this->contact(12, 'Pending', array('billing_status' => 'pending', 'invoice_amount' => 50)),
        ));

        $summary = $this->tool($store)->execute(array(), array('user_id' => 7))['data']['summary'];

        $this->assertSame(3, $summary['total_records']);
        $this->assertSame(1550.0, $summary['total_amount']);
        $this->assertSame(1, $summary['overdue_count']);
        $this->assertSame(1200.0, $summary['overdue_amount']);
        $this->assertSame(300.0, $summary['paid_amount']);
        $this->assertSame(50.0, $summary['pending_amount']);
    }

    public function test_the_audit_meta_uses_seven_years_for_closed_records(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Paid', array('billing_status' => 'paid')),
            $this->contact(11, 'Pending', array('billing_status' => 'pending', 'audit_trail' => 'yes')),
        ));

        $records = $this->recordsById($this->tool($store)->execute(
            array('include_audit_meta' => true),
            array('user_id' => 7)
        ));

        $this->assertSame(7, $records[10]['audit_meta']['data_retention_years']);
        $this->assertFalse($records[10]['audit_meta']['audit_trail_available']);

        $this->assertSame(3, $records[11]['audit_meta']['data_retention_years']);
        $this->assertTrue($records[11]['audit_meta']['audit_trail_available']);
        $this->assertSame(
            array('No accounting integration detected'),
            $records[11]['audit_meta']['accounting_platform_hints']
        );
    }

    public function test_get_cached_is_a_not_found_error_on_a_miss(): void
    {
        $result = $this->tool($this->store())->execute(
            array('action' => 'get_cached'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
        $this->assertSame(array(), $result['error']['data']['records']);
        $this->assertSame(0, $result['error']['data']['total']);
    }

    public function test_the_host_service_actions_answer_with_a_typed_error(): void
    {
        $tool = $this->tool($this->store());

        foreach (array('schedule', 'unschedule') as $action) {
            $result = $tool->execute(array('action' => $action), array('user_id' => 7));

            $this->assertFalse($result['success']);
            $this->assertSame('schedule_unavailable', $result['error']['code']);
        }
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $result = $this->tool($this->store())->execute(
            array('action' => 'export'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function tool(ContentStoreInterface $store): CrmEmailSearchAccountingTool
    {
        return new class ($this->errors, $store, self::NOW) extends CrmEmailSearchAccountingTool {
            public function __construct($errors, $content, private int $fixedNow)
            {
                parent::__construct($errors, $content);
            }

            protected function now(): int
            {
                return $this->fixedNow;
            }
        };
    }

    /**
     * @param array<string, mixed> $result
     * @return array<int, array<string, mixed>>
     */
    private function recordsById(array $result): array
    {
        $indexed = array();

        foreach ($result['data']['records'] as $record) {
            $indexed[ $record['id'] ] = $record;
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function contact(int $id, string $title, array $meta, string $createdAt = '2026-01-01 09:00:00'): ContentItem
    {
        $when = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));

        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: 'mcp_crm_contacts',
            authorId: 1,
            createdAt: $when,
            updatedAt: $when,
            meta: $meta,
        );
    }

    /**
     * A store that evaluates relation groups plus BETWEEN / numeric compares,
     * and sorts on date or title.
     *
     * @param array<int, ContentItem> $items
     */
    private function store(array $items = array()): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items = array();

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
                $rows = array_values(array_filter(
                    $this->items,
                    function (ContentItem $item) use ($query): bool {
                        if (array() !== $query->types && ! in_array($item->type, $query->types, true)) {
                            return false;
                        }

                        if (array() !== $query->statuses && ! in_array($item->status, $query->statuses, true)) {
                            return false;
                        }

                        return $this->matches($item, $query->metaQuery);
                    }
                ));

                $desc = 'DESC' === strtoupper($query->order);

                usort(
                    $rows,
                    static function (ContentItem $a, ContentItem $b) use ($query, $desc): int {
                        $cmp = 'title' === $query->orderBy
                            ? strcmp($a->title, $b->title)
                            : $a->createdAt->getTimestamp() <=> $b->createdAt->getTimestamp();

                        return $desc ? -$cmp : $cmp;
                    }
                );

                $total = count($rows);
                $slice = array_slice($rows, ($query->page - 1) * $query->perPage, $query->perPage);

                return new ContentCollection(
                    $slice,
                    $total,
                    $query->page,
                    $query->perPage,
                    (int) ceil($total / max(1, $query->perPage))
                );
            }

            /**
             * @param array<string, mixed> $metaQuery
             */
            private function matches(ContentItem $item, array $metaQuery): bool
            {
                if (array() === $metaQuery) {
                    return true;
                }

                $relation = strtoupper((string) ( $metaQuery['relation'] ?? 'AND' ));
                $results  = array();

                foreach ($metaQuery as $name => $clause) {
                    if ('relation' === $name || ! is_array($clause)) {
                        continue;
                    }

                    $results[] = isset($clause['key'])
                        ? $this->clause($item, $clause)
                        : $this->matches($item, $clause);
                }

                if (array() === $results) {
                    return true;
                }

                return 'OR' === $relation
                    ? in_array(true, $results, true)
                    : ! in_array(false, $results, true);
            }

            /**
             * @param array<string, mixed> $clause
             */
            private function clause(ContentItem $item, array $clause): bool
            {
                $key     = (string) ( $clause['key'] ?? '' );
                $value   = $clause['value'] ?? null;
                $compare = strtoupper((string) ( $clause['compare'] ?? '=' ));
                $stored  = $item->meta[ $key ] ?? null;

                if ('BETWEEN' === $compare) {
                    $values = is_array($value) ? array_values($value) : array();

                    return 2 === count($values)
                        && (float) $stored >= (float) $values[0]
                        && (float) $stored <= (float) $values[1];
                }

                if (in_array($compare, array('>=', '<=', '>', '<'), true)) {
                    $left  = (float) $stored;
                    $right = (float) $value;

                    return match ($compare) {
                        '>=' => $left >= $right,
                        '<=' => $left <= $right,
                        '>'  => $left > $right,
                        '<'  => $left < $right,
                    };
                }

                return (string) $stored === (string) $value;
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
                return array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return true;
            }
        };
    }
}
