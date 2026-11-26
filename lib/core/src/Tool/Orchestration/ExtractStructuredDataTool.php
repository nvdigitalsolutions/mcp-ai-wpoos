<?php

/**
 * Extract named fields from HTML or text.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Extract_Structured_Data` (Proposal 164 section
 * 164.77). The description promises CSS selectors; the code does not implement
 * them, and the usage guidance admits as much. What the port keeps:
 *
 * - **A selector matching `[a-z0-9-.#\[\]]+` is used as a tag name**, so
 *   `.product-price` is looked for as an element literally named
 *   `<.product-price>` and matches nothing. Only bare tag names (`h1`, `title`)
 *   work on that path.
 * - **Anything else is spliced into `/<pattern>/i`.** The caller's slashes are
 *   not escaped and there is no `preg_quote`, so a pattern containing `/` is an
 *   invalid regex. That answers an empty string rather than raising a warning —
 *   the WordPress code emits the warning and returns empty.
 * - **`csv` does not quote values.** A field containing a comma breaks the row.
 * - **A non-string selector reports that field as `''`**, which the WordPress
 *   code does deliberately rather than fataling in `preg_match_all()`.
 *
 * One edge is narrowed: WordPress's `empty()` test also rejects the string
 * `"0"`; here only a missing or empty value is refused.
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
use Nvoos\Core\Tool\AbstractTool;

class ExtractStructuredDataTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(ErrorFactoryInterface $e)
    {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'extract_structured_data';
    }

    public function getName(): string
    {
        return 'Extract Structured Data';
    }

    public function getDescription(): string
    {
        return 'Extract named fields from HTML or text content. A selector that looks like a tag name is matched as an element; anything else is applied as a regular expression. Use convert_html_to_markdown to convert whole documents instead.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'content'       => array(
                    'type'        => 'string',
                    'description' => 'HTML or text content to parse.',
                ),
                'selectors'     => array(
                    'type'        => 'object',
                    'description' => 'Field names mapped to tag names or regular expressions (e.g. {"title": "h1", "price": "\\$[0-9.]+"}).',
                ),
                'output_format' => array(
                    'type'        => 'string',
                    'enum'        => array('json', 'markdown', 'csv'),
                    'description' => 'Output format (default: json).',
                    'default'     => 'json',
                ),
            ),
            'required'             => array('content', 'selectors'),
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
        return array('read-only', 'idempotent', 'cacheable', 'local-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'edit_posts');

        $content   = $arguments['content'] ?? null;
        $selectors = $arguments['selectors'] ?? null;
        $noContent = null === $content || '' === $content;
        $noFields  = null === $selectors
            || (is_array($selectors) && array() === $selectors)
            || (is_string($selectors) && '' === $selectors);

        if ($noContent || $noFields) {
            return $this->errors->create(
                'content_selectors_required',
                'Content and selectors are required.'
            );
        }

        if (! is_string($content)) {
            return $this->errors->create('invalid_content', 'Content must be a string.');
        }

        if (! is_array($selectors)) {
            return $this->errors->create(
                'invalid_selectors',
                'Selectors must be an object of field names to selectors.'
            );
        }

        $format    = $this->stringParam($arguments, 'output_format', 'json');
        $extracted = $this->extract($content, $selectors);

        return $this->success(
            sprintf('Extracted %d field(s).', count($extracted)),
            array(
                'extracted_data'   => $extracted,
                'formatted_output' => $this->format($extracted, $format),
                'fields_extracted' => count($extracted),
                'output_format'    => $format,
            )
        );
    }

    /**
     * @param array<string, mixed> $selectors Field => selector.
     *
     * @return array<string, string|list<string>>
     */
    private function extract(string $content, array $selectors): array
    {
        $data = array();

        foreach ($selectors as $field => $selector) {
            if (! is_string($selector)) {
                $data[ $field ] = '';
                continue;
            }

            if (1 === preg_match('/^[a-z0-9\-\.#\[\]]+$/i', $selector)) {
                $data[ $field ] = $this->byTag($content, $selector);
                continue;
            }

            $data[ $field ] = $this->byPattern($content, $selector);
        }

        return $data;
    }

    /**
     * @return string|list<string>
     */
    private function byTag(string $content, string $tag): string|array
    {
        $pattern = '/<' . preg_quote($tag, '/') . '[^>]*>(.*?)<\/' . preg_quote($tag, '/') . '>/is';

        if (preg_match_all($pattern, $content, $matches) > 0) {
            return 1 === count($matches[1]) ? $matches[1][0] : $matches[1];
        }

        return '';
    }

    /**
     * @return string|list<string>
     */
    private function byPattern(string $content, string $pattern): string|array
    {
        $matched = @preg_match_all('/' . $pattern . '/i', $content, $matches);

        if (false === $matched || 0 === $matched) {
            return '';
        }

        return 1 === count($matches[0]) ? $matches[0][0] : $matches[0];
    }

    /**
     * @param array<string, string|list<string>> $data
     */
    private function format(array $data, string $format): string
    {
        if ('json' === $format) {
            return (string) json_encode($data, JSON_PRETTY_PRINT);
        }

        if ('csv' === $format) {
            $csv = '';

            foreach ($data as $key => $value) {
                $csv .= sprintf("%s,%s\n", $key, is_array($value) ? implode(';', $value) : $value);
            }

            return $csv;
        }

        $markdown = "# Extracted Data\n\n";

        foreach ($data as $key => $value) {
            $markdown .= '## ' . ucfirst((string) $key) . "\n\n";

            if (is_array($value)) {
                foreach ($value as $item) {
                    $markdown .= "- {$item}\n";
                }
            } else {
                $markdown .= "{$value}\n";
            }

            $markdown .= "\n";
        }

        return $markdown;
    }
}
