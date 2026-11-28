<?php

/**
 * Check an HS tariff code — no stored record.
 *
 * Port of `WP_MCP_AI_Tool_Check_HS_Code` (Proposal 164 section 164.151). Format
 * validation, a small chapter/heading table with subcodes, and a product-type
 * match that suggests a heading when the caller says what the product is.
 *
 * ## An invalid code is a verdict, not a failed call
 *
 * WordPress returned `WP_Error( 'wp_mcp_ai_invalid_arguments' )` when the format
 * failed, carrying the same facts in the error data. Two validators in one
 * family therefore disagreed about how to say "no": `validate_reg_product`
 * returned success with `is_valid: false`, this one returned an error. A caller
 * cannot handle both without special-casing the slug.
 *
 * The port returns the verdict in the envelope — `is_valid`, `errors`, and an
 * empty `hs_info` — for the reason §164.150 recorded: a validator reports, and
 * a business outcome is not a transport failure.
 *
 * ## What counts as valid
 *
 * Six to ten digits once spaces and dots are removed, **and** a chapter of 33.
 * The second rule is a scope statement rather than a defect: this tool exists
 * for cosmetics and perfumes, and WordPress treated a non-33 chapter as
 * invalid. It is stated in the description so a caller checking a carton code
 * knows why it fails, and the two failures are reported separately so one
 * mistake does not produce two complaints about the same code.
 *
 * `product_type_match` is `true` when no product type is supplied — nothing to
 * disagree with — which is also what WordPress returned.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Regulatory;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

class CheckHsCodeTool extends RegulatoryTool
{
    /** Heading to description, category and subcodes. */
    private const HEADINGS = array(
        '3303' => array(
            'description' => 'Perfumes and toilet waters',
            'category'    => 'perfumes',
            'subcodes'    => array(),
        ),
        '3304' => array(
            'description' => 'Beauty or makeup preparations and skin care preparations',
            'category'    => 'cosmetics',
            'subcodes'    => array(
                '330410' => 'Lip makeup preparations',
                '330420' => 'Eye makeup preparations',
                '330430' => 'Manicure or pedicure preparations',
                '330491' => 'Powders (pressed or unpressed)',
                '330499' => 'Other beauty/makeup preparations',
            ),
        ),
        '3305' => array(
            'description' => 'Preparations for use on hair',
            'category'    => 'haircare',
            'subcodes'    => array(
                '330510' => 'Shampoos',
                '330520' => 'Preparations for permanent waving or straightening',
                '330530' => 'Hair lacquers',
                '330590' => 'Other hair preparations',
            ),
        ),
        '3306' => array(
            'description' => 'Preparations for oral or dental hygiene',
            'category'    => 'oral_care',
            'subcodes'    => array(),
        ),
        '3307' => array(
            'description' => 'Pre-shave, shaving preparations, deodorants',
            'category'    => 'toiletries',
            'subcodes'    => array(),
        ),
    );

    /** Product-type fragment to the headings it belongs under. */
    private const PRODUCT_TYPES = array(
        'perfume'       => array('3303'),
        'lipstick'      => array('3304'),
        'lip gloss'     => array('3304'),
        'mascara'       => array('3304'),
        'eye shadow'    => array('3304'),
        'foundation'    => array('3304'),
        'nail polish'   => array('3304'),
        'face powder'   => array('3304'),
        'cream'         => array('3304'),
        'lotion'        => array('3304'),
        'serum'         => array('3304'),
        'shampoo'       => array('3305'),
        'conditioner'   => array('3305'),
        'hair oil'      => array('3305'),
        'hair spray'    => array('3305'),
        'toothpaste'    => array('3306'),
        'mouthwash'     => array('3306'),
        'deodorant'     => array('3307'),
        'shaving cream' => array('3307'),
    );

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'check_hs_code';
    }

    public function getName(): string
    {
        return 'Check HS Code';
    }

    public function getDescription(): string
    {
        return 'Checks a cosmetics or perfume HS tariff code: 6-10 digits and a chapter of 33. Returns the heading and subcode meaning, and suggests a heading when product_type does not match.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress declares `edit_posts`; the port gates on `read` because the
        // tool mutates nothing, as section 164.150 decided for the sibling validator.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'hs_code'      => array(
                    'type'        => 'string',
                    'description' => 'The HS code, e.g. 3304.99.00.',
                    'minLength'   => 1,
                    'maxLength'   => 50,
                ),
                'product_type' => array(
                    'type'        => 'string',
                    'description' => 'Product type, e.g. shampoo, to check the heading and suggest one.',
                    'maxLength'   => 100,
                ),
            ),
            'required'             => array('hs_code'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $hsCode = trim($this->stringParam($arguments, 'hs_code'));

        if ('' === $hsCode) {
            return $this->errors->validationFailed(
                'The HS code cannot be checked.',
                array('hs_code' => array('The tariff code to check.'))
            );
        }

        $clean  = str_replace(array(' ', '.'), '', $hsCode);
        $errors = array();

        if (1 !== preg_match('/^\d{6,10}$/', $clean)) {
            $errors[] = 'HS code must be 6-10 digits.';
        } elseif ('33' !== substr($clean, 0, 2)) {
            $errors[] = 'Cosmetics and perfumes typically use HS codes starting with 33.';
        }

        if (array() !== $errors) {
            return $this->success(
                'HS code is not valid.',
                array(
                    'hs_code'            => $hsCode,
                    'is_valid'           => false,
                    'hs_info'            => array(),
                    'product_type_match' => false,
                    'suggestions'        => array(),
                    'errors'             => $errors,
                )
            );
        }

        $info        = $this->info($clean);
        $productType = trim($this->stringParam($arguments, 'product_type'));
        $matches     = true;
        $suggestions = array();

        if ('' !== $productType) {
            $match       = $this->productTypeMatch($clean, $productType);
            $matches     = $match['matches'];
            $suggestions = $match['suggestions'];
        }

        return $this->success(
            'HS code is valid.',
            array(
                'hs_code'            => $hsCode,
                'is_valid'           => true,
                'hs_info'            => $info,
                'product_type_match' => $matches,
                'suggestions'        => $suggestions,
                'errors'             => array(),
            )
        );
    }

    /**
     * Heading and subcode information for a cleaned code.
     *
     * @return array<string, mixed>
     */
    private function info(string $clean): array
    {
        $chapter = substr($clean, 0, 2);
        $heading = substr($clean, 0, 4);

        $info = self::HEADINGS[ $heading ] ?? array();

        if (array() === $info && '33' === $chapter) {
            $info = array(
                'description' => 'Essential oils and resinoids; perfumery, cosmetic or toilet preparations',
                'category'    => 'cosmetics_general',
                'subcodes'    => array(),
            );
        }

        $subcode = substr($clean, 0, 6);

        if (isset($info['subcodes'][ $subcode ])) {
            $info['subcode_description'] = $info['subcodes'][ $subcode ];
        }

        return $info;
    }

    /**
     * Whether the heading matches the product type, with suggestions when not.
     *
     * @return array{matches: bool, suggestions: list<array<string, string>>}
     */
    private function productTypeMatch(string $clean, string $productType): array
    {
        $heading  = substr($clean, 0, 4);
        $lower    = strtolower($productType);
        $expected = array();

        foreach (self::PRODUCT_TYPES as $type => $codes) {
            if (false !== strpos($lower, $type)) {
                $expected = array_merge($expected, $codes);
            }
        }

        $expected = array_values(array_unique($expected));
        $matches  = array() === $expected || in_array($heading, $expected, true);

        $suggestions = array();

        if (! $matches) {
            foreach ($expected as $code) {
                $suggestions[] = array(
                    'hs_code'     => $code . '.00.00',
                    'description' => self::HEADINGS[ $code ]['description'] ?? 'Unknown',
                );
            }
        }

        return array('matches' => $matches, 'suggestions' => $suggestions);
    }
}
