<?php

/**
 * The fourth regulatory-registration slice: the registration lifecycle.
 *
 * Proposal 164 section 164.147. What is pinned: one status vocabulary accepted
 * in both forms and stored as the term name; an unusable date refused rather
 * than silently dropped; approve and submit appending a dated line instead of
 * replacing the note body; renewal linking both directions before the original
 * is stamped; and a country summary whose statistics survive `group_by_status`.
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
use Nvoos\Core\Tool\Regulatory\ApproveRegistrationTool;
use Nvoos\Core\Tool\Regulatory\ListRegistrationsByCountryTool;
use Nvoos\Core\Tool\Regulatory\RenewRegistrationTool;
use Nvoos\Core\Tool\Regulatory\SubmitRegistrationTool;
use Nvoos\Core\Tool\Regulatory\UpdateRegistrationStatusTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryRegistrationLifecycleTest extends TestCase
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

    // ─── Status ──────────────────────────────────────────────────────────

    public function test_a_status_slug_and_a_display_name_both_resolve_to_the_term_name(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK', array(), array('mcp_ai_reg_status' => array('Draft')))));

        $this->statusTool($store)->execute(
            array('registration_id' => 20, 'status' => 'pending_documents'),
            array('user_id' => 7)
        );
        $this->assertSame(array('Pending Documents'), $store->items[20]->taxonomy['mcp_ai_reg_status']);

        $this->statusTool($store)->execute(
            array('registration_id' => 20, 'status' => 'Under Review'),
            array('user_id' => 7)
        );
        $this->assertSame(array('Under Review'), $store->items[20]->taxonomy['mcp_ai_reg_status']);
    }

    public function test_the_status_response_reports_the_stored_name(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK')));

        $result = $this->statusTool($store)->execute(
            array('registration_id' => 20, 'status' => 'approved', 'cos_number' => 'COS-9'),
            array('user_id' => 7)
        );

        $this->assertSame('Approved', $result['data']['registration']['status']);
        $this->assertSame('COS-9', $result['data']['registration']['cos_number']);
        $this->assertArrayHasKey('created_date', $result['data']['registration']);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK')));

        $result = $this->statusTool($store)->execute(
            array('registration_id' => 20, 'status' => 'archived'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('status', $result['error']['data']);
        $this->assertSame(array(), $store->updated);
    }

    public function test_a_malformed_date_is_refused_rather_than_dropped(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK')));

        $result = $this->statusTool($store)->execute(
            array('registration_id' => 20, 'status' => 'approved', 'expiry_date' => '05/01/2028'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('expiry_date', $result['error']['data']);
        $this->assertSame(array(), $store->updated);
    }

    public function test_an_empty_cos_number_clears_it(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK', array('cos_number' => 'COS-1'))));

        $this->statusTool($store)->execute(
            array('registration_id' => 20, 'status' => 'rejected', 'cos_number' => ''),
            array('user_id' => 7)
        );

        $this->assertSame('', $store->items[20]->meta['cos_number']);
    }

    // ─── Approve ─────────────────────────────────────────────────────────

    public function test_approve_defaults_the_date_and_appends_a_dated_note(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK', array(), array('mcp_ai_reg_status' => array('Under Review')), 'Original note.')));

        $result = $this->approve($store)->execute(
            array('registration_id' => 20, 'expiry_date' => '2028-01-05', 'cos_number' => 'COS-2', 'notes' => 'Granted.'),
            array('user_id' => 7)
        );

        $today = gmdate('Y-m-d');

        $this->assertSame($today, $result['data']['approval_date']);
        $this->assertSame('Approved', $result['data']['status']);
        $this->assertSame('2028-01-05', $result['data']['expiry_date']);
        $this->assertSame('COS-2', $result['data']['cos_number']);

        $this->assertSame(array('Approved'), $store->items[20]->taxonomy['mcp_ai_reg_status']);
        $this->assertSame($today, $store->items[20]->meta['approval_date']);
        $this->assertSame(
            "Original note.\n\n[Approved on " . $today . '] Granted.',
            $store->items[20]->content
        );
    }

    public function test_approve_refuses_a_malformed_expiry_and_reports_nulls_for_omissions(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK')));

        $bad = $this->approve($store)->execute(
            array('registration_id' => 20, 'expiry_date' => '2028/01/05'),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $bad['error']['code']);
        $this->assertArrayHasKey('expiry_date', $bad['error']['data']);

        $ok = $this->approve($store)->execute(array('registration_id' => 20), array('user_id' => 7));
        $this->assertNull($ok['data']['expiry_date']);
        $this->assertNull($ok['data']['cos_number']);
    }

    // ─── Submit ──────────────────────────────────────────────────────────

    public function test_submit_records_the_date_and_appends_the_line(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK', array(), array(), 'Note.')));

        $result = $this->submit($store)->execute(
            array('registration_id' => 20, 'submission_date' => '2026-02-01', 'notes' => 'Filed.'),
            array('user_id' => 7)
        );

        $this->assertSame('Submitted', $result['data']['status']);
        $this->assertSame('2026-02-01', $result['data']['submission_date']);
        $this->assertSame('2026-02-01', $store->items[20]->meta['submission_date']);
        $this->assertSame("Note.\n\n[Submitted on 2026-02-01] Filed.", $store->items[20]->content);
        $this->assertSame(array('Submitted'), $store->items[20]->taxonomy['mcp_ai_reg_status']);
    }

    public function test_submit_refuses_a_malformed_date(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK')));

        $result = $this->submit($store)->execute(
            array('registration_id' => 20, 'submission_date' => 'next week'),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('submission_date', $result['error']['data']);
    }

    // ─── Renew ───────────────────────────────────────────────────────────

    public function test_renew_creates_a_draft_and_marks_the_original(): void
    {
        $store = $this->store(array(
            $this->registration(20, 'Rose', array(
                'product_id'        => 4,
                'country'           => 'LK',
                'authority'         => 'NMRA',
                'cos_number'        => 'COS-OLD',
                'expiry_date'       => '2027-01-01',
                'registration_type' => 'new',
            )),
        ));

        $result = $this->renew($store)->execute(
            array('registration_id' => 20, 'expected_expiry_date' => '2029-01-01', 'notes' => 'Renew early.'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Rose - LK Renewal', $result['data']['renewal_title']);
        $this->assertSame(4, $result['data']['product_id']);
        $this->assertSame('renewal', $result['data']['registration_type']);

        $copy = $store->created[0];
        $this->assertSame('publish', $copy['status']);
        $this->assertSame('mcp_ai_registration', $copy['type']);
        $this->assertSame(4, $copy['meta']['product_id']);
        $this->assertSame('LK', $copy['meta']['country']);
        $this->assertSame('NMRA', $copy['meta']['authority']);
        $this->assertSame('renewal', $copy['meta']['registration_type']);
        $this->assertSame(20, $copy['meta']['original_registration_id']);
        $this->assertSame('2029-01-01', $copy['meta']['expected_expiry_date']);
        $this->assertArrayNotHasKey('cos_number', $copy['meta']);
        $this->assertStringContainsString('Renewal of COS: COS-OLD (Expired: 2027-01-01)', $copy['content']);
        $this->assertSame(array('Draft'), $copy['taxonomy']['mcp_ai_reg_status']);

        $this->assertSame($result['data']['renewal_id'], $store->items[20]->meta['renewed_by']);
        $this->assertArrayHasKey('renewal_date', $store->items[20]->meta);
    }

    public function test_renew_refuses_a_malformed_expected_expiry_and_an_unknown_original(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose', array('country' => 'LK'))));

        $bad = $this->renew($store)->execute(
            array('registration_id' => 20, 'expected_expiry_date' => 'soon'),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $bad['error']['code']);
        $this->assertCount(0, $store->created);

        $missing = $this->renew($store)->execute(array('registration_id' => 99), array('user_id' => 7));
        $this->assertSame('not_found', $missing['error']['code']);
    }

    // ─── By country ──────────────────────────────────────────────────────

    public function test_the_country_view_groups_sorts_and_summarises(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'A', array('country' => 'UAE'), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(2, 'B', array('country' => 'Sri Lanka'), array('mcp_ai_reg_status' => array('Draft'))),
            $this->registration(3, 'C', array('country' => 'UAE'), array('mcp_ai_reg_status' => array('Under Review'))),
        ));

        $result = $this->byCountry($store)->execute(array(), array('user_id' => 7));

        $this->assertSame(2, $result['data']['total_countries']);
        $this->assertSame(3, $result['data']['total_registrations']);
        $this->assertSame(array('Sri Lanka', 'UAE'), array_column($result['data']['countries'], 'country'));

        $uae = $result['data']['countries'][1];
        $this->assertSame(2, $uae['count']);
        $this->assertSame(1, $uae['stats']['total'] >= 2 ? 1 : 0, 'stats must cover every row');
        $this->assertSame(1, $uae['stats']['approved']);
        $this->assertSame(1, $uae['stats']['pending']);
        $this->assertSame(2, $uae['stats']['status_distribution']['Approved'] + $uae['stats']['status_distribution']['Under Review']);
    }

    public function test_the_statistics_survive_group_by_status(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'A', array('country' => 'UAE', 'expiry_date' => gmdate('Y-m-d', time() - ( 5 * 86400 ))), array('mcp_ai_reg_status' => array('Approved'))),
            $this->registration(2, 'B', array('country' => 'UAE'), array('mcp_ai_reg_status' => array('Draft'))),
        ));

        $result = $this->byCountry($store)->execute(array('group_by_status' => true), array('user_id' => 7));
        $uae    = $result['data']['countries'][0];

        $this->assertSame(array(), $uae['registrations']);
        $this->assertSame(2, $uae['stats']['total']);
        $this->assertSame(1, $uae['stats']['approved']);
        $this->assertSame(1, $uae['stats']['expired']);
        $this->assertSame(1, $uae['stats']['status_distribution']['Approved']);
    }

    public function test_the_country_filter_is_exact_and_statistics_can_be_omitted(): void
    {
        $store = $this->store(array(
            $this->registration(1, 'A', array('country' => 'UAE')),
            $this->registration(2, 'B', array('country' => 'Sri Lanka')),
        ));

        $filtered = $this->byCountry($store)->execute(array('country' => 'UAE', 'include_stats' => false), array('user_id' => 7));

        $this->assertSame(1, $filtered['data']['total_countries']);
        $this->assertSame('UAE', $filtered['data']['countries'][0]['country']);
        $this->assertArrayNotHasKey('stats', $filtered['data']['countries'][0]);
    }

    public function test_statistics_are_included_by_default(): void
    {
        $store = $this->store(array($this->registration(1, 'A', array('country' => 'UAE'))));

        $result = $this->byCountry($store)->execute(array(), array('user_id' => 7));

        $this->assertArrayHasKey('stats', $result['data']['countries'][0]);
    }

    public function test_the_lifecycle_capabilities(): void
    {
        $store = $this->store();

        $this->assertSame('edit_posts', $this->statusTool($store)->getRequiredCapability());
        $this->assertSame('edit_posts', $this->approve($store)->getRequiredCapability());
        $this->assertSame('edit_posts', $this->submit($store)->getRequiredCapability());
        $this->assertSame('edit_posts', $this->renew($store)->getRequiredCapability());
        $this->assertSame('read', $this->byCountry($store)->getRequiredCapability());
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function statusTool(ContentStoreInterface $store): UpdateRegistrationStatusTool
    {
        return new UpdateRegistrationStatusTool($this->errors, $store);
    }

    private function approve(ContentStoreInterface $store): ApproveRegistrationTool
    {
        return new ApproveRegistrationTool($this->errors, $store);
    }

    private function submit(ContentStoreInterface $store): SubmitRegistrationTool
    {
        return new SubmitRegistrationTool($this->errors, $store);
    }

    private function renew(ContentStoreInterface $store): RenewRegistrationTool
    {
        return new RenewRegistrationTool($this->errors, $store);
    }

    private function byCountry(ContentStoreInterface $store): ListRegistrationsByCountryTool
    {
        return new ListRegistrationsByCountryTool($this->errors, $store);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function registration(int $id, string $title, array $meta = array(), array $terms = array(), string $content = ''): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: $content,
            status: 'publish',
            type: 'mcp_ai_registration',
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
            taxonomy: $terms,
        );
    }

    /**
     * A store that applies updates and records writes.
     *
     * @param array<int, ContentItem> $items
     */
    private function store(array $items = array()): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items = array();

            /** @var list<array<string, mixed>> */
            public array $created = array();

            /** @var list<array<string, mixed>> */
            public array $updated = array();

            private int $nextId = 1;

            public function __construct(array $items)
            {
                foreach ($items as $item) {
                    $this->items[$item->id] = $item;
                    $this->nextId            = max($this->nextId, $item->id + 1);
                }
            }

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return $this->items[$id] ?? null;
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $rows = array_values(array_filter(
                    array_values($this->items),
                    static fn (ContentItem $i): bool => in_array($i->type, $query->types, true)
                ));

                foreach ($query->metaQuery as $clause) {
                    if (! is_array($clause) || ! isset($clause['key'])) {
                        continue;
                    }

                    $key    = (string) $clause['key'];
                    $value  = (string) ($clause['value'] ?? '');
                    $rows   = array_values(array_filter(
                        $rows,
                        static fn (ContentItem $i): bool => array_key_exists($key, $i->meta)
                            && (string) $i->meta[ $key ] === $value
                    ));
                }

                usort($rows, static fn (ContentItem $a, ContentItem $b): int => $b->id <=> $a->id);

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

            public function create(CreateContentCommand $command): ContentItem
            {
                $id  = $this->nextId++;
                $now = new \DateTimeImmutable('2026-01-01T09:00:00+00:00');

                $item = new ContentItem(
                    id: $id,
                    title: $command->title,
                    content: $command->content,
                    status: $command->status,
                    type: $command->type,
                    authorId: $command->authorId,
                    createdAt: $now,
                    updatedAt: $now,
                    meta: $command->meta,
                    taxonomy: $command->taxonomyInput,
                );

                $this->items[$id] = $item;
                $this->created[]  = array(
                    'id'       => $id,
                    'title'    => $command->title,
                    'content'  => $command->content,
                    'status'   => $command->status,
                    'type'     => $command->type,
                    'meta'     => $command->meta,
                    'taxonomy' => $command->taxonomyInput,
                );

                return $item;
            }

            public function update(int $id, UpdateContentCommand $command): ContentItem
            {
                $current = $this->items[$id];

                $meta = array_merge($current->meta, $command->meta);
                $tax  = $current->taxonomy;

                foreach ($command->taxonomyInput as $taxonomy => $terms) {
                    $tax[ $taxonomy ] = $terms;
                }

                $updated = new ContentItem(
                    id: $current->id,
                    title: $command->title ?? $current->title,
                    content: $command->content ?? $current->content,
                    status: $command->status ?? $current->status,
                    type: $current->type,
                    authorId: $current->authorId,
                    createdAt: $current->createdAt,
                    updatedAt: new \DateTimeImmutable('2026-01-03T09:00:00+00:00'),
                    meta: $meta,
                    taxonomy: $tax,
                );

                $this->items[$id] = $updated;
                $this->updated[]  = array('id' => $id, 'meta' => $command->meta, 'taxonomy' => $command->taxonomyInput);

                return $updated;
            }

            public function delete(int $id, int $userId): void
            {
                throw new \LogicException('not used here.');
            }

            public function getMeta(int $id): array
            {
                return $this->items[$id]->meta ?? array();
            }

            public function getTaxonomyTerms(int $id): array
            {
                return $this->items[$id]->taxonomy ?? array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return isset($this->items[$id]);
            }
        };
    }
}
