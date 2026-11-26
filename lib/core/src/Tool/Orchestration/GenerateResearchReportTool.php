<?php

/**
 * Multi-step research report generation.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Generate_Research_Report` (Proposal 164 section
 * 164.81). Two modes share one slug: **research** (topic → queries → web
 * search → model synthesis → assembled report) and **formatting** (pre-written
 * sections → assembled report). WordPress ran both; the port runs both.
 *
 * ## The pipeline, and what each step depends on
 *
 * | step | WordPress | this port |
 * |---|---|---|
 * | queries | `generate_research_queries()` | same order, same depth counts |
 * | search | `web_search` via the tool registry | {@see WebSearchTool}, optional |
 * | synthesis | provider client chosen from settings | `ProviderRouter` default, optional |
 * | assembly | `parse_and_format_research()` | same fence-stripping, TOC, references |
 * | save | Paper Store manager | `PaperStoreInterface`, optional |
 * | draft | `wp_insert_post` | `ContentStoreInterface`, optional |
 *
 * Every optional collaborator degrades explicitly. No search tool means empty
 * sources, exactly as WordPress behaved; no provider router means the
 * `provider_unavailable` error the base completion tool already returns; a save
 * option without its store is a named error rather than a silent no-op.
 *
 * ## Adaptations
 *
 * - **Search parameters.** The core's search tool reads `count`, not
 *   WordPress's `max_results`, and returns items shaped `{title, url,
 *   description}`. The snippet is mapped from `description`.
 * - **Synthesis routing.** WordPress picked a provider and model from settings
 *   with a five-method fallback chain. The port asks the router for its default,
 *   with `max_tokens` pinned at 4,000 as WordPress did. A host that wants a
 *   specific research model configures the default; the tool does not expose
 *   `provider`/`model` because the WordPress tool did not.
 * - **`str_word_count`** counts the same way it did, so `word_count` matches
 *   the original for the same report text.
 * - **Record `type`.** WordPress wrote the collection name into the record's
 *   `type` field. The port writes `research-report`, which is what the field
 *   means; the collection is already on the record.
 * - **Silent side-effect failures.** WordPress dropped a failed Paper Store
 *   save or draft creation without a word. The port returns the report and adds
 *   `paper_store_error` / `draft_post_error`, so the caller learns the write did
 *   not happen.
 *
 * ## Flags
 *
 * The tool is gated as a writer because `save_to_paper_store` and
 * `create_draft_post` write. The formatting mode and a plain research call are
 * side-effect free, but flags describe the tool, not the invocation, and a
 * read-only profile should not reach a switch that persists.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractCompletionTool;
use Nvoos\Core\Tool\WebSearchTool;

class GenerateResearchReportTool extends AbstractCompletionTool implements ToolCapabilityFlagsInterface
{
    /** Results requested from the search tool per query. */
    private const MAX_RESULTS_PER_QUERY = 5;

    /** Queries per depth level. */
    private const QUERIES = array(
        'basic'         => 1,
        'standard'      => 2,
        'comprehensive' => 3,
    );

    /** @var list<string> */
    private const REPORT_TYPES = array('aia_project', 'ncs_drawing', 'csi_specification', 'general');

    /** The answer size WordPress asked for. */
    private const MAX_TOKENS = 4000;

    /** The system instruction WordPress sent. */
    private const SYSTEM = 'You are a professional researcher and technical writer. You create comprehensive, well-structured research reports following industry standards. Always respond with valid JSON matching the requested format. Use provided sources when available to ensure accuracy.';

    /** Default Paper Store collection for a saved report. */
    private const DEFAULT_COLLECTION = 'research-reports';

    /** @var list<string> */
    private const POST_STATUSES = array('draft', 'pending');

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?WebSearchTool $search = null,
        ?ProviderRouter $providers = null,
        private readonly ?PaperStoreInterface $paper = null,
        private readonly ?ContentStoreInterface $content = null,
    ) {
        parent::__construct($errors, $providers);
    }

    public function getSlug(): string
    {
        return 'generate_research_report';
    }

    public function getName(): string
    {
        return 'Generate Research Report';
    }

    public function getDescription(): string
    {
        return 'Generate professional research reports with multi-step research, web search, citations, and industry-standard formatting. Supports AIA project reports, NCS drawing documentation, and CSI MasterFormat specifications. Can also format pre-written content.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'topic'                  => array(
                    'type'        => 'string',
                    'description' => 'Research topic (e.g., "residential project feasibility" or "concrete specifications"). Use this for automatic research.',
                ),
                'report_type'            => array(
                    'type'        => 'string',
                    'enum'        => self::REPORT_TYPES,
                    'description' => 'Type of report to generate.',
                    'default'     => 'general',
                ),
                'depth'                  => array(
                    'type'        => 'string',
                    'enum'        => array_keys(self::QUERIES),
                    'description' => 'Research depth level.',
                    'default'     => 'standard',
                ),
                'focus_areas'            => array(
                    'type'        => 'array',
                    'description' => 'Specific areas to focus research on (e.g., ["sustainability", "cost estimating"]).',
                    'items'       => array('type' => 'string'),
                ),
                'title'                  => array(
                    'type'        => 'string',
                    'description' => 'Report title (for formatting pre-written content).',
                ),
                'sections'               => array(
                    'type'        => 'array',
                    'description' => 'Pre-written report sections (for formatting mode).',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'heading' => array('type' => 'string'),
                            'content' => array('type' => 'string'),
                        ),
                    ),
                ),
                'include_toc'            => array(
                    'type'        => 'boolean',
                    'description' => 'Include table of contents (default: true).',
                    'default'     => true,
                ),
                'include_citations'      => array(
                    'type'        => 'boolean',
                    'description' => 'Include citations section (default: true).',
                    'default'     => true,
                ),
                'citations'              => array(
                    'type'        => 'array',
                    'description' => 'Pre-provided citations (for formatting mode).',
                    'items'       => array('type' => 'string'),
                ),
                'save_to_paper_store'    => array(
                    'type'        => 'boolean',
                    'description' => 'Save the generated report to the Paper Store as a temporary record.',
                    'default'     => false,
                ),
                'paper_store_collection' => array(
                    'type'        => 'string',
                    'description' => 'Paper Store collection for the report. Default: "research-reports".',
                ),
                'create_draft_post'      => array(
                    'type'        => 'boolean',
                    'description' => 'Create a draft post from the report content.',
                    'default'     => false,
                ),
                'draft_post_type'        => array(
                    'type'        => 'string',
                    'description' => 'Content type for the draft. Default: "post".',
                    'default'     => 'post',
                ),
                'draft_post_status'      => array(
                    'type'        => 'string',
                    'enum'        => self::POST_STATUSES,
                    'description' => 'Post status for the draft.',
                    'default'     => 'draft',
                ),
                'draft_post_category'    => array(
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'description' => 'Category term ID to assign to the draft post.',
                ),
                'draft_post_tags'        => array(
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                    'description' => 'Tags to assign to the draft post.',
                ),
            ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'network-dependent',
            'non-deterministic',
            'requires-credentials',
            'state-changing',
            'write',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $topic = Text::singleLine($this->stringParam($arguments, 'topic'));

        if ('' !== $topic) {
            return $this->research($topic, $arguments, $context);
        }

        if (
            isset($arguments['sections']) && is_array($arguments['sections'])
            && '' !== $this->stringParam($arguments, 'title')
        ) {
            return $this->formatting($arguments);
        }

        return $this->errors->validationFailed(
            'Either a topic or pre-written sections are required.',
            array(
                'topic' => array('Provide "topic" for research mode, or "title" and "sections" for formatting mode.'),
            )
        );
    }

    // ─── Research mode ───────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function research(string $topic, array $arguments, array $context): mixed
    {
        $reportType = Text::sanitizeKey($this->stringParam($arguments, 'report_type', 'general'));
        $reportType = in_array($reportType, self::REPORT_TYPES, true) ? $reportType : 'general';

        $depth = Text::sanitizeKey($this->stringParam($arguments, 'depth', 'standard'));
        $depth = isset(self::QUERIES[ $depth ]) ? $depth : 'standard';

        $focus = array();

        if (isset($arguments['focus_areas']) && is_array($arguments['focus_areas'])) {
            foreach ($arguments['focus_areas'] as $area) {
                if (is_scalar($area)) {
                    $clean = Text::singleLine((string) $area);

                    if ('' !== $clean) {
                        $focus[] = $clean;
                    }
                }
            }
        }

        $gathered = $this->gather($topic, $reportType, $depth, $focus, $context);
        $prompt   = $this->prompt($topic, $reportType, $depth, $focus, $gathered);
        $answer   = $this->completeTool(array('max_tokens' => self::MAX_TOKENS), $prompt, null, self::SYSTEM);

        if ($this->errors->isError($answer) || ! is_array($answer)) {
            return $answer;
        }

        $report = $this->assemble((string) ( $answer['text'] ?? '' ), $topic, $reportType, $gathered);

        if ($this->errors->isError($report) || ! is_array($report)) {
            return $report;
        }

        $report['model']    = (string) ( $answer['model'] ?? '' );
        $report['provider'] = (string) ( $answer['provider'] ?? '' );

        if ($this->boolParam($arguments, 'save_to_paper_store', false)) {
            [$id, $error] = $this->saveReport($report, $arguments, $topic, (int) ( $context['user_id'] ?? 0 ));

            if (null !== $id) {
                $report['paper_store_id']         = $id;
                $report['paper_store_collection'] = $this->collectionName($arguments);
            }

            if (null !== $error) {
                $report['paper_store_error'] = $error;
            }
        }

        if ($this->boolParam($arguments, 'create_draft_post', false)) {
            [$post, $error] = $this->draft($report, $arguments, (int) ( $context['user_id'] ?? 0 ));

            if (null !== $post) {
                $report['draft_post'] = $post;
            }

            if (null !== $error) {
                $report['draft_post_error'] = $error;
            }
        }

        return $this->success(
            sprintf(
                '%s report on "%s" generated: %d section(s), %d word(s).',
                $this->label($reportType),
                $topic,
                (int) $report['section_count'],
                (int) $report['word_count']
            ),
            $report
        );
    }

    /**
     * Run the generated queries and collect the sources they return.
     *
     * @param list<string>         $focus     Focus areas.
     * @param array<string, mixed> $context   Execution context.
     *
     * @return array{sources: list<array<string, mixed>>, queries: list<string>}
     */
    private function gather(string $topic, string $reportType, string $depth, array $focus, array $context): array
    {
        $queries = $this->queries($topic, $reportType, $depth, $focus);

        if (null === $this->search) {
            return array('sources' => array(), 'queries' => $queries);
        }

        $sources = array();

        foreach ($queries as $query) {
            $found = $this->search->execute(
                array('query' => $query, 'count' => self::MAX_RESULTS_PER_QUERY),
                $context
            );

            if ($this->errors->isError($found) || ! is_array($found)) {
                // One failed query does not abort the report; WordPress logged
                // and continued for the same reason.
                continue;
            }

            foreach ((array) ( $found['data']['items'] ?? array() ) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $url = isset($item['url']) && is_scalar($item['url']) ? (string) $item['url'] : '';

                if ('' === $url) {
                    continue;
                }

                $sources[] = array(
                    'url'     => $url,
                    'title'   => isset($item['title']) && is_scalar($item['title']) ? (string) $item['title'] : '',
                    'snippet' => isset($item['description']) && is_scalar($item['description'])
                        ? (string) $item['description']
                        : '',
                );
            }
        }

        return array(
            'sources' => $this->uniqueSources($sources),
            'queries' => $queries,
        );
    }

    /**
     * The WordPress query plan: the topic, then focus areas, then type-specific
     * queries, capped by depth.
     *
     * @param list<string> $focus Focus areas.
     *
     * @return list<string>
     */
    private function queries(string $topic, string $reportType, string $depth, array $focus): array
    {
        $queries = array($topic);
        $limit   = self::QUERIES[ $depth ];

        foreach ($focus as $area) {
            if (count($queries) >= $limit) {
                break;
            }

            $queries[] = $topic . ' ' . $area;
        }

        if (count($queries) < $limit) {
            foreach ($this->typeQueries($topic, $reportType, $depth) as $query) {
                if (count($queries) >= $limit) {
                    break;
                }

                $queries[] = $query;
            }
        }

        return array_slice($queries, 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function typeQueries(string $topic, string $reportType, string $depth): array
    {
        $queries = array();

        switch ($reportType) {
            case 'aia_project':
                $queries[] = $topic . ' AIA standards requirements';

                if ('comprehensive' === $depth) {
                    $queries[] = $topic . ' building codes sustainability LEED';
                    $queries[] = $topic . ' cost estimate schedule feasibility';
                } elseif ('standard' === $depth) {
                    $queries[] = $topic . ' building codes compliance';
                }

                break;

            case 'ncs_drawing':
                $queries[] = $topic . ' NCS CAD layer standards';

                if ('comprehensive' === $depth) {
                    $queries[] = $topic . ' AIA CAD guidelines sheet organization';
                    $queries[] = $topic . ' drawing standards dimension annotation';
                } elseif ('standard' === $depth) {
                    $queries[] = $topic . ' drawing organization standards';
                }

                break;

            case 'csi_specification':
                $queries[] = $topic . ' CSI MasterFormat three-part specification';

                if ('comprehensive' === $depth) {
                    $queries[] = $topic . ' ASTM standards specifications';
                    $queries[] = $topic . ' product manufacturers installation requirements';
                } elseif ('standard' === $depth) {
                    $queries[] = $topic . ' specifications standards';
                }

                break;

            default:
                $queries[] = $topic . ' industry standards best practices';

                if ('comprehensive' === $depth) {
                    $queries[] = $topic . ' research analysis';
                    $queries[] = $topic . ' recommendations guidelines';
                } elseif ('standard' === $depth) {
                    $queries[] = $topic . ' overview summary';
                }

                break;
        }

        return $queries;
    }

    /**
     * Build the synthesis prompt, including the sources the search found.
     *
     * @param list<string>                                                       $focus    Focus areas.
     * @param array{sources: list<array<string, mixed>>, queries: list<string>} $gathered Search output.
     */
    private function prompt(string $topic, string $reportType, string $depth, array $focus, array $gathered): string
    {
        $prompt = sprintf(
            "Research the following topic and generate a comprehensive, professional report:\n\n**Topic:** %s\n",
            $topic
        );

        $prompt .= '**Report Type:** ' . $this->label($reportType) . "\n";
        $prompt .= '**Depth Level:** ' . ucfirst($depth) . "\n\n";

        if (array() !== $gathered['sources']) {
            $prompt .= "**Available Research Sources:**\n";
            $count   = min(5, count($gathered['sources']));

            for ($index = 0; $index < $count; ++$index) {
                $source = $gathered['sources'][ $index ];

                $prompt .= sprintf(
                    "[%d] %s - %s\n",
                    $index + 1,
                    '' !== (string) $source['title'] ? (string) $source['title'] : 'Source',
                    (string) $source['url']
                );

                if ('' !== (string) $source['snippet']) {
                    $prompt .= '    ' . mb_substr((string) $source['snippet'], 0, 150) . "...\n";
                }
            }

            $prompt .= "\n";
        }

        if (array() !== $focus) {
            $prompt .= '**Focus Areas:** ' . implode(', ', $focus) . "\n\n";
        }

        $prompt .= $this->instructions($reportType);

        $prompt .= "\n**Output Format:**\n";
        $prompt .= "Respond with a JSON object containing:\n";
        $prompt .= "{\n";
        $prompt .= '  "title": "Report title",' . "\n";
        $prompt .= '  "sections": [' . "\n";
        $prompt .= '    {"heading": "Section Name", "content": "Detailed content..."},' . "\n";
        $prompt .= '    ...' . "\n";
        $prompt .= '  ],' . "\n";
        $prompt .= '  "citations": ["Source 1", "Source 2", ...]' . "\n";
        $prompt .= "}\n\n";

        $prompt .= "**Important Guidelines:**\n";
        $prompt .= "- Use the provided research sources when available\n";
        $prompt .= "- Include citations for all claims and data\n";
        $prompt .= "- Follow industry-standard structure for the report type\n";
        $prompt .= "- Write in professional, clear language\n";
        $prompt .= "- Provide actionable insights and recommendations\n";
        $prompt .= "- Ensure accuracy and cite all sources\n";

        return $prompt;
    }

    private function label(string $reportType): string
    {
        $labels = array(
            'aia_project'       => 'AIA Architectural Project Report',
            'ncs_drawing'       => 'NCS Drawing Documentation Report',
            'csi_specification' => 'CSI MasterFormat Specification Report',
            'general'           => 'General Research Report',
        );

        return $labels[ $reportType ] ?? 'Research Report';
    }

    private function instructions(string $reportType): string
    {
        switch ($reportType) {
            case 'aia_project':
                return "**Required Sections (AIA Standard):**\n"
                    . "1. Executive Summary - Project overview and key points\n"
                    . "2. Design Intent & Architectural Concepts - Design philosophy and approach\n"
                    . "3. Technical Design Solutions - Structural, mechanical, electrical systems\n"
                    . "4. Accessibility & Code Compliance - Building codes, ADA, life safety\n"
                    . "5. Sustainability Assessment - LEED, energy efficiency, green building\n"
                    . "6. Cost Estimating - Budget breakdown and value engineering\n"
                    . "7. Project Schedule & Phasing - Timeline, milestones, critical path\n\n";

            case 'ncs_drawing':
                return "**Required Sections (NCS Standard):**\n"
                    . "1. Drawing Set Organization - Sheet types, numbering, organization per UDS\n"
                    . "2. CAD Layer Structure - Discipline-Major-Minor-Status format (e.g., A-WALL-FULL-N)\n"
                    . "3. Drawing Types & Standards - Floor plans, elevations, sections, details\n"
                    . "4. Scale & Dimensioning - Standard scales, annotation conventions\n"
                    . "5. Quality Control Checklist - Layer naming compliance, coordination, accuracy\n\n";

            case 'csi_specification':
                return "**Required Sections (CSI MasterFormat Three-Part Format):**\n"
                    . "1. Part 1 - General - Scope, submittals, quality assurance, warranties\n"
                    . "2. Part 2 - Products - Materials, manufacturers, standards (ASTM, ANSI, UL)\n"
                    . "3. Part 3 - Execution - Installation methods, quality control, testing\n"
                    . "4. Division Organization - Appropriate CSI MasterFormat division (00-35)\n\n";

            default:
                return "**Suggested Report Structure:**\n"
                    . "1. Executive Summary - Key findings and recommendations\n"
                    . "2. Background & Context - Overview of topic and significance\n"
                    . "3. Research Findings - Detailed analysis and data\n"
                    . "4. Best Practices - Industry standards and recommendations\n"
                    . "5. Conclusions - Summary and action items\n\n";
        }
    }

    /**
     * Turn the model's answer into the assembled report.
     *
     * @param array{sources: list<array<string, mixed>>, queries: list<string>} $gathered Search output.
     *
     * @return array<string, mixed>|mixed
     */
    private function assemble(string $text, string $topic, string $reportType, array $gathered): mixed
    {
        $content = trim($text);

        if ('' === $content) {
            return $this->errors->create(
                'invalid_response',
                'The provider returned an empty response.'
            );
        }

        if (1 === preg_match('/```json\s*(.*?)\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (1 === preg_match('/```\s*(.*?)\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } else {
            $json = $content;
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            return $this->errors->create(
                'parse_error',
                sprintf('Failed to parse the model response as JSON: %s', json_last_error_msg())
            );
        }

        $title = isset($data['title']) && is_scalar($data['title'])
            ? Text::singleLine((string) $data['title'])
            : '';

        if ('' === $title) {
            $title = ucwords(str_replace('_', ' ', $reportType)) . ' Report: ' . $topic;
        }

        if (! isset($data['sections']) || ! is_array($data['sections']) || array() === $data['sections']) {
            return $this->errors->create(
                'invalid_data',
                'The model response is missing the required sections.'
            );
        }

        $sections = $this->sections($data['sections']);

        if (array() === $sections) {
            return $this->errors->create(
                'invalid_data',
                'The model response contained no usable section.'
            );
        }

        $citations = array();

        if (isset($data['citations']) && is_array($data['citations'])) {
            foreach ($data['citations'] as $citation) {
                if (is_scalar($citation) && '' !== Text::singleLine((string) $citation)) {
                    $citations[] = Text::singleLine((string) $citation);
                }
            }
        }

        foreach ($gathered['sources'] as $source) {
            $url = (string) $source['url'];

            if ('' === $url) {
                continue;
            }

            $citation = '' !== (string) $source['title']
                ? (string) $source['title'] . ' - ' . $url
                : $url;

            if (! in_array($citation, $citations, true)) {
                $citations[] = $citation;
            }
        }

        $report = $this->render($title, $sections, $citations);

        return array(
            'title'         => $title,
            'report'        => $report,
            'word_count'    => str_word_count($report),
            'section_count' => count($sections),
            'sources'       => $gathered['sources'],
            'report_type'   => $reportType,
        );
    }

    // ─── Formatting mode ─────────────────────────────────────────────────

    /**
     * Format pre-written sections, as WordPress's legacy mode did.
     *
     * @param array<string, mixed> $arguments
     */
    private function formatting(array $arguments): mixed
    {
        $title    = Text::singleLine($this->stringParam($arguments, 'title'));
        $sections = $this->sections($arguments['sections']);
        $citations = array();

        if (isset($arguments['citations']) && is_array($arguments['citations'])) {
            foreach ($arguments['citations'] as $citation) {
                if (is_scalar($citation)) {
                    $clean = Text::singleLine((string) $citation);

                    if ('' !== $clean) {
                        $citations[] = $clean;
                    }
                }
            }
        }

        if ('' === $title || array() === $sections) {
            return $this->errors->validationFailed(
                'Formatting mode needs a title and at least one section.',
                array(
                    'sections' => array('Each section needs a heading and content.'),
                )
            );
        }

        $report = "# " . $title . "\n\n";
        $report .= '_Generated on ' . gmdate('F j, Y') . "_" . "\n\n";

        if ($this->boolParam($arguments, 'include_toc', true) && count($sections) > 1) {
            $report .= "## Table of Contents\n\n";

            foreach ($sections as $index => $section) {
                $report .= ( $index + 1 ) . '. ' . $section['heading'] . "\n";
            }

            $report .= "\n---\n\n";
        }

        foreach ($sections as $section) {
            $report .= '## ' . $section['heading'] . "\n\n";
            $report .= $section['content'] . "\n\n";
        }

        if ($this->boolParam($arguments, 'include_citations', true) && array() !== $citations) {
            $report .= "## References\n\n";

            foreach ($citations as $index => $citation) {
                $report .= ( $index + 1 ) . '. ' . $citation . "\n";
            }
        }

        return $this->success(
            sprintf('Report "%s" formatted: %d section(s), %d word(s).', $title, count($sections), str_word_count($report)),
            array(
                'title'         => $title,
                'report'        => $report,
                'word_count'    => str_word_count($report),
                'section_count' => count($sections),
            )
        );
    }

    // ─── Assembled markdown ──────────────────────────────────────────────

    /**
     * @param list<array{heading: string, content: string}> $sections
     * @param list<string>                                  $citations
     */
    private function render(string $title, array $sections, array $citations): string
    {
        $report = '# ' . $title . "\n\n";
        $report .= '_Generated on ' . gmdate('F j, Y') . "_" . "\n\n";

        if (count($sections) > 1) {
            $report .= "## Table of Contents\n\n";

            foreach ($sections as $index => $section) {
                $report .= ( $index + 1 ) . '. ' . $section['heading'] . "\n";
            }

            $report .= "\n---\n\n";
        }

        foreach ($sections as $section) {
            if ('' !== $section['heading']) {
                $report .= '## ' . $section['heading'] . "\n\n";
            }

            if ('' !== $section['content']) {
                $report .= $section['content'] . "\n\n";
            }
        }

        if (array() !== $citations) {
            $report .= "## References\n\n";

            foreach ($citations as $index => $citation) {
                $report .= ( $index + 1 ) . '. ' . $citation . "\n";
            }
        }

        return $report;
    }

    /**
     * Normalise a section list, dropping entries with nothing usable.
     *
     * @param mixed $raw Raw sections.
     *
     * @return list<array{heading: string, content: string}>
     */
    private function sections(mixed $raw): array
    {
        if (! is_array($raw)) {
            return array();
        }

        $sections = array();

        foreach ($raw as $section) {
            if (! is_array($section)) {
                continue;
            }

            $heading = isset($section['heading']) && is_scalar($section['heading'])
                ? Text::singleLine((string) $section['heading'])
                : '';
            $content = isset($section['content']) && is_scalar($section['content'])
                ? Text::multiline((string) $section['content'])
                : '';

            if ('' === $heading && '' === $content) {
                continue;
            }

            $sections[] = array('heading' => $heading, 'content' => $content);
        }

        return $sections;
    }

    // ─── Optional persistence ────────────────────────────────────────────

    /**
     * Save the report to the Paper Store.
     *
     * @param array<string, mixed> $report    Assembled report.
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array{0: string|null, 1: string|null} Record id and error, never both.
     */
    private function saveReport(array $report, array $arguments, string $topic, int $userId): array
    {
        if (null === $this->paper) {
            return array(null, 'This host has no Paper Store.');
        }

        $collection = $this->collectionName($arguments);
        $title      = (string) ( $report['title'] ?? 'Research Report' );
        $markdown   = (string) ( $report['report'] ?? '' );
        $slug       = Text::slug($title);
        $slug       = '' !== $slug ? mb_substr($slug, 0, 40) : 'research-report';
        $recordId   = $slug . '-' . substr(md5($title . time()), 0, 8);

        $record = array(
            'id'          => $recordId,
            'title'       => $title,
            'description' => sprintf(
                'Research report "%s" — %d words across %d sections.',
                $title,
                (int) ( $report['word_count'] ?? 0 ),
                (int) ( $report['section_count'] ?? 0 )
            ),
            'type'        => 'research-report',
            'tags'        => array('research-report', (string) ( $report['report_type'] ?? 'general' )),
            'status'      => 'draft',
            'meta'        => array(
                'report_type'   => (string) ( $report['report_type'] ?? 'general' ),
                'word_count'    => (int) ( $report['word_count'] ?? 0 ),
                'section_count' => (int) ( $report['section_count'] ?? 0 ),
                'user_id'       => $userId,
                'topic'         => $topic,
            ),
            'body'        => array(
                'markdown' => $markdown,
                'sources'  => $report['sources'] ?? array(),
            ),
        );

        try {
            $saved = $this->paper->save($collection, $record);
        } catch (\Throwable $error) {
            return array(null, $error->getMessage());
        }

        return array((string) ( $saved['id'] ?? $recordId ), null);
    }

    /**
     * Create a draft post from the report.
     *
     * @param array<string, mixed> $report    Assembled report.
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function draft(array $report, array $arguments, int $userId): array
    {
        if (null === $this->content) {
            return array(null, 'This host has no content store.');
        }

        $type   = Text::sanitizeKey($this->stringParam($arguments, 'draft_post_type', 'post'));
        $status = Text::sanitizeKey($this->stringParam($arguments, 'draft_post_status', 'draft'));
        $status = in_array($status, self::POST_STATUSES, true) ? $status : 'draft';

        $taxonomyInput = array();
        $categoryId    = max(0, $this->intParam($arguments, 'draft_post_category', 0));

        if ($categoryId > 0) {
            $name = null;

            foreach ($this->content->listTaxonomyTerms('category', 500) as $term) {
                if ((int) ( $term['id'] ?? 0 ) === $categoryId) {
                    $name = (string) ( $term['name'] ?? '' );

                    break;
                }
            }

            if (null === $name) {
                return array(null, sprintf('No category term has id %d.', $categoryId));
            }

            $taxonomyInput['category'] = array($name);
        }

        if (isset($arguments['draft_post_tags']) && is_array($arguments['draft_post_tags'])) {
            $tags = array();

            foreach ($arguments['draft_post_tags'] as $tag) {
                if (is_scalar($tag)) {
                    $clean = Text::singleLine((string) $tag);

                    if ('' !== $clean) {
                        $tags[] = $clean;
                    }
                }
            }

            if (array() !== $tags) {
                $taxonomyInput['post_tag'] = array_values(array_unique($tags));
            }
        }

        $command = new CreateContentCommand(
            title: (string) ( $report['title'] ?? 'Research Report' ),
            type: '' !== $type ? $type : 'post',
            authorId: $userId,
            status: $status,
            content: (string) ( $report['report'] ?? '' ),
            taxonomyInput: $taxonomyInput,
        );

        try {
            $item = $this->content->create($command);
        } catch (AccessDeniedException $error) {
            return array(null, $error->getMessage());
        } catch (ValidationException $error) {
            return array(null, $error->getMessage());
        } catch (\Throwable $error) {
            return array(null, $error->getMessage());
        }

        return array(
            array(
                'post_id'     => $item->id,
                'post_type'   => $item->type,
                'post_status' => $item->status,
                'edit_url'    => null,
                'permalink'   => null,
            ),
            null,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function collectionName(array $arguments): string
    {
        $collection = Text::sanitizeKey($this->stringParam($arguments, 'paper_store_collection', self::DEFAULT_COLLECTION));

        return '' !== $collection ? $collection : self::DEFAULT_COLLECTION;
    }

    /**
     * Drop sources whose URL repeats, preserving first-seen order.
     *
     * @param list<array<string, mixed>> $sources Sources.
     *
     * @return list<array<string, mixed>>
     */
    private function uniqueSources(array $sources): array
    {
        $unique = array();
        $seen   = array();

        foreach ($sources as $source) {
            $url = (string) $source['url'];

            if (isset($seen[ $url ])) {
                continue;
            }

            $seen[ $url ] = true;
            $unique[]     = $source;
        }

        return $unique;
    }
}
