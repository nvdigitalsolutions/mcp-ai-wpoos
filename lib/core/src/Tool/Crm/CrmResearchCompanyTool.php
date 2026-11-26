<?php

/**
 * Research a company through the web-search tool.
 *
 * Port of `WP_MCP_AI_Tool_Research_Company`. The WordPress tool constructs the
 * base web-search tool, runs one query assembled from the arguments, and
 * structures the hits into `research_data`. The port does the same through the
 * core `WebSearchTool`, injected rather than constructed so the host decides
 * which search provider answers.
 *
 * ## Three measured differences from the original
 *
 * - **The result-count argument now arrives.** WordPress passes
 *   `num_results => 5`; the web-search tool it calls reads `max_results` and
 *   has no `num_results` parameter, so the request was silently ignored and the
 *   provider used its own default — which is also five, so the behaviour
 *   matched by luck. The port passes `count`, the core tool's real parameter,
 *   and the intent reaches the callee.
 * - **`summary` and `sources` are always empty in WordPress too.** The
 *   WordPress web-search tool returns `results` and nothing else, so
 *   `structure_research_data()`'s `summary` and `sources` keys are constants
 *   there. The port keeps the keys for shape compatibility and says so, rather
 *   than pretending a summariser exists.
 * - **Result items are passed through, not reshaped.** WordPress hits carry
 *   `title` / `url` / `snippet` / `source` / `type`; the core's carry `title` /
 *   `url` / `description`. This tool counts the hits and never reads a field,
 *   so the port forwards whatever the search tool produced instead of inventing
 *   `source` and `type` values.
 *
 * ## Capability
 *
 * The WordPress tool declares `edit_posts` and then re-checks `read` inside
 * `execute()`. The declared capability is the effective one — the registry
 * checks it first — so `getRequiredCapability()` keeps `edit_posts` and the
 * `read` check is kept in place, where a reader of `execute()` expects it.
 *
 * Two host concerns have no core equivalent and are the host's to enforce:
 * WordPress also refuses a user who is not a member of the current site in a
 * multisite network, and fires `do_action( 'wp_mcp_ai_company_researched' )`
 * after a successful run. Neither exists here; a host that needs the hook wraps
 * the tool.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Crm;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;
use Nvoos\Core\Tool\WebSearchTool;

class CrmResearchCompanyTool extends AbstractTool
{
    /** The focus values the schema offers. Not enforced in `execute()`. */
    public const FOCUSES = array('general', 'target_fit', 'industry_analysis', 'competition');

    /** Hits requested from the web-search tool. */
    public const SEARCH_RESULTS = 5;

    /** The WordPress guidance returned with each focus. */
    private const GUIDANCE = array(
        'target_fit'        => 'Review company size, revenue, and business model to determine if they match your ideal customer profile. Look for indicators that they might need your services.',
        'industry_analysis' => 'Analyze industry trends, challenges, and best practices. Use this information to position your services effectively and identify pain points.',
        'competition'       => 'Understand competitive landscape to identify differentiation opportunities and potential partnership possibilities.',
        'general'           => 'Review the company overview, website, and contact information. Extract key details to populate the CRM record.',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly WebSearchTool $search,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'research_company';
    }

    public function getName(): string
    {
        return 'Research Company';
    }

    public function getDescription(): string
    {
        return 'Research a company using AI-powered web search. Gets company information, industry insights, market position, and identifies if they are a good target for your services. Returns structured data ready for CRM import.';
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'company_name'    => array(
                    'type'        => 'string',
                    'description' => 'Name of the company to research.',
                ),
                'industry'        => array(
                    'type'        => 'string',
                    'description' => 'Industry sector (helps narrow search results).',
                ),
                'location'        => array(
                    'type'        => 'string',
                    'description' => 'Company location (city, state, or country) to help identify the right company.',
                ),
                'research_focus'  => array(
                    'type'        => 'string',
                    'description' => 'What to focus the research on: general (company overview), target_fit (evaluate as prospect), industry_analysis (industry trends), or competition (competitive positioning).',
                    'enum'        => self::FOCUSES,
                    'default'     => 'general',
                ),
                'service_context' => array(
                    'type'        => 'string',
                    'description' => 'Description of your services to evaluate target fit (e.g., "WordPress development agency", "B2B SaaS consulting").',
                ),
            ),
            'required'             => array('company_name'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        // The WordPress tool re-checks `read` inside execute() although it
        // declares `edit_posts`; the declared capability is the gate.
        $this->authorize($context, 'read');

        if (empty($arguments['company_name'])) {
            return $this->errors->validationFailed(
                'Company name is required.',
                array('company_name' => array('Give the company to research.')),
            );
        }

        $companyName = Text::singleLine($this->scalar($arguments, 'company_name'));

        // WordPress would carry a sanitised-empty name into the query. The
        // schema requires a subject, so the port refuses rather than research
        // nothing and return a report about "".
        if ('' === $companyName) {
            return $this->errors->validationFailed(
                'Company name is required.',
                array('company_name' => array('Give the company to research.')),
            );
        }

        $industry       = Text::singleLine($this->scalar($arguments, 'industry'));
        $location       = Text::singleLine($this->scalar($arguments, 'location'));
        $serviceContext = Text::singleLine($this->scalar($arguments, 'service_context'));
        $focus          = isset($arguments['research_focus'])
            ? Text::singleLine($this->scalar($arguments, 'research_focus'))
            : 'general';

        $searchQuery = $this->buildSearchQuery($companyName, $industry, $location, $focus, $serviceContext);

        $result = $this->search->execute(
            array(
                'query' => $searchQuery,
                'count' => self::SEARCH_RESULTS,
            ),
            $context
        );

        if ($this->errors->isError($result)) {
            $normalised = $this->errors->normalize($result);

            return $this->errors->create(
                'search_failed',
                'Web search failed: ' . (string) ( $normalised['message'] ?? 'unknown error' )
            );
        }

        $researchData = $this->structureResearchData($result, $companyName, $focus);

        return $this->success(
            sprintf('Research completed for "%s". Review the data and use create_company tool to add to CRM.', $companyName),
            array(
                'company_name'   => $companyName,
                'research_focus' => $focus,
                'research_data'  => $researchData,
                'search_query'   => $searchQuery,
            )
        );
    }

    /**
     * The WordPress query, part for part.
     *
     * An unrecognised `research_focus` falls to the general tail and is echoed
     * back unchanged, exactly as the original's `switch` default does.
     */
    private function buildSearchQuery(
        string $companyName,
        string $industry,
        string $location,
        string $focus,
        string $serviceContext,
    ): string {
        $parts = array($companyName);

        if ('' !== $industry) {
            $parts[] = $industry;
        }

        if ('' !== $location) {
            $parts[] = $location;
        }

        switch ($focus) {
            case 'target_fit':
                $parts[] = 'company size employees revenue business model';

                if ('' !== $serviceContext) {
                    $parts[] = 'uses ' . $serviceContext;
                }
                break;

            case 'industry_analysis':
                $parts[] = 'industry trends market analysis best practices';
                break;

            case 'competition':
                $parts[] = 'competitors market position competitive landscape';
                break;

            case 'general':
            default:
                $parts[] = 'company information overview website contact';
                break;
        }

        return implode(' ', $parts);
    }

    /**
     * The WordPress payload, with the hits read from the core envelope.
     *
     * @param mixed $result The web-search tool's return value.
     * @return array<string, mixed>
     */
    private function structureResearchData(mixed $result, string $companyName, string $focus): array
    {
        $items = array();

        if (is_array($result)) {
            $data  = is_array($result['data'] ?? null) ? $result['data'] : array();
            $hits  = $data['items'] ?? null;
            $items = is_array($hits) ? array_values($hits) : array();
        }

        return array(
            'search_results'    => $items,
            // WordPress' web-search tool returns neither; both are constant
            // empty there and here.
            'summary'           => '',
            'sources'           => array(),
            'findings_summary'  => sprintf(
                'Found %2$d sources of information about %1$s.',
                $companyName,
                count($items),
            ),
            'guidance'          => self::GUIDANCE[ $focus ] ?? self::GUIDANCE['general'],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function scalar(array $arguments, string $key): string
    {
        $value = $arguments[ $key ] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
