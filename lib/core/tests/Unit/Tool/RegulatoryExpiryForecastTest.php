<?php

/**
 * The eleventh regulatory-registration slice: the expiry forecast.
 *
 * Proposal 164 section 164.154. What is pinned: the monthly timeline is built
 * from month-aligned arithmetic so no month is skipped, every non-expired
 * registration inside the horizon appears in its month whatever its band,
 * records outside the horizon are counted rather than dropped, and the day
 * bands are unchanged from WordPress.
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
use Nvoos\Core\Tool\Regulatory\GenerateExpiryForecastTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryExpiryForecastTest extends TestCase
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

    public function test_the_four_bands_are_classified_by_day_count(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(-6)),
            $this->registration(2, $this->inDays(10)),
            $this->registration(3, $this->inDays(120)),
        ));

        $result = $this->forecast($store)->execute(array(), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['summary']['expired']);
        $this->assertSame(1, $result['data']['summary']['high_risk']);
        $this->assertSame(1, $result['data']['summary']['medium_risk']);
        $this->assertSame(0, $result['data']['summary']['low_risk']);
        $this->assertSame(3, $result['data']['summary']['total_expiring']);
    }

    public function test_a_registration_inside_the_horizon_is_low_risk(): void
    {
        $store = $this->store(array($this->registration(1, $this->inDays(200))));

        $result = $this->forecast($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(1, $result['data']['summary']['low_risk']);
        $this->assertSame(0, $result['data']['summary']['beyond_horizon']);
    }

    public function test_a_registration_beyond_the_horizon_is_counted_not_dropped(): void
    {
        $store = $this->store(array($this->registration(1, $this->inDays(800))));

        $result = $this->forecast($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(0, $result['data']['summary']['low_risk']);
        $this->assertSame(1, $result['data']['summary']['beyond_horizon']);
        $this->assertSame(0, $result['data']['summary']['total_expiring']);
        $this->assertSame(1, $result['data']['summary']['total_considered']);
    }

    public function test_the_timeline_has_one_bucket_per_month_and_no_gaps(): void
    {
        $result = $this->forecast($this->store())->execute(
            array('forecast_months' => 12),
            array('user_id' => 7)
        );

        $byMonth = $result['data']['expiry_data']['by_month'];

        $this->assertCount(13, $byMonth);
        $this->assertSame(gmdate('Y-m'), array_key_first($byMonth));

        $previous = null;

        foreach (array_keys($byMonth) as $key) {
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $key);

            $current = \DateTimeImmutable::createFromFormat('Y-m-d', $key . '-01', new \DateTimeZone('UTC'));

            $this->assertNotFalse($current);

            if (null !== $previous) {
                $this->assertSame(1, (int) $previous->diff($current)->format('%m'), 'months must be consecutive');
            }

            $previous = $current;
        }
    }

    public function test_the_horizon_is_the_last_day_of_the_final_month(): void
    {
        $result = $this->forecast($this->store())->execute(
            array('forecast_months' => 12),
            array('user_id' => 7)
        );

        $byMonth = array_keys($result['data']['expiry_data']['by_month']);
        $last    = end($byMonth);

        $expected = gmdate('Y-m-t', (int) strtotime($last . '-01'));

        $this->assertSame($expected, $result['data']['horizon']);
    }

    public function test_the_timeline_counts_every_band_inside_the_horizon(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(10)),
            $this->registration(2, $this->inDays(120)),
            $this->registration(3, $this->inDays(200)),
        ));

        $result = $this->forecast($store)->execute(array(), array('user_id' => 7));
        $byMonth = $result['data']['expiry_data']['by_month'];

        $this->assertSame(3, array_sum($byMonth));
        $this->assertSame(
            1,
            $byMonth[ gmdate('Y-m', (int) strtotime($this->inDays(200))) ],
            'the low-risk month carries exactly one registration'
        );
    }

    public function test_an_expired_registration_is_not_in_the_timeline(): void
    {
        $store = $this->store(array($this->registration(1, $this->inDays(-10))));

        $result = $this->forecast($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(0, array_sum($result['data']['expiry_data']['by_month']));
        $this->assertSame(1, $result['data']['summary']['expired']);
    }

    public function test_an_unreadable_date_is_expired_and_named(): void
    {
        $store = $this->store(array($this->registration(1, 'n/a')));

        $result = $this->forecast($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(1, $result['data']['summary']['expired']);
        $this->assertNull($result['data']['expiry_data']['expired'][0]['days_to_expiry']);
        $this->assertSame('n/a', $result['data']['unparsed_expiry_dates'][0]['expiry_date']);
    }

    public function test_by_country_counts_every_bucket(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(-6), 'LK'),
            $this->registration(2, $this->inDays(10), 'LK'),
            $this->registration(3, $this->inDays(800), 'AE'),
        ));

        $result = $this->forecast($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(array('LK' => 2, 'AE' => 1), $result['data']['expiry_data']['by_country']);
    }

    public function test_a_country_filter_narrows_every_bucket(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(-6), 'LK'),
            $this->registration(2, $this->inDays(-6), 'AE'),
        ));

        $result = $this->forecast($store)->execute(
            array('countries' => array('AE')),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['summary']['expired']);
        $this->assertSame(array('AE' => 1), $result['data']['expiry_data']['by_country']);
    }

    public function test_recommendations_name_the_bands_that_are_populated(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(-6)),
            $this->registration(2, $this->inDays(10)),
            $this->registration(3, $this->inDays(120)),
        ));

        $result = $this->forecast($store)->execute(array(), array('user_id' => 7));
        $notes  = $result['data']['recommendations'];

        $this->assertCount(3, $notes);
        $this->assertStringContainsString('Urgent', $notes[0]);
        $this->assertStringContainsString('High Priority', $notes[1]);
        $this->assertStringContainsString('180 days', $notes[2]);
    }

    public function test_bands_are_ordered_soonest_first(): void
    {
        $store = $this->store(array(
            $this->registration(1, $this->inDays(80)),
            $this->registration(2, $this->inDays(20)),
        ));

        $result = $this->forecast($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $result['data']['expiry_data']['high_risk'][0]['id']);
        $this->assertSame(1, $result['data']['expiry_data']['high_risk'][1]['id']);
    }

    public function test_the_forecast_bounds_are_enforced(): void
    {
        $store = $this->store();

        $months = $this->forecast($store)->execute(
            array('forecast_months' => 37),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $months['error']['code']);
        $this->assertArrayHasKey('forecast_months', $months['error']['data']);

        $threshold = $this->forecast($store)->execute(
            array('risk_threshold' => 29),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $threshold['error']['code']);
        $this->assertArrayHasKey('risk_threshold', $threshold['error']['data']);
    }

    public function test_the_forecast_capability_is_read(): void
    {
        $this->assertSame('read', $this->forecast($this->store())->getRequiredCapability());
    }

    // ─── Fixtures ───────────────────────────────────────────────────────

    private function forecast(ContentStoreInterface $store): GenerateExpiryForecastTool
    {
        return new GenerateExpiryForecastTool($this->errors, $store);
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
