<?php
/**
 * Trait for converting Markdown content to HTML in tool inputs.
 *
 * LLMs frequently return Markdown-formatted content. This trait detects
 * Markdown and converts it to HTML before it reaches wp_kses_post and
 * block-editor processing, so headings, bold, lists etc. render correctly
 * instead of appearing as raw `# Heading` or `**bold**` text.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Markdown_Converter
 *
 * Provides maybe_convert_markdown() and convert_markdown_to_html() methods
 * that can be used by any tool that accepts LLM-generated content destined
 * for post_content.
 */
trait WP_MCP_AI_Tool_Markdown_Converter {

	/**
	 * Detects whether content appears to be Markdown and converts it to HTML.
	 *
	 * Content that already contains HTML tags or block markers is returned
	 * unchanged so we never double-convert or mangle existing markup.
	 *
	 * @since 1.1.0
	 *
	 * @param string $content Raw content that may contain Markdown.
	 * @return string Content with Markdown converted to HTML, or unchanged.
	 */
	private function maybe_convert_markdown( $content ) {
		$trimmed = trim( $content );

		// Never convert empty content.
		if ( '' === $trimmed ) {
			return $content;
		}

		// If content already contains HTML tags or block markers, skip.
		if ( false !== strpos( $trimmed, '<' ) || false !== strpos( $trimmed, '<!-- wp:' ) ) {
			return $content;
		}

		// Quick heuristic: does it have Markdown markers?
		$has_markdown = (
			preg_match( '/^#{1,6}\s/m', $trimmed ) ||
			preg_match( '/\*\*|__/', $trimmed ) ||
			preg_match( '/^[*-]\s/m', $trimmed ) ||
			preg_match( '/^\d+\.\s/m', $trimmed ) ||
			preg_match( '/\[.+\]\(.+\)/', $trimmed ) ||
			preg_match( '/^>/m', $trimmed ) ||
			preg_match( '/```/', $trimmed )
		);

		if ( ! $has_markdown ) {
			return $content;
		}

		return $this->convert_markdown_to_html( $trimmed );
	}

	/**
	 * Converts common Markdown patterns to HTML using regex.
	 *
	 * This is a lightweight converter covering the patterns LLMs most
	 * frequently produce: ATX headings, bold/italic, lists, links, images,
	 * fenced code blocks, blockquotes, horizontal rules, and strikethrough.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text Markdown text.
	 * @return string HTML.
	 */
	private function convert_markdown_to_html( $text ) {
		// Normalize line endings.
		$text = preg_replace( "/\r\n?/", "\n", $text );

		// 1. Fenced code blocks: ```...``` → <pre><code>...</code></pre>
		//    Placeholder-protect these first so inner content is untouched.
		$code_blocks = array();
		$text        = preg_replace_callback(
			'/```(\w*)\n(.*?)```/s',
			function ( $m ) use ( &$code_blocks ) {
				$placeholder  = '<!--CODEBLOCK' . count( $code_blocks ) . '-->';
				$lang         = $m[1] ? ' class="language-' . esc_attr( $m[1] ) . '"' : '';
				$code_blocks[] = '<pre><code' . $lang . '>' . esc_html( trim( $m[2] ) ) . '</code></pre>';
				return $placeholder;
			},
			$text
		);

		// 2. Inline code: `code` → <code>code</code>
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );

		// 3. Images (before links so ![alt](url) is not mis-matched as a link).
		$text = preg_replace( '/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" />', $text );

		// 4. Links: [text](url).
		$text = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text );

		// 5. ATX headings (must be at line start).
		$text = preg_replace( '/^######\s+(.+)$/m', '<h6>$1</h6>', $text );
		$text = preg_replace( '/^#####\s+(.+)$/m', '<h5>$1</h5>', $text );
		$text = preg_replace( '/^####\s+(.+)$/m', '<h4>$1</h4>', $text );
		$text = preg_replace( '/^###\s+(.+)$/m', '<h3>$1</h3>', $text );
		$text = preg_replace( '/^##\s+(.+)$/m', '<h2>$1</h2>', $text );
		$text = preg_replace( '/^#\s+(.+)$/m', '<h1>$1</h1>', $text );

		// 6. Horizontal rules.
		$text = preg_replace( '/^[-*_]{3,}\s*$/m', '<hr />', $text );

		// 7. Bold + italic (three asterisks).
		$text = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text );

		// 8. Bold: **text** or __text__.
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__(.+?)__/', '<strong>$1</strong>', $text );

		// 9. Italic: *text* or _text_.
		$text = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $text );
		$text = preg_replace( '/_(.+?)_/', '<em>$1</em>', $text );

		// 10. Strikethrough.
		$text = preg_replace( '/~~(.+?)~~/', '<del>$1</del>', $text );

		// 11. Unordered lists: group consecutive `- ` / `* ` lines.
		$text = preg_replace( '/^([*-])\s+(.+)$/m', '<li>$2</li>', $text );
		$text = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $text );
		// Merge adjacent <ul> blocks.
		$text = preg_replace( '/<\/ul>\s*<ul>/', '', $text );

		// 12. Ordered lists: group consecutive `1. ` lines.
		$text = preg_replace( '/^\d+\.\s+(.+)$/m', '<li>$1</li>', $text );
		$text = preg_replace( '/(<li>.*<\/li>)/s', '<ol>$1</ol>', $text );
		// Merge adjacent <ol> blocks.
		$text = preg_replace( '/<\/ol>\s*<ol>/', '', $text );

		// 13. Blockquotes.
		$text = preg_replace( '/^>\s?(.+)$/m', '<blockquote>$1</blockquote>', $text );
		// Merge adjacent <blockquote> blocks.
		$text = preg_replace( '/<\/blockquote>\s*<blockquote>/', "\n", $text );

		// 14. Restore code blocks from placeholders.
		foreach ( $code_blocks as $i => $block ) {
			$text = str_replace( '<!--CODEBLOCK' . $i . '-->', $block, $text );
		}

		// 15. Wrap remaining text lines in <p> tags.
		$lines  = explode( "\n", $text );
		$output = array();
		$buffer = '';
		foreach ( $lines as $line ) {
			$trimmed_line = trim( $line );
			// Skip lines that are already block-level HTML.
			if ( '' === $trimmed_line ) {
				if ( '' !== $buffer ) {
					$output[] = '<p>' . trim( $buffer ) . '</p>';
					$buffer    = '';
				}
				continue;
			}
			if ( preg_match( '/^<(h[1-6]|ul|ol|li|blockquote|pre|hr|table|div|img)\b/', $trimmed_line ) ) {
				if ( '' !== $buffer ) {
					$output[] = '<p>' . trim( $buffer ) . '</p>';
					$buffer    = '';
				}
				$output[] = $trimmed_line;
			} else {
				$buffer .= ( '' === $buffer ? '' : '<br />' ) . $line;
			}
		}
		if ( '' !== $buffer ) {
			$output[] = '<p>' . trim( $buffer ) . '</p>';
		}

		return implode( "\n", $output );
	}
}
