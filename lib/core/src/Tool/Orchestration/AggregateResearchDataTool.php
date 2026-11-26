<?php

/**
 * Multi-source research aggregation with deduplication.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Aggregate_Research_Data` (Proposal 164 section
 * 164.81). The tool is pure: it merges the sources a caller supplies, drops
 * duplicates at the requested sensitivity, and renders markdown, JSON or HTML.
 * No network, no store, no model.
 *
 * ## Deduplication
 *
 * Two passes, in the WordPress order: an exact repeated URL is dropped, then
 * content similarity is measured with `similar_text()` against every source
 * already accepted, at 95% (strict), 85% (moderate) or 70% (lenient). An exact
 * content match short-circuits before the similarity pass — same verdict,
 * without an O(n²) comparison of two identical strings.
 *
 * Similarity is computed on at most the first 2,000 characters of each
 * source. `similar_text()` is super-linear in the input length, and a tool call
 * is not a batch job: an unbounded compare lets one caller pin a worker on two
 * 100 KB pages. Duplicates that matter for research — the same article
 * syndicated twice — differ near the start or not at all.
 *
 * ## Corrections
 *
 * - An unknown `deduplication_mode` or `output_format` is normalised to the
 *   documented default **and the effective value is reported**. WordPress kept
 *   echoing the value it was given while behaving as the default, so a caller
 *   who asked for `output_format: "yaml"` was told it got YAML.
 * - The 200-character excerpt gains its ellipsis only when it was actually
 *   truncated; WordPress appended `...` to every excerpt, however short.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class AggregateResearchDataTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /** Similarity at or above the threshold counts as a duplicate. */
    private const THRESHOLDS = array(
        'strict'   => 0.95,
        'moderate' => 0.85,
        'lenient'  => 0.70,
    );

    /** Characters of each content compared for similarity. */
    private const SIMILARITY_SAMPLE = 2000;

    /** Characters kept in a source excerpt. */
    private const EXCERPT_LENGTH = 200;

    /** Key points kept in the compilation. */
    private const MAX_KEY_POINTS = 10;

    /**
     * The phrases whose containing sentence becomes a key point.
     *
     * @var list<string>
     */
    private const KEY_PATTERNS = array(
        'important',
        'significant',
        'critical',
        'essential',
        'key finding',
        'shows that',
        'reveals that',
    );

    /** @var list<string> */
    private const OUTPUT_FORMATS = array('markdown', 'json', 'html');

    public function __construct(ErrorFactoryInterface $e)
    {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'aggregate_research_data';
    }

    public function getName(): string
    {
        return 'Aggregate Research Data';
    }

    public function getDescription(): string
    {
        return 'Aggregate data from multiple research sources with automatic deduplication and structured compilation. Perfect for market research, competitor analysis, and multi-source information gathering.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'sources'            => array(
                    'type'        => 'array',
                    'description' => 'Array of research sources to aggregate.',
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'url'     => array(
                                'type'        => 'string',
                                'description' => 'Source URL.',
                            ),
                            'title'   => array(
                                'type'        => 'string',
                                'description' => 'Source title.',
                            ),
                            'content' => array(
                                'type'        => 'string',
                                'description' => 'Source content or summary.',
                            ),
                            'date'    => array(
                                'type'        => 'string',
                                'description' => 'Publication date (optional).',
                            ),
                            'author'  => array(
                                'type'        => 'string',
                                'description' => 'Author or organization (optional).',
                            ),
                        ),
                        'required'   => array('url', 'content'),
                    ),
                ),
                'topic'              => array(
                    'type'        => 'string',
                    'description' => 'Main research topic or theme.',
                ),
                'deduplication_mode' => array(
                    'type'        => 'string',
                    'enum'        => array_keys(self::THRESHOLDS),
                    'description' => 'Deduplication sensitivity (default: moderate).',
                    'default'     => 'moderate',
                ),
                'output_format'      => array(
                    'type'        => 'string',
                    'enum'        => self::OUTPUT_FORMATS,
                    'description' => 'Output format (default: markdown).',
                    'default'     => 'markdown',
                ),
            ),
            'required'             => array('sources', 'topic'),
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
        return CapabilityFlagVocabulary::PURE_LOCAL;
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        if (! isset($arguments['sources']) || ! is_array($arguments['sources']) || array() === $arguments['sources']) {
            return $this->errors->create('sources_required', 'Sources array is required.');
        }

        $topic = Text::singleLine($this->stringParam($arguments, 'topic'));

        if ('' === $topic) {
            return $this->errors->create('topic_required', 'Research topic is required.');
        }

        $mode   = Text::sanitizeKey($this->stringParam($arguments, 'deduplication_mode', 'moderate'));
        $mode   = isset(self::THRESHOLDS[ $mode ]) ? $mode : 'moderate';
        $format = Text::sanitizeKey($this->stringParam($arguments, 'output_format', 'markdown'));
        $format = in_array($format, self::OUTPUT_FORMATS, true) ? $format : 'markdown';

        $unique = $this->deduplicate($arguments['sources'], self::THRESHOLDS[ $mode ]);
        $data   = $this->aggregate($unique, $topic);
        $output = $this->format($data, $topic, $format);

        if (null === $output) {
            return $this->errors->create(
                'encode_failed',
                'The aggregation could not be encoded in the requested format.'
            );
        }

        return $this->success(
            $this->message($topic, count($unique), $format),
            array(
                'topic'            => $topic,
                'total_sources'    => count($arguments['sources']),
                'unique_sources'   => count($unique),
                'duplicates_found' => count($arguments['sources']) - count($unique),
                'aggregated_data'  => $data,
                'formatted_output' => $output,
                'output_format'    => $format,
                'dedup_mode'       => $mode,
            )
        );
    }

    /**
     * Drop repeated URLs and near-identical content.
     *
     * @param array<mixed> $sources Raw sources.
     *
     * @return list<array<string, mixed>>
     */
    private function deduplicate(array $sources, float $threshold): array
    {
        $unique     = array();
        $seen_urls  = array();
        $seen_hash  = array();
        $accepted   = array();

        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $url     = isset($source['url']) && is_scalar($source['url'])
                ? Text::singleLine((string) $source['url'])
                : '';
            $content = isset($source['content']) && is_scalar($source['content'])
                ? Text::multiline((string) $source['content'])
                : '';

            if ('' !== $url && isset($seen_urls[ $url ])) {
                continue;
            }

            $hash = md5($content);

            if (isset($seen_hash[ $hash ])) {
                continue;
            }

            $duplicate = false;
            $sample    = mb_substr($content, 0, self::SIMILARITY_SAMPLE);

            foreach ($accepted as $existing) {
                similar_text($sample, $existing, $percent);

                if (( $percent / 100 ) >= $threshold) {
                    $duplicate = true;

                    break;
                }
            }

            if ($duplicate) {
                continue;
            }

            $source['url']     = $url;
            $source['content'] = $content;

            $unique[]  = $source;
            $seen_hash[ $hash ] = true;

            if ('' !== $url) {
                $seen_urls[ $url ] = true;
            }

            $accepted[] = $sample;
        }

        return $unique;
    }

    /**
     * Compile the source set into the documented shape.
     *
     * @param list<array<string, mixed>> $sources Deduplicated sources.
     *
     * @return array<string, mixed>
     */
    private function aggregate(array $sources, string $topic): array
    {
        $data = array(
            'summary'           => '',
            'key_points'        => array(),
            'common_themes'     => array(),
            'sources'           => array(),
            'publication_dates' => array(),
            'authors'           => array(),
        );

        foreach ($sources as $source) {
            $content = (string) ( $source['content'] ?? '' );
            $excerpt = mb_strlen($content) > self::EXCERPT_LENGTH
                ? mb_substr($content, 0, self::EXCERPT_LENGTH) . '…'
                : $content;

            $data['sources'][] = array(
                'url'     => (string) ( $source['url'] ?? '' ),
                'title'   => isset($source['title']) && '' !== (string) $source['title']
                    ? Text::singleLine((string) $source['title'])
                    : 'Untitled',
                'excerpt' => $excerpt,
            );

            $date = isset($source['date']) && is_scalar($source['date'])
                ? Text::singleLine((string) $source['date'])
                : '';

            if ('' !== $date && ! in_array($date, $data['publication_dates'], true)) {
                $data['publication_dates'][] = $date;
            }

            $author = isset($source['author']) && is_scalar($source['author'])
                ? Text::singleLine((string) $source['author'])
                : '';

            if ('' !== $author && ! in_array($author, $data['authors'], true)) {
                $data['authors'][] = $author;
            }

            foreach (self::KEY_PATTERNS as $pattern) {
                if (false === stripos($content, $pattern)) {
                    continue;
                }

                $sentences = preg_split('/(?<=[.?!])\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);

                foreach (is_array($sentences) ? $sentences : array() as $sentence) {
                    if (false === stripos($sentence, $pattern)) {
                        continue;
                    }

                    $sentence = Text::singleLine($sentence);

                    if ('' !== $sentence && ! in_array($sentence, $data['key_points'], true)) {
                        $data['key_points'][] = $sentence;
                    }
                }
            }
        }

        $data['key_points'] = array_slice($data['key_points'], 0, self::MAX_KEY_POINTS);
        $data['summary']    = sprintf(
            'Aggregated research on "%s" from %d unique sources.',
            $topic,
            count($sources)
        );

        return $data;
    }

    /**
     * @param array<string, mixed> $data Aggregated data.
     */
    private function format(array $data, string $topic, string $format): ?string
    {
        if ('json' === $format) {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return false === $json ? null : $json;
        }

        if ('html' === $format) {
            return $this->toHtml($data, $topic);
        }

        return $this->toMarkdown($data, $topic);
    }

    /**
     * @param array<string, mixed> $data Aggregated data.
     */
    private function toMarkdown(array $data, string $topic): string
    {
        $markdown = '# Research Compilation: ' . $topic . "\n\n";
        $markdown .= "## Summary\n\n";
        $markdown .= $data['summary'] . "\n\n";

        if (array() !== $data['key_points']) {
            $markdown .= "## Key Findings\n\n";

            foreach ($data['key_points'] as $point) {
                $markdown .= '- ' . $point . "\n";
            }

            $markdown .= "\n";
        }

        $markdown .= "## Sources\n\n";

        foreach ($data['sources'] as $source) {
            $title = '' !== $source['title'] ? $source['title'] : 'Untitled';
            $url   = '' !== $source['url'] ? $source['url'] : '#';

            $markdown .= '### [' . $title . '](' . $url . ")\n\n";
            $markdown .= $source['excerpt'] . "\n\n";
        }

        if (array() !== $data['publication_dates']) {
            $markdown .= "## Publication Timeline\n\n";

            foreach ($data['publication_dates'] as $date) {
                $markdown .= '- ' . $date . "\n";
            }

            $markdown .= "\n";
        }

        if (array() !== $data['authors']) {
            $markdown .= "## Contributors\n\n";

            foreach ($data['authors'] as $author) {
                $markdown .= '- ' . $author . "\n";
            }
        }

        return $markdown;
    }

    /**
     * @param array<string, mixed> $data Aggregated data.
     */
    private function toHtml(array $data, string $topic): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $html = '<h1>Research Compilation: ' . $escape($topic) . "</h1>\n";
        $html .= "<h2>Summary</h2>\n";
        $html .= '<p>' . $escape((string) $data['summary']) . "</p>\n";

        if (array() !== $data['key_points']) {
            $html .= "<h2>Key Findings</h2>\n<ul>\n";

            foreach ($data['key_points'] as $point) {
                $html .= '<li>' . $escape((string) $point) . "</li>\n";
            }

            $html .= "</ul>\n";
        }

        $html .= "<h2>Sources</h2>\n";

        foreach ($data['sources'] as $source) {
            $url = (string) $source['url'];

            // WordPress ran esc_url(), which drops a scheme it does not allow.
            // The core escapes the attribute and only links HTTP(S) targets.
            $safeUrl = '' !== $url && false !== filter_var($url, FILTER_VALIDATE_URL)
                && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), array('http', 'https'), true)
                ? $escape($url)
                : '#';

            $html .= sprintf(
                "<h3><a href=\"%s\">%s</a></h3>\n",
                $safeUrl,
                $escape((string) $source['title'])
            );
            $html .= '<p>' . $escape((string) $source['excerpt']) . "</p>\n";
        }

        return $html;
    }

    private function message(string $topic, int $unique, string $format): string
    {
        return sprintf(
            'Aggregated %d unique source(s) on "%s" as %s.',
            $unique,
            $topic,
            $format
        );
    }
}
