<?php

/**
 * The sixth regulatory-registration slice: document update, listing, versions.
 *
 * Proposal 164 section 164.149. What is pinned: a partial update reports only
 * what it wrote, `rejected` moves a record to trash as WordPress did, the
 * listing counts the rows it returns even when filtering by expiry, the
 * product/registration filter stays an OR, and the version history records the
 * new version it used to lose.
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
use Nvoos\Core\Tool\Regulatory\ListRegDocumentsTool;
use Nvoos\Core\Tool\Regulatory\TrackDocumentVersionTool;
use Nvoos\Core\Tool\Regulatory\UpdateRegDocumentTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryDocumentWritesTest extends TestCase
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

    // ─── Update ──────────────────────────────────────────────────────────

    public function test_update_writes_only_the_supplied_fields_and_reports_them(): void
    {
        $store = $this->store(array($this->document(30, 'LOA', array('version' => '1.0', 'issue_date' => '2026-01-01'))));

        $result = $this->update($store)->execute(
            array('document_id' => 30, 'version' => '1.1', 'notes' => 'Reissued.'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('notes', 'version'), $result['data']['updated_fields']);
        $this->assertSame(array('version' => '1.1'), $store->updated[0]['meta']);
        $this->assertSame('Reissued.', $store->items[30]->content);
        $this->assertSame('2026-01-01', $store->items[30]->meta['issue_date']);
    }

    public function test_rejected_moves_the_record_to_trash_as_wordpress_did(): void
    {
        $store = $this->store(array($this->document(30, 'LOA')));

        $result = $this->update($store)->execute(
            array('document_id' => 30, 'status' => 'rejected'),
            array('user_id' => 7)
        );

        $this->assertSame('trash', $store->items[30]->status);
        $this->assertContains('status', $result['data']['updated_fields']);
    }

    public function test_approved_publishes_the_record(): void
    {
        $store = $this->store(array($this->document(30, 'LOA')));

        $this->update($store)->execute(array('document_id' => 30, 'status' => 'approved'), array('user_id' => 7));

        $this->assertSame('publish', $store->items[30]->status);
    }

    public function test_update_refuses_a_bad_date_and_a_blank_title(): void
    {
        $store = $this->store(array($this->document(30, 'LOA')));

        $badDate = $this->update($store)->execute(
            array('document_id' => 30, 'expiry_date' => '2027/01/01'),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $badDate['error']['code']);
        $this->assertArrayHasKey('expiry_date', $badDate['error']['data']);

        $blank = $this->update($store)->execute(
            array('document_id' => 30, 'title' => '  '),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $blank['error']['code']);
        $this->assertSame(array(), $store->updated);
    }

    public function test_the_document_type_updates_both_meta_and_term(): void
    {
        $store = $this->store(array($this->document(30, 'LOA', array('document_type' => 'LOA'), array('mcp_ai_doc_type' => array('LOA')))));

        $this->update($store)->execute(
            array('document_id' => 30, 'document_type' => 'Certificate of Analysis'),
            array('user_id' => 7)
        );

        $this->assertSame('Certificate of Analysis', $store->items[30]->meta['document_type']);
        $this->assertSame(array('Certificate of Analysis'), $store->items[30]->taxonomy['mcp_ai_doc_type']);
    }

    // ─── List ────────────────────────────────────────────────────────────

    public function test_the_list_counts_the_rows_it_returns_when_filtering_by_expiry(): void
    {
        $store = $this->store(array(
            $this->document(1, 'Expired', array('expiry_date' => gmdate('Y-m-d', time() - ( 5 * 86400 )))),
            $this->document(2, 'Soon', array('expiry_date' => gmdate('Y-m-d', time() + ( 5 * 86400 )))),
            $this->document(3, 'Far', array('expiry_date' => gmdate('Y-m-d', time() + ( 400 * 86400 )))),
        ));

        $result = $this->list($store)->execute(array('status' => 'expiring_soon'), array('user_id' => 7));

        $this->assertSame(1, $result['data']['total']);
        $this->assertSame(1, $result['data']['total_pages']);
        $this->assertSame('Soon', $result['data']['documents'][0]['title']);
    }

    public function test_the_product_and_registration_filter_match_either_reference(): void
    {
        $store = $this->store(array(
            $this->document(1, 'By product', array('product_id' => 4)),
            $this->document(2, 'By registration', array('registration_id' => 20)),
            $this->document(3, 'Unattached'),
        ));

        $result = $this->list($store)->execute(array('product_id' => 4, 'registration_id' => 20), array('user_id' => 7));

        $this->assertSame(2, $result['data']['total']);
    }

    public function test_the_list_row_reports_the_record_status_and_expiry_flags(): void
    {
        $store = $this->store(array($this->document(3, 'Far', array('expiry_date' => gmdate('Y-m-d', time() + ( 400 * 86400 ))))));

        $result = $this->list($store)->execute(array(), array('user_id' => 7));
        $row    = $result['data']['documents'][0];

        $this->assertSame('publish', $row['status']);
        $this->assertFalse($row['is_expired']);
        $this->assertFalse($row['expiring_soon']);
        $this->assertSame('valid', $row['expiry_status']);
    }

    public function test_an_unknown_expiry_filter_is_refused(): void
    {
        $result = $this->list($this->store())->execute(array('status' => 'stale'), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame('read', $this->list($this->store())->getRequiredCapability());
    }

    // ─── Versions ────────────────────────────────────────────────────────

    public function test_history_synthesises_the_current_version_when_absent(): void
    {
        $store = $this->store(array($this->document(30, 'LOA', array('version' => '1.0', 'file_url' => 'https://e.test/1.pdf'))));

        $result = $this->track($store)->execute(
            array('document_id' => 30, 'action' => 'get_history'),
            array('user_id' => 7)
        );

        $this->assertSame('1.0', $result['data']['current_version']);
        $this->assertSame(1, $result['data']['total_versions']);
        $this->assertSame('1.0', $result['data']['version_history'][0]['version']);
    }

    public function test_create_version_records_the_new_version_it_used_to_lose(): void
    {
        $store = $this->store(array($this->document(30, 'LOA', array('version' => '1.0', 'file_url' => 'https://e.test/1.pdf'))));

        $result = $this->track($store)->execute(
            array(
                'document_id'  => 30,
                'action'       => 'create_version',
                'new_version'  => '2.0',
                'file_url'     => 'https://e.test/2.pdf',
                'change_notes' => 'Reformulated.',
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('1.0', $result['data']['old_version']);
        $this->assertSame('2.0', $result['data']['new_version']);
        $this->assertSame('2.0', $store->items[30]->meta['version']);
        $this->assertSame('https://e.test/2.pdf', $store->items[30]->meta['file_url']);

        $history = $store->items[30]->meta['version_history'];
        $this->assertSame(array('1.0', '2.0'), array_column($history, 'version'));
        $this->assertSame('Reformulated.', $history[1]['notes']);
    }

    public function test_a_second_version_does_not_duplicate_the_outgoing_entry(): void
    {
        $store = $this->store(array(
            $this->document(30, 'LOA', array(
                'version'         => '2.0',
                'file_url'        => 'https://e.test/2.pdf',
                'version_history' => array(
                    array('version' => '1.0', 'file_url' => 'https://e.test/1.pdf', 'created_at' => '2026-01-01 00:00:00', 'notes' => ''),
                    array('version' => '2.0', 'file_url' => 'https://e.test/2.pdf', 'created_at' => '2026-02-01 00:00:00', 'notes' => ''),
                ),
            )),
        ));

        $this->track($store)->execute(
            array('document_id' => 30, 'action' => 'create_version', 'new_version' => '3.0', 'file_url' => 'https://e.test/3.pdf'),
            array('user_id' => 7)
        );

        $versions = array_column($store->items[30]->meta['version_history'], 'version');
        $this->assertSame(array('1.0', '2.0', '3.0'), $versions);
    }

    public function test_history_is_sorted_by_version_not_by_string(): void
    {
        $store = $this->store(array(
            $this->document(30, 'LOA', array(
                'version'         => '2.10',
                'file_url'        => 'https://e.test/10.pdf',
                'version_history' => array(
                    array('version' => '2.9', 'file_url' => 'https://e.test/9.pdf', 'created_at' => '', 'notes' => ''),
                    array('version' => '2.10', 'file_url' => 'https://e.test/10.pdf', 'created_at' => '', 'notes' => ''),
                ),
            )),
        ));

        $result = $this->track($store)->execute(
            array('document_id' => 30, 'action' => 'get_history'),
            array('user_id' => 7)
        );

        $this->assertSame(array('2.10', '2.9'), array_column($result['data']['version_history'], 'version'));
    }

    public function test_create_version_requires_an_http_url_and_a_version(): void
    {
        $store = $this->store(array($this->document(30, 'LOA', array('version' => '1.0'))));

        $noVersion = $this->track($store)->execute(
            array('document_id' => 30, 'action' => 'create_version', 'file_url' => 'https://e.test/2.pdf'),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $noVersion['error']['code']);
        $this->assertArrayHasKey('new_version', $noVersion['error']['data']);

        $badUrl = $this->track($store)->execute(
            array('document_id' => 30, 'action' => 'create_version', 'new_version' => '2.0', 'file_url' => 'file:///x.pdf'),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $badUrl['error']['code']);
        $this->assertArrayHasKey('file_url', $badUrl['error']['data']);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function update(ContentStoreInterface $store): UpdateRegDocumentTool
    {
        return new UpdateRegDocumentTool($this->errors, $store);
    }

    private function list(ContentStoreInterface $store): ListRegDocumentsTool
    {
        return new ListRegDocumentsTool($this->errors, $store);
    }

    private function track(ContentStoreInterface $store): TrackDocumentVersionTool
    {
        return new TrackDocumentVersionTool($this->errors, $store);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function document(int $id, string $title, array $meta = array(), array $terms = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: 'mcp_ai_reg_document',
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
            taxonomy: $terms,
        );
    }

    /**
     * A store that applies updates and evaluates the query it is given.
     *
     * @param array<int, ContentItem> $items
     */
    private function store(array $items = array()): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items = array();

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
                        && in_array($i->status, $query->statuses, true)
                ));

                foreach ($query->metaQuery as $clause) {
                    $rows = $this->applyClause($rows, $clause);
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

            /**
             * @param list<ContentItem> $rows
             * @param mixed             $clause
             *
             * @return list<ContentItem>
             */
            private function applyClause(array $rows, $clause): array
            {
                if (! is_array($clause)) {
                    return $rows;
                }

                if (isset($clause['relation'])) {
                    $matched = array();

                    foreach ($clause as $key => $child) {
                        if ('relation' === $key || ! is_array($child)) {
                            continue;
                        }

                        foreach ($this->applyClause($rows, $child) as $row) {
                            $matched[ $row->id ] = $row;
                        }
                    }

                    return array_values($matched);
                }

                if (! isset($clause['key'])) {
                    return $rows;
                }

                $key   = (string) $clause['key'];
                $value = (string) ($clause['value'] ?? '');

                return array_values(array_filter(
                    $rows,
                    static fn (ContentItem $i): bool => array_key_exists($key, $i->meta)
                        && (string) $i->meta[ $key ] === $value
                ));
            }

            public function create(CreateContentCommand $command): ContentItem
            {
                throw new \LogicException('not used here.');
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
