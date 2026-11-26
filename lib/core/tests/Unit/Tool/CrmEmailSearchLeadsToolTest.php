<?php

/**
 * The email-driven lead search: filters, sorts, relevance, and cache actions.
 *
 * The assertions that matter are the measured behaviours:
 *
 * - `lead_status` defaults to `new`, so a contact without that key is not in a
 *   default search. Passing `all` is the documented way to see it.
 * - A meta sort excludes rows without the key, as WordPress' `meta_key` INNER
 *   JOIN does — a missing score is not a zero.
 * - Relevance ranks after the query, keeps arrival order on ties, and adds
 *   `relevance_score`.
 * - The cache actions round-trip, and the host-service actions answer with a
 *   typed `*_unavailable` error rather than pretending.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Crm\CrmEmailSearchLeadsTool;
use PHPUnit\Framework\TestCase;

final class CrmEmailSearchLeadsToolTest extends TestCase
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

        $this->assertSame('crm_email_search_leads', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('action'), $schema['required']);
        $this->assertSame(
            array('search', 'get_cached', 'clear_cache', 'schedule', 'unschedule', 'import_from_gmail'),
            $schema['properties']['action']['enum']
        );
        $this->assertSame('new', $schema['properties']['lead_status']['default']);
        $this->assertSame('lead_score', $schema['properties']['orderby']['default']);
        $this->assertSame('DESC', $schema['properties']['order']['default']);
        $this->assertSame(20, $schema['properties']['per_page']['default']);
        $this->assertSame(3600, $schema['properties']['cache_ttl']['default']);
    }

    public function test_the_default_status_filters_on_a_key_the_contact_manager_never_writes(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Ada Lovelace', array('lead_status' => 'new', 'email' => 'ada@example.test')),
            $this->contact(11, 'Grace Hopper', array('email' => 'grace@example.test')),
        ));

        $result = $this->tool($store)->execute(array('orderby' => 'date'), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('new', $result['data']['filters']['lead_status']);
        $this->assertSame(array(10), array_column($result['data']['leads'], 'id'));
    }

    public function test_all_returns_contacts_without_a_status(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Ada Lovelace', array('lead_status' => 'new', 'email' => 'ada@example.test')),
            $this->contact(11, 'Grace Hopper', array('email' => 'grace@example.test')),
        ));

        $result = $this->tool($store)->execute(
            array('lead_status' => 'all', 'orderby' => 'date'),
            array('user_id' => 7)
        );

        $this->assertSame(2, $result['data']['total']);
    }

    public function test_the_declared_filters_are_anded(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Ada', array(
                'lead_status'  => 'qualified',
                'inquiry_type' => 'demo_request',
                'source'       => 'web_form',
                'priority'     => 'high',
                'mql_stage'    => 'sql',
                'contact_owner' => 'rep@example.test',
                'email'        => 'ada@example.test',
            )),
            $this->contact(11, 'Grace', array(
                'lead_status'  => 'qualified',
                'inquiry_type' => 'demo_request',
                'source'       => 'web_form',
                'priority'     => 'low',
                'mql_stage'    => 'sql',
                'contact_owner' => 'rep@example.test',
                'email'        => 'grace@example.test',
            )),
        ));

        $result = $this->tool($store)->execute(
            array(
                'lead_status'   => 'qualified',
                'inquiry_type'  => 'demo_request',
                'source'        => 'web_form',
                'priority'      => 'high',
                'mql_stage'     => 'sql',
                'contact_owner' => 'rep@example.test',
                'orderby'       => 'date',
            ),
            array('user_id' => 7)
        );

        $this->assertSame(array(10), array_column($result['data']['leads'], 'id'));
    }

    public function test_the_lead_score_range_is_a_numeric_between(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Cold', array('lead_status' => 'new', 'lead_score' => 20)),
            $this->contact(11, 'Warm', array('lead_status' => 'new', 'lead_score' => 55)),
            $this->contact(12, 'Hot', array('lead_status' => 'new', 'lead_score' => 85)),
        ));

        $result = $this->tool($store)->execute(
            array('lead_status' => 'all', 'lead_score_min' => 40, 'lead_score_max' => 80),
            array('user_id' => 7)
        );

        $this->assertSame(array(11), array_column($result['data']['leads'], 'id'));
    }

    public function test_a_meta_sort_excludes_rows_without_the_key(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Cold', array('lead_status' => 'new', 'lead_score' => 20)),
            $this->contact(11, 'Hot', array('lead_status' => 'new', 'lead_score' => 90)),
            $this->contact(12, 'Unscored', array('lead_status' => 'new')),
        ));

        $result = $this->tool($store)->execute(
            array('lead_status' => 'all', 'orderby' => 'lead_score', 'order' => 'DESC'),
            array('user_id' => 7)
        );

        $this->assertSame(array(11, 10), array_column($result['data']['leads'], 'id'));
        $this->assertSame(2, $result['data']['total']);
    }

    public function test_a_name_sort_uses_the_title(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Zoe', array('lead_status' => 'new')),
            $this->contact(11, 'Ada', array('lead_status' => 'new')),
        ));

        $result = $this->tool($store)->execute(
            array('lead_status' => 'all', 'orderby' => 'name', 'order' => 'ASC'),
            array('user_id' => 7)
        );

        $this->assertSame(array(11, 10), array_column($result['data']['leads'], 'id'));
    }

    public function test_relevance_ranks_after_the_query_and_adds_a_score(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Grace Hopper', array('lead_status' => 'new', 'email' => 'grace@example.test')),
            $this->contact(11, 'Ada Lovelace', array('lead_status' => 'new', 'email' => 'ada@example.test')),
        ));

        $result = $this->tool($store)->execute(
            array('lead_status' => 'all', 'orderby' => 'relevance', 'search' => 'Ada'),
            array('user_id' => 7)
        );

        $this->assertSame(array(11, 10), array_column($result['data']['leads'], 'id'));
        $this->assertGreaterThan(0, $result['data']['leads'][0]['relevance_score']);
        $this->assertSame(0.0, (float) $result['data']['leads'][1]['relevance_score']);
    }

    public function test_the_email_domain_filter_matches_after_the_at_sign(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Ada', array('lead_status' => 'new', 'email' => 'ada@example.test')),
            $this->contact(11, 'Grace', array('lead_status' => 'new', 'email' => 'grace@other.test')),
        ));

        $result = $this->tool($store)->execute(
            array('lead_status' => 'all', 'email_domain' => 'example.test', 'orderby' => 'date'),
            array('user_id' => 7)
        );

        $this->assertSame(array(10), array_column($result['data']['leads'], 'id'));
    }

    public function test_the_date_range_filters_on_the_added_date(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Old', array('lead_status' => 'new'), '2026-01-01 09:00:00'),
            $this->contact(11, 'New', array('lead_status' => 'new'), '2026-03-01 09:00:00'),
        ));

        $result = $this->tool($store)->execute(
            array('lead_status' => 'all', 'date_from' => '2026-02-01', 'orderby' => 'date'),
            array('user_id' => 7)
        );

        $this->assertSame(array(11), array_column($result['data']['leads'], 'id'));
    }

    public function test_a_search_is_cached_and_a_second_call_serves_the_cache(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Ada', array('lead_status' => 'new')),
        ));
        $cache = new class () implements CacheStoreInterface {
            /** @var array<string, mixed> */
            public array $values = array();

            public function getValue(string $key, mixed $default = null): mixed
            {
                return $this->values[ $key ] ?? $default;
            }

            public function setValue(string $key, mixed $value, int $ttl = 3600): bool
            {
                $this->values[ $key ] = $value;

                return true;
            }

            public function deleteValue(string $key): bool
            {
                unset($this->values[ $key ]);

                return true;
            }

            public function increment(string $key, int $by = 1, int $ttl = 3600): int
            {
                $this->values[ $key ] = (int) ( $this->values[ $key ] ?? 0 ) + $by;

                return (int) $this->values[ $key ];
            }

            public function remember(string $key, int $ttl, callable $callback): mixed
            {
                if (! array_key_exists($key, $this->values)) {
                    $this->values[ $key ] = $callback();
                }

                return $this->values[ $key ];
            }
        };

        $tool = $this->tool($store, $cache);

        $first = $tool->execute(array('orderby' => 'date'), array('user_id' => 7));
        $this->assertTrue($first['data']['cached']);
        $this->assertArrayHasKey('cache_key', $first['data']);

        $second = $tool->execute(array('orderby' => 'date'), array('user_id' => 7));
        $this->assertTrue($second['data']['from_cache']);
        $this->assertSame(1, $second['data']['total']);
    }

    public function test_get_cached_falls_back_to_a_fresh_search(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Ada', array('lead_status' => 'new')),
        ));

        $result = $this->tool($store)->execute(
            array('action' => 'get_cached', 'orderby' => 'date'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['auto_fallback']);
        $this->assertSame(1, $result['data']['total']);
    }

    public function test_clear_cache_removes_the_key(): void
    {
        $store = $this->store();
        $cache = new class () implements CacheStoreInterface {
            /** @var array<string, mixed> */
            public array $values = array();

            public function getValue(string $key, mixed $default = null): mixed
            {
                return $this->values[ $key ] ?? $default;
            }

            public function setValue(string $key, mixed $value, int $ttl = 3600): bool
            {
                $this->values[ $key ] = $value;

                return true;
            }

            public function deleteValue(string $key): bool
            {
                unset($this->values[ $key ]);

                return true;
            }

            public function increment(string $key, int $by = 1, int $ttl = 3600): int
            {
                return $by;
            }

            public function remember(string $key, int $ttl, callable $callback): mixed
            {
                return $callback();
            }
        };

        $tool = $this->tool($store, $cache);

        $tool->execute(array('orderby' => 'date'), array('user_id' => 7));
        $this->assertNotSame(array(), $cache->values);

        $tool->execute(array('action' => 'clear_cache', 'orderby' => 'date'), array('user_id' => 7));
        $this->assertSame(array(), $cache->values);
    }

    public function test_the_host_service_actions_answer_with_a_typed_error(): void
    {
        $tool = $this->tool($this->store());

        foreach (array('schedule', 'unschedule') as $action) {
            $result = $tool->execute(array('action' => $action), array('user_id' => 7));

            $this->assertFalse($result['success']);
            $this->assertSame('schedule_unavailable', $result['error']['code']);
        }

        $import = $tool->execute(array('action' => 'import_from_gmail'), array('user_id' => 7));
        $this->assertSame('external_search_unavailable', $import['error']['code']);

        $external = $tool->execute(array('include_external' => true), array('user_id' => 7));
        $this->assertSame('external_search_unavailable', $external['error']['code']);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $result = $this->tool($this->store())->execute(
            array('action' => 'purge'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('action', $result['error']['data']);
    }

    public function test_pagination_slices_the_matched_set(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'A', array('lead_status' => 'new'), '2026-01-04 09:00:00'),
            $this->contact(11, 'B', array('lead_status' => 'new'), '2026-01-03 09:00:00'),
            $this->contact(12, 'C', array('lead_status' => 'new'), '2026-01-02 09:00:00'),
        ));

        $result = $this->tool($store)->execute(
            array('lead_status' => 'all', 'per_page' => 2, 'page' => 2, 'orderby' => 'date', 'order' => 'DESC'),
            array('user_id' => 7)
        );

        $this->assertSame(3, $result['data']['total']);
        $this->assertSame(2, $result['data']['pages']);
        $this->assertSame(array(12), array_column($result['data']['leads'], 'id'));
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function tool(
        ContentStoreInterface $store,
        ?CacheStoreInterface $cache = null,
    ): CrmEmailSearchLeadsTool {
        return new CrmEmailSearchLeadsTool($this->errors, $store, null, $cache);
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
     * A store that evaluates relation groups and the compare set the tool uses,
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
