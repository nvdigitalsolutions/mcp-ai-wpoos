<?php

/**
 * The fourteenth regulatory-registration slice: country and pipeline reports.
 *
 * Proposal 164 section 164.157. What is pinned: the country comparison names a
 * country for each of its three facts instead of returning null, a country with
 * no measured interval cannot win fastest, an unmeasured average is null rather
 * than a division by zero, the bottleneck list does not reorder the averages it
 * was built from, and the trend buckets are six consecutive months.
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
use Nvoos\Core\Tool\Regulatory\GenerateCountryPerformanceTool;
use Nvoos\Core\Tool\Regulatory\GeneratePipelineReportTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryAnalyticsReportsTest extends TestCase
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

    // ─── Country performance ────────────────────────────────────────────

    public function test_each_country_is_measured(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('authority' => 'NMRA'), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(2, 'LK', array('authority' => 'NMRA'), array('mcp_ai_reg_status' => array('Pending'))),
            $this->registration(3, 'AE', array('authority' => 'MOHAP'), array('mcp_ai_reg_status' => array('Approved'))),
        ));

        $result = $this->countries($store)->execute(array(), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['total_countries']);
        $this->assertSame(2, $result['data']['metrics']['LK']['total_registrations']);
        $this->assertSame(1, $result['data']['metrics']['LK']['active']);
        $this->assertSame(1, $result['data']['metrics']['LK']['pending']);
        $this->assertSame(50.0, $result['data']['metrics']['LK']['approval_rate']);
        $this->assertSame('NMRA', $result['data']['metrics']['LK']['authority']);
    }

    public function test_a_country_with_two_authorities_lists_both(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('authority' => 'NMRA')),
            $this->registration(2, 'LK', array('authority' => 'MOH')),
        ));

        $row = $this->countries($store)->execute(array(), array('user_id' => 7))['data']['metrics']['LK'];

        $this->assertSame('NMRA', $row['authority']);
        $this->assertSame(array('NMRA', 'MOH'), $row['authorities']);
    }

    public function test_an_empty_authority_on_the_first_row_does_not_win(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('authority' => '')),
            $this->registration(2, 'LK', array('authority' => 'NMRA')),
        ));

        $row = $this->countries($store)->execute(array(), array('user_id' => 7))['data']['metrics']['LK'];

        $this->assertSame('NMRA', $row['authority']);
    }

    public function test_the_comparison_names_a_country_for_each_fact(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                'submission_date' => '2026-01-01',
                'approval_date'   => '2026-01-11',
            ), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(2, 'AE', array(
                'submission_date' => '2026-01-01',
                'approval_date'   => '2026-02-10',
            ), array('mcp_ai_reg_status' => array('Pending'))),
            $this->registration(3, 'AE'),
        ));

        $comparison = $this->countries($store)->execute(array(), array('user_id' => 7))['data']['comparison'];

        $this->assertSame('AE', $comparison['highest_volume']);
        $this->assertSame('LK', $comparison['highest_approval_rate']);
        $this->assertSame('LK', $comparison['fastest_processing']);
        $this->assertSame(25.0, $comparison['avg_processing_days']);
        $this->assertSame(50.0, $comparison['avg_approval_rate']);
        $this->assertSame(2, $comparison['countries_compared']);
    }

    public function test_an_unmeasured_country_cannot_win_fastest(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                'submission_date' => '2026-01-01',
                'approval_date'   => '2026-01-11',
            )),
            $this->registration(2, 'AE'),
        ));

        $comparison = $this->countries($store)->execute(array(), array('user_id' => 7))['data']['comparison'];

        $this->assertSame('LK', $comparison['fastest_processing']);
    }

    public function test_no_measured_average_is_null_not_a_division_by_zero(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK'),
            $this->registration(2, 'AE'),
        ));

        $result = $this->countries($store)->execute(array(), array('user_id' => 7));

        $this->assertNull($result['data']['comparison']['fastest_processing']);
        $this->assertNull($result['data']['comparison']['avg_processing_days']);
        $this->assertSame(0.0, $result['data']['comparison']['avg_approval_rate']);
    }

    public function test_a_single_country_has_no_comparison(): void
    {
        $store = $this->store(array($this->registration(1, 'LK')));

        $result = $this->countries($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(array(), $result['data']['comparison']);
    }

    public function test_the_sort_is_descending_and_ties_break_on_country(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK'),
            $this->registration(2, 'AE'),
            $this->registration(3, 'AE'),
        ));

        $result = $this->countries($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(array('AE', 'LK'), array_keys($result['data']['metrics']));

        $byRate = $this->countries($store)->execute(array('sort_by' => 'approval_rate'), array('user_id' => 7));

        $this->assertSame('approval_rate', $byRate['data']['sorted_by']);
        $this->assertCount(2, $byRate['data']['metrics']);
    }

    public function test_an_unreadable_expiry_counts_as_expired_and_is_named(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('expiry_date' => 'n/a')),
            $this->registration(2, 'LK', array('expiry_date' => $this->inDays(-5))),
        ));

        $result = $this->countries($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $result['data']['metrics']['LK']['expired']);
        $this->assertSame(1, $result['data']['unreadable_expiry']);
    }

    public function test_the_compliance_score_keeps_its_weights(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array('expiry_date' => $this->inDays(-5)), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(2, 'LK', array('expiry_date' => $this->inDays(400)), array('mcp_ai_reg_status' => array('Approved'))),
        ));

        $row = $this->countries($store)->execute(array(), array('user_id' => 7))['data']['metrics']['LK'];

        // 100 * 0.6 approval + (1 - 0.5) * 40 expiry = 80.
        $this->assertSame(80.0, $row['compliance_score']);
    }

    public function test_an_unknown_sort_key_is_refused(): void
    {
        $result = $this->countries($this->store())->execute(
            array('sort_by' => 'volume'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('total', $result['error']['data']['sort_by'][0]);
    }

    public function test_the_country_report_capability_is_read(): void
    {
        $this->assertSame('read', $this->countries($this->store())->getRequiredCapability());
    }

    // ─── Pipeline report ────────────────────────────────────────────────

    public function test_registrations_are_grouped_by_status(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(2, 'LK', array(), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(3, 'LK', array(), array('mcp_ai_reg_status' => array('Pending'))),
            $this->registration(4, 'LK'),
        ));

        $pipeline = $this->pipeline($store)->execute(array(), array('user_id' => 7))['data']['pipeline'];

        $this->assertSame(2, $pipeline['Approved']['count']);
        $this->assertSame(1, $pipeline['Pending']['count']);
        $this->assertSame(1, $pipeline['Unknown']['count']);
        $this->assertSame(array(1, 2), $pipeline['Approved']['registrations']);
    }

    public function test_the_other_groupings(): void
    {
        $store = $this->store(array(
            $this->product(9, 'Rose Cream'),
            $this->registration(
                1,
                'LK',
                array('product_id' => 9),
                array('mcp_ai_reg_status' => array('Approved')),
                $this->inDays(-40)
            ),
        ));

        $byCountry = $this->pipeline($store)->execute(array('grouping' => 'country'), array('user_id' => 7));
        $this->assertSame(array('LK'), array_keys($byCountry['data']['pipeline']));

        $byProduct = $this->pipeline($store)->execute(array('grouping' => 'product'), array('user_id' => 7));
        $this->assertSame(array('Rose Cream'), array_keys($byProduct['data']['pipeline']));

        $byMonth = $this->pipeline($store)->execute(array('grouping' => 'month'), array('user_id' => 7));
        $this->assertSame(
            array(gmdate('Y-m', (int) strtotime($this->inDays(-40)))),
            array_keys($byMonth['data']['pipeline'])
        );
    }

    public function test_stage_times_average_since_submission(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                'submission_date' => '2026-01-01',
                'approval_date'   => '2026-01-11',
            ), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(2, 'LK', array(
                'submission_date' => '2026-01-01',
                'approval_date'   => '2026-01-31',
            ), array('mcp_ai_reg_status' => array('Approved'))),
        ));

        $result = $this->pipeline($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(array('Approved' => 20.0), $result['data']['average_stage_times']);
    }

    public function test_the_bottleneck_list_does_not_reorder_the_averages(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                'submission_date' => '2026-01-01',
                'approval_date'   => '2026-01-11',
            ), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(2, 'LK', array(
                'submission_date' => '2026-01-01',
                'approval_date'   => '2026-03-01',
            ), array('mcp_ai_reg_status' => array('Pending'))),
        ));

        $result = $this->pipeline($store)->execute(array(), array('user_id' => 7));

        // Averages stay keyed by stage, alphabetically, whatever the ranking is.
        $this->assertSame(array('Approved', 'Pending'), array_keys($result['data']['average_stage_times']));
        $this->assertSame('Pending', $result['data']['bottlenecks'][0]['stage']);
        $this->assertSame(59.0, $result['data']['bottlenecks'][0]['days']);
        $this->assertSame(1, $result['data']['bottlenecks'][0]['samples']);
    }

    public function test_a_negative_interval_is_excluded_and_counted(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                'submission_date' => '2026-02-10',
                'approval_date'   => '2026-02-01',
            ), array('mcp_ai_reg_status' => array('Approved'))),
        ));

        $result = $this->pipeline($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(array(), $result['data']['average_stage_times']);
        $this->assertSame(1, $result['data']['negative_intervals_ignored']);
    }

    public function test_an_unreadable_date_is_counted(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                'submission_date' => 'n/a',
                'approval_date'   => '2026-01-11',
            ), array('mcp_ai_reg_status' => array('Approved'))),
        ));

        $result = $this->pipeline($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(1, $result['data']['unreadable_dates']);
        $this->assertSame(array(), $result['data']['average_stage_times']);
    }

    public function test_the_trend_is_six_consecutive_months(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(), array(), $this->inDays(-10)),
        ));

        $trends = $this->pipeline($store)->execute(array(), array('user_id' => 7))['data']['trends'];

        $this->assertCount(6, $trends);
        $this->assertSame(gmdate('Y-m'), array_key_last($trends));
        $this->assertSame(1, array_sum($trends));

        $previous = null;

        foreach (array_keys($trends) as $key) {
            $current = \DateTimeImmutable::createFromFormat('Y-m-d', $key . '-01', new \DateTimeZone('UTC'));

            $this->assertNotFalse($current);

            if (null !== $previous) {
                $this->assertSame(1, (int) $previous->diff($current)->m, 'months must be consecutive');
            }

            $previous = $current;
        }
    }

    public function test_the_trend_and_bottlenecks_can_be_switched_off(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'LK', array(
                'submission_date' => '2026-01-01',
                'approval_date'   => '2026-01-11',
            ), array('mcp_ai_reg_status' => array('Approved'))),
        ));

        $result = $this->pipeline($store)->execute(
            array('include_trends' => false, 'include_bottlenecks' => false),
            array('user_id' => 7)
        );

        $this->assertSame(array(), $result['data']['trends']);
        $this->assertSame(array(), $result['data']['bottlenecks']);
        $this->assertSame(array('Approved' => 10.0), $result['data']['average_stage_times']);
    }

    public function test_an_unknown_grouping_is_refused(): void
    {
        $result = $this->pipeline($this->store())->execute(
            array('grouping' => 'authority'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('grouping', $result['error']['data']);
    }

    public function test_the_pipeline_capability_is_read(): void
    {
        $this->assertSame('read', $this->pipeline($this->store())->getRequiredCapability());
    }

    // ─── Fixtures ───────────────────────────────────────────────────────

    private function countries(ContentStoreInterface $store): GenerateCountryPerformanceTool
    {
        return new GenerateCountryPerformanceTool($this->errors, $store);
    }

    private function pipeline(ContentStoreInterface $store): GeneratePipelineReportTool
    {
        return new GeneratePipelineReportTool($this->errors, $store);
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
     * A store that answers type and IN/equality meta clauses.
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
