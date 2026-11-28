<?php

/**
 * The fifth regulatory-registration slice: document upload and read.
 *
 * Proposal 164 section 164.148. What is pinned: the record is written with the
 * WordPress meta keys and its type term in the caller's spelling, a URL must be
 * http or https, a binary upload is refused with a named gap rather than
 * silently ignored, a document must attach to a product or a registration that
 * exists, and the read row keeps WordPress's shape and expiry thresholds.
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
use Nvoos\Core\Tool\Regulatory\GetRegDocumentTool;
use Nvoos\Core\Tool\Regulatory\UploadRegDocumentTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryDocumentToolsTest extends TestCase
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
            static fn (string $message = 'Resource not found.', array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'not_found', 'message' => $message, 'data' => $data),
            )
        );
    }

    // ─── Upload ──────────────────────────────────────────────────────────

    public function test_upload_writes_the_record_with_the_wordpress_meta(): void
    {
        $store = $this->store(array($this->product(4, 'Rose Serum')));

        $result = $this->upload($store)->execute(
            array(
                'title'         => 'Letter of Authorization',
                'document_type' => 'LOA',
                'file_url'      => 'https://example.test/loa.pdf',
                'product_id'    => 4,
                'issue_date'    => '2026-01-05',
                'expiry_date'   => '2028-01-05',
                'version'       => '2.1',
                'notes'         => 'Signed by the manufacturer.',
            ),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertSame('https://example.test/loa.pdf', $result['data']['file_url']);
        $this->assertSame('Document uploaded successfully.', $result['message']);

        $created = $store->created[0];
        $this->assertSame('Letter of Authorization', $created['title']);
        $this->assertSame('mcp_ai_reg_document', $created['type']);
        $this->assertSame('publish', $created['status']);
        $this->assertSame('LOA', $created['meta']['document_type']);
        $this->assertSame('https://example.test/loa.pdf', $created['meta']['file_url']);
        $this->assertSame('2.1', $created['meta']['version']);
        $this->assertSame(4, $created['meta']['product_id']);
        $this->assertArrayNotHasKey('registration_id', $created['meta']);
        $this->assertSame('2026-01-05', $created['meta']['issue_date']);
        $this->assertSame('2028-01-05', $created['meta']['expiry_date']);
        $this->assertSame('Signed by the manufacturer.', $created['content']);
        // The term holds the caller's spelling, not a slug.
        $this->assertSame(array('LOA'), $created['taxonomy']['mcp_ai_doc_type']);
    }

    public function test_upload_defaults_the_version_and_accepts_a_registration_target(): void
    {
        $store = $this->store(array($this->registration(20, 'Rose - LK')));

        $this->upload($store)->execute(
            array(
                'title'           => 'COS',
                'document_type'   => 'Certificate',
                'file_url'        => 'http://example.test/cos.pdf',
                'registration_id' => 20,
            ),
            array('user_id' => 7)
        );

        $this->assertSame('1.0', $store->created[0]['meta']['version']);
        $this->assertSame(20, $store->created[0]['meta']['registration_id']);
    }

    public function test_upload_refuses_binary_data_with_a_named_gap(): void
    {
        $store = $this->store(array($this->product(4, 'Rose Serum')));

        $result = $this->upload($store)->execute(
            array(
                'title'         => 'Scan',
                'document_type' => 'LOA',
                'file_data'     => 'JVBERi0xLjQK',
                'file_name'     => 'scan.pdf',
                'product_id'    => 4,
            ),
            array('user_id' => 7)
        );

        $this->assertSame('reg_document_upload_unavailable', $result['error']['code']);
        $this->assertCount(0, $store->created);
    }

    public function test_upload_refuses_a_non_http_url(): void
    {
        $store = $this->store(array($this->product(4, 'Rose Serum')));

        foreach (array('javascript:alert(1)', 'file:///etc/passwd', '/relative/path.pdf') as $url) {
            $result = $this->upload($store)->execute(
                array('title' => 'X', 'document_type' => 'LOA', 'file_url' => $url, 'product_id' => 4),
                array('user_id' => 7)
            );

            $this->assertSame('validation_failed', $result['error']['code'], $url);
            $this->assertArrayHasKey('file_url', $result['error']['data']);
        }

        $this->assertCount(0, $store->created);
    }

    public function test_upload_requires_a_target_that_exists(): void
    {
        $store = $this->store(array($this->product(4, 'Rose Serum')));

        $noTarget = $this->upload($store)->execute(
            array('title' => 'X', 'document_type' => 'LOA', 'file_url' => 'https://e.test/x.pdf'),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $noTarget['error']['code']);
        $this->assertArrayHasKey('product_id', $noTarget['error']['data']);

        $badProduct = $this->upload($store)->execute(
            array('title' => 'X', 'document_type' => 'LOA', 'file_url' => 'https://e.test/x.pdf', 'product_id' => 99),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $badProduct['error']['code']);
        $this->assertArrayHasKey('product_id', $badProduct['error']['data']);
    }

    public function test_upload_refuses_a_malformed_date(): void
    {
        $store = $this->store(array($this->product(4, 'Rose Serum')));

        $result = $this->upload($store)->execute(
            array(
                'title'         => 'X',
                'document_type' => 'LOA',
                'file_url'      => 'https://e.test/x.pdf',
                'product_id'    => 4,
                'issue_date'    => '05/01/2026',
            ),
            array('user_id' => 7)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('issue_date', $result['error']['data']);
    }

    // ─── Get ─────────────────────────────────────────────────────────────

    public function test_get_returns_the_wordpress_row_shape(): void
    {
        $store = $this->store(array(
            $this->document(30, 'LOA', array(
                'document_type'   => 'LOA',
                'file_url'        => 'https://e.test/loa.pdf',
                'version'         => '1.2',
                'product_id'      => '4',
                'registration_id' => '20',
            ), array('mcp_ai_doc_type' => array('LOA'))),
        ));

        $result = $this->get($store)->execute(array('document_id' => 30), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $row = $result['data']['document'];

        $this->assertSame(
            array(
                'document_id', 'title', 'document_type', 'file_url', 'version', 'issue_date',
                'expiry_date', 'expiry_status', 'days_to_expiry', 'status', 'notes',
                'created_at', 'modified_at', 'product_id', 'registration_id', 'taxonomies',
            ),
            array_keys($row)
        );
        $this->assertSame(30, $row['document_id']);
        $this->assertSame('LOA', $row['document_type']);
        $this->assertSame('1.2', $row['version']);
        $this->assertNull($row['days_to_expiry']);
        $this->assertSame('valid', $row['expiry_status']);
        $this->assertSame('publish', $row['status']);
        // References stay strings, as WordPress reported them.
        $this->assertSame('4', $row['product_id']);
        $this->assertSame('20', $row['registration_id']);
        $this->assertSame(array('LOA'), $row['taxonomies']['doc_types']);
    }

    public function test_the_expiry_flags_cover_expired_soon_far_and_unparsable(): void
    {
        $store = $this->store(array(
            $this->document(1, 'Expired', array('expiry_date' => gmdate('Y-m-d', time() - ( 10 * 86400 )))),
            $this->document(2, 'Soon', array('expiry_date' => gmdate('Y-m-d', time() + ( 10 * 86400 )))),
            $this->document(3, 'Far', array('expiry_date' => gmdate('Y-m-d', time() + ( 400 * 86400 )))),
            $this->document(4, 'Unparsable', array('expiry_date' => 'not-a-date')),
        ));

        $expired = $this->get($store)->execute(array('document_id' => 1), array('user_id' => 7));
        $this->assertSame('expired', $expired['data']['document']['expiry_status']);
        $this->assertLessThan(0, $expired['data']['document']['days_to_expiry']);

        $soon = $this->get($store)->execute(array('document_id' => 2), array('user_id' => 7));
        $this->assertSame('expiring_soon', $soon['data']['document']['expiry_status']);

        $far = $this->get($store)->execute(array('document_id' => 3), array('user_id' => 7));
        $this->assertSame('valid', $far['data']['document']['expiry_status']);

        $unparsable = $this->get($store)->execute(array('document_id' => 4), array('user_id' => 7));
        $this->assertSame('valid', $unparsable['data']['document']['expiry_status']);
        $this->assertNull($unparsable['data']['document']['days_to_expiry']);
    }

    public function test_the_two_expansions_resolve_their_records(): void
    {
        $store = $this->store(array(
            $this->product(4, 'Rose Serum', array('brand' => 'Acme')),
            $this->registration(20, 'Rose - LK', array('country' => 'LK', 'cos_number' => 'COS-1')),
            $this->document(30, 'LOA', array('product_id' => 4, 'registration_id' => 20)),
        ));

        $result = $this->get($store)->execute(
            array('document_id' => 30, 'include_product' => true, 'include_registration' => true),
            array('user_id' => 7)
        );

        $row = $result['data']['document'];

        $this->assertSame(array('id' => 4, 'title' => 'Rose Serum', 'brand' => 'Acme'), $row['product']);
        $this->assertSame(
            array('id' => 20, 'title' => 'Rose - LK', 'country' => 'LK', 'cos_number' => 'COS-1'),
            $row['registration']
        );
    }

    public function test_an_unknown_document_is_not_found(): void
    {
        $result = $this->get($this->store())->execute(array('document_id' => 99), array('user_id' => 7));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_the_capabilities(): void
    {
        $store = $this->store();

        $this->assertSame('edit_posts', $this->upload($store)->getRequiredCapability());
        $this->assertSame('read', $this->get($store)->getRequiredCapability());
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function upload(ContentStoreInterface $store): UploadRegDocumentTool
    {
        return new UploadRegDocumentTool($this->errors, $store);
    }

    private function get(ContentStoreInterface $store): GetRegDocumentTool
    {
        return new GetRegDocumentTool($this->errors, $store);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function product(int $id, string $title, array $meta = array()): ContentItem
    {
        return $this->record($id, $title, 'mcp_ai_reg_product', $meta);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function registration(int $id, string $title, array $meta = array()): ContentItem
    {
        return $this->record($id, $title, 'mcp_ai_registration', $meta);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function document(int $id, string $title, array $meta = array(), array $terms = array()): ContentItem
    {
        return $this->record($id, $title, 'mcp_ai_reg_document', $meta, $terms);
    }

    /**
     * @param array<string, mixed>              $meta
     * @param array<string, array<int, string>> $terms
     */
    private function record(int $id, string $title, string $type, array $meta = array(), array $terms = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: $type,
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
            taxonomy: $terms,
        );
    }

    /**
     * A store that records creations and answers finds.
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
                return new ContentCollection(array(), 0, 1, $query->perPage, 0);
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
                throw new \LogicException('not used here.');
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
