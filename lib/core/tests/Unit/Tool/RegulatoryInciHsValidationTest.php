<?php

/**
 * The eighth regulatory-registration slice: INCI and HS code validation.
 *
 * Proposal 164 section 164.151. What is pinned: the restriction table is on
 * unless explicitly disabled, so the documented call actually checks; an empty
 * ingredient list is not a valid one; an invalid HS code is a verdict in the
 * envelope rather than a transport error, and one mistake produces one error;
 * and the chapter-33 rule is reported as a scope statement.
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
use Nvoos\Core\Tool\Regulatory\CheckHsCodeTool;
use Nvoos\Core\Tool\Regulatory\ValidateInciIngredientsTool;
use PHPUnit\Framework\TestCase;

final class RegulatoryInciHsValidationTest extends TestCase
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

    // ─── INCI: the restriction table ─────────────────────────────────────

    public function test_a_bare_call_checks_the_restriction_table(): void
    {
        $result = $this->inci()->execute(
            array('ingredients' => 'Aqua, Hydroquinone'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['is_valid']);
        $this->assertSame(1, $result['data']['restricted_ingredients']);
        $this->assertSame('mostly_valid', $result['data']['status']);
        $this->assertTrue($result['data']['ingredients'][1]['is_restricted']);
        $this->assertSame(
            'banned',
            $result['data']['ingredients'][1]['restriction_info']['restriction_level']
        );
    }

    public function test_an_explicit_false_is_the_only_way_to_skip_restrictions(): void
    {
        $result = $this->inci()->execute(
            array('ingredients' => 'Aqua, Hydroquinone', 'check_restrictions' => false),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['is_valid']);
        $this->assertSame('valid', $result['data']['status']);
        $this->assertSame(0, $result['data']['restricted_ingredients']);
        $this->assertSame(array(), $result['data']['ingredients'][1]['restriction_info']);
    }

    public function test_a_restricted_substance_matches_regardless_of_case(): void
    {
        $result = $this->inci()->execute(
            array('ingredients' => 'LEAD ACETATE'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['ingredients'][0]['is_valid_inci']);
        $this->assertTrue($result['data']['ingredients'][0]['is_restricted']);
        $this->assertSame('banned', $result['data']['ingredients'][0]['restriction_info']['restriction_level']);
    }

    public function test_the_country_is_recorded_and_is_global_by_default(): void
    {
        $withCountry = $this->inci()->execute(
            array('ingredients' => 'Hydroquinone', 'country' => 'LK'),
            array('user_id' => 7)
        );

        $withoutCountry = $this->inci()->execute(
            array('ingredients' => 'Hydroquinone'),
            array('user_id' => 7)
        );

        $this->assertSame('LK', $withCountry['data']['ingredients'][0]['restriction_info']['country']);
        $this->assertSame('Global', $withoutCountry['data']['ingredients'][0]['restriction_info']['country']);
    }

    public function test_the_restricted_level_is_carried_through(): void
    {
        $result = $this->inci()->execute(
            array('ingredients' => 'Parabens'),
            array('user_id' => 7)
        );

        $this->assertSame(
            'restricted',
            $result['data']['ingredients'][0]['restriction_info']['restriction_level']
        );
    }

    // ─── INCI: the empty list ────────────────────────────────────────────

    public function test_an_empty_list_is_not_a_valid_list(): void
    {
        $result = $this->inci()->execute(
            array('ingredients' => ',,,'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['is_valid']);
        $this->assertSame('invalid', $result['data']['status']);
        $this->assertSame(0, $result['data']['total_ingredients']);
        $this->assertSame(array(), $result['data']['ingredients']);
    }

    public function test_a_blank_list_is_a_validation_failure(): void
    {
        $result = $this->inci()->execute(array('ingredients' => '   '), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('ingredients', $result['error']['data']);
    }

    // ─── INCI: naming rules ──────────────────────────────────────────────

    public function test_a_clean_list_is_valid_and_resolves_common_names(): void
    {
        $result = $this->inci()->execute(
            array('ingredients' => 'Aqua, Glycerin, Parfum'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['is_valid']);
        $this->assertSame(100.0, $result['data']['validation_score']);
        $this->assertSame('Water', $result['data']['ingredients'][0]['common_name']);
        $this->assertSame('Glycerine', $result['data']['ingredients'][1]['common_name']);
        $this->assertSame('Fragrance', $result['data']['ingredients'][2]['common_name']);
        $this->assertSame('', $this->inci()->execute(
            array('ingredients' => 'Rosa Damascena'),
            array('user_id' => 7)
        )['data']['ingredients'][0]['common_name']);
    }

    public function test_a_lowercase_name_is_invalid(): void
    {
        $result = $this->inci()->execute(array('ingredients' => 'aqua'), array('user_id' => 7));

        $this->assertFalse($result['data']['ingredients'][0]['is_valid_inci']);
        $this->assertFalse($result['data']['is_valid']);
        $this->assertSame(1, $result['data']['invalid_ingredients']);
        $this->assertSame(0.0, $result['data']['validation_score']);
        $this->assertSame('invalid', $result['data']['status']);
    }

    public function test_digits_break_the_format_and_warn(): void
    {
        $result = $this->inci()->execute(array('ingredients' => 'Parfum 2'), array('user_id' => 7));

        $ingredient = $result['data']['ingredients'][0];

        $this->assertFalse($ingredient['is_valid_inci']);
        $this->assertContains(
            'INCI names should not contain numbers (use Roman numerals)',
            $ingredient['warnings']
        );
    }

    public function test_a_two_letter_name_is_valid_but_warns(): void
    {
        $result = $this->inci()->execute(array('ingredients' => 'Al'), array('user_id' => 7));

        $ingredient = $result['data']['ingredients'][0];

        $this->assertTrue($ingredient['is_valid_inci']);
        $this->assertContains('Ingredient name too short (minimum 3 characters)', $ingredient['warnings']);
    }

    public function test_an_eighty_percent_score_is_mostly_valid_but_not_valid(): void
    {
        $result = $this->inci()->execute(
            array('ingredients' => 'Aqua, Glycerin, Parfum, Retinol, aqua'),
            array('user_id' => 7)
        );

        $this->assertSame(5, $result['data']['total_ingredients']);
        $this->assertSame(80.0, $result['data']['validation_score']);
        $this->assertSame('mostly_valid', $result['data']['status']);
        $this->assertFalse($result['data']['is_valid']);
    }

    public function test_the_inci_capability_is_read(): void
    {
        $this->assertSame('read', $this->inci()->getRequiredCapability());
    }

    // ─── HS code: the verdict envelope ───────────────────────────────────

    public function test_a_valid_code_returns_its_heading_and_subcode(): void
    {
        $result = $this->hs()->execute(array('hs_code' => '33049900'), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['is_valid']);
        $this->assertSame(array(), $result['data']['errors']);
        $this->assertSame('cosmetics', $result['data']['hs_info']['category']);
        $this->assertSame(
            'Other beauty/makeup preparations',
            $result['data']['hs_info']['subcode_description']
        );
        $this->assertTrue($result['data']['product_type_match']);
        $this->assertSame(array(), $result['data']['suggestions']);
    }

    public function test_a_dotted_code_is_accepted_and_echoed_as_supplied(): void
    {
        $result = $this->hs()->execute(array('hs_code' => '3304.99.00'), array('user_id' => 7));

        $this->assertTrue($result['data']['is_valid']);
        $this->assertSame('3304.99.00', $result['data']['hs_code']);
    }

    public function test_a_six_digit_subcode_is_described(): void
    {
        $result = $this->hs()->execute(array('hs_code' => '330410'), array('user_id' => 7));

        $this->assertSame('Lip makeup preparations', $result['data']['hs_info']['subcode_description']);
    }

    public function test_an_unknown_heading_in_chapter_33_falls_back_to_the_chapter(): void
    {
        $result = $this->hs()->execute(array('hs_code' => '339900'), array('user_id' => 7));

        $this->assertTrue($result['data']['is_valid']);
        $this->assertSame('cosmetics_general', $result['data']['hs_info']['category']);
    }

    public function test_a_short_code_is_a_verdict_with_a_single_error(): void
    {
        $result = $this->hs()->execute(array('hs_code' => '3304'), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['is_valid']);
        $this->assertSame(
            array('HS code must be 6-10 digits.'),
            $result['data']['errors']
        );
        $this->assertSame(array(), $result['data']['hs_info']);
        $this->assertFalse($result['data']['product_type_match']);
    }

    public function test_a_non_cosmetic_chapter_is_reported_as_out_of_scope(): void
    {
        $result = $this->hs()->execute(array('hs_code' => '481910'), array('user_id' => 7));

        $this->assertFalse($result['data']['is_valid']);
        $this->assertSame(
            array('Cosmetics and perfumes typically use HS codes starting with 33.'),
            $result['data']['errors']
        );
    }

    public function test_a_missing_hs_code_is_a_validation_failure(): void
    {
        $result = $this->hs()->execute(array(), array('user_id' => 7));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('hs_code', $result['error']['data']);
    }

    // ─── HS code: the product type ───────────────────────────────────────

    public function test_a_matching_product_type_reports_no_suggestions(): void
    {
        $result = $this->hs()->execute(
            array('hs_code' => '330510', 'product_type' => 'shampoo'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['product_type_match']);
        $this->assertSame(array(), $result['data']['suggestions']);
    }

    public function test_a_mismatched_product_type_suggests_the_right_heading(): void
    {
        $result = $this->hs()->execute(
            array('hs_code' => '33049900', 'product_type' => 'shampoo'),
            array('user_id' => 7)
        );

        $this->assertFalse($result['data']['product_type_match']);
        $this->assertSame(
            array(
                array(
                    'hs_code'     => '3305.00.00',
                    'description' => 'Preparations for use on hair',
                ),
            ),
            $result['data']['suggestions']
        );
    }

    public function test_an_unknown_product_type_has_nothing_to_disagree_with(): void
    {
        $result = $this->hs()->execute(
            array('hs_code' => '33049900', 'product_type' => 'ham sandwich'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['data']['product_type_match']);
        $this->assertSame(array(), $result['data']['suggestions']);
    }

    public function test_the_hs_capability_is_read(): void
    {
        $this->assertSame('read', $this->hs()->getRequiredCapability());
    }

    // ─── Fixtures ────────────────────────────────────────────────────────

    private function inci(): ValidateInciIngredientsTool
    {
        return new ValidateInciIngredientsTool($this->errors, $this->store());
    }

    private function hs(): CheckHsCodeTool
    {
        return new CheckHsCodeTool($this->errors, $this->store());
    }

    /**
     * A read-only store: both tools are pure logic over their arguments.
     */
    private function store(): ContentStoreInterface
    {
        return new class implements ContentStoreInterface {
            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                return null;
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
                return array();
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
                return false;
            }
        };
    }
}
