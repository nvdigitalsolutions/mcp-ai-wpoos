<?php

/**
 * Convert HTML to Markdown.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Convert_Html_To_Markdown` (Proposal 164 section
 * 164.77). The WordPress description claims turndown.js; the code is a chain of
 * regular expressions, and its own usage guidance says so. The port keeps the
 * chain, because replacing it with a real converter would change every output.
 *
 * ## What is pinned
 *
 * - **The conversion is regex-based and order-dependent.** Images are matched
 *   with `src` before `alt`; an `<img>` whose `alt` comes first falls through to
 *   the second pattern and loses its alt text.
 * - **Links keep only the double-quoted `href`.** Single-quoted attributes are
 *   not matched, and the anchor's inner markup survives into the link text.
 * - **`preserve_links: false` keeps the text and drops the target;**
 *   `preserve_images: false` removes the image entirely.** The asymmetry is the
 *   original's.
 * - **`compression` is a signed percentage string.** A conversion that grows the
 *   document reports a negative percentage, e.g. `-12.5%`.
 *
 * One edge is narrowed rather than copied: WordPress rejects HTML that is
 * `empty()`, which also rejects the string `"0"`. Here only a missing or
 * non-string value is refused, because `"0"` is a valid document.
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
use Nvoos\Core\Support\Text;
use Nvoos\Core\Tool\AbstractTool;

class ConvertHtmlToMarkdownTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    public function __construct(ErrorFactoryInterface $e)
    {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'convert_html_to_markdown';
    }

    public function getName(): string
    {
        return 'Convert HTML to Markdown';
    }

    public function getDescription(): string
    {
        return 'Convert HTML content to clean, formatted Markdown with preserved formatting. The conversion is regex-based, so complex or malformed HTML may convert imperfectly.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'html'            => array(
                    'type'        => 'string',
                    'description' => 'HTML content to convert.',
                ),
                'preserve_images' => array(
                    'type'        => 'boolean',
                    'description' => 'Preserve image tags (default: true).',
                    'default'     => true,
                ),
                'preserve_links'  => array(
                    'type'        => 'boolean',
                    'description' => 'Preserve hyperlinks (default: true).',
                    'default'     => true,
                ),
            ),
            'required'             => array('html'),
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

        $html = $arguments['html'] ?? null;

        if (null === $html || '' === $html) {
            return $this->errors->create('html_required', 'HTML content is required.');
        }

        if (! is_string($html)) {
            return $this->errors->create('invalid_html', 'HTML content must be a string.');
        }

        $preserveImages = $this->boolParam($arguments, 'preserve_images', true);
        $preserveLinks  = $this->boolParam($arguments, 'preserve_links', true);
        $markdown       = $this->convert($html, $preserveImages, $preserveLinks);
        $original       = strlen($html);
        $converted      = strlen($markdown);

        return $this->success(
            'HTML converted to Markdown.',
            array(
                'markdown'        => $markdown,
                'original_length' => $original,
                'markdown_length' => $converted,
                'compression'     => round((1 - ( $converted / $original )) * 100, 2) . '%',
            )
        );
    }

    /**
     * The WordPress conversion chain, in its original order.
     */
    private function convert(string $html, bool $preserveImages, bool $preserveLinks): string
    {
        $markdown = $html;

        foreach (array(1, 2, 3, 4, 5, 6) as $level) {
            $markdown = $this->sub('/<h' . $level . '[^>]*>(.*?)<\/h' . $level . '>/is', str_repeat('#', $level) . " $1\n\n", $markdown);
        }

        $markdown = $this->sub('/<p[^>]*>(.*?)<\/p>/is', "$1\n\n", $markdown);
        $markdown = $this->sub('/<(strong|b)[^>]*>(.*?)<\/\1>/is', '**$2**', $markdown);
        $markdown = $this->sub('/<(em|i)[^>]*>(.*?)<\/\1>/is', '*$2*', $markdown);

        if ($preserveLinks) {
            $markdown = $this->sub('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', '[$2]($1)', $markdown);
        } else {
            $markdown = $this->sub('/<a[^>]*>(.*?)<\/a>/is', '$1', $markdown);
        }

        if ($preserveImages) {
            $markdown = $this->sub('/<img[^>]+src="([^"]+)"[^>]*alt="([^"]*)"[^>]*>/is', '![$2]($1)', $markdown);
            $markdown = $this->sub('/<img[^>]+src="([^"]+)"[^>]*>/is', '![]($1)', $markdown);
        } else {
            $markdown = $this->sub('/<img[^>]*>/is', '', $markdown);
        }

        $markdown = $this->sub('/<li[^>]*>(.*?)<\/li>/is', "- $1\n", $markdown);
        $markdown = $this->sub('/<\/?[uo]l[^>]*>/is', "\n", $markdown);
        $markdown = $this->sub('/<code[^>]*>(.*?)<\/code>/is', '`$1`', $markdown);
        $markdown = $this->sub('/<pre[^>]*>(.*?)<\/pre>/is', "```\n$1\n```\n\n", $markdown);

        $markdown = Text::multiline($markdown);
        $markdown = $this->sub('/\n{3,}/', "\n\n", $markdown);

        return trim($markdown);
    }

    /**
     * `preg_replace()` with its null-on-error collapsed to the subject.
     */
    private function sub(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        return null === $result ? $subject : $result;
    }
}
