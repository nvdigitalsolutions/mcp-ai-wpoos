<?php

/**
 * Validate a product's registration readiness — `mcp_ai_reg_product`.
 *
 * Port of `WP_MCP_AI_Tool_Validate_Reg_Product` (Proposal 164 section 164.150).
 * Three checks — completeness, INCI format, HS code — aggregate into one verdict
 * and a `ready_for_registration` flag.
 *
 * ## The declared defaults were not the actual defaults
 *
 * The schema promised `check_completeness`, `check_inci` and `check_hs_code` as
 * `true`; the handler read each with `! empty( $arguments[ ... ] )`. A caller
 * that wrote `validate_reg_product({ product_id })` — the call the schema
 * invites — therefore ran **no checks at all** and received
 * `is_valid: true`. In a compliance toolkit that is the worst possible failure:
 * a product reported valid because nothing looked at it. The port applies the
 * documented defaults, and only an explicit `false` disables a check.
 *
 * `ready_for_registration` follows from the same fault: WordPress required the
 * completeness result to be present, so with completeness off it was always
 * `false`. Here it is true only when the checks ran, passed, and completeness
 * reported complete.
 *
 * ## A negative verdict is a successful call
 *
 * `success` stays `true` when `is_valid` is `false`. A validator's job is to
 * report, and returning a `WP_Error` for "non-compliant" would make every
 * caller treat a business outcome as a failure — the distinction §164.146 drew
 * for expiry flags. The verdict lives in `is_valid` and `status`.
 *
 * ## Three smaller corrections
 *
 * - **The INCI count ignores blank items.** WordPress exploded on commas and
 *   counted the result, so a trailing comma added an ingredient; a blank is
 *   skipped here.
 * - **`target_country` is accepted and unused**, as it was in WordPress — the
 *   INCI checks are format rules and do not vary by country. It is described
 *   that way rather than implying a restriction check that never existed.
 * - **The HS warning is suppressed for a code too short to have a chapter
 *   prefix**, where WordPress warned "typically use 33" about a code it had
 *   already rejected for being too short.
 *
 * Gated on `read`: WordPress declared `edit_posts`, but every branch of this
 * tool reads, and the family's readers ask for `read`.
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
use Nvoos\Core\Domain\Entity\ContentItem;

class ValidateRegProductTool extends RegulatoryTool
{
    /** Fields a product must carry before it can be registered. */
    private const REQUIRED_FIELDS = array(
        'brand',
        'manufacturer',
        'origin_country',
        'inci_ingredients',
        'hs_code',
    );

    /** Fields that should be present but do not block registration. */
    private const RECOMMENDED_FIELDS = array('barcode', 'pack_size', 'allergens');

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'validate_reg_product';
    }

    public function getName(): string
    {
        return 'Validate Regulatory Product';
    }

    public function getDescription(): string
    {
        return 'Validates a product for registration: required-field completeness, INCI ingredient format and HS code format. Returns a verdict, per-check detail, errors and warnings; a non-compliant product is still a successful call.';
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id'         => array(
                    'type'        => 'integer',
                    'description' => 'The product id.',
                    'minimum'     => 1,
                ),
                'check_completeness' => array(
                    'type'        => 'boolean',
                    'description' => 'Check required and recommended fields. Default true.',
                    'default'     => true,
                ),
                'check_inci'         => array(
                    'type'        => 'boolean',
                    'description' => 'Check the INCI ingredient list format. Default true.',
                    'default'     => true,
                ),
                'check_hs_code'      => array(
                    'type'        => 'boolean',
                    'description' => 'Check the HS code format. Default true.',
                    'default'     => true,
                ),
                'target_country'     => array(
                    'type'        => 'string',
                    'description' => 'Accepted for shape compatibility; the checks do not vary by country.',
                    'maxLength'   => 100,
                ),
            ),
            'required'             => array('product_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $productId = max(0, $this->intParam($arguments, 'product_id'));

        if ($productId <= 0) {
            return $this->errors->validationFailed(
                'A product id is required.',
                array('product_id' => array('The id shown by list_reg_products.'))
            );
        }

        $product = $this->content->find($productId);

        if (! $product instanceof ContentItem || self::PRODUCT_TYPE !== $product->type) {
            return $this->errors->notFound('Product not found.');
        }

        $results       = array();
        $errors        = array();
        $warnings      = array();
        $passed        = array();
        $complete      = null;

        if ($this->boolParam($arguments, 'check_completeness', true)) {
            $result                = $this->checkCompleteness($product);
            $results['completeness'] = $result;
            $errors                = array_merge($errors, $result['errors']);
            $warnings              = array_merge($warnings, $result['warnings']);
            $complete              = $result['is_complete'];

            if ($result['is_complete']) {
                $passed[] = 'Data completeness';
            }
        }

        if ($this->boolParam($arguments, 'check_inci', true)) {
            $result         = $this->checkInci($product);
            $results['inci'] = $result;
            $warnings       = array_merge($warnings, $result['warnings']);

            if ($result['is_valid']) {
                $passed[] = 'INCI validation';
            } else {
                $errors = array_merge($errors, $result['errors']);
            }
        }

        if ($this->boolParam($arguments, 'check_hs_code', true)) {
            $result            = $this->checkHsCode($product);
            $results['hs_code'] = $result;
            $warnings          = array_merge($warnings, $result['warnings']);

            if ($result['is_valid']) {
                $passed[] = 'HS code validation';
            } else {
                $errors = array_merge($errors, $result['errors']);
            }
        }

        $isValid     = array() === $errors;
        $hasWarnings = array() !== $warnings;
        $status      = $isValid ? ( $hasWarnings ? 'valid_with_warnings' : 'valid' ) : 'invalid';

        return $this->success(
            $isValid
                ? 'Product validation passed.'
                : sprintf('Product validation failed with %d error(s).', count($errors)),
            array(
                'product_id'             => $product->id,
                'product_title'          => $product->title,
                'is_valid'               => $isValid,
                'status'                 => $status,
                'ready_for_registration' => $isValid && true === $complete,
                'validation_results'     => $results,
                'errors'                 => $errors,
                'warnings'               => $warnings,
                'passed_checks'          => $passed,
                'total_checks'           => count($passed) + count($errors),
            )
        );
    }

    /**
     * Required and recommended fields.
     *
     * @return array<string, mixed>
     */
    private function checkCompleteness(ContentItem $product): array
    {
        $errors    = array();
        $warnings  = array();
        $missing   = array();
        $present   = array();

        foreach (self::REQUIRED_FIELDS as $field) {
            if ('' === $this->text($product->meta[ $field ] ?? null)) {
                $missing[] = $field;
                $errors[]  = sprintf('Required field missing: %s', $this->label($field));
                continue;
            }

            $present[] = $field;
        }

        foreach (self::RECOMMENDED_FIELDS as $field) {
            if ('' === $this->text($product->meta[ $field ] ?? null)) {
                $warnings[] = sprintf('Recommended field missing: %s', $this->label($field));
            }
        }

        $total = count(self::REQUIRED_FIELDS);

        return array(
            'is_complete'           => array() === $missing,
            'completion_percentage' => round(( count($present) / max(1, $total) ) * 100, 2),
            'missing_fields'        => $missing,
            'present_fields'        => $present,
            'errors'                => $errors,
            'warnings'              => $warnings,
        );
    }

    /**
     * INCI format: a non-empty list, initial capitals, and no digits.
     *
     * The digit rule is a warning, not an error, and it is what WordPress
     * checked; a full INCI vocabulary check belongs to
     * `validate_inci_ingredients`.
     *
     * @return array<string, mixed>
     */
    private function checkInci(ContentItem $product): array
    {
        $raw = $this->text($product->meta['inci_ingredients'] ?? null);

        if ('' === $raw) {
            return array(
                'is_valid'         => false,
                'errors'           => array('INCI ingredients list is empty.'),
                'warnings'         => array(),
                'ingredient_count' => 0,
            );
        }

        $ingredients = array();
        $warnings    = array();

        foreach (explode(',', $raw) as $piece) {
            $ingredient = trim($piece);

            if ('' === $ingredient) {
                continue;
            }

            $ingredients[] = $ingredient;

            if (1 !== preg_match('/^[A-Z]/', $ingredient)) {
                $warnings[] = sprintf('Ingredient should start with capital letter: %s', $ingredient);
            }

            if (1 === preg_match('/[0-9]/', $ingredient)) {
                $warnings[] = sprintf('INCI names should not contain numbers: %s', $ingredient);
            }
        }

        if (array() === $ingredients) {
            return array(
                'is_valid'         => false,
                'errors'           => array('INCI ingredients list is empty.'),
                'warnings'         => array(),
                'ingredient_count' => 0,
            );
        }

        return array(
            'is_valid'         => true,
            'errors'           => array(),
            'warnings'         => $warnings,
            'ingredient_count' => count($ingredients),
        );
    }

    /**
     * HS code format: 6–10 digits once spaces and dots are removed.
     *
     * @return array<string, mixed>
     */
    private function checkHsCode(ContentItem $product): array
    {
        $hsCode = $this->text($product->meta['hs_code'] ?? null);

        if ('' === $hsCode) {
            return array(
                'is_valid' => false,
                'hs_code'  => '',
                'errors'   => array('HS code is missing.'),
                'warnings' => array(),
            );
        }

        $clean   = str_replace(array(' ', '.'), '', $hsCode);
        $errors  = array();
        $warnings = array();

        if (1 !== preg_match('/^\d{6,10}$/', $clean)) {
            $errors[] = 'HS code must be 6-10 digits.';
        } elseif ('33' !== substr($clean, 0, 2)) {
            $warnings[] = 'Cosmetics typically use HS codes starting with 33.';
        }

        return array(
            'is_valid' => array() === $errors,
            'hs_code'  => $hsCode,
            'errors'   => $errors,
            'warnings' => $warnings,
        );
    }

    /**
     * A field key as a message names it: `hs_code` → `Hs code`.
     */
    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
