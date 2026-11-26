<?php

/**
 * The hygiene pair: stale archiving and engagement scoring.
 *
 * Both tools are time-dependent, so the tests inject a fixed clock through the
 * `now()` seam and assert exact arithmetic rather than ranges.
 *
 * The assertions that matter are the measured behaviours:
 *
 * - Archiving only considers contacts **created** before the cutoff, then
 *   treats a contact as stale when its newest activity is absent or older.
 * - "Activity" includes rows linked the way `create_crm_activity` links them,
 *   not only the dead `_activity_contact_id` family.
 * - The score weights are the WordPress ones, and the completion component
 *   accepts the live `completed` stamp because nothing writes
 *   `_activity_outcome`.
 * - `score_model` is a label: `custom` scores identically.
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
use Nvoos\Core\Tool\Crm\ArchiveStaleContactsTool;
use Nvoos\Core\Tool\Crm\RecalculateEngagementScoresTool;
use PHPUnit\Framework\TestCase;

final class CrmHygieneToolsTest extends TestCase
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

    // ─── archive_stale_contacts ──────────────────────────────────────────

    public function test_the_archive_tool_keeps_the_wordpress_surface(): void
    {
        $tool = $this->archive($this->store());

        $this->assertSame('archive_stale_contacts', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array(), $schema['required']);
        $this->assertSame(365, $schema['properties']['days_inactive']['default']);
        $this->assertSame(30, $schema['properties']['days_inactive']['minimum']);
        $this->assertSame(3650, $schema['properties']['days_inactive']['maximum']);
        $this->assertSame('all', $schema['properties']['contact_type']['default']);
        $this->assertTrue($schema['properties']['dry_run']['default']);
    }

    public function test_a_dry_run_reports_a_never_active_contact_without_writing(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
        ));

        $result = $this->archive($store)->execute(array(), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame('2025-06-01 00:00:00', $result['data']['cutoff_date']);
        $this->assertSame(1, $result['data']['archived_count']);
        $this->assertSame(0, $result['data']['skipped_count']);

        $row = $result['data']['contacts']['archived'][0];

        $this->assertSame(100, $row['id']);
        $this->assertSame('mcp_ai_lead', $row['type']);
        $this->assertSame('Never', $row['last_activity']);
        $this->assertArrayNotHasKey('new_status', $row);

        // Nothing was written.
        $this->assertSame('publish', $store->items[100]->status);
        $this->assertArrayNotHasKey('_archived_date', $store->items[100]->meta);
    }

    public function test_a_legacy_linked_activity_makes_a_contact_recent(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->activity(200, 'Recent call', '2026-05-20 09:00:00', array('_activity_contact_id' => 100)),
        ));

        $result = $this->archive($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(0, $result['data']['archived_count']);
        $this->assertSame(1, $result['data']['skipped_count']);
        $this->assertSame('Has recent activity.', $result['data']['contacts']['skipped'][0]['reason']);
        $this->assertSame('2026-05-20 09:00:00', $result['data']['contacts']['skipped'][0]['last_activity']);
    }

    public function test_a_live_linked_activity_makes_a_contact_recent(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->activity(200, 'Recent call', '2026-05-20 09:00:00', array(
                'activity_type' => 'call',
                'related_type'  => 'lead',
                'related_id'    => 100,
            )),
        ));

        $result = $this->archive($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(0, $result['data']['archived_count']);
        $this->assertSame(1, $result['data']['skipped_count']);
    }

    public function test_a_live_run_drafts_the_contact_and_stamps_the_archive_meta(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
        ));

        $result = $this->archive($store)->execute(
            array('dry_run' => false),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['dry_run']);
        $this->assertSame('draft', $result['data']['contacts']['archived'][0]['new_status']);

        $item = $store->items[100];

        $this->assertSame('draft', $item->status);
        $this->assertSame('2026-06-01 00:00:00', $item->meta['_archived_date']);
        $this->assertSame('Stale contact (no recent activity).', $item->meta['_archive_reason']);
        $this->assertSame(7, $item->meta['_archived_by']);
    }

    public function test_a_contact_created_after_the_cutoff_is_not_a_candidate(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Brand new', 'mcp_ai_lead', '2026-05-25 09:00:00'),
        ));

        $result = $this->archive($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(0, $result['data']['archived_count']);
        $this->assertSame(0, $result['data']['skipped_count']);
    }

    public function test_contact_type_limits_which_store_is_scanned(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Lead', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->item(101, 'Customer', 'mcp_ai_customer', '2025-01-01 09:00:00'),
        ));

        $result = $this->archive($store)->execute(
            array('contact_type' => 'lead'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['archived_count']);
        $this->assertSame(100, $result['data']['contacts']['archived'][0]['id']);
    }

    public function test_an_unknown_contact_type_scans_nothing(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Lead', 'mcp_ai_lead', '2025-01-01 09:00:00'),
        ));

        $result = $this->archive($store)->execute(
            array('contact_type' => 'partner'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['archived_count']);
    }

    public function test_the_inactivity_window_is_clamped_to_wordpress_bounds(): void
    {
        $result = $this->archive($this->store())->execute(
            array('days_inactive' => 5),
            array('user_id' => 7)
        );

        $this->assertSame(30, $result['data']['days_inactive']);
        $this->assertSame('2026-05-02 00:00:00', $result['data']['cutoff_date']);
    }

    // ─── recalculate_engagement_scores ───────────────────────────────────

    public function test_the_scorer_keeps_the_wordpress_surface(): void
    {
        $tool = $this->scorer($this->store());

        $this->assertSame('recalculate_engagement_scores', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array(), $schema['required']);
        $this->assertSame('default', $schema['properties']['score_model']['default']);
        $this->assertSame(array('default', 'custom'), $schema['properties']['score_model']['enum']);
        $this->assertTrue($schema['properties']['dry_run']['default']);
    }

    public function test_one_fresh_typed_activity_scores_thirty_four(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->activity(200, 'Email', '2026-06-01 00:00:00', array(
                'related_type' => 'lead',
                'related_id'   => 100,
                'activity_type' => 'email',
            )),
        ));

        $row = $this->scoreOne($store);

        // volume 10×.40=4, recency 100×.25=25, diversity 25×.20=5, completion 0.
        $this->assertSame(34, $row['engagement_score']);
        $this->assertSame(10, $row['score_breakdown']['volume']);
        $this->assertSame(100, $row['score_breakdown']['recency']);
        $this->assertSame(25, $row['score_breakdown']['diversity']);
        $this->assertSame(0, $row['score_breakdown']['completion']);
        $this->assertSame(1, $row['score_breakdown']['total_activities']);
        $this->assertSame(0, $row['score_breakdown']['days_since_last']);
    }

    public function test_the_weighted_score_matches_the_wordpress_formula(): void
    {
        $activities = array();

        // 4 unique types, 10 activities, 5 completed, newest today.
        $types = array('email', 'call', 'meeting', 'note', 'email', 'email', 'call', 'email', 'note', 'email');

        foreach ($types as $index => $type) {
            $meta = array(
                'related_type'  => 'lead',
                'related_id'    => 100,
                'activity_type' => $type,
                'completed'     => $index < 5 ? '1' : '',
            );

            $activities[] = $this->activity(200 + $index, 'Activity ' . $index, '2026-06-01 00:00:00', $meta);
        }

        $store = $this->store(array_merge(
            array($this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00')),
            $activities
        ));

        $row = $this->scoreOne($store);

        // 100×.40 + 100×.25 + 100×.20 + 50×.15 = 92.5 → 93.
        $this->assertSame(93, $row['engagement_score']);
        $this->assertSame(100, $row['score_breakdown']['volume']);
        $this->assertSame(100, $row['score_breakdown']['diversity']);
        $this->assertSame(50, $row['score_breakdown']['completion']);
        $this->assertSame(10, $row['score_breakdown']['total_activities']);
    }

    public function test_a_contact_with_no_activity_scores_zero(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
        ));

        $row = $this->scoreOne($store);

        $this->assertSame(0, $row['engagement_score']);
        $this->assertSame(0, $row['score_breakdown']['volume']);
        $this->assertSame(0, $row['score_breakdown']['total_activities']);
    }

    public function test_the_legacy_completion_key_still_counts(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->activity(200, 'Done', '2026-06-01 00:00:00', array(
                '_activity_contact_id' => 100,
                '_activity_type'       => 'call',
                '_activity_outcome'    => 'completed',
            )),
        ));

        $row = $this->scoreOne($store);

        $this->assertSame(100, $row['score_breakdown']['completion']);
        // 4 + 25 + 5 + 15 = 49.
        $this->assertSame(49, $row['engagement_score']);
    }

    public function test_the_live_completed_stamp_counts(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->activity(200, 'Done', '2026-06-01 00:00:00', array(
                'related_type'  => 'lead',
                'related_id'    => 100,
                'activity_type' => 'call',
                'completed'     => '1',
            )),
        ));

        $row = $this->scoreOne($store);

        $this->assertSame(100, $row['score_breakdown']['completion']);
        $this->assertSame(49, $row['engagement_score']);
    }

    public function test_a_dry_run_writes_no_score_meta(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->activity(200, 'Email', '2026-06-01 00:00:00', array('related_type' => 'lead', 'related_id' => 100)),
        ));

        $this->scoreOne($store);

        $this->assertArrayNotHasKey('_engagement_score', $store->items[100]->meta);
    }

    public function test_a_live_run_stamps_the_four_score_keys(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->activity(200, 'Email', '2026-06-01 00:00:00', array(
                'related_type'  => 'lead',
                'related_id'    => 100,
                'activity_type' => 'email',
            )),
        ));

        $result = $this->scorer($store)->execute(
            array('contact_ids' => array(100), 'dry_run' => false),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);

        $meta = $store->items[100]->meta;

        $this->assertSame(34, $meta['_engagement_score']);
        $this->assertSame('2026-06-01 00:00:00', $meta['_engagement_score_date']);
        $this->assertSame('default', $meta['_engagement_score_model']);
        $this->assertIsString($meta['_engagement_score_breakdown']);

        $breakdown = json_decode($meta['_engagement_score_breakdown'], true);

        $this->assertSame(10, $breakdown['volume']);
        $this->assertSame(1, $breakdown['total_activities']);
    }

    public function test_an_id_of_the_wrong_type_fails_without_scoring(): void
    {
        $store = $this->store(array(
            $this->item(100, 'A deal', 'mcp_ai_deal', '2025-01-01 09:00:00'),
        ));

        $result = $this->scorer($store)->execute(
            array('contact_ids' => array(100)),
            array('user_id' => 7)
        );

        $this->assertSame(0, $result['data']['scored_count']);
        $this->assertSame(1, $result['data']['failed_count']);
        $this->assertSame('Contact not found or invalid type.', $result['data']['contacts']['failed'][0]['reason']);
    }

    public function test_the_named_ids_limit_the_recalculation(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->item(101, 'Grace', 'mcp_ai_customer', '2025-01-01 09:00:00'),
        ));

        $result = $this->scorer($store)->execute(
            array('contact_ids' => array(101)),
            array('user_id' => 7)
        );

        $this->assertSame(1, $result['data']['scored_count']);
        $this->assertSame(101, $result['data']['contacts']['scored'][0]['id']);
    }

    public function test_the_all_contacts_path_lists_leads_then_customers(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Lead older', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->item(101, 'Lead newer', 'mcp_ai_lead', '2025-02-01 09:00:00'),
            $this->item(102, 'Customer', 'mcp_ai_customer', '2025-03-01 09:00:00'),
        ));

        $result = $this->scorer($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(array(101, 100, 102), array_column($result['data']['contacts']['scored'], 'id'));
    }

    public function test_the_custom_model_is_a_label_only(): void
    {
        $store = $this->store(array(
            $this->item(100, 'Ada', 'mcp_ai_lead', '2025-01-01 09:00:00'),
            $this->activity(200, 'Email', '2026-06-01 00:00:00', array('related_type' => 'lead', 'related_id' => 100)),
        ));

        $default = $this->scorer($store)->execute(
            array('contact_ids' => array(100)),
            array('user_id' => 7)
        );

        $custom = $this->scorer($store)->execute(
            array('contact_ids' => array(100), 'score_model' => 'custom'),
            array('user_id' => 7)
        );

        $this->assertSame(
            $default['data']['contacts']['scored'][0]['engagement_score'],
            $custom['data']['contacts']['scored'][0]['engagement_score']
        );
        $this->assertSame('custom', $custom['data']['score_model']);
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function archive(ContentStoreInterface $store): ArchiveStaleContactsTool
    {
        return new class ($this->errors, $store, self::NOW) extends ArchiveStaleContactsTool {
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

    private function scorer(ContentStoreInterface $store): RecalculateEngagementScoresTool
    {
        return new class ($this->errors, $store, self::NOW) extends RecalculateEngagementScoresTool {
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
     * Score contact 100 in a dry run and return its row.
     *
     * @return array<string, mixed>
     */
    private function scoreOne(ContentStoreInterface $store): array
    {
        $result = $this->scorer($store)->execute(
            array('contact_ids' => array(100)),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);

        return $result['data']['contacts']['scored'][0];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function activity(int $id, string $title, string $createdAt, array $meta): ContentItem
    {
        return $this->item($id, $title, 'mcp_ai_crm_activity', $createdAt, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function item(
        int $id,
        string $title,
        string $type,
        string $createdAt,
        array $meta = array(),
    ): ContentItem {
        $when = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));

        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: $type,
            authorId: 1,
            createdAt: $when,
            updatedAt: $when,
            meta: $meta,
        );
    }

    /**
     * A stateful store whose query understands the nested relation groups the
     * activity linkage uses.
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

                usort(
                    $rows,
                    static fn (ContentItem $a, ContentItem $b): int => $b->createdAt->getTimestamp()
                        <=> $a->createdAt->getTimestamp()
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

                if ('IN' === $compare) {
                    $values = array_map('strval', is_array($value) ? $value : array($value));

                    return in_array((string) $stored, $values, true);
                }

                if ('NUMERIC' === strtoupper((string) ( $clause['type'] ?? '' ))) {
                    return (float) $stored === (float) $value;
                }

                return (string) $stored === (string) $value;
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                throw new \LogicException('not used here.');
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                $item = $this->items[ $id ];

                $updated = new ContentItem(
                    id: $item->id,
                    title: $command->title ?? $item->title,
                    content: $command->content ?? $item->content,
                    status: $command->status ?? $item->status,
                    type: $item->type,
                    authorId: $item->authorId,
                    createdAt: $item->createdAt,
                    updatedAt: $item->updatedAt,
                    meta: array_merge($item->meta, $command->meta),
                    taxonomy: $item->taxonomy,
                    excerpt: $command->excerpt ?? $item->excerpt,
                    slug: $item->slug,
                );

                $this->items[ $id ] = $updated;

                return $updated;
            }

            public function delete(int $id, int $userId): void
            {
                unset($this->items[ $id ]);
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
