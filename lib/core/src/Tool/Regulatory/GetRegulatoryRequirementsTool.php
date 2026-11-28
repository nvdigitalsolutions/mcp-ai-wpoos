<?php

/**
 * List regulatory requirements.
 *
 * Port of `WP_MCP_AI_Tool_Get_Regulatory_Requirements` (Proposal 164 section
 * 164.162). Reads `mcp_ai_requirement` items for a country, optionally narrowed
 * by authority, kind, product category and the mandatory flag.
 *
 * ## The mandatory filter compared a string against a boolean
 *
 * WordPress added `array( 'key' => 'is_mandatory', 'value' => '1' )` to the meta
 * query. That works only because `update_post_meta` serialises `true` as `1`; a
 * requirement stored as a real boolean, or as `'true'`, or written by an import,
 * matched nothing. The port reads the flag with `flag()` after the query, so the
 * filter means what it says regardless of how the value was stored — and it
 * agrees with the reader `check_product_compliance` already uses.
 *
 * `mandatory_only` was also read with `! empty()`, so the string `'false'` turned
 * the filter **on**. It goes through `boolParam()` here.
 *
 * ## No capability was enforced
 *
 * The handler never read `$context`, like its sibling writer. The port gates on
 * `read`: the tool only reads, and every listing in this family gates on `read`.
 *
 * ## Ordering and paging
 *
 * WordPress ordered by the `requirement_type` meta value, which groups the page
 * by kind. The port sorts by kind and then by id, so a page boundary does not
 * depend on the store's return order, and clamps `per_page` to the 1-100 range
 * the schema declares where WordPress passed `absint` straight to `WP_Query`.
 * `total` is the number that matched, not the page size, and `total_pages` is
 * derived from it.
 *
 * `is_mandatory` is returned as `flag()` reads it, so a stored `'false'` string
 * is reported as false rather than as the truthy string WordPress cast with
 * `(bool)`.
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

class GetRegulatoryRequirementsTool extends RegulatoryTool
{
    /** The largest page this tool will return. */
    protected const MAX_PER_PAGE = 100;

    /** The page size used when none is given. */
    protected const DEFAULT_PER_PAGE = 50;

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'get_regulatory_requirements';
    }

    public function getName(): string
    {
        return 'Get Regulatory Requirements';
    }

    public function getDescription(): string
    {
        return 'Lists the requirements stored for a country, grouped by kind, optionally narrowed by authority, requirement kind, product category and the mandatory flag. The mandatory flag is read from the stored value, so a requirement written as a boolean or as text is matched the same way.';
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
                'country'          => array(
                    'type'        => 'string',
                    'description' => 'Country code to list requirements for.',
                    'minLength'   => 1,
                    'maxLength'   => 10,
                ),
                'authority'        => array(
                    'type'        => 'string',
                    'description' => 'Only requirements from this authority.',
                    'maxLength'   => 100,
                ),
                'requirement_type' => array(
                    'type'        => 'string',
                    'description' => 'Only requirements of this kind.',
                    'enum'        => self::REQUIREMENT_TYPES,
                ),
                'product_category' => array(
                    'type'        => 'string',
                    'description' => 'Only requirements stored against this product category.',
                    'maxLength'   => 100,
                ),
                'mandatory_only'   => array(
                    'type'        => 'boolean',
                    'description' => 'Only requirements the compliance check enforces. Default false.',
                    'default'     => false,
                ),
                'page'             => array(
                    'type'        => 'integer',
                    'description' => 'Page number, 1-based. Default 1.',
                    'minimum'     => 1,
                    'default'     => 1,
                ),
                'per_page'         => array(
                    'type'        => 'integer',
                    'description' => 'Requirements per page. Default 50, maximum 100.',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                    'default'     => self::DEFAULT_PER_PAGE,
                ),
            ),
            'required'             => array('country'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $country    = trim($this->stringParam($arguments, 'country'));
        $authority  = trim($this->stringParam($arguments, 'authority'));
        $type       = trim($this->stringParam($arguments, 'requirement_type'));
        $category   = trim($this->stringParam($arguments, 'product_category'));
        $mandatoryOnly = $this->boolParam($arguments, 'mandatory_only', false);

        $fields = array();

        if ('' === $country) {
            $fields['country'] = array('A country code.');
        }

        if ('' !== $type && ! in_array($type, self::REQUIREMENT_TYPES, true)) {
            $fields['requirement_type'] = array(
                'One of: ' . implode(', ', self::REQUIREMENT_TYPES) . '.',
            );
        }

        if (array() !== $fields) {
            return $this->errors->validationFailed('The requirements cannot be read.', $fields);
        }

        $page    = max(1, $this->intParam($arguments, 'page', 1));
        $perPage = $this->intParam($arguments, 'per_page', self::DEFAULT_PER_PAGE);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $metaQuery = array(
            'relation' => 'AND',
            array('key' => 'country', 'value' => $country, 'compare' => '='),
        );

        if ('' !== $authority) {
            $metaQuery[] = array('key' => 'authority', 'value' => $authority, 'compare' => '=');
        }

        if ('' !== $type) {
            $metaQuery[] = array('key' => 'requirement_type', 'value' => $type, 'compare' => '=');
        }

        if ('' !== $category) {
            $metaQuery[] = array('key' => 'product_category', 'value' => $category, 'compare' => '=');
        }

        $matched = array();

        foreach ($this->collectContent(array(self::REQUIREMENT_TYPE), $metaQuery) as $item) {
            // Read here rather than in the query: WordPress asked the store for
            // the string '1', which only matched because true was serialised
            // that way. flag() agrees with check_product_compliance.
            if ($mandatoryOnly && ! $this->flag($item->meta['is_mandatory'] ?? null)) {
                continue;
            }

            $matched[] = $item;
        }

        usort(
            $matched,
            static function (ContentItem $a, ContentItem $b): int {
                $left  = (string) ( $a->meta['requirement_type'] ?? '' );
                $right = (string) ( $b->meta['requirement_type'] ?? '' );

                if ($left !== $right) {
                    return $left <=> $right;
                }

                return $a->id <=> $b->id;
            }
        );

        $offset = ( $page - 1 ) * $perPage;
        $rows   = array();

        foreach (array_slice($matched, $offset, $perPage) as $item) {
            $rows[] = $this->requirementRow($item);
        }

        return $this->success(
            sprintf('Retrieved %d regulatory requirement(s).', count($rows)),
            array(
                'country'      => $country,
                'requirements' => $rows,
                'total'        => count($matched),
                'page'         => $page,
                'per_page'     => $perPage,
                'total_pages'  => (int) ceil(count($matched) / $perPage),
                'filters'      => array(
                    'authority'        => $authority,
                    'requirement_type' => $type,
                    'product_category' => $category,
                    'mandatory_only'   => $mandatoryOnly,
                ),
            )
        );
    }

    /**
     * One returned requirement row.
     *
     * @return array<string, mixed>
     */
    private function requirementRow(ContentItem $item): array
    {
        $meta = $item->meta;

        return array(
            'requirement_id'   => $item->id,
            'title'            => $item->title,
            'description'      => $item->content,
            'country'          => $this->text($meta['country'] ?? null),
            'authority'        => $this->text($meta['authority'] ?? null),
            'requirement_type' => $this->text($meta['requirement_type'] ?? null),
            'is_mandatory'     => $this->flag($meta['is_mandatory'] ?? null),
            'product_category' => $this->text($meta['product_category'] ?? null),
            'effective_date'   => $this->text($meta['effective_date'] ?? null),
            'reference_url'    => $this->text($meta['reference_url'] ?? null),
        );
    }
}
