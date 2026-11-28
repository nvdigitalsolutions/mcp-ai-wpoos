<?php

/**
 * Validate an ingredient list against INCI rules — no stored record.
 *
 * Port of `WP_MCP_AI_Tool_Validate_INCI_Ingredients` (Proposal 164 section
 * 164.151). Pure logic over a caller-supplied list: format, a small common-name
 * map, and a small restriction table.
 *
 * ## The restriction check was off unless asked for
 *
 * The schema declares `check_restrictions: true`; the handler read it with
 * `! empty( $arguments[ ... ] )`. A caller who omitted the flag — the call the
 * schema documents — had **no restriction check at all**, and `is_valid` then
 * ignored the restricted table completely, so a list containing Hydroquinone
 * came back `is_valid: true`.
 *
 * For a validator whose stated job includes "restricted substances", that is
 * the same class of fault §164.150 fixed in `validate_reg_product`. The port
 * applies the declared default; only an explicit `false` skips the table.
 *
 * ## An empty list is not a valid list
 *
 * WordPress parsed the input, skipped blanks, and then computed a score over
 * the survivors. An input of `",,,"` produced zero ingredients, a score of 0,
 * and `status: valid` with `is_valid: true`. A compliance check that passes on
 * nothing is worse than one that fails, so zero parsed ingredients is reported
 * as invalid.
 *
 * ## What is checked, and what is deliberately loose
 *
 * - **Format**: initial capital, letters, spaces, hyphens and parentheses only.
 *   Digits make a name invalid, which is why the warning about Roman numerals
 *   is mostly unreachable in practice — kept because WordPress emitted it.
 * - **Restrictions** match by substring, case-insensitively: `Lead` flags
 *   `Lead Acetate`, which is the intent, and also flags an unrelated word that
 *   merely contains it. Loose on purpose — a false positive asks for a human
 *   look, a false negative ships a banned substance.
 * - **`country` does not select a different table.** WordPress recorded it in
 *   the result and never changed the list; the tables here are global, and the
 *   result says which country the check was attributed to.
 *
 * A non-compliant list is a successful call, as in §164.150.
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

class ValidateInciIngredientsTool extends RegulatoryTool
{
    /** INCI name to the name an operator would recognise. */
    private const COMMON_NAMES = array(
        'Aqua'                   => 'Water',
        'Butyrospermum Parkii'   => 'Shea Butter',
        'Cocos Nucifera'         => 'Coconut Oil',
        'Olea Europaea'          => 'Olive Oil',
        'Tocopherol'             => 'Vitamin E',
        'Ascorbic Acid'          => 'Vitamin C',
        'Retinol'                => 'Vitamin A',
        'Glycerin'               => 'Glycerine',
        'Sodium Chloride'        => 'Salt',
        'Parfum'                 => 'Fragrance',
        'Cetearyl Alcohol'       => 'Fatty Alcohol',
        'Sodium Lauryl Sulfate'  => 'SLS',
        'Sodium Laureth Sulfate' => 'SLES',
    );

    /** The restricted table: name fragment to level and reason. */
    private const RESTRICTED = array(
        'Hydroquinone' => array('banned', 'Banned in EU, restricted in many countries'),
        'Mercury'      => array('banned', 'Toxic heavy metal, globally banned'),
        'Lead'         => array('banned', 'Toxic heavy metal, banned in cosmetics'),
        'Formaldehyde' => array('restricted', 'Carcinogenic, restricted in many countries'),
        'Parabens'     => array('restricted', 'Endocrine disruptor, restricted in some countries'),
        'Triclosan'    => array('restricted', 'Antimicrobial resistance concerns'),
    );

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'validate_inci_ingredients';
    }

    public function getName(): string
    {
        return 'Validate INCI Ingredients';
    }

    public function getDescription(): string
    {
        return 'Validates a comma-separated ingredient list against INCI naming rules and a restricted-substance table. A list with no ingredients, a badly formed name or a restricted substance is not valid.';
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
                'ingredients'        => array(
                    'type'        => 'string',
                    'description' => 'Comma-separated ingredient list.',
                    'minLength'   => 1,
                    'maxLength'   => 10000,
                ),
                'country'            => array(
                    'type'        => 'string',
                    'description' => 'Country code recorded on the result. The restriction table is global.',
                    'maxLength'   => 10,
                ),
                'check_restrictions' => array(
                    'type'        => 'boolean',
                    'description' => 'Check the restricted-substance table. Default true.',
                    'default'     => true,
                ),
            ),
            'required'             => array('ingredients'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $raw = $this->stringParam($arguments, 'ingredients');

        if ('' === trim($raw)) {
            return $this->errors->validationFailed(
                'The ingredient list cannot be validated.',
                array('ingredients' => array('A comma-separated ingredient list.'))
            );
        }

        $country       = trim($this->stringParam($arguments, 'country'));
        $checkRestrict = $this->boolParam($arguments, 'check_restrictions', true);

        $results    = array();
        $invalid    = 0;
        $restricted = 0;

        foreach (explode(',', $raw) as $piece) {
            $ingredient = trim($piece);

            if ('' === $ingredient) {
                continue;
            }

            $isValidInci  = $this->isValidInci($ingredient);
            $restriction  = $checkRestrict ? $this->restriction($ingredient, $country) : array('is_restricted' => false, 'info' => array());
            $warnings     = array();

            if (strlen($ingredient) < 3) {
                $warnings[] = 'Ingredient name too short (minimum 3 characters)';
            }

            if (1 === preg_match('/[0-9]/', $ingredient)) {
                $warnings[] = 'INCI names should not contain numbers (use Roman numerals)';
            }

            if (1 === preg_match('/[^a-zA-Z\s\-\(\)]/', $ingredient)) {
                $warnings[] = 'Contains special characters not typically used in INCI names';
            }

            if (! $isValidInci) {
                ++$invalid;
            }

            if ($restriction['is_restricted']) {
                ++$restricted;
            }

            $results[] = array(
                'ingredient'       => $ingredient,
                'is_valid_inci'    => $isValidInci,
                'common_name'      => self::COMMON_NAMES[ $ingredient ] ?? '',
                'is_restricted'    => $restriction['is_restricted'],
                'restriction_info' => $restriction['info'],
                'warnings'         => $warnings,
            );
        }

        $total = count($results);

        if (0 === $total) {
            return $this->success(
                'No ingredients were found in the supplied list.',
                array(
                    'is_valid'               => false,
                    'status'                 => 'invalid',
                    'validation_score'       => 0.0,
                    'total_ingredients'      => 0,
                    'valid_ingredients'      => 0,
                    'invalid_ingredients'    => 0,
                    'restricted_ingredients' => 0,
                    'ingredients'            => array(),
                )
            );
        }

        $validCount = $total - $invalid;
        $score      = round(( $validCount / $total ) * 100, 2);
        $isValid    = 0 === $invalid && 0 === $restricted;
        $status     = $isValid ? 'valid' : ( $score >= 80 ? 'mostly_valid' : 'invalid' );

        return $this->success(
            $isValid
                ? 'All ingredients are valid INCI names and not restricted.'
                : sprintf(
                    '%1$d invalid INCI name(s), %2$d restricted ingredient(s) found.',
                    $invalid,
                    $restricted
                ),
            array(
                'is_valid'               => $isValid,
                'status'                 => $status,
                'validation_score'       => $score,
                'total_ingredients'      => $total,
                'valid_ingredients'      => $validCount,
                'invalid_ingredients'    => $invalid,
                'restricted_ingredients' => $restricted,
                'ingredients'            => $results,
            )
        );
    }

    /**
     * An initial capital and only letters, spaces, hyphens and parentheses.
     */
    private function isValidInci(string $ingredient): bool
    {
        if (1 !== preg_match('/^[A-Z]/', $ingredient)) {
            return false;
        }

        return 1 === preg_match('/^[A-Za-z\s\-\(\)]+$/', $ingredient);
    }

    /**
     * The restriction table lookup, by case-insensitive substring.
     *
     * @return array{is_restricted: bool, info: array<string, string>}
     */
    private function restriction(string $ingredient, string $country): array
    {
        foreach (self::RESTRICTED as $name => $entry) {
            if (false !== stripos($ingredient, $name)) {
                return array(
                    'is_restricted' => true,
                    'info'          => array(
                        'restriction_level' => $entry[0],
                        'reason'            => $entry[1],
                        'country'           => '' !== $country ? $country : 'Global',
                    ),
                );
            }
        }

        return array('is_restricted' => false, 'info' => array());
    }
}
