<?php

/**
 * The two interaction tools: the activity vocabularies, the date walk, and the
 * handover bundle.
 *
 * The assertions that matter are the ones the WordPress originals get wrong or
 * leave implicit:
 *
 * - **Two activity vocabularies.** `create_crm_activity` stamps
 *   `activity_type` / `related_type` / `related_id`; the WordPress reader looks
 *   for `_activity_contact_id` / `_activity_type`. The port reads both, so a
 *   test proves a legacy row *and* a toolkit-written row are reachable.
 * - **The date range is inclusive** and applied while walking pages, not after
 *   the first page.
 * - **The bundle's section order** is the WordPress order, and a lead's bundle
 *   has no stage history because a lead has no ledger.
 *
 * The store evaluates the query it is given, including nested `relation`
 * groups, so a test asserts on the rows that come back rather than on the query
 * object. Its meta semantics mirror the Laravel adapter's `applyMetaQuery()`.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\Crm\GetContactInteractionsTool;
use Nvoos\Core\Tool\Crm\GetCrmHandoverTool;
use PHPUnit\Framework\TestCase;

final class CrmInteractionToolsTest extends TestCase
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

    // ─── get_contact_interactions ────────────────────────────────────────

    public function test_the_interaction_tool_keeps_the_wordpress_surface(): void
    {
        $tool = $this->interactions($this->store());

        $this->assertSame('get_contact_interactions', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());

        $schema = $tool->getParametersSchema();

        $this->assertSame(array('contact_id'), $schema['required']);
        $this->assertSame(50, $schema['properties']['limit']['default']);
        $this->assertSame(500, $schema['properties']['limit']['maximum']);
        $this->assertSame('all', $schema['properties']['interaction_type']['default']);
        $this->assertSame(
            array('email', 'call', 'meeting', 'note', 'all'),
            $schema['properties']['interaction_type']['enum']
        );
    }

    public function test_a_contact_id_is_required(): void
    {
        $result = $this->interactions($this->store())->execute(array(), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('contact_id', $result['error']['data']);
    }

    public function test_a_date_that_does_not_exist_is_refused(): void
    {
        $result = $this->interactions($this->store())->execute(
            array('contact_id' => 1, 'date_from' => '2026-02-31'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('date_from', $result['error']['data']);
    }

    public function test_a_legacy_underscore_activity_is_returned(): void
    {
        $store = $this->store(array(
            $this->activity(10, 'Discovery call', '2026-01-02 09:00:00', array(
                '_activity_contact_id' => 42,
                '_activity_type'       => 'call',
                '_activity_outcome'    => 'positive',
                '_activity_duration'   => 30,
            )),
        ));

        $result = $this->interactions($store)->execute(
            array('contact_id' => 42),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total_count']);

        $row = $result['data']['interactions'][0];

        $this->assertSame(10, $row['id']);
        $this->assertSame('call', $row['type']);
        $this->assertSame('positive', $row['outcome']);
        $this->assertSame(30, $row['duration_minutes']);
        $this->assertSame('Discovery call', $row['subject']);
        $this->assertSame('2026-01-02 09:00:00', $row['date']);
    }

    public function test_a_toolkit_written_activity_is_reachable(): void
    {
        // What create_crm_activity actually stamps.
        $store = $this->store(array(
            $this->activity(11, 'Follow-up email', '2026-01-03 09:00:00', array(
                'activity_type' => 'email',
                'related_type'  => 'lead',
                'related_id'    => 42,
            )),
        ));

        $result = $this->interactions($store)->execute(
            array('contact_id' => 42),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame('email', $result['data']['interactions'][0]['type']);
    }

    public function test_a_deal_activity_is_not_attributed_to_a_contact(): void
    {
        // related_type 'deal' means the id is a deal id, not a contact id.
        $store = $this->store(array(
            $this->activity(12, 'Deal note', '2026-01-03 09:00:00', array(
                'activity_type' => 'note',
                'related_type'  => 'deal',
                'related_id'    => 42,
            )),
        ));

        $result = $this->interactions($store)->execute(
            array('contact_id' => 42),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['total_count']);
    }

    public function test_the_type_filter_matches_either_vocabulary(): void
    {
        $store = $this->store(array(
            $this->activity(20, 'Legacy call', '2026-01-04 09:00:00', array(
                '_activity_contact_id' => 42,
                '_activity_type'       => 'call',
            )),
            $this->activity(21, 'Live email', '2026-01-05 09:00:00', array(
                'activity_type' => 'email',
                'related_type'  => 'contact',
                'related_id'    => 42,
            )),
        ));

        $calls = $this->interactions($store)->execute(
            array('contact_id' => 42, 'interaction_type' => 'call'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $calls['data']['total_count']);
        $this->assertSame(20, $calls['data']['interactions'][0]['id']);

        $emails = $this->interactions($store)->execute(
            array('contact_id' => 42, 'interaction_type' => 'email'),
            array('user_id' => 7)
        );

        $this->assertSame(1, $emails['data']['total_count']);
        $this->assertSame(21, $emails['data']['interactions'][0]['id']);
    }

    public function test_the_date_range_is_inclusive(): void
    {
        $store = $this->store(array(
            $this->activity(30, 'Before', '2026-01-01 23:00:00', array('_activity_contact_id' => 42)),
            $this->activity(31, 'On the day', '2026-01-02 08:00:00', array('_activity_contact_id' => 42)),
            $this->activity(32, 'After', '2026-01-03 00:00:01', array('_activity_contact_id' => 42)),
        ));

        $result = $this->interactions($store)->execute(
            array('contact_id' => 42, 'date_from' => '2026-01-02', 'date_to' => '2026-01-02'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['total_count']);
        $this->assertSame(31, $result['data']['interactions'][0]['id']);
    }

    public function test_rows_are_newest_first_and_the_limit_applies(): void
    {
        $store = $this->store(array(
            $this->activity(40, 'Oldest', '2026-01-01 09:00:00', array('_activity_contact_id' => 42)),
            $this->activity(41, 'Middle', '2026-01-02 09:00:00', array('_activity_contact_id' => 42)),
            $this->activity(42, 'Newest', '2026-01-03 09:00:00', array('_activity_contact_id' => 42)),
        ));

        $result = $this->interactions($store)->execute(
            array('contact_id' => 42, 'limit' => 2),
            array('user_id' => 7)
        );

        $this->assertSame(2, $result['data']['total_count']);
        $this->assertSame(array(42, 41), array_column($result['data']['interactions'], 'id'));
    }

    // ─── get_crm_handover ────────────────────────────────────────────────

    public function test_the_handover_keeps_the_wordpress_surface(): void
    {
        $tool = $this->handover($this->store());

        $this->assertSame('get_crm_handover', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertSame(array('entity_id'), $tool->getParametersSchema()['required']);
    }

    public function test_an_unknown_entity_kind_is_refused(): void
    {
        $result = $this->handover($this->store())->execute(
            array('entity' => 'contact', 'entity_id' => 1),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('entity', $result['error']['data']);
    }

    public function test_an_unknown_deal_is_not_found(): void
    {
        $result = $this->handover($this->store())->execute(
            array('entity' => 'deal', 'entity_id' => 999),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
    }

    public function test_a_deal_bundle_has_every_section_in_wordpress_order(): void
    {
        $result = $this->handover($this->fullStore())->execute(
            array('entity' => 'deal', 'entity_id' => 200),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array('lead', 'company', 'deals', 'history', 'activities'),
            $result['data']['sections']
        );

        $text = $result['data']['text'];

        $this->assertStringContainsString('## Lead', $text);
        $this->assertStringContainsString('- Name: Ada Lovelace', $text);
        $this->assertStringContainsString('- BANT: {"budget":true}', $text);
        $this->assertStringContainsString('- Last reply: 2026-01-05 (positive)', $text);
        $this->assertStringContainsString('## Company', $text);
        $this->assertStringContainsString('- Industry: Analytical engines', $text);
        $this->assertStringContainsString('## Deals', $text);
        $this->assertStringContainsString('$1,500.00', $text);
        $this->assertStringContainsString('## Stage history', $text);
        $this->assertStringContainsString('qualified → proposal', $text);
        $this->assertStringContainsString('## Recent activities', $text);
        $this->assertStringContainsString('— Asked for a written proposal', $text);
        $this->assertStringContainsString('## What I need from you', $text);
        $this->assertStringContainsString('Summarize this record and propose the next action.', $text);
    }

    public function test_the_include_list_restricts_the_bundle(): void
    {
        $result = $this->handover($this->fullStore())->execute(
            array('entity' => 'deal', 'entity_id' => 200, 'include' => array('deals')),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('deals'), $result['data']['sections']);
        $this->assertStringNotContainsString('## Lead', $result['data']['text']);
        $this->assertStringNotContainsString('## Company', $result['data']['text']);
    }

    public function test_an_include_list_with_no_known_section_is_refused(): void
    {
        $result = $this->handover($this->fullStore())->execute(
            array('entity' => 'deal', 'entity_id' => 200, 'include' => array('billing')),
            array('user_id' => 7)
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('include', $result['error']['data']);
    }

    public function test_the_closing_ask_and_currency_come_from_the_settings_store(): void
    {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('get')->willReturn(array(
            'default_currency' => 'EUR',
            'handover_ask'     => 'Book the demo.',
        ));

        $result = $this->handover($this->fullStore(), $settings)->execute(
            array('entity' => 'deal', 'entity_id' => 200),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('€1,500.00', $result['data']['text']);
        $this->assertStringEndsWith('Book the demo.', $result['data']['text']);
    }

    public function test_a_lead_bundle_uses_the_lead_as_the_subject_and_has_no_history(): void
    {
        $result = $this->handover($this->fullStore())->execute(
            array('entity' => 'lead', 'entity_id' => 100),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('lead', 'company', 'deals', 'activities'), $result['data']['sections']);
        $this->assertStringNotContainsString('## Stage history', $result['data']['text']);
        $this->assertStringContainsString('- #200 Proposal preparation', $result['data']['text']);
    }

    // ─── fixtures ────────────────────────────────────────────────────────

    private function interactions(ContentStoreInterface $store): GetContactInteractionsTool
    {
        return new GetContactInteractionsTool($this->errors, $store);
    }

    private function handover(
        ContentStoreInterface $store,
        ?SettingsStoreInterface $settings = null,
    ): GetCrmHandoverTool {
        return new GetCrmHandoverTool($this->errors, $store, $settings);
    }

    /**
     * One activity content item.
     *
     * @param array<string, mixed> $meta
     */
    private function activity(int $id, string $title, string $createdAt, array $meta): ContentItem
    {
        return $this->item($id, $title, 'mcp_ai_crm_activity', $createdAt, $meta, '');
    }

    /**
     * A lead, a company, a deal and the two activities linked to them, as the
     * toolkit links them.
     */
    private function fullStore(): ContentStoreInterface
    {
        return $this->store(array(
            $this->item(100, 'Ada Lovelace', 'mcp_ai_lead', '2026-01-01 08:00:00', array(
                'first_name'           => 'Ada',
                'last_name'            => 'Lovelace',
                'email'                => 'ada@example.test',
                'phone'                => '+44 20 0000 0000',
                'company_name'         => 'Analytical Engines',
                'job_title'            => 'Chief Engineer',
                'source'               => 'referral',
                'lifecycle_stage'      => 'opportunity',
                'lead_status'          => 'working',
                'lead_score'           => 82,
                'bant_assessment'      => array('budget' => true),
                'last_email_received'  => '2026-01-05',
                'last_email_sentiment' => 'positive',
                'last_email_snippet'   => 'Send the proposal.',
                'company_id'           => 150,
            ), ''),
            $this->item(150, 'Analytical Engines', 'mcp_ai_company', '2026-01-01 08:00:00', array(
                '_company_website'  => 'https://example.test',
                '_company_industry' => 'Analytical engines',
                '_company_size'     => '51-200',
                '_company_status'   => 'client',
            ), ''),
            $this->item(200, 'Proposal preparation', 'mcp_ai_deal', '2026-01-02 08:00:00', array(
                'lead_id'             => 100,
                'pipeline_stage'      => 'proposal',
                'amount'              => 1500,
                'win_probability'     => 60,
                'expected_close_date' => '2026-02-01',
                'stage_changed_at'    => '2026-01-04 08:00:00',
                'stage_history'       => array(
                    array('at' => '2026-01-03 08:00:00', 'from' => 'qualified', 'to' => 'proposal', 'source' => 'tool'),
                ),
            ), ''),
            $this->item(300, 'Proposal email', 'mcp_ai_crm_activity', '2026-01-05 10:00:00', array(
                'activity_type' => 'email',
                'related_type'  => 'deal',
                'related_id'    => 200,
            ), 'Asked for a written proposal.'),
            $this->activity(301, 'Intro call', '2026-01-02 09:00:00', array(
                'activity_type' => 'call',
                'related_type'  => 'lead',
                'related_id'    => 100,
            )),
        ));
    }

    private function item(
        int $id,
        string $title,
        string $type,
        string $createdAt,
        array $meta,
        string $content,
    ): ContentItem {
        $when = new \DateTimeImmutable($createdAt, new \DateTimeZone('UTC'));

        return new ContentItem(
            id: $id,
            title: $title,
            content: $content,
            status: 'publish',
            type: $type,
            authorId: 1,
            createdAt: $when,
            updatedAt: $when,
            meta: $meta,
        );
    }

    /**
     * A store that evaluates the query it is given, including nested relation
     * groups and the adapter's meta compare set.
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

                if ('IN' === $compare) {
                    $values = array_map('strval', is_array($value) ? $value : array($value));

                    return in_array((string) $stored, $values, true);
                }

                if ('LIKE' === $compare) {
                    return null !== $stored && false !== stripos((string) $stored, (string) $value);
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
