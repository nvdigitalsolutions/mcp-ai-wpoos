<?php

/**
 * The fifteenth regulatory-registration slice: the submission pack.
 *
 * Proposal 164 section 164.158. What is pinned: the two flags declared true are
 * true on a bare call, a document stored with the caller's spelling satisfies a
 * lowercase requirement, a missing type refuses the pack and names it, and the
 * pack manifest is written onto the registration.
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
use Nvoos\Core\Tool\Regulatory\GenerateSubmissionPackTool;
use PHPUnit\Framework\TestCase;

final class RegulatorySubmissionPackTest extends TestCase
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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Forbidden.'): array => array(
                'success' => false,
                'error'   => array('code' => 'forbidden', 'message' => $message),
            )
        );
    }

    public function test_a_bare_call_produces_the_letter_and_the_index(): void
    {
        $store = $this->packsStore('AE');

        $result = $this->pack($store)->execute(array('registration_id' => 2), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertIsString($result['data']['cover_letter']);
        $this->assertIsString($result['data']['document_index']);
        $this->assertStringContainsString('Rose Cream', $result['data']['cover_letter']);
        $this->assertStringContainsString('MOHAP', $result['data']['cover_letter']);
        $this->assertStringContainsString('DOCUMENT INDEX', $result['data']['document_index']);
        $this->assertSame(8, $result['data']['document_count']);
    }

    public function test_each_piece_can_be_switched_off(): void
    {
        $store = $this->packsStore('AE');

        $result = $this->pack($store)->execute(
            array('registration_id' => 2, 'include_cover_letter' => false),
            array('user_id' => 7)
        );

        $this->assertNull($result['data']['cover_letter']);
        $this->assertIsString($result['data']['document_index']);

        $neither = $this->pack($store)->execute(
            array('registration_id' => 2, 'include_cover_letter' => false, 'include_index' => false),
            array('user_id' => 7)
        );

        $this->assertNull($neither['data']['cover_letter']);
        $this->assertNull($neither['data']['document_index']);
    }

    public function test_a_document_stored_with_its_caller_spelling_satisfies_a_slug(): void
    {
        $store = $this->packsStore('AE', array('LoA', 'FSC', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula'));

        $result = $this->pack($store)->execute(array('registration_id' => 2), array('user_id' => 7));

        $this->assertTrue($result['success']);
    }

    public function test_a_missing_type_refuses_the_pack_and_names_it(): void
    {
        $store = $this->packsStore('AE', array('loa', 'fsc'));

        $result = $this->pack($store)->execute(array('registration_id' => 2), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertContains('coa', $result['error']['data']['missing_documents']);
        $this->assertNotContains('loa', $result['error']['data']['missing_documents']);
    }

    public function test_a_country_table_adds_its_own_requirement(): void
    {
        $store = $this->packsStore('LK');

        $result = $this->pack($store)->execute(array('registration_id' => 2), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array('iso'), $result['error']['data']['missing_documents']);
    }

    public function test_the_pack_manifest_is_written_onto_the_registration(): void
    {
        $store = $this->packsStore('AE');

        $this->pack($store)->execute(
            array('registration_id' => 2, 'submission_type' => 'renewal'),
            array('user_id' => 7)
        );

        $this->assertCount(1, $store->updates);
        $this->assertSame(2, $store->updates[0]['id']);
        $this->assertSame(7, $store->updates[0]['user_id']);

        $pack = $store->updates[0]['meta']['submission_pack'];

        $this->assertSame('renewal', $pack['submission_type']);
        $this->assertSame('AE', $pack['country']);
        $this->assertSame(8, $pack['document_count']);
        $this->assertArrayHasKey('submission_pack_date', $store->updates[0]['meta']);
    }

    public function test_the_document_list_carries_what_a_dossier_needs(): void
    {
        $store = $this->packsStore('AE');

        $documents = $this->pack($store)->execute(
            array('registration_id' => 2),
            array('user_id' => 7)
        )['data']['documents'];

        $this->assertSame(
            array('document_id', 'title', 'document_type', 'file_url', 'version'),
            array_keys($documents[0])
        );
        $this->assertSame('https://example.test/loa.pdf', $documents[0]['file_url']);
        $this->assertStringContainsString('1. LOA', $this->pack($store)->execute(
            array('registration_id' => 2),
            array('user_id' => 7)
        )['data']['document_index']);
    }

    public function test_an_unknown_registration_or_product_is_not_found(): void
    {
        $store = $this->packsStore('AE');

        $absent = $this->pack($store)->execute(array('registration_id' => 99), array('user_id' => 7));
        $this->assertSame('not_found', $absent['error']['code']);

        $orphanStore = $this->packsStore('AE', null, false);
        $orphan      = $this->pack($orphanStore)->execute(array('registration_id' => 2), array('user_id' => 7));

        $this->assertSame('not_found', $orphan['error']['code']);
        $this->assertStringContainsString('product', $orphan['error']['message']);
    }

    public function test_a_missing_registration_id_or_bad_submission_type_is_refused(): void
    {
        $store = $this->packsStore('AE');

        $absent = $this->pack($store)->execute(array(), array('user_id' => 7));
        $this->assertSame('validation_failed', $absent['error']['code']);
        $this->assertArrayHasKey('registration_id', $absent['error']['data']);

        $badType = $this->pack($store)->execute(
            array('registration_id' => 2, 'submission_type' => 'transfer'),
            array('user_id' => 7)
        );
        $this->assertSame('validation_failed', $badType['error']['code']);
        $this->assertArrayHasKey('submission_type', $badType['error']['data']);
    }

    public function test_the_pack_capability_is_edit_posts(): void
    {
        $this->assertSame('edit_posts', $this->pack($this->packsStore('AE'))->getRequiredCapability());
    }

    // ─── Fixtures ───────────────────────────────────────────────────────

    private function pack(ContentStoreInterface $store): GenerateSubmissionPackTool
    {
        return new GenerateSubmissionPackTool($this->errors, $store);
    }

    /**
     * A product at id 1, a registration at id 2, and the documents for a
     * country's required set at ids 100+.
     *
     * @param list<string>|null $types Document types to attach. `null` uses the
     *                                 default eight.
     */
    private function packsStore(string $country, ?array $types = null, bool $withProduct = true): ContentStoreInterface
    {
        $items = array();

        if ($withProduct) {
            $items[] = $this->item(1, 'Rose Cream', 'mcp_ai_reg_product', array('brand' => 'Acme'));
        }

        $items[] = $this->item(2, 'Registration 2', 'mcp_ai_registration', array(
            'product_id' => 1,
            'country'    => $country,
            'authority'  => 'MOHAP',
        ));

        $defaults = array('loa', 'fsc', 'coa', 'gmp', 'msds', 'pif', 'artwork', 'formula');
        $types    = null === $types ? $defaults : $types;

        foreach ($types as $index => $type) {
            $items[] = $this->item(100 + $index, strtoupper($type), 'mcp_ai_reg_document', array(
                'registration_id' => 2,
                'document_type'   => $type,
                'file_url'        => 'https://example.test/' . $type . '.pdf',
                'version'         => '1',
            ));
        }

        return $this->store($items);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function item(int $id, string $title, string $type, array $meta = array()): ContentItem
    {
        $created = new \DateTimeImmutable('2026-01-01T09:00:00+00:00');

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
            taxonomy: array(),
        );
    }

    /**
     * A store that answers type and equality clauses and records updates.
     *
     * @param array<int, ContentItem> $items
     */
    private function store(array $items): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var array<int, ContentItem> */
            public array $items = array();

            /** @var list<array{id: int, user_id: int, meta: array<string, mixed>}> */
            public array $updates = array();

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
                if (! isset($this->items[ $id ])) {
                    throw new \RuntimeException('not found.');
                }

                $this->updates[] = array(
                    'id'      => $id,
                    'user_id' => $command->userId,
                    'meta'    => $command->meta,
                );

                return $this->items[ $id ];
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
                return isset($this->items[ $id ]);
            }

            /**
             * @param array<int|string, mixed> $clauses
             */
            private function matchesMeta(ContentItem $item, array $clauses): bool
            {
                foreach ($clauses as $name => $clause) {
                    if ('relation' === $name || ! is_array($clause) || ! isset($clause['key'])) {
                        continue;
                    }

                    if ((string) ( $item->meta[ (string) $clause['key'] ] ?? '' ) !== (string) ( $clause['value'] ?? '' )) {
                        return false;
                    }
                }

                return true;
            }
        };
    }
}
