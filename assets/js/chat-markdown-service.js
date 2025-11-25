/**
 * Markdown Rendering Service for WP oOS Chat
 * 
 * Handles markdown parsing and rendering with code block support, lists, and formatting.
 * This is a self-contained service that can be used independently.
 * 
 * @since 1.0.0
 */

(function(window) {
	'use strict';

	/**
	 * Escape HTML to prevent XSS.
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
	 * Replace all occurrences of search with replacement.
	 * 
	 * @param {string} text - Text to search in
	 * @param {string} search - String to search for
	 * @param {string} replacement - Replacement string
	 * @return {string} Result
	 */
	function replaceAll(text, search, replacement) {
		return text.split(search).join(replacement);
	}

	/**
	 * Render inline label with inline code support.
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
			processed = replaceAll(processed, item.placeholder, '<code>' + escapeHtml(item.code) + '</code>');
		});

		return processed;
	}

	/**
	 * Render markdown to HTML.
	 * 
	 * @param {string} text - Markdown text
	 * @return {string} HTML output
	 */
	function renderMarkdown(text) {
		if (!text) {
			return '';
		}

		const placeholderBase = 'WP_MCP_AI_' + Math.random().toString(36).slice(2);
		const codeBlocks = [];
		const inlineCodes = [];
		const images = [];
		const links = [];
		let processed = String(text).replace(/\r\n|\r|\u2028|\u2029/g, '\n');

		// Extract code blocks
		processed = processed.replace(/```([\w+-]*)\n?([\s\S]*?)```/g, function (match, language, code) {
			const placeholder = '@@' + placeholderBase + '_CODE_' + codeBlocks.length + '@@';
			codeBlocks.push({
				placeholder: placeholder,
				language: (language || '').trim(),
				code: code.replace(/\s+$/, ''),
			});
			return placeholder;
		});

		// Extract inline code
		processed = processed.replace(/`([^`]+)`/g, function (match, code) {
			const placeholder = '@@' + placeholderBase + '_INLINE_' + inlineCodes.length + '@@';
			inlineCodes.push({
				placeholder: placeholder,
				code: code,
			});
			return placeholder;
		});

		// Extract images BEFORE links (images use ![alt](url) syntax)
		processed = processed.replace(/!\[([^\]]*)\]\(([^)]+)\)/g, function (match, alt, url) {
			const placeholder = '@@' + placeholderBase + '_IMAGE_' + images.length + '@@';
			images.push({
				placeholder: placeholder,
				alt: alt,
				url: url,
			});
			return placeholder;
		});

		// Extract links (after images to avoid matching image syntax)
		processed = processed.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (match, label, url) {
			const placeholder = '@@' + placeholderBase + '_LINK_' + links.length + '@@';
			links.push({
				placeholder: placeholder,
				label: label,
				url: url,
			});
			return placeholder;
		});

		processed = escapeHtml(processed);

		const codePlaceholderMap = {};
		codeBlocks.forEach(function (item) {
			codePlaceholderMap[item.placeholder] = true;
		});

		const lines = processed.split('\n');
		const htmlParts = [];
		let paragraphLines = [];
		const listStack = [];

		function flushParagraph() {
			if (!paragraphLines.length) {
				return;
			}
			htmlParts.push('<p>' + paragraphLines.join('<br />') + '</p>');
			paragraphLines = [];
		}

		function flushAllLists() {
			while (listStack.length > 0) {
				const list = listStack.pop();
				if (list.items.length > 0) {
					const html = '<' + list.type + '>' + list.items.join('') + '</' + list.type + '>';
					if (listStack.length > 0) {
						const parent = listStack[listStack.length - 1];
						if (parent.items.length > 0) {
							const lastItemIndex = parent.items.length - 1;
							parent.items[lastItemIndex] = parent.items[lastItemIndex].replace('</li>', html + '</li>');
						}
					} else {
						htmlParts.push(html);
					}
				}
			}
		}

		function getIndentLevel(line) {
			const match = line.match(/^(\s*)/);
			if (!match) {
				return 0;
			}
			const spaces = match[1].replace(/\t/g, '  ');
			return Math.floor(spaces.length / 2);
		}

		function processListItem(indent, listType, itemText) {
			flushParagraph();

			const targetDepth = indent;

			while (listStack.length > targetDepth + 1) {
				const list = listStack.pop();
				if (list.items.length > 0) {
					const html = '<' + list.type + '>' + list.items.join('') + '</' + list.type + '>';
					if (listStack.length > 0) {
						const parent = listStack[listStack.length - 1];
						if (parent.items.length > 0) {
							parent.items[parent.items.length - 1] = parent.items[parent.items.length - 1].replace('</li>', html + '</li>');
						}
					}
				}
			}

			if (listStack.length === 0 || listStack.length <= targetDepth) {
				listStack.push({ type: listType, items: [] });
			} else {
				const currentList = listStack[listStack.length - 1];
				if (currentList.type !== listType) {
					const list = listStack.pop();
					if (list.items.length > 0) {
						const html = '<' + list.type + '>' + list.items.join('') + '</' + list.type + '>';
						if (listStack.length > 0) {
							const parent = listStack[listStack.length - 1];
							if (parent.items.length > 0) {
								parent.items[parent.items.length - 1] = parent.items[parent.items.length - 1].replace('</li>', html + '</li>');
							}
						} else {
							htmlParts.push(html);
						}
					}
					listStack.push({ type: listType, items: [] });
				}
			}

			const currentList = listStack[listStack.length - 1];
			currentList.items.push('<li>' + formatInline(itemText) + '</li>');
		}

		lines.forEach(function (line) {
			const trimmed = line.trim();

			if (!trimmed) {
				flushParagraph();
				flushAllLists();
				return;
			}

			if (codePlaceholderMap[trimmed]) {
				flushParagraph();
				flushAllLists();
				htmlParts.push(trimmed);
				return;
			}

			if (trimmed.indexOf('&gt;') === 0) {
				flushParagraph();
				flushAllLists();
				htmlParts.push('<blockquote><p>' + formatInline(trimmed.replace(/^&gt;\s*/, '')) + '</p></blockquote>');
				return;
			}

			const headingMatch = trimmed.match(/^(#{1,6})\s+(.*)$/);
			if (headingMatch) {
				flushParagraph();
				flushAllLists();
				const level = headingMatch[1].length;
				const headingText = formatInline(headingMatch[2]);
				htmlParts.push('<h' + level + '>' + headingText + '</h' + level + '>');
				return;
			}

			const indent = getIndentLevel(line);
			const orderedMatch = trimmed.match(/^(\d+)\.\s+(.*)$/);
			if (orderedMatch) {
				processListItem(indent, 'ol', orderedMatch[2]);
				return;
			}

			const bulletMatch = trimmed.match(/^[-*+]\s+(.*)$/);
			if (bulletMatch) {
				processListItem(indent, 'ul', bulletMatch[1]);
				return;
			}

			if (listStack.length > 0) {
				flushAllLists();
			}

			paragraphLines.push(formatInline(line));
		});

		flushParagraph();
		flushAllLists();

		let html = htmlParts.join('');

		// Restore inline code
		inlineCodes.forEach(function (item) {
			html = replaceAll(html, item.placeholder, '<code>' + escapeHtml(item.code) + '</code>');
		});

		// Restore images
		images.forEach(function (item) {
			const src = sanitizeUrl(item.url);
			const altText = escapeHtml(item.alt || '');
			let imgHtml = '<img src="' + src + '" alt="' + altText + '" class="wp-mcp-ai-chat__image" loading="lazy"';
			imgHtml += ' />';
			html = replaceAll(html, item.placeholder, imgHtml);
		});

		// Restore links
		links.forEach(function (item) {
			const labelHtml = renderInlineLabel(item.label);
			const href = sanitizeUrl(item.url);
			let attributes = ' href="' + href + '"';
			if (/^https?:/i.test(href)) {
				attributes += ' target="_blank" rel="noopener noreferrer"';
			}
			html = replaceAll(html, item.placeholder, '<a' + attributes + '>' + labelHtml + '</a>');
		});

		// Restore code blocks
		codeBlocks.forEach(function (item) {
			const language = item.language.replace(/[^a-z0-9+#.-]/gi, '').toLowerCase();
			const className = language ? ' class="language-' + language + '"' : '';
			const codeHtml = '<pre class="wp-mcp-ai-chat__code-block"><code' + className + '>' + escapeHtml(item.code) + '</code></pre>';
			html = replaceAll(html, item.placeholder, codeHtml);
		});

		return html;
	}

	// Export public API
	window.wpMcpAiChatMarkdown = {
		renderMarkdown: renderMarkdown,
		renderInlineLabel: renderInlineLabel,
		escapeHtml: escapeHtml,
		sanitizeUrl: sanitizeUrl,
		formatInline: formatInline
	};

})(window);
