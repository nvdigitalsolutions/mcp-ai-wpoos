<?php

/**
 * List and search CRM companies — `mcp_ai_company`.
 *
 * Ported from `WP_MCP_AI_Tool_Get_Companies`: same nine filters, the same
 * pagination bounds (20 per page by default, 100 at most), alphabetical by
 * name, and the same payload keys. The filter semantics are kept exactly,
 * because a search that quietly widens is worse than one that returns nothing:
 *
 * - `industry`, `city`, `state` and `country` are **substring** matches, as
 *   `WP_Query` treats a `LIKE` compare.
 * - `company_size` and `target_status` are **exact** matches, because they are
 *   closed vocabularies.
 * - `search` is full-text over the title and the description.
 * - All filters are ANDed.
 *
 * `total_companies` is the whole matching set, not the page, and
 * `filters_applied` echoes the non-empty filters so a caller can tell an empty
 * result from a filter it forgot to clear.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;

class GetCompaniesTool extends CrmTool
{
    public const MAX_PER_PAGE = 100;

    /** Filters that match as substrings, mapped to their meta keys. */
    private const LIKE_FILTERS = array(
        'industry' => '_company_industry',
        'city'     => '_company_city',
        'state'    => '_company_state',
        'country'  => '_company_country',
    );

    /** Filters that match exactly, mapped to their meta keys. */
    private const EXACT_FILTERS = array(
        'company_size'  => '_company_size',
        'target_status' => '_company_target_status',
    );

    public function __construct(ErrorFactoryInterface $e, ContentStoreInterface $content)
    {
        parent::__construct($e, $content);
    }

    public function getSlug(): string
    {
        return 'get_companies';
    }

    public function getName(): string
    {
        return 'Get Companies';
    }

    public function getDescription(): string
    {
        return 'Lists CRM companies alphabetically, filtered by a search term, industry, size, target status, city, state or country, with pagination. Filters are ANDed; industry, city, state and country match substrings.';
    }

    public function getRequiredCapability(): string
    {
        // WordPress gates this read on `read`, not `edit_posts`.
        return 'read';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'search'        => array(
                    'type'        => 'string',
                    'description' => 'Search term matched against company name and description.',
                    'maxLength'   => 200,
                ),
                'industry'      => array(
                    'type'        => 'string',
                    'description' => 'Industry substring to match.',
                    'maxLength'   => 120,
                ),
                'company_size'  => array(
                    'type'        => 'string',
                    'description' => 'Exact company size range.',
                    'enum'        => array('1-10', '11-50', '51-200', '201-500', '501-1000', '1001-5000', '5001+'),
                ),
                'target_status' => array(
                    'type'        => 'string',
                    'description' => 'Exact target status.',
                    'enum'        => array('prospect', 'target', 'in_discussion', 'client', 'not_interested'),
                ),
                'city'          => array(
                    'type'        => 'string',
                    'description' => 'City substring to match.',
                    'maxLength'   => 120,
                ),
                'state'         => array(
                    'type'        => 'string',
                    'description' => 'State or province substring to match.',
                    'maxLength'   => 120,
                ),
                'country'       => array(
                    'type'        => 'string',
                    'description' => 'Country substring to match.',
                    'maxLength'   => 120,
                ),
                'per_page'      => array(
                    'type'        => 'integer',
                    'description' => 'Companies per page. Default 20, maximum 100.',
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => self::MAX_PER_PAGE,
                ),
                'page'          => array(
                    'type'        => 'integer',
                    'description' => 'Page number, 1-based.',
                    'default'     => 1,
                    'minimum'     => 1,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $per_page = min(max($this->intParam($arguments, 'per_page', 20), 1), self::MAX_PER_PAGE);
        $page     = max($this->intParam($arguments, 'page', 1), 1);
        $search   = trim($this->stringParam($arguments, 'search'));

        $meta   = array();
        $applied = array();

        foreach (self::LIKE_FILTERS as $argument => $key) {
            $value = trim($this->stringParam($arguments, $argument));

            if ('' === $value) {
                continue;
            }

            $meta[]                = array('key' => $key, 'value' => $value, 'compare' => 'LIKE');
            $applied[$argument]    = $value;
        }

        foreach (self::EXACT_FILTERS as $argument => $key) {
            $value = trim($this->stringParam($arguments, $argument));

            if ('' === $value) {
                continue;
            }

            $meta[]             = array('key' => $key, 'value' => $value, 'compare' => '=');
            $applied[$argument] = $value;
        }

        if ('' !== $search) {
            $applied['search'] = $search;
        }

        try {
            $result = $this->content->query(
                new ContentQuery(
                    types: array(self::COMPANY_TYPE),
                    statuses: array('publish'),
                    search: '' !== $search ? $search : null,
                    metaQuery: $meta,
                    orderBy: 'title',
                    order: 'ASC',
                    page: $page,
                    perPage: $per_page,
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('get_companies_failed', $e->getMessage());
        }

        $companies = array();

        foreach ($result->items as $item) {
            if ($item instanceof ContentItem && self::COMPANY_TYPE === $item->type) {
                $companies[] = $this->companyRow($item);
            }
        }

        return $this->success(
            sprintf('Found %d companies matching your criteria.', $result->total),
            array(
                'companies'       => $companies,
                'total_companies' => $result->total,
                'total_pages'     => $result->totalPages,
                'current_page'    => $page,
                'per_page'        => $per_page,
                'filters_applied' => $applied,
            )
        );
    }
}
