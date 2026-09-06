/**
 * NV oOS Content Graph AI — Shared SSE utilities.
 *
 * Framework-free helpers shared by the admin Chat Tester and (later)
 * the [nvoos_content_graph_chat] shortcode. The SSE parser mirrors the
 * SPA-v2 adapter (addons/pro/assets/spa-v2/src/sse-adapter.ts):
 * event-boundary splitting, eventType tracking, multi-line data fields,
 * and a message_delta fallback for non-JSON payloads.
 *
 * Exposed as window.NvoosContentGraphAiSse in the browser and as a
 * CommonJS module under Jest.
 *
 * @since 1.1.0
 */
(function (root, factory) {
	'use strict';
	var api = factory();
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	}
	if (root) {
		root.NvoosContentGraphAiSse = api;
	}
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
	'use strict';

	// ─── Escaping ──────────────────────────────────────────────────

	/**
	 * Escape a string for safe insertion into HTML.
	 *
	 * @param {string} str Input string.
	 * @return {string} HTML-escaped string.
	 */
	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * Validate a markdown link target — only web and mailto schemes.
	 *
	 * @param {string} href Raw href.
	 * @return {string|null} The safe href, or null when disallowed.
	 */
	function safeUrl(href) {
		if (/^(https?:|mailto:)/i.test(href)) {
			return href;
		}
		return null;
	}

	// ─── SSE parsing ───────────────────────────────────────────────

	/**
	 * Parse a raw SSE buffer into complete frames plus the unconsumed
	 * remainder.
	 *
	 * The server frames events as:
	 *
	 *     event: message\n
	 *     data: {…}\n
	 *     \n
	 *
	 * @param {string} buffer Raw text received so far.
	 * @return {{frames: Array<object>, rest: string}} Parsed frames and remainder.
	 */
	function parseSseBuffer(buffer) {
		var frames = [];
		var parts = String(buffer).split('\n\n');
		var rest = parts.pop() || '';

		parts.forEach(function (part) {
			var lines = part.split('\n');
			var dataLines = [];
			var eventType = '';

			lines.forEach(function (line) {
				if (line.indexOf('event:') === 0) {
					eventType = line.slice(6).trim();
				} else if (line.indexOf('data:') === 0) {
					// Strip the 'data:' prefix and one optional space.
					dataLines.push(line.slice(5).replace(/^ /, ''));
				}
			});

			if (dataLines.length === 0) {
				return;
			}

			var raw = dataLines.join('\n');
			if (raw === '' || raw === '[DONE]') {
				return;
			}

			try {
				var parsed = JSON.parse(raw);
				if (eventType === 'error') {
					parsed.type = 'error';
				}
				frames.push(parsed);
			} catch (e) {
				// Non-JSON payload — treat as a raw text delta.
				frames.push({ type: 'message_delta', delta: raw });
			}
		});

		return { frames: frames, rest: rest };
	}

	// ─── Frame extractors ──────────────────────────────────────────

	/**
	 * Extract a token delta from a streaming frame.
	 *
	 * Supports the server's `{choices:[{delta:{content}}]}` shape plus
	 * flat `delta`/`text`/`content` fields for forward compatibility.
	 *
	 * @param {object} frame Parsed SSE frame.
	 * @return {string} Token content ('' when none).
	 */
	function extractDelta(frame) {
		if (!frame || typeof frame !== 'object') {
			return '';
		}
		if (Array.isArray(frame.choices) && frame.choices.length > 0) {
			var delta = frame.choices[0].delta;
			if (delta && typeof delta.content === 'string') {
				return delta.content;
			}
		}
		if (typeof frame.delta === 'string') {
			return frame.delta;
		}
		if (typeof frame.text === 'string') {
			return frame.text;
		}
		if (typeof frame.content === 'string') {
			return frame.content;
		}
		return '';
	}

	/**
	 * Extract reasoning/thinking deltas when present.
	 *
	 * @param {object} frame Parsed SSE frame.
	 * @return {string} Reasoning content ('' when none).
	 */
	function extractReasoning(frame) {
		if (!frame || typeof frame !== 'object') {
			return '';
		}
		if (Array.isArray(frame.choices) && frame.choices.length > 0) {
			var delta = frame.choices[0].delta || {};
			if (typeof delta.reasoning_content === 'string') {
				return delta.reasoning_content;
			}
			if (typeof delta.thinking === 'string') {
				return delta.thinking;
			}
		}
		return '';
	}

	/**
	 * Extract the authoritative assistant text from the final frame
	 * (`{assistant_id, data, tool_results, cost}`).
	 *
	 * @param {object} frame Parsed SSE frame.
	 * @return {string|null} Final content, or null when absent.
	 */
	function extractFinalContent(frame) {
		if (!frame || typeof frame !== 'object') {
			return null;
		}
		var data = frame.data;
		if (!data || !Array.isArray(data.choices) || data.choices.length === 0) {
			return null;
		}
		var message = data.choices[0].message;
		if (message && typeof message.content === 'string') {
			return message.content;
		}
		return null;
	}

	/**
	 * Normalise the server cost object into display-friendly fields.
	 *
	 * The server emits {cost_usd, provider, model, is_estimated} plus
	 * prompt_tokens / completion_tokens / agentic_iterations_count.
	 *
	 * @param {object} cost Raw cost object.
	 * @return {object|null} Normalised summary, or null when not usable.
	 */
	function summarizeCost(cost) {
		if (!cost || typeof cost !== 'object') {
			return null;
		}

		function num(v) {
			return typeof v === 'number' && isFinite(v) ? v : null;
		}

		return {
			usd: num(cost.cost_usd),
			estimated: !!cost.is_estimated,
			promptTokens: num(cost.prompt_tokens),
			completionTokens: num(cost.completion_tokens),
			iterations: num(cost.agentic_iterations_count),
			model: typeof cost.model === 'string' ? cost.model : '',
			provider: typeof cost.provider === 'string' ? cost.provider : '',
		};
	}

	// ─── Markdown-lite renderer ────────────────────────────────────

	/**
	 * Inline markdown: `code`, **bold**, *italic*, [links](url).
	 *
	 * Input must already be HTML-escaped; the transforms operate on
	 * escaped text so no markup injection is possible.
	 *
	 * @param {string} escaped Already-escaped text.
	 * @return {string} HTML string.
	 */
	function inlineMarkdown(escaped) {
		// Extract code spans first so their contents are not transformed.
		var codes = [];
		escaped = escaped.replace(/`([^`]+)`/g, function (m, code) {
			codes.push(code);
			return '\u0000' + (codes.length - 1) + '\u0000';
		});

		// Bold.
		escaped = escaped.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		// Italic (single asterisk, not adjacent to others).
		escaped = escaped.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
		// Links — only safe schemes.
		escaped = escaped.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, label, href) {
			var safe = safeUrl(href);
			if (safe === null) {
				return label;
			}
			return '<a href="' + safe + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
		});

		// Restore code spans (already escaped).
		escaped = escaped.replace(/\u0000(\d+)\u0000/g, function (m, idx) {
			return '<code>' + codes[Number(idx)] + '</code>';
		});

		return escaped;
	}

	/**
	 * Render markdown-lite to safe HTML. No external dependencies.
	 *
	 * Supports: fenced code blocks, h1–h3 (rendered as h3–h5 for chat
	 * proportion), blockquotes, unordered/ordered lists, inline code,
	 * bold, italic, links, and paragraphs.
	 *
	 * @param {string} text Raw markdown text.
	 * @return {string} HTML string (always escaped at the source).
	 */
	function renderMarkdownLite(text) {
		var src = String(text == null ? '' : text).replace(/\r\n?/g, '\n');
		if (src === '') {
			return '';
		}

		var lines = src.split('\n');
		var html = [];
		var listType = null;
		var para = [];
		var i = 0;

		function flushList() {
			if (listType) {
				html.push('</' + listType + '>');
				listType = null;
			}
		}

		function flushPara() {
			if (para.length > 0) {
				html.push('<p>' + para.join('<br>') + '</p>');
				para = [];
			}
		}

		while (i < lines.length) {
			var line = lines[i];
			var trimmed = line.trim();

			// Fenced code block.
			var fence = trimmed.match(/^```([\w+-]*)\s*$/);
			if (fence) {
				flushPara();
				flushList();
				var lang = fence[1] || '';
				var codeLines = [];
				i++;
				while (i < lines.length && !/^```\s*$/.test(lines[i].trim())) {
					codeLines.push(lines[i]);
					i++;
				}
				i++; // Skip closing fence.
				var langAttr = lang ? ' class="language-' + escapeHtml(lang) + '"' : '';
				html.push('<pre><code' + langAttr + '>' + escapeHtml(codeLines.join('\n')) + '</code></pre>');
				continue;
			}

			// Headings — # → h3, ## → h4, ### → h5.
			var heading = trimmed.match(/^(#{1,3})\s+(.*)$/);
			if (heading) {
				flushPara();
				flushList();
				var level = heading[1].length + 2;
				html.push(
					'<h' + level + '>' +
					inlineMarkdown(escapeHtml(trimmed.slice(heading[1].length).trim())) +
					'</h' + level + '>'
				);
				i++;
				continue;
			}

			// Blockquote.
			if (/^>\s?/.test(trimmed)) {
				flushPara();
				flushList();
				var quoteLines = [];
				while (i < lines.length && /^>\s?/.test(lines[i].trim())) {
					quoteLines.push(lines[i].trim().replace(/^>\s?/, ''));
					i++;
				}
				html.push('<blockquote>' + inlineMarkdown(escapeHtml(quoteLines.join('<br>'))) + '</blockquote>');
				continue;
			}

			// Unordered list.
			if (/^[-*]\s+/.test(trimmed)) {
				flushPara();
				if (listType !== 'ul') {
					flushList();
					html.push('<ul>');
					listType = 'ul';
				}
				html.push('<li>' + inlineMarkdown(escapeHtml(trimmed.replace(/^[-*]\s+/, ''))) + '</li>');
				i++;
				continue;
			}

			// Ordered list.
			if (/^\d+[.)]\s+/.test(trimmed)) {
				flushPara();
				if (listType !== 'ol') {
					flushList();
					html.push('<ol>');
					listType = 'ol';
				}
				html.push('<li>' + inlineMarkdown(escapeHtml(trimmed.replace(/^\d+[.)]\s+/, ''))) + '</li>');
				i++;
				continue;
			}

			// Blank line ends the current paragraph/list.
			if (trimmed === '') {
				flushPara();
				flushList();
				i++;
				continue;
			}

			// Ordinary text line.
			flushList();
			para.push(inlineMarkdown(escapeHtml(trimmed)));
			i++;
		}

		flushPara();
		flushList();

		return html.join('\n');
	}

	// ─── Public API ────────────────────────────────────────────────

	return {
		escapeHtml: escapeHtml,
		safeUrl: safeUrl,
		parseSseBuffer: parseSseBuffer,
		extractDelta: extractDelta,
		extractReasoning: extractReasoning,
		extractFinalContent: extractFinalContent,
		summarizeCost: summarizeCost,
		renderMarkdownLite: renderMarkdownLite,
	};
});
