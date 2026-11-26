<?php

/**
 * The correspondence search: follow-up modes, the default-sort collision,
 * SLA filtering, analytics, and routing.
 *
 * The assertions that matter are the measured ones:
 *
 * - `needs_followup` includes never-contacted rows, but the default
 *   `last_contacted` sort's INNER JOIN removes them, so the default call sees
 *   only the stale half. `never_contacted` with the default sort returns zero.
 * - The channel filter reads the stored `channel` meta, so an absent channel
 *   does not match `email` even though the projection defaults to `email`.
 * - `sla_breach_only` does not treat a never-contacted record as a breach.
 * - Analytics and routing follow the WordPress rules, with malformed dates
 *   yielding null rather than a huge day count.
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
use Nvoos\Core\Tool\Crm\CrmEmailSearchCorrespondenceTool;
use PHPUnit\Framework\TestCase;

final class CrmEmailSearchCorrespondenceToolTest extends TestCase
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

        $this->assertSame('crm_email_search_correspondence', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(
            array('search', 'get_cached', 'clear_cache', 'schedule', 'unschedule'),
            $schema['properties']['action']['enum']
        );
        $this->assertSame('needs_followup', $schema['properties']['correspondence_type']['default']);
        $this->assertSame('all', $schema['properties']['category']['default']);
        $this->assertSame('all', $schema['properties']['channel']['default']);
        $this->assertSame(7, $schema['properties']['days_since_contact']['default']);
        $this->assertTrue($schema['properties']['include_analytics']['default']);
        $this->assertSame('last_contacted', $schema['properties']['orderby']['default']);
        $this->assertSame('ASC', $schema['properties']['order']['default']);
    }

    public function test_the_default_mode_and_default_sort_hide_the_never_contacted_cohort(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Stale', array('last_contacted' => '2026-05-01 00:00:00')),
            $this->contact(11, 'Recent', array('last_contacted' => '2026-05-31 00:00:00')),
            $this->contact(12, 'Never', array()),
            $this->contact(13, 'Very stale', array('last_contacted' => '2026-04-01 00:00:00')),
        ));

        $result = $this->tool($store)->execute(array(), array('user_id' => 7));

        // needs_followup matches 10, 12 and 13; the meta sort drops 12.
        $this->assertSame(array(13, 10), array_column($result['data']['contacts'], 'id'));
    }

    public function test_never_contacted_with_the_default_sort_returns_nothing(): void
    {
        $store = $this->store(array(
            $this->contact(12, 'Never', array()),
        ));

        $result = $this->tool($store)->execute(
            array('correspondence_type' => 'never_contacted'),
            array('user_id' => 7)
        );

        $this->assertSame(0, $result['data']['total']);
    }

    public function test_never_contacted_with_a_date_sort_returns_the_cohort(): void
    {
        $store = $this->store(array(
            $this->contact(12, 'Never', array()),
            $this->contact(10, 'Stale', array('last_contacted' => '2026-05-01 00:00:00')),
        ));

        $result = $this->tool($store)->execute(
            array('correspondence_type' => 'never_contacted', 'orderby' => 'date'),
            array('user_id' => 7)
        );

        $this->assertSame(array(12), array_column($result['data']['contacts'], 'id'));
    }

    public function test_recently_contacted_and_overdue_modes(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Overdue stale', array('last_contacted' => '2026-05-01 00:00:00', 'follow_up_date' => '2026-05-20 00:00:00')),
            $this->contact(11, 'Recent', array('last_contacted' => '2026-05-31 00:00:00')),
            $this->contact(13, 'Stale no follow-up', array('last_contacted' => '2026-04-01 00:00:00')),
        ));

        $tool = $this->tool($store);

        $recent = $tool->execute(
            array('correspondence_type' => 'recently_contacted', 'orderby' => 'date'),
            array('user_id' => 7)
        );
        $this->assertSame(array(11), array_column($recent['data']['contacts'], 'id'));

        $overdue = $tool->execute(
            array('correspondence_type' => 'overdue', 'orderby' => 'date'),
            array('user_id' => 7)
        );
        $this->assertSame(array(10), array_column($overdue['data']['contacts'], 'id'));
    }

    public function test_category_channel_and_status_filters_use_the_stored_meta(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Support', array('category' => 'support', 'correspondence_category' => 'support', 'channel' => 'email', 'contact_status' => 'active')),
            $this->contact(11, 'Sales phone', array('correspondence_category' => 'sales', 'channel' => 'phone', 'contact_status' => 'active')),
            $this->contact(12, 'No channel', array('correspondence_category' => 'support', 'contact_status' => 'active')),
        ));

        $tool = $this->tool($store);

        $support = $tool->execute(
            array('correspondence_type' => 'all', 'orderby' => 'date', 'category' => 'support'),
            array('user_id' => 7)
        );
        $this->assertSame(array(10, 12), array_column($support['data']['contacts'], 'id'));

        // The stored meta decides: an absent channel does not match "email",
        // even though the projection defaults it to email.
        $email = $tool->execute(
            array('correspondence_type' => 'all', 'orderby' => 'date', 'channel' => 'email'),
            array('user_id' => 7)
        );
        $this->assertSame(array(10), array_column($email['data']['contacts'], 'id'));

        $status = $tool->execute(
            array('correspondence_type' => 'all', 'orderby' => 'date', 'contact_status' => 'active'),
            array('user_id' => 7)
        );
        $this->assertCount(3, $status['data']['contacts']);
    }

    public function test_the_tags_filter_requires_all_tags(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Both', array('tags' => array('vip', 'premium'))),
            $this->contact(11, 'One', array('tags' => array('vip'))),
        ));

        $result = $this->tool($store)->execute(
            array('correspondence_type' => 'all', 'orderby' => 'date', 'tags' => array('vip', 'premium')),
            array('user_id' => 7)
        );

        $this->assertSame(array(10), array_column($result['data']['contacts'], 'id'));
    }

    public function test_sla_breach_only_does_not_treat_never_contacted_as_a_breach(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Breach', array('last_contacted' => '2026-05-01 00:00:00')),
            $this->contact(11, 'In SLA', array('last_contacted' => '2026-05-31 00:00:00')),
            $this->contact(12, 'Never', array()),
            $this->contact(13, 'Long breach', array('last_contacted' => '2026-04-01 00:00:00')),
        ));

        $result = $this->tool($store)->execute(
            array('correspondence_type' => 'all', 'orderby' => 'date', 'sla_breach_only' => true),
            array('user_id' => 7)
        );

        $this->assertSame(array(10, 12, 13), array_column($result['data']['contacts'], 'id'));
    }

    public function test_analytics_report_days_since_overdue_engagement_and_routing(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Support', array(
                'last_contacted' => '2026-05-01 00:00:00',
                'follow_up_date' => '2026-05-20 00:00:00',
                'contact_count'  => 5,
                'correspondence_category' => 'support',
            )),
        ));

        $records = $this->byId($this->tool($store)->execute(
            array('correspondence_type' => 'all', 'orderby' => 'date'),
            array('user_id' => 7)
        ));

        $analytics = $records[10]['analytics'];

        $this->assertSame(31, $analytics['days_since_last_contact']);
        $this->assertTrue($analytics['is_overdue']);
        $this->assertSame(12, $analytics['days_overdue']);
        $this->assertSame('moderate', $analytics['engagement_level']);
        $this->assertSame('Route to Customer Support queue', $analytics['routing_suggestion']);
    }

    public function test_a_malformed_contact_date_yields_null_analytics(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Bad date', array('last_contacted' => 'not-a-date', 'follow_up_date' => 'also-bad')),
        ));

        $records = $this->byId($this->tool($store)->execute(
            array('correspondence_type' => 'all', 'orderby' => 'date'),
            array('user_id' => 7)
        ));

        $this->assertNull($records[10]['analytics']['days_since_last_contact']);
        $this->assertFalse($records[10]['analytics']['is_overdue']);
    }

    public function test_relevance_ranks_after_the_query(): void
    {
        $store = $this->store(array(
            $this->contact(10, 'Acme Co', array('last_contacted' => '2026-05-01 00:00:00', 'company' => 'Acme', 'email' => 'ap@acme.test')),
            $this->contact(11, 'Acme Partner', array('last_contacted' => '2026-05-01 00:00:00', 'company' => 'Beta', 'email' => 'ap@acme.test')),
        ));

        $result = $this->tool($store)->execute(
            array('correspondence_type' => 'all', 'orderby' => 'relevance', 'search' => 'Acme'),
            array('user_id' => 7)
        );

        $this->assertSame(array(10, 11), array_column($result['data']['contacts'], 'id'));
        $this->assertGreaterThan(0, $result['data']['contacts'][0]['relevance_score']);
    }

    public function test_get_cached_is_a_not_found_error_on_a_miss(): void
    {
        $result = $this->tool($this->store())->execute(
            array('action' => 'get_cached'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
        $this->assertSame(array(), $result['error']['data']['contacts']);
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
            array('action' => 'purge'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function tool(ContentStoreInterface $store): CrmEmailSearchCorrespondenceTool
    {
        return new class ($this->errors, $store, self::NOW) extends CrmEmailSearchCorrespondenceTool {
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
    private function byId(array $result): array
    {
        $indexed = array();

        foreach ($result['data']['contacts'] as $contact) {
            $indexed[ $contact['id'] ] = $contact;
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
     * A store that understands NOT EXISTS, DATETIME string compares, LIKE and
     * the keyword search, and sorts on date or title.
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

                        if (null !== $query->search && '' !== $query->search) {
                            $needle = strtolower($query->search);

                            if (
                                ! str_contains(strtolower($item->title), $needle)
                                && ! str_contains(strtolower($item->content), $needle)
                                && ! str_contains(strtolower((string) ( $item->excerpt ?? '' )), $needle)
                            ) {
                                return false;
                            }
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
                $type    = strtoupper((string) ( $clause['type'] ?? '' ));
                $exists  = array_key_exists($key, $item->meta) && null !== $item->meta[ $key ];
                $stored  = $item->meta[ $key ] ?? null;

                if ('NOT EXISTS' === $compare) {
                    return ! $exists;
                }

                if ('LIKE' === $compare) {
                    return $exists && false !== stripos((string) $stored, (string) $value);
                }

                // A missing key matches no comparison, as a NULL JSON path does
                // not satisfy one in the adapter.
                if (! $exists) {
                    return false;
                }

                if ('DATETIME' === $type && in_array($compare, array('<', '>=', '>', '<='), true)) {
                    $left  = (string) $stored;
                    $right = (string) $value;

                    return match ($compare) {
                        '<'  => $left < $right,
                        '>=' => $left >= $right,
                        '>'  => $left > $right,
                        '<=' => $left <= $right,
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
