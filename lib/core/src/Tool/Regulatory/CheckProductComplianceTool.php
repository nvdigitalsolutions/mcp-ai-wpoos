<?php

/**
 * Check a product against a country's regulatory requirements.
 *
 * Port of `WP_MCP_AI_Tool_Check_Product_Compliance` (Proposal 164 section
 * 164.152). Reads the country's requirement records, the product's documents
 * and the product's ingredient list, then reports a compliance score.
 *
 * ## The check flags were off, for the third time
 *
 * The schema declares `check_documents` and `check_ingredients` as `true`. The
 * handler read each with `! empty( $arguments[ ... ] )`. A bare
 * `check_product_compliance({ product_id, country })` therefore ran **no
 * checks**, computed `total_checks: 0`, `compliance_score: 0`, and then set
 * `is_compliant: true` — because compliance was defined as "no issues were
 * found", and nothing had looked. The response contradicted itself: a zero
 * score beside a `compliant` status.
 *
 * The port applies the declared defaults. Only an explicit `false` disables a
 * check, and when no check runs the response says so in `checks_ran` and in the
 * message rather than claiming compliance on the strength of silence.
 *
 * ## `is_mandatory` composes with the writer into a no-op
 *
 * `add_regulatory_requirement` documents `is_mandatory` as "default: true" and
 * stores `! empty( $arguments['is_mandatory'] )`, which is `false` when the
 * argument is omitted. This tool only checks mandatory requirements. A
 * requirement created exactly as its own schema documents is therefore
 * non-mandatory and is skipped by the only tool that enforces requirements.
 *
 * The reader stays faithful — `is_mandatory` follows the stored value — because
 * the writer is the defect, and a reader that guessed would make a record
 * created with an explicit `false` indistinguishable from one created with no
 * argument. The writer's default is recorded here so the requirements slice
 * fixes it in one place.
 *
 * ## An unknown ingredient list is not a pass
 *
 * WordPress checked `$req['is_mandatory'] && ! empty( $inci_ingredients )`. With
 * no ingredient list the requirement landed in neither `passed` nor `issues`,
 * so it vanished from the counts and the product stayed compliant. A compliance
 * report that cannot see the ingredients is not evidence that no restricted
 * substance is present, so the port reports an issue of severity `warning`
 * naming the requirement it could not verify.
 *
 * ## Document matching is a substring, in both directions
 *
 * A requirement is met when its title and a document type are substrings of one
 * another, case-insensitively. That is how WordPress inferred the link, since
 * no explicit key joins the two, and it is kept: the alternative would be a
 * mapping table this port would have to invent. It is loose — a document type
 * of `c` satisfies every requirement — so it is stated here rather than
 * tightened silently.
 *
 * A non-compliant product is a successful call, as in sections 164.150 and
 * 164.151.
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

class CheckProductComplianceTool extends RegulatoryTool
{
    /** The requirement types this tool acts on. */
    private const DOCUMENT_REQUIREMENT = 'document';

    private const INGREDIENT_REQUIREMENT = 'ingredient_restriction';

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'check_product_compliance';
    }

    public function getName(): string
    {
        return 'Check Product Compliance';
    }

    public function getDescription(): string
    {
        return 'Checks a product against the regulatory requirements configured for a country: required documents, and restricted ingredients in the product ingredient list. Both checks run unless an explicit false disables them; checks_ran reports whether anything was actually checked.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress declares `edit_posts`; the port gates on `read` because the
        // tool mutates nothing, as section 164.150 decided for the family.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'product_id'        => array(
                    'type'        => 'integer',
                    'description' => 'The product id.',
                    'minimum'     => 1,
                ),
                'country'           => array(
                    'type'        => 'string',
                    'description' => 'The country code whose requirements apply, e.g. LK.',
                    'minLength'   => 1,
                    'maxLength'   => 10,
                ),
                'check_documents'   => array(
                    'type'        => 'boolean',
                    'description' => 'Check document requirements. Default true.',
                    'default'     => true,
                ),
                'check_ingredients' => array(
                    'type'        => 'boolean',
                    'description' => 'Check ingredient restrictions. Default true.',
                    'default'     => true,
                ),
            ),
            'required'             => array('product_id', 'country'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $productId = $this->intValue($arguments['product_id'] ?? null);
        $country   = trim($this->stringParam($arguments, 'country'));

        $fields = array();

        if ($productId < 1) {
            $fields['product_id'] = array('A product id of at least 1.');
        }

        if ('' === $country) {
            $fields['country'] = array('The country code whose requirements apply.');
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The compliance check cannot run.', $fields);
        }

        $product = $this->content->find($productId);

        if (! $product instanceof ContentItem || self::PRODUCT_TYPE !== $product->type) {
            return $this->errors->notFound('Product not found.');
        }

        $checkDocuments   = $this->boolParam($arguments, 'check_documents', true);
        $checkIngredients = $this->boolParam($arguments, 'check_ingredients', true);

        $category     = $this->productCategory($productId);
        $requirements = $this->requirements($country, $category);

        $issues = array();
        $passed = array();
        $skipped = array();

        if ($checkDocuments) {
            $result = $this->documentCompliance($productId, $requirements);
            $issues = array_merge($issues, $result['issues']);
            $passed = array_merge($passed, $result['passed']);
        } else {
            $skipped[] = 'documents';
        }

        if ($checkIngredients) {
            $result = $this->ingredientCompliance($product, $requirements);
            $issues = array_merge($issues, $result['issues']);
            $passed = array_merge($passed, $result['passed']);
        } else {
            $skipped[] = 'ingredients';
        }

        $totalChecks = count($issues) + count($passed);
        $score       = $totalChecks > 0 ? round(( count($passed) / $totalChecks ) * 100, 2) : 0.0;
        $isCompliant = array() === $issues;
        $status      = $isCompliant
            ? 'compliant'
            : ( $score >= 50 ? 'partially_compliant' : 'non_compliant' );

        // A requirement evaluated is what makes the verdict evidence. The score
        // alone cannot carry that: WordPress's ingredient check records a
        // synthetic pass when a country configures no restrictions, so
        // `total_checks` is 1 on a country with nothing to check.
        $anyEnabled = $checkDocuments || $checkIngredients;
        $checksRan  = $anyEnabled && array() !== $requirements;

        return $this->success(
            $this->message($isCompliant, $anyEnabled, $checksRan, count($issues), $country),
            array(
                'product_id'          => $productId,
                'country'             => $country,
                'is_compliant'        => $isCompliant,
                'status'              => $status,
                'compliance_score'    => $score,
                'total_checks'        => $totalChecks,
                'checks_ran'          => $checksRan,
                'checks_skipped'      => $skipped,
                'requirements_found'  => count($requirements),
                'passed_checks'       => count($passed),
                'failed_checks'       => count($issues),
                'compliance_issues'   => $issues,
                'passed_requirements' => $passed,
            )
        );
    }

    /**
     * What the caller should understand from the numbers.
     *
     * A compliant verdict with no check behind it is the one sentence this tool
     * must never say quietly, so it names the reason instead.
     */
    private function message(
        bool $isCompliant,
        bool $anyEnabled,
        bool $checksRan,
        int $issueCount,
        string $country
    ): string {
        if (! $isCompliant) {
            return sprintf(
                'Product has %d compliance issue(s) that must be addressed.',
                $issueCount
            );
        }

        if (! $anyEnabled) {
            return 'No compliance checks ran: both check types were disabled.';
        }

        if ($checksRan) {
            return 'Product is compliant with all regulatory requirements.';
        }

        return sprintf(
            'No compliance checks ran: no requirements are configured for %s.',
            $country
        );
    }

    /**
     * The product's first category term, or an empty string.
     */
    private function productCategory(int $productId): string
    {
        try {
            $terms = $this->content->getTaxonomyTerms($productId);
        } catch (\Throwable $e) {
            return '';
        }

        $categories = $this->termNames($terms, self::CATEGORY_TAX);

        return array() !== $categories ? $categories[0] : '';
    }

    /**
     * The requirements that apply to one country and product category.
     *
     * A requirement with no `product_category` applies to every category, which
     * is why the query asks for the matching category *or* no category at all.
     *
     * @return list<array{id: int, title: string, requirement_type: string, is_mandatory: bool}>
     */
    private function requirements(string $country, string $category): array
    {
        $metaQuery = array('relation' => 'AND', array('key' => 'country', 'value' => $country, 'compare' => '='));

        if ('' !== $category) {
            $metaQuery[] = array(
                'relation' => 'OR',
                array('key' => 'product_category', 'value' => $category, 'compare' => '='),
                array('key' => 'product_category', 'compare' => 'NOT EXISTS'),
            );
        }

        $rows = array();

        foreach ($this->collectContent(array(self::REQUIREMENT_TYPE), $metaQuery) as $item) {
            $rows[] = array(
                'id'               => $item->id,
                'title'            => $item->title,
                'requirement_type' => $this->text($item->meta['requirement_type'] ?? null),
                'is_mandatory'     => $this->flag($item->meta['is_mandatory'] ?? null),
            );
        }

        return $rows;
    }

    /**
     * Document requirements against the documents attached to the product.
     *
     * @param list<array{id: int, title: string, requirement_type: string, is_mandatory: bool}> $requirements
     *
     * @return array{issues: list<array<string, mixed>>, passed: list<array<string, mixed>>}
     */
    private function documentCompliance(int $productId, array $requirements): array
    {
        $wanted = array();

        foreach ($requirements as $requirement) {
            if (self::DOCUMENT_REQUIREMENT === $requirement['requirement_type']) {
                $wanted[] = $requirement;
            }
        }

        if (array() === $wanted) {
            return array('issues' => array(), 'passed' => array());
        }

        $types = $this->documentTypes(
            array(array('key' => 'product_id', 'value' => $productId, 'compare' => '='))
        );

        $issues = array();
        $passed = array();

        foreach ($wanted as $requirement) {
            if (! $requirement['is_mandatory']) {
                continue;
            }

            if ($this->matchesAny($requirement['title'], $types)) {
                $passed[] = array(
                    'requirement' => $requirement['title'],
                    'type'        => 'document',
                );

                continue;
            }

            $issues[] = array(
                'requirement' => $requirement['title'],
                'type'        => 'document',
                'severity'    => 'critical',
                'message'     => sprintf('Missing required document: %s', $requirement['title']),
            );
        }

        return array('issues' => $issues, 'passed' => $passed);
    }

    /**
     * Restricted-ingredient requirements against the product's ingredient list.
     *
     * @param list<array{id: int, title: string, requirement_type: string, is_mandatory: bool}> $requirements
     *
     * @return array{issues: list<array<string, mixed>>, passed: list<array<string, mixed>>}
     */
    private function ingredientCompliance(ContentItem $product, array $requirements): array
    {
        $wanted = array();

        foreach ($requirements as $requirement) {
            if (self::INGREDIENT_REQUIREMENT === $requirement['requirement_type']) {
                $wanted[] = $requirement;
            }
        }

        if (array() === $wanted) {
            return array(
                'issues' => array(),
                'passed' => array(
                    array(
                        'requirement' => 'No ingredient restrictions',
                        'type'        => 'ingredient',
                    ),
                ),
            );
        }

        $ingredients = $this->text($product->meta['inci_ingredients'] ?? null);
        $issues      = array();
        $passed      = array();

        foreach ($wanted as $requirement) {
            if (! $requirement['is_mandatory']) {
                continue;
            }

            if ('' === $ingredients) {
                $issues[] = array(
                    'requirement' => $requirement['title'],
                    'type'        => 'ingredient_restriction',
                    'severity'    => 'warning',
                    'message'     => sprintf(
                        'Cannot verify restriction: the product has no INCI ingredient list (%s).',
                        $requirement['title']
                    ),
                );

                continue;
            }

            if (false !== stripos($ingredients, $requirement['title'])) {
                $issues[] = array(
                    'requirement' => $requirement['title'],
                    'type'        => 'ingredient_restriction',
                    'severity'    => 'critical',
                    'message'     => sprintf(
                        'Product contains restricted ingredient: %s',
                        $requirement['title']
                    ),
                );

                continue;
            }

            $passed[] = array(
                'requirement' => $requirement['title'],
                'type'        => 'ingredient_restriction',
            );
        }

        return array('issues' => $issues, 'passed' => $passed);
    }

    /**
     * The document types attached to a product or registration.
     *
     * A document's type is the meta value or the doc-type term, whichever the
     * row reports, as in section 164.148.
     *
     * @param array<int|string, mixed> $metaQuery
     *
     * @return list<string>
     */
    private function documentTypes(array $metaQuery): array
    {
        $types = array();

        foreach ($this->collectContent(array(self::DOCUMENT_TYPE), $metaQuery) as $item) {
            $type = $this->text($item->meta['document_type'] ?? null);

            try {
                $terms = $this->termNames($this->content->getTaxonomyTerms($item->id), self::DOC_TYPE_TAX);
            } catch (\Throwable $e) {
                $terms = array();
            }

            if (array() !== $terms) {
                $type = $terms[0];
            }

            if ('' !== $type) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Whether a requirement title and any document type contain one another.
     *
     * @param list<string> $types
     */
    private function matchesAny(string $title, array $types): bool
    {
        foreach ($types as $type) {
            if (false !== stripos($title, $type) || false !== stripos($type, $title)) {
                return true;
            }
        }

        return false;
    }
}
