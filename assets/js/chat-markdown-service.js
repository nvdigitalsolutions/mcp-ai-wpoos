/**
 * Markdown Rendering Service for NV oOS Chat (Optimized)
 * 
 * Uses industry-standard libraries for markdown parsing:
 * - marked: CommonMark-compliant markdown parser
 * - DOMPurify: XSS sanitization
 * 
 * This replaces ~240 lines of custom markdown parsing code with battle-tested libraries.
 * 
 * NOTE: This file uses ES6 imports which are handled by esbuild during the build process.
 * ESLint is configured to allow this via the overrides section in .eslintrc.json.
 * 
 * @package WP_MCP_AI
 * @since 1.1.0
 */

import { marked } from 'marked';
import DOMPurify from 'dompurify';

(function(window) {
	'use strict';

	// Configure marked for security and compatibility
	marked.setOptions({
		breaks: true,        // Enable GFM line breaks
		gfm: true,           // GitHub Flavored Markdown
		headerIds: false,    // Don't add IDs to headers (not needed)
		mangle: false,       // Don't escape email addresses
		sanitize: false,     // We use DOMPurify for sanitization instead
	});

	// Configure custom renderer for code blocks to add NV oOS classes
	const renderer = new marked.Renderer();
	
	// Override code block rendering to add our CSS class
	// Note: marked v17+ uses token-based API where renderers receive an object parameter
	renderer.code = function(token) {
		const code = token.text || '';
		const language = token.lang || '';
		const escapedLang = language.replace(/[^a-z0-9+#.-]/gi, '').toLowerCase();
		const className = escapedLang ? ' class="language-' + escapedLang + '"' : '';
		return '<pre class="wp-mcp-ai-chat__code-block"><code' + className + '>' + code + '</code></pre>';
	};

	// Override image rendering to add our CSS class and lazy loading
	// Note: marked v17+ uses token-based API where renderers receive an object parameter
	renderer.image = function(token) {
		const href = token.href || '';
		const title = token.title || '';
		const text = token.text || '';
		const titleAttr = title ? ' title="' + title + '"' : '';
		return '<img src="' + href + '" alt="' + text + '"' + titleAttr + ' class="wp-mcp-ai-chat__image" loading="lazy" />';
	};

	// Use custom renderer
	marked.use({ renderer: renderer });

	/**
	 * Escape HTML to prevent XSS.
	 * Kept for backward compatibility with existing code.
	 * 
	 * @param {string} text - Text to escape
	 * @return {string} Escaped text
	 */
	function escapeHtml(text) {
		return String(text).replace(/[&<>"']/g, function (character) {
			switch (character) {
				case '&':
					return '&amp;';
				case '<':
					return '&lt;';
				case '>':
					return '&gt;';
				case '"':
					return '&quot;';
				case '\'':
					return '&#39;';
				default:
					return character;
			}
		});
	}

	/**
	 * Sanitize URL to prevent XSS.
	 * Kept for backward compatibility with existing code.
	 * 
	 * @param {string} url - URL to sanitize
	 * @return {string} Sanitized URL or '#' if invalid
	 */
	function sanitizeUrl(url) {
		if (!url) {
			return '#';
		}

		const trimmed = url.trim();
		if (!trimmed) {
			return '#';
		}

		try {
			const parsed = new URL(trimmed, window.location.origin);
			const protocol = parsed.protocol ? parsed.protocol.replace(/:$/, '').toLowerCase() : '';
			if (protocol && ['http', 'https', 'mailto', 'tel'].indexOf(protocol) === -1) {
				return '#';
			}
		} catch (error) {
			if (!/^https?:/i.test(trimmed) && !/^mailto:/i.test(trimmed) && !/^tel:/i.test(trimmed)) {
				return '#';
			}
		}

		return trimmed.replace(/"/g, '%22');
	}

	/**
	 * Format inline markdown (bold, italic, strikethrough).
	 * Kept for backward compatibility with renderInlineLabel.
	 * 
	 * @param {string} text - Text to format
	 * @return {string} Formatted HTML
	 */
	function formatInline(text) {
		let result = text;
		result = result.replace(/~~(?=\S)(.+?)(?<=\S)~~/g, '<del>$1</del>');
		result = result.replace(/\*\*(?=\S)(.+?)(?<=\S)\*\*/g, '<strong>$1</strong>');
		result = result.replace(/\*(?=\S)(.+?)(?<=\S)\*/g, '<em>$1</em>');
		return result;
	}

	/**
	 * Render inline label with inline code support.
	 * Used for link labels and other inline content.
	 * Kept for backward compatibility.
	 * 
	 * @param {string} text - Text to render
	 * @return {string} Rendered HTML
	 */
	function renderInlineLabel(text) {
		if (!text) {
			return '';
		}

		const inlineBase = 'WP_MCP_AI_INLINE_' + Math.random().toString(36).slice(2);
		const inlineCodes = [];
		let processed = String(text).replace(/\r\n|\r|\u2028|\u2029/g, ' ');

		processed = processed.replace(/`([^`]+)`/g, function (match, code) {
			const placeholder = '@@' + inlineBase + '_CODE_' + inlineCodes.length + '@@';
			inlineCodes.push({
				placeholder: placeholder,
				code: code,
			});
			return placeholder;
		});

		processed = escapeHtml(processed);
		processed = formatInline(processed);

		inlineCodes.forEach(function (item) {
			const replaceAll = function(str, search, replacement) {
				return str.split(search).join(replacement);
			};
			processed = replaceAll(processed, item.placeholder, '<code>' + escapeHtml(item.code) + '</code>');
		});

		return processed;
	}

	/**
	 * Render markdown to HTML using marked + DOMPurify.
	 * 
	 * This is the main function that replaces ~240 lines of custom markdown parsing.
	 * 
	 * @param {string} text - Markdown text
	 * @return {string} Sanitized HTML output
	 */
	function renderMarkdown(text) {
		if (!text) {
			return '';
		}

		try {
			// Parse markdown to HTML using marked
			const rawHtml = marked.parse(text);

			// Sanitize with DOMPurify to prevent XSS
			const sanitized = DOMPurify.sanitize(rawHtml, {
				ALLOWED_TAGS: [
					'p', 'br', 'strong', 'em', 'code', 'pre', 'a', 
					'ul', 'ol', 'li', 'blockquote', 'h1', 'h2', 
					'h3', 'h4', 'h5', 'h6', 'del', 'img'
				],
				ALLOWED_ATTR: ['href', 'target', 'rel', 'class', 'src', 'alt', 'title', 'loading'],
				// Allow external links to open in new tabs
				ALLOW_DATA_ATTR: false,
			});

			return sanitized;
		} catch (error) {
			// Fallback to escaped text if parsing fails
			console.error('Markdown parsing error:', error);
			return '<p>' + escapeHtml(text) + '</p>';
		}
	}

	// Export public API (maintain backward compatibility)
	window.wpMcpAiChatMarkdown = {
		renderMarkdown: renderMarkdown,
		renderInlineLabel: renderInlineLabel,
		escapeHtml: escapeHtml,
		sanitizeUrl: sanitizeUrl,
		formatInline: formatInline
	};

})(window);
