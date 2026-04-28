/**
 * TMA Markdown Renderer
 *
 * Lightweight, self-contained markdown-to-HTML renderer for Telegram Mini App
 * chat interfaces. Exposes the same window.wpMcpAiChatMarkdown API used by
 * the main chat-markdown-service so that templates can share one interface.
 *
 * No external dependencies — safe to load directly inside Telegram's WebView.
 *
 * Supported syntax:
 *  - Fenced code blocks  (``` lang\n…\n ```)
 *  - Inline code         (`code`)
 *  - Headings            (# / ## / ### → <h3>)
 *  - Bold                (**text** or __text__)
 *  - Italic              (*text* or _text_)
 *  - Strikethrough       (~~text~~)
 *  - Unordered lists     (- item / * item)
 *  - Ordered lists       (1. item)
 *  - Blockquotes         (> text)
 *  - Links               ([label](url))
 *  - Paragraphs / line-breaks
 *
 * @package   WP_MCP_AI
 * @since     1.1.0
 */
(function (window) {
	'use strict';

	/* ── Helpers ──────────────────────────────────────────────────────────── */

	/**
	 * Escape HTML special chars to prevent XSS.
	 *
	 * @param {string} text Raw text.
	 * @return {string} HTML-escaped text.
	 */
	function escapeHtml(text) {
		return String(text).replace(/[&<>"']/g, function (ch) {
			switch (ch) {
				case '&': return '&amp;';
				case '<': return '&lt;';
				case '>': return '&gt;';
				case '"': return '&quot;';
				case "'": return '&#39;';
				default:  return ch;
			}
		});
	}

	/**
	 * Sanitize a URL – only allow http/https/mailto/tel protocols.
	 *
	 * @param {string} url Candidate URL.
	 * @return {string} Safe URL or '#'.
	 */
	var ALLOWED_URL_PROTOCOLS = ['https:', 'http:', 'mailto:', 'tel:'];

	function sanitizeUrl(url) {
		if (!url) {
			return '#';
		}
		var trimmed = String(url).trim();
		if (!trimmed || trimmed === '#') {
			return trimmed || '#';
		}
		/* Extract protocol from the URL (everything before the first ':') */
		var colonIdx = trimmed.indexOf(':');
		if (colonIdx !== -1) {
			var protocol = trimmed.slice(0, colonIdx + 1).toLowerCase();
			var allowed  = false;
			for (var i = 0; i < ALLOWED_URL_PROTOCOLS.length; i++) {
				if (ALLOWED_URL_PROTOCOLS[i] === protocol) {
					allowed = true;
					break;
				}
			}
			if (!allowed) {
				return '#';
			}
		}
		return trimmed.replace(/"/g, '%22');
	}

	/* ── Inline formatter (used for list items, headings, paragraphs) ─────── */

	/**
	 * Apply inline markdown spans (bold, italic, strikethrough, code, links)
	 * to a string that has already been HTML-escaped, EXCEPT for code spans
	 * which are handled before escaping.
	 *
	 * @param {string} text HTML-escaped text that may contain markdown spans.
	 * @return {string} HTML with inline spans converted.
	 */
	function formatInline(text) {
		var result = text;

		/* Strikethrough: ~~text~~ */
		result = result.replace(/~~(.+?)~~/g, '<del>$1</del>');

		/* Bold: **text** or __text__ (non-greedy) */
		result = result.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
		result = result.replace(/__(.+?)__/g, '<strong>$1</strong>');

		/* Italic: *text* or _text_ (non-greedy, not adjacent to word chars to avoid false matches) */
		result = result.replace(/\*([^*]+?)\*/g, '<em>$1</em>');
		result = result.replace(/_([^_]+?)_/g, '<em>$1</em>');

		/* Links: [label](url) */
		result = result.replace(/\[([^\]]+?)\]\(([^)]+?)\)/g, function (match, label, url) {
			var safe = sanitizeUrl(url);
			var target = /^https?:\/\//i.test(safe) ? ' target="_blank" rel="noopener noreferrer"' : '';
			return '<a href="' + safe + '"' + target + '>' + label + '</a>';
		});

		return result;
	}

	/* ── Main renderer ────────────────────────────────────────────────────── */

	/**
	 * Render markdown text to sanitized HTML.
	 *
	 * @param {string} text Markdown text.
	 * @return {string} HTML string safe for innerHTML insertion.
	 */
	function renderMarkdown(text) {
		if (!text) {
			return '';
		}

		try {
			/* 1. Extract fenced code blocks before any other processing. */
			var codeBlocks = [];
			var FENCE_PH   = '\x00FENCE\x00';
			var processed  = String(text).replace(/```(\w*)\n?([\s\S]*?)```/g, function (match, lang, code) {
				var escapedCode = escapeHtml(code.replace(/^\n|\n$/g, ''));
				var langClass   = lang ? ' class="language-' + lang.replace(/[^a-z0-9+.-]/gi, '').toLowerCase() + '"' : '';
				codeBlocks.push('<pre><code' + langClass + '>' + escapedCode + '</code></pre>');
				return FENCE_PH + (codeBlocks.length - 1) + FENCE_PH;
			});

			/* 2. Extract inline code spans. */
			var inlineCodes = [];
			var INLINE_PH   = '\x00CODE\x00';
			processed = processed.replace(/`([^`]+)`/g, function (match, code) {
				inlineCodes.push('<code>' + escapeHtml(code) + '</code>');
				return INLINE_PH + (inlineCodes.length - 1) + INLINE_PH;
			});

			/* 3. Split into lines for block-level processing. */
			var lines  = processed.split('\n');
			var output = [];
			var i      = 0;

			while (i < lines.length) {
				var line = lines[i];

				/* Blank line — paragraph separator, handled at join stage. */
				if (/^\s*$/.test(line)) {
					output.push('');
					i++;
					continue;
				}

				/* Unordered list block */
				if (/^[ \t]*[-*]\s+/.test(line)) {
					var ulItems = [];
					while (i < lines.length && /^[ \t]*[-*]\s+/.test(lines[i])) {
						ulItems.push('<li>' + formatInline(escapeHtml(lines[i].replace(/^[ \t]*[-*]\s+/, ''))) + '</li>');
						i++;
					}
					output.push('<ul>' + ulItems.join('') + '</ul>');
					continue;
				}

				/* Ordered list block */
				if (/^[ \t]*\d+\.\s+/.test(line)) {
					var olItems = [];
					while (i < lines.length && /^[ \t]*\d+\.\s+/.test(lines[i])) {
						olItems.push('<li>' + formatInline(escapeHtml(lines[i].replace(/^[ \t]*\d+\.\s+/, ''))) + '</li>');
						i++;
					}
					output.push('<ol>' + olItems.join('') + '</ol>');
					continue;
				}

				/* Blockquote block */
				if (/^>/.test(line)) {
					var bqLines = [];
					while (i < lines.length && /^>/.test(lines[i])) {
						bqLines.push(lines[i].replace(/^>\s?/, ''));
						i++;
					}
					output.push('<blockquote>' + formatInline(escapeHtml(bqLines.join('\n'))) + '</blockquote>');
					continue;
				}

				/* Headings # / ## / ### (all map to h3 for visual balance in mobile) */
				var headMatch = line.match(/^(#{1,6})\s+(.+)/);
				if (headMatch) {
					var level   = Math.min(headMatch[1].length, 6);
					var headTag = level <= 2 ? 'h3' : (level === 3 ? 'h4' : 'h5');
					output.push('<' + headTag + '>' + formatInline(escapeHtml(headMatch[2])) + '</' + headTag + '>');
					i++;
					continue;
				}

				/* Fence/inline placeholders pass through as-is (restored later). */
				if (line.indexOf(FENCE_PH) !== -1 || line.indexOf(INLINE_PH) !== -1) {
					output.push(line);
					i++;
					continue;
				}

				/* Normal paragraph text */
				output.push('<p>' + formatInline(escapeHtml(line)) + '</p>');
				i++;
			}

			/* 4. Join, collapse consecutive blank lines, restore placeholders. */
			var html = output.join('\n');

			/* Restore inline code spans */
			html = html.replace(new RegExp(INLINE_PH.replace(/\x00/g, '\\x00') + '(\\d+)' + INLINE_PH.replace(/\x00/g, '\\x00'), 'g'), function (match, idx) {
				return inlineCodes[parseInt(idx, 10)] || '';
			});

			/* Restore fenced code blocks */
			html = html.replace(new RegExp(FENCE_PH.replace(/\x00/g, '\\x00') + '(\\d+)' + FENCE_PH.replace(/\x00/g, '\\x00'), 'g'), function (match, idx) {
				return codeBlocks[parseInt(idx, 10)] || '';
			});

			/* Clean up empty <p></p> tags produced from blank lines */
			html = html.replace(/<p>\s*<\/p>/g, '');

			return html;

		} catch (err) {
			/* Fallback: plain escaped text wrapped in a paragraph */
			return '<p>' + escapeHtml(String(text)) + '</p>';
		}
	}

	/* ── Render inline label (bold/italic/code only, no block elements) ───── */

	/**
	 * Render a single-line label with inline markdown spans.
	 *
	 * @param {string} text Label text (may contain inline markdown).
	 * @return {string} HTML string.
	 */
	function renderInlineLabel(text) {
		if (!text) {
			return '';
		}
		var safe = escapeHtml(String(text));

		/* Inline code */
		safe = safe.replace(/`([^`]+)`/g, function (match, code) {
			return '<code>' + escapeHtml(code) + '</code>';
		});

		return formatInline(safe);
	}

	/* ── Public API ───────────────────────────────────────────────────────── */

	/**
	 * Mirrors the interface exported by chat-markdown-service.js so that TMA
	 * templates can use the same renderMarkdown() call regardless of which
	 * script is loaded.
	 */
	window.wpMcpAiChatMarkdown = {
		renderMarkdown:    renderMarkdown,
		renderInlineLabel: renderInlineLabel,
		escapeHtml:        escapeHtml,
		sanitizeUrl:       sanitizeUrl,
		formatInline:      formatInline,
	};

})(window);
