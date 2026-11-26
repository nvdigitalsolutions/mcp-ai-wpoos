<?php

/**
 * Build a complete, printable HTML document from plain content.
 *
 * Pure string work, ported from `WP_MCP_AI_Html_Formatter` under Proposal 164
 * §164.31. A renderer receives a self-contained document and knows nothing about
 * sections, headings or page widths — which is the point: the media worker's
 * `pdf/generate` route renders HTML in a browser, so everything that makes a
 * document *look* like a document has to happen before the call.
 *
 * ## Why this is not `HtmlToPdfTool`
 *
 * `HtmlToPdfTool` takes HTML a caller has already written and renders it. This
 * class is the half that turns *content* — a title, paragraphs, a section list —
 * into that HTML. Keeping them apart means `pro_pdf_document` and
 * `generate_pdf` share one composition instead of each growing its own.
 *
 * ## Escaping, and where the WordPress original was wrong
 *
 * `create_document()` interpolates option values into a `<style>` block. The
 * WordPress formatter ran them through `esc_attr()`, which escapes quotes and
 * angle brackets but **not `}`, `{` or `;`** — so a `font_family` of
 * `x} body{display:none` closes the rule and opens another. `esc_attr()` is an
 * attribute escaper; a `<style>` block is not an attribute context.
 *
 * Every value substituted into CSS here runs through {@see cssValue()} instead,
 * which refuses the characters that can leave a declaration. Content is escaped
 * once, as text, and never treated as markup.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Document;

final class HtmlFormatter
{
    /**
     * Page widths in pixels, used to size the printed body.
     *
     * The numbers are the WordPress tool's, kept identical so a document
     * rendered here and there looks the same. They are approximations at
     * 96 DPI; the renderer's own `format` option is what actually sets the
     * paper size, and this only bounds the text column.
     *
     * @var array<string, string>
     */
    public const PAGE_WIDTHS = array(
        'A4' => '816px',
        'Letter' => '816px',
        'Legal' => '816px',
        'A3' => '1123px',
        'A5' => '559px',
    );

    /**
     * Defaults for {@see createDocument()}.
     *
     * @var array<string, string|int|float>
     */
    public const DEFAULTS = array(
        'title' => 'Document',
        'author' => '',
        'description' => '',
        'font_family' => 'Arial, Helvetica, sans-serif',
        'font_size' => 12,
        'line_height' => 1.6,
        'color' => '#333333',
        'margin' => '1in',
        'page_width' => '816px',
        'text_align' => 'left',
        'orientation' => 'portrait',
    );

    /**
     * Build a complete HTML document.
     *
     * @param string               $content Body HTML, already escaped.
     * @param array<string, mixed> $options Formatting options; see {@see DEFAULTS}.
     *
     * @return string A standalone HTML document.
     */
    public function createDocument(string $content, array $options = array()): string
    {
        $merged = self::DEFAULTS;

        foreach ($options as $key => $value) {
            if (null !== $value && '' !== $value) {
                $merged[$key] = $value;
            }
        }

        $title = $this->escape((string) $merged['title']);
        $styles = $this->styles($merged);
        $meta = $this->metaTags($merged);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	{$meta}
	<title>{$title}</title>
	<style>
{$styles}
	</style>
</head>
<body>
	{$content}
</body>
</html>
HTML;
    }

    /**
     * Convert plain text to paragraph HTML.
     *
     * Blank lines separate paragraphs; single newlines become line breaks.
     *
     * @param string               $content Plain text.
     * @param array<string, mixed> $options {
     *     @type bool $auto_paragraphs Wrap blocks in `<p>`. Default true.
     * }
     *
     * @return string Escaped HTML.
     */
    public function textToHtml(string $content, array $options = array()): string
    {
        $escaped = $this->escape($content);

        if (false === ($options['auto_paragraphs'] ?? true)) {
            return nl2br($escaped);
        }

        return $this->toParagraphs($escaped);
    }

    /**
     * Convert a section list to heading-and-paragraph HTML.
     *
     * Each section is `{'heading': string, 'content': string, 'level': int}`.
     * A `level` outside 1–6 is clamped rather than rejected: the heading still
     * belongs in the outline, it just cannot be an `<h7>`.
     *
     * @param array<int, mixed>    $sections Section list.
     * @param array<string, mixed> $options  Reserved; ignored.
     *
     * @return string Escaped HTML.
     */
    public function sectionsToHtml(array $sections, array $options = array()): string
    {
        $parts = array();

        foreach ($sections as $section) {
            if (!is_array($section) || array() === $section) {
                continue;
            }

            $heading = $section['heading'] ?? '';
            if (is_string($heading) && '' !== trim($heading)) {
                $level = isset($section['level']) ? (int) $section['level'] : 2;
                $level = max(1, min(6, $level));

                $parts[] = sprintf(
                    '<h%d>%s</h%d>',
                    $level,
                    $this->escape($heading),
                    $level,
                );
            }

            $content = $section['content'] ?? '';
            if (is_string($content) && '' !== trim($content)) {
                $parts[] = $this->toParagraphs($this->escape($content));
            }
        }

        return implode("\n", $parts);
    }

    /**
     * A table from a header row and body rows.
     *
     * @param array<string, mixed> $data {
     *     @type array<int, string>       $headers Column headings.
     *     @type array<int, array<mixed>> $rows    Body rows.
     * }
     * @param array<string, mixed> $options Reserved; ignored.
     */
    public function createTable(array $data, array $options = array()): string
    {
        $headers = is_array($data['headers'] ?? null) ? $data['headers'] : array();
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : array();

        $html = array('<table>');

        if (array() !== $headers) {
            $cells = array();
            foreach ($headers as $header) {
                $cells[] = '<th>' . $this->escape((string) $header) . '</th>';
            }
            $html[] = '<thead><tr>' . implode('', $cells) . '</tr></thead>';
        }

        if (array() !== $rows) {
            $html[] = '<tbody>';
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $cells = array();
                foreach ($row as $cell) {
                    $cells[] = '<td>' . $this->escape((string) $cell) . '</td>';
                }
                $html[] = '<tr>' . implode('', $cells) . '</tr>';
            }
            $html[] = '</tbody>';
        }

        $html[] = '</table>';

        return implode("\n", $html);
    }

    /**
     * A list from a flat array.
     *
     * @param array<int, mixed> $items List items.
     * @param string            $type  `ul` or `ol`; anything else becomes `ul`.
     */
    public function createList(array $items, string $type = 'ul'): string
    {
        $tag = 'ol' === $type ? 'ol' : 'ul';
        $html = array('<' . $tag . '>');

        foreach ($items as $item) {
            $html[] = '<li>' . $this->escape((string) $item) . '</li>';
        }

        $html[] = '</' . $tag . '>';

        return implode("\n", $html);
    }

    /**
     * A page break, for callers composing HTML directly.
     */
    public function pageBreak(): string
    {
        return '<div class="page-break"></div>';
    }

    /**
     * Wrap content so it is not split across a page.
     */
    public function avoidBreak(string $content): string
    {
        return '<div class="avoid-break">' . $content . '</div>';
    }

    /**
     * The pixel width for a page size, falling back to A4.
     */
    public static function pageWidth(string $pageSize): string
    {
        return self::PAGE_WIDTHS[$pageSize] ?? self::PAGE_WIDTHS['A4'];
    }

    /**
     * Build the `<style>` body.
     *
     * @param array<string, mixed> $options Resolved options.
     */
    private function styles(array $options): string
    {
        $fontFamily = $this->cssValue((string) $options['font_family'], self::DEFAULTS['font_family']);
        $fontSize = max(1, (int) $options['font_size']);
        $lineHeight = (float) $options['line_height'];
        $lineHeight = $lineHeight > 0 ? $lineHeight : (float) self::DEFAULTS['line_height'];
        $color = $this->cssValue((string) $options['color'], (string) self::DEFAULTS['color']);
        $margin = $this->cssValue((string) $options['margin'], (string) self::DEFAULTS['margin']);
        $pageWidth = $this->cssValue((string) $options['page_width'], (string) self::DEFAULTS['page_width']);
        $textAlign = $this->cssValue((string) $options['text_align'], 'left');

        return <<<CSS
	* {
		margin: 0;
		padding: 0;
		box-sizing: border-box;
	}

	html, body {
		font-family: {$fontFamily};
		font-size: {$fontSize}pt;
		line-height: {$lineHeight};
		color: {$color};
		background: #fff;
	}

	body {
		max-width: {$pageWidth};
		margin: 0 auto;
		padding: {$margin};
	}

	h1, h2, h3, h4, h5, h6 {
		font-weight: bold;
		margin-top: 1.5em;
		margin-bottom: 0.75em;
		line-height: 1.3;
	}

	h1 { font-size: 2em; margin-top: 0; text-align: center; }
	h2 { font-size: 1.5em; }
	h3 { font-size: 1.25em; }
	h4 { font-size: 1.1em; }
	h5 { font-size: 1em; }
	h6 { font-size: 0.9em; }

	p {
		margin-bottom: 1em;
		text-align: {$textAlign};
	}

	ul, ol {
		margin-bottom: 1em;
		padding-left: 2em;
	}

	li { margin-bottom: 0.5em; }

	table {
		width: 100%;
		border-collapse: collapse;
		margin-bottom: 1em;
	}

	th, td {
		border: 1px solid #ddd;
		padding: 8px 12px;
		text-align: left;
	}

	th {
		background-color: #f2f2f2;
		font-weight: bold;
	}

	strong, b { font-weight: bold; }
	em, i { font-style: italic; }
	u { text-decoration: underline; }

	a {
		color: #0066cc;
		text-decoration: underline;
	}

	code {
		font-family: 'Courier New', monospace;
		background-color: #f5f5f5;
		padding: 2px 4px;
		border-radius: 3px;
	}

	pre {
		font-family: 'Courier New', monospace;
		background-color: #f5f5f5;
		padding: 1em;
		margin-bottom: 1em;
		border-radius: 3px;
		overflow-x: auto;
	}

	blockquote {
		margin: 1em 0;
		padding-left: 1em;
		border-left: 4px solid #ddd;
		color: #666;
	}

	@media print {
		body { margin: 0; }

		h1, h2, h3, h4, h5, h6 { page-break-after: avoid; }

		p, li { page-break-inside: avoid; }

		table { page-break-inside: avoid; }

		a { color: #0066cc; }
	}

	.page-break { page-break-after: always; }

	.avoid-break { page-break-inside: avoid; }
CSS;
    }

    /**
     * Build the metadata tags.
     *
     * @param array<string, mixed> $options Resolved options.
     */
    private function metaTags(array $options): string
    {
        $tags = array();

        foreach (array('author', 'description') as $name) {
            $value = $options[$name] ?? '';

            if (is_string($value) && '' !== trim($value)) {
                $tags[] = sprintf(
                    '<meta name="%s" content="%s">',
                    $name,
                    $this->escape($value),
                );
            }
        }

        return implode("\n\t", $tags);
    }

    /**
     * Wrap escaped text in paragraphs, splitting on blank lines.
     */
    private function toParagraphs(string $text): string
    {
        $blocks = preg_split('/\n\s*\n/', $text) ?: array();
        $parts = array();

        foreach ($blocks as $block) {
            $block = trim($block);

            if ('' === $block) {
                continue;
            }

            $parts[] = '<p>' . nl2br($block) . '</p>';
        }

        return implode("\n", $parts);
    }

    /**
     * Escape text for an HTML text node or attribute value.
     */
    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Make a value safe to substitute inside a `<style>` declaration.
     *
     * This is the check the WordPress formatter did not make. `esc_attr()` is
     * correct for an attribute and wrong for a stylesheet: `}` closes a rule, so
     * an unescaped brace in `font_family` can open one of the caller's choosing.
     *
     * Characters that can start or end a construct are refused outright rather
     * than percent-encoded — a font stack with a brace in it was never intended
     * and silently "fixing" it would hide the attempt. A value that fails
     * returns the default.
     *
     * @param string $value    Caller-supplied value.
     * @param mixed  $fallback Value used when the input is refused.
     */
    private function cssValue(string $value, mixed $fallback): string
    {
        $trimmed = trim($value);

        if ('' === $trimmed) {
            return (string) $fallback;
        }

        if (1 === preg_match('/[<>{};\\\\@\x00-\x1f]/', $trimmed)) {
            return (string) $fallback;
        }

        // `url(...)` and `expression(...)` are the two functions that turn a
        // declaration into a request or a script.
        if (1 === preg_match('/\b(?:url|expression|javascript)\s*\(/i', $trimmed)) {
            return (string) $fallback;
        }

        return $trimmed;
    }
}
