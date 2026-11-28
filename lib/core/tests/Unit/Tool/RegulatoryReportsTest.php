<?php

/**
 * The thirteenth regulatory-registration slice: the reporting pair.
 *
 * Proposal 164 section 164.156. What is pinned: `format: pdf` is refused rather
 * than echoed, a custom range requires both dates, an unreadable expiry is
 * counted rather than guessed, a negative approval interval does not drag the
 * average down, and a fee that cannot be read is counted instead of silently
 * becoming zero.
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
use Nvoos\Core\Tool\Regulatory\GenerateComplianceReportTool;
use Nvoos\Core\Tool\Regulatory\GenerateCostAnalysisTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryReportsTest extends TestCase
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

    // ─── Compliance report ──────────────────────────────────────────────

    public function test_status_and_country_metrics(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(2, 'LK', array(), array('mcp_ai_reg_status' => array('Pending Documents'))),
            $this->registration(3, 'AE', array(), array('mcp_ai_reg_status' => array('Approved'))),
        ));

        $metrics = $this->compliance($store)->execute(array(), array('user_id' => 7))['data']['metrics'];

        $this->assertSame(3, $metrics['total_registrations']);
        $this->assertSame(array('Approved' => 2, 'Pending Documents' => 1), $metrics['by_status']);
        $this->assertSame(array('LK' => 2, 'AE' => 1), $metrics['by_country']);
        $this->assertSame(2, $metrics['active']);
        $this->assertSame(0, $metrics['pending']);
        $this->assertSame(66.67, $metrics['approval_rate']);
    }

    public function test_a_hyphenated_status_name_is_normalised_before_matching(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(), array('mcp_ai_reg_status' => array('Under-Review'))),
        ));

        $metrics = $this->compliance($store)->execute(array(), array('user_id' => 7))['data']['metrics'];

        $this->assertSame(1, $metrics['pending']);
        $this->assertSame(0, $metrics['active']);
    }

    public function test_expiry_buckets_and_an_unreadable_date(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('expiry_date' => $this->inDays(-5))),
            $this->registration(2, 'LK', array('expiry_date' => $this->inDays(30))),
            $this->registration(3, 'LK', array('expiry_date' => $this->inDays(400))),
            $this->registration(4, 'LK', array('expiry_date' => 'n/a')),
        ));

        $metrics = $this->compliance($store)->execute(array(), array('user_id' => 7))['data']['metrics'];

        $this->assertSame(1, $metrics['expired']);
        $this->assertSame(1, $metrics['expiring_soon']);
        $this->assertSame(1, $metrics['unreadable_expiry']);
    }

    public function test_the_approval_average_ignores_a_reversed_pair(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                'submission_date' => '2026-01-01',
                'approval_date'   => '2026-01-31',
            )),
            $this->registration(2, 'LK', array(
                'submission_date' => '2026-02-10',
                'approval_date'   => '2026-02-01',
            )),
        ));

        $metrics = $this->compliance($store)->execute(array(), array('user_id' => 7))['data']['metrics'];

        $this->assertSame(30.0, $metrics['average_approval_days']);
        $this->assertSame(1, $metrics['approval_intervals_ignored']);
    }

    public function test_a_pdf_format_is_refused_naming_the_alternative(): void
    {
        $result = $this->compliance($this->store())->execute(
            array('format' => 'pdf'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('generate_pdf', $result['error']['data']['format'][0]);
    }

    public function test_a_custom_range_needs_both_dates(): void
    {
        $result = $this->compliance($this->store())->execute(
            array('date_range' => 'custom', 'start_date' => '2026-01-01'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('end_date', $result['error']['data']);
    }

    public function test_a_custom_range_filters_on_the_creation_date(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(), array(), $this->inDays(-200)),
            $this->registration(2, 'LK', array(), array(), $this->inDays(-10)),
        ));

        $result = $this->compliance($store)->execute(
            array(
                'date_range' => 'custom',
                'start_date' => $this->inDays(-30),
                'end_date'   => $this->inDays(0),
            ),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['metrics']['total_registrations']);
        $this->assertSame($this->inDays(-30), $result['data']['window']['start']);
    }

    public function test_a_country_filter_narrows_the_estate(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK'),
            $this->registration(2, 'AE'),
        ));

        $result = $this->compliance($store)->execute(
            array('countries' => array('AE')),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['metrics']['total_registrations']);
    }

    public function test_the_compliance_report_capability_is_read(): void
    {
        $this->assertSame('read', $this->compliance($this->store())->getRequiredCapability());
    }

    // ─── Cost analysis ──────────────────────────────────────────────────

    public function test_fees_are_summed_and_grouped_by_country(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                '_registration_fee' => '100',
                '_renewal_fee'      => '20',
                '_additional_fees'  => '5',
            )),
            $this->registration(2, 'LK', array('_registration_fee' => '50')),
            $this->registration(3, 'AE', array('_registration_fee' => '25')),
        ));

        $result = $this->costs($store)->execute(array('grouping' => 'country'), array('user_id' => 7));

        $this->assertSame(3, $result['data']['summary']['total_registrations']);
        $this->assertSame(200.0, $result['data']['summary']['total_costs']);
        $this->assertSame(66.67, $result['data']['summary']['avg_cost_per_registration']);
        $this->assertSame(175.0, $result['data']['cost_breakdown']['LK']['total']);
        $this->assertSame(2, $result['data']['cost_breakdown']['LK']['count']);
        $this->assertSame(25.0, $result['data']['cost_breakdown']['AE']['total']);
    }

    public function test_an_unreadable_fee_is_counted_not_zeroed_silently(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('_registration_fee' => '100', '_renewal_fee' => 'TBD')),
        ));

        $result = $this->costs($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(100.0, $result['data']['summary']['total_costs']);
        $this->assertSame(1, $result['data']['summary']['unparsed_fees']);
        $this->assertSame('_renewal_fee', $result['data']['unparsed_fee_fields'][0]['field']);
        $this->assertSame('TBD', $result['data']['unparsed_fee_fields'][0]['value']);
    }

    public function test_the_product_grouping_resolves_the_title(): void
    {
        $store = $this->store(array(
            $this->product(9, 'Rose Cream'),
            $this->registration(1, 'LK', array('product_id' => 9, '_registration_fee' => '10')),
            $this->registration(2, 'LK', array('product_id' => 404, '_registration_fee' => '5')),
        ));

        $result = $this->costs($store)->execute(array('grouping' => 'product'), array('user_id' => 7));

        $this->assertSame(10.0, $result['data']['cost_breakdown']['Rose Cream']['total']);
        $this->assertSame(5.0, $result['data']['cost_breakdown']['Unknown']['total']);
    }

    public function test_the_type_and_month_groupings(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('registration_type' => 'cosmetic', '_registration_fee' => '10'), array(), $this->inDays(-40)),
        ));

        $byType = $this->costs($store)->execute(array('grouping' => 'type'), array('user_id' => 7));
        $this->assertSame(10.0, $byType['data']['cost_breakdown']['cosmetic']['total']);

        $byMonth = $this->costs($store)->execute(array('grouping' => 'month'), array('user_id' => 7));
        $this->assertSame(
            array(gmdate('Y-m', (int) strtotime($this->inDays(-40))) => 10.0),
            array_map(static fn (array $row): float => $row['total'], $byMonth['data']['cost_breakdown'])
        );
    }

    public function test_the_forecast_projects_renewal_fees_by_expiry_month(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('expiry_date' => $this->inDays(40), '_renewal_fee' => '15')),
            $this->registration(2, 'LK', array('expiry_date' => $this->inDays(50), '_renewal_fee' => '35')),
            $this->registration(3, 'LK', array('expiry_date' => $this->inDays(800), '_renewal_fee' => '99')),
            $this->registration(4, 'LK', array('expiry_date' => $this->inDays(-10), '_renewal_fee' => '77')),
        ));

        $result = $this->costs($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(50.0, $result['data']['forecast_total_next_12m']);

        $months = array_keys($result['data']['forecast']);
        $sorted = $months;
        sort($sorted);

        $this->assertSame($sorted, $months, 'forecast months are sorted');
        $this->assertSame(50.0, array_sum($result['data']['forecast']));
    }

    public function test_the_forecast_can_be_switched_off(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('expiry_date' => $this->inDays(40), '_renewal_fee' => '15')),
        ));

        $result = $this->costs($store)->execute(
            array('include_forecast' => false),
            array('user_id' => 7)
        );

        $this->assertSame(array(), $result['data']['forecast']);
        $this->assertSame(0.0, $result['data']['forecast_total_next_12m']);
    }

    public function test_the_default_range_is_the_last_year(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('_registration_fee' => '10'), array(), $this->inDays(-10)),
            $this->registration(2, 'LK', array('_registration_fee' => '10'), array(), $this->inDays(-800)),
        ));

        $result = $this->costs($store)->execute(array(), array('user_id' => 7));

        $this->assertSame('last_year', $result['data']['date_range']);
        $this->assertSame(1, $result['data']['summary']['total_registrations']);
    }

    public function test_a_cost_custom_range_needs_both_dates(): void
    {
        $result = $this->costs($this->store())->execute(
            array('date_range' => 'custom'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('start_date', $result['error']['data']);
    }

    public function test_an_unknown_grouping_is_refused(): void
    {
        $result = $this->costs($this->store())->execute(
            array('grouping' => 'colour'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('grouping', $result['error']['data']);
    }

    public function test_the_cost_capability_is_read(): void
    {
        $this->assertSame('read', $this->costs($this->store())->getRequiredCapability());
    }

    // ─── Fixtures ───────────────────────────────────────────────────────

    private function compliance(ContentStoreInterface $store): GenerateComplianceReportTool
    {
        return new GenerateComplianceReportTool($this->errors, $store);
    }

    private function costs(ContentStoreInterface $store): GenerateCostAnalysisTool
    {
        return new GenerateCostAnalysisTool($this->errors, $store);
    }

    /**
     * A date a given number of days from today.
     */
    private function inDays(int $days): string
    {
        return gmdate('Y-m-d', time() + ( $days * 86400 ));
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $taxonomy
     */
    private function registration(
        int $id,
        string $country,
        array $meta = array(),
        array $taxonomy = array(),
        ?string $createdOn = null
    ): ContentItem {
        return $this->item(
            $id,
            'Registration ' . $id,
            'mcp_ai_registration',
            array_merge(array('country' => $country), $meta),
            $taxonomy,
            $createdOn
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function product(int $id, string $title): ContentItem
    {
        return $this->item($id, $title, 'mcp_ai_reg_product', array());
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
        array $taxonomy = array(),
        ?string $createdOn = null
    ): ContentItem {
        $created = null !== $createdOn
            ? new \DateTimeImmutable($createdOn . 'T09:00:00+00:00')
            : new \DateTimeImmutable(gmdate('Y-m-d', time() - 86400) . 'T09:00:00+00:00');

        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: $created,
            updatedAt: $created,
            meta: $meta,
            taxonomy: $taxonomy,
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
