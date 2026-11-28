<?php

/**
 * The seventh regulatory-registration slice: product validation.
 *
 * Proposal 164 section 164.150. What is pinned: the three check flags default
 * to on, so a bare call validates rather than reporting a vacuous pass; a
 * negative verdict is a successful call; completeness, INCI format and HS code
 * format each report their own errors and warnings; and a trailing comma in an
 * ingredient list does not add an ingredient.
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
use Nvoos\Core\Tool\Regulatory\ValidateRegProductTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryProductValidationTest extends TestCase
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

    public function test_a_bare_call_runs_all_three_checks(): void
    {
        $store  = $this->store(array($this->product(4, 'Rose', $this->completeMeta())));
        $result = $this->validate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['is_valid']);
        $this->assertSame('valid_with_warnings', $result['data']['status']);
        $this->assertTrue($result['data']['ready_for_registration']);
        $this->assertSame(
            array('Data completeness', 'INCI validation', 'HS code validation'),
            $result['data']['passed_checks']
        );
        $this->assertArrayHasKey('completeness', $result['data']['validation_results']);
        $this->assertSame('Product validation passed.', $result['message']);
    }

    public function test_missing_required_fields_fail_and_are_named(): void
    {
        $store  = $this->store(array($this->product(4, 'Rose', array('brand' => 'Acme'))));
        $result = $this->validate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['is_valid']);
        $this->assertSame('invalid', $result['data']['status']);
        $this->assertFalse($result['data']['ready_for_registration']);

        $completeness = $result['data']['validation_results']['completeness'];
        $this->assertFalse($completeness['is_complete']);
        $this->assertSame(20.0, $completeness['completion_percentage']);
        $this->assertContains('manufacturer', $completeness['missing_fields']);
        $this->assertContains('Required field missing: Hs code', $result['data']['errors']);
        $this->assertStringContainsString('failed with', $result['message']);
    }

    public function test_recommended_fields_only_warn(): void
    {
        $store  = $this->store(array($this->product(4, 'Rose', $this->completeMeta())));
        $result = $this->validate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertTrue($result['data']['is_valid']);
        $this->assertSame('valid_with_warnings', $result['data']['status']);
        $this->assertContains('Recommended field missing: Barcode', $result['data']['warnings']);
    }

    public function test_an_empty_ingredient_list_is_an_error(): void
    {
        $meta                   = $this->completeMeta();
        $meta['inci_ingredients'] = '';
        $store                  = $this->store(array($this->product(4, 'Rose', $meta)));
        $result                 = $this->validate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertFalse($result['data']['is_valid']);
        $this->assertContains('INCI ingredients list is empty.', $result['data']['errors']);
        $this->assertSame(0, $result['data']['validation_results']['inci']['ingredient_count']);
    }

    public function test_inci_case_and_digits_warn_but_do_not_fail(): void
    {
        $meta                     = $this->completeMeta();
        $meta['inci_ingredients'] = 'aqua, Rosa Damascena, Parfum 2';
        $store                    = $this->store(array($this->product(4, 'Rose', $meta)));
        $result                   = $this->validate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertTrue($result['data']['validation_results']['inci']['is_valid']);
        $this->assertContains('Ingredient should start with capital letter: aqua', $result['data']['warnings']);
        $this->assertContains('INCI names should not contain numbers: Parfum 2', $result['data']['warnings']);
    }

    public function test_a_trailing_comma_does_not_add_an_ingredient(): void
    {
        $meta                     = $this->completeMeta();
        $meta['inci_ingredients'] = 'Aqua, Glycerin, ,';
        $store                    = $this->store(array($this->product(4, 'Rose', $meta)));
        $result                   = $this->validate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $this->assertSame(2, $result['data']['validation_results']['inci']['ingredient_count']);
    }

    public function test_an_hs_code_with_spaces_and_dots_is_accepted(): void
    {
        $meta            = $this->completeMeta();
        $meta['hs_code'] = '3304.99.00';
        $store           = $this->store(array($this->product(4, 'Rose', $meta)));
        $result          = $this->validate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $hs = $result['data']['validation_results']['hs_code'];

        $this->assertTrue($hs['is_valid']);
        $this->assertSame(array(), $hs['warnings']);
        $this->assertSame('3304.99.00', $hs['hs_code']);
    }

    public function test_a_short_hs_code_fails_without_the_chapter_warning(): void
    {
        $meta            = $this->completeMeta();
        $meta['hs_code'] = '3304';
        $store           = $this->store(array($this->product(4, 'Rose', $meta)));
        $result          = $this->validate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $hs = $result['data']['validation_results']['hs_code'];

        $this->assertFalse($hs['is_valid']);
        $this->assertContains('HS code must be 6-10 digits.', $hs['errors']);
        $this->assertSame(array(), $hs['warnings']);
    }

    public function test_a_non_cosmetic_chapter_warns_but_passes(): void
    {
        $meta            = $this->completeMeta();
        $meta['hs_code'] = '481910';
        $store           = $this->store(array($this->product(4, 'Rose', $meta)));
        $result          = $this->validate($store)->execute(array('product_id' => 4), array('user_id' => 7));

        $hs = $result['data']['validation_results']['hs_code'];

        $this->assertTrue($hs['is_valid']);
        $this->assertContains('Cosmetics typically use HS codes starting with 33.', $hs['warnings']);
    }

    public function test_an_explicit_false_disables_a_check_and_readiness_stays_false(): void
    {
        $store  = $this->store(array($this->product(4, 'Rose', $this->completeMeta())));
        $result = $this->validate($store)->execute(
            array('product_id' => 4, 'check_completeness' => false, 'check_inci' => false, 'check_hs_code' => false),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['is_valid']);
        $this->assertSame(array(), $result['data']['validation_results']);
        $this->assertFalse($result['data']['ready_for_registration']);
        $this->assertSame(0, $result['data']['total_checks']);
    }

    public function test_capability_and_an_unknown_product(): void
    {
        $store = $this->store();

        $this->assertSame('read', $this->validate($store)->getRequiredCapability());

        $result = $this->validate($store)->execute(array('product_id' => 99), array('user_id' => 7));
        $this->assertSame('not_found', $result['error']['code']);
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function validate(ContentStoreInterface $store): ValidateRegProductTool
    {
        return new ValidateRegProductTool($this->errors, $store);
    }

    /**
     * A product that passes every check.
     *
     * @return array<string, mixed>
     */
    private function completeMeta(): array
    {
        return array(
            'brand'            => 'Acme',
            'manufacturer'     => 'Acme Works',
            'origin_country'   => 'LK',
            'inci_ingredients' => 'Aqua, Glycerin, Rosa Damascena',
            'hs_code'          => '33049900',
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function product(int $id, string $title, array $meta = array()): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: '',
            status: 'publish',
            type: 'mcp_ai_reg_product',
            authorId: 7,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-02T09:00:00+00:00'),
            meta: $meta,
        );
    }

    /**
     * A read-only store.
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
                return new ContentCollection(array(), 0, 1, $query->perPage, 0);
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
                return isset($this->items[ $id ]);
            }
        };
    }
}
