/**
 * NV oOS Chat SPA — XSS-safe markdown renderer.
 *
 * Wraps marked + DOMPurify mirroring the security-hardened pattern from the
 * main plugin's chat-markdown-service.js.  Markdown is parsed to HTML, then
 * sanitised through DOMPurify with an allowlist of safe tags and attributes.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.7.0
 */

import { marked } from 'marked';
import DOMPurify from 'dompurify';

// ── One-time marked configuration ───────────────────────────────────────────

marked.setOptions( {
	breaks: true, // GFM line breaks
	gfm: true,    // GitHub Flavored Markdown
} );

// Custom renderer — match the NV oOS CSS class convention so code blocks and
// images pick up the shared chat styles without duplicating rules.
const renderer = new marked.Renderer();

renderer.code = function ( code: string, language: string ): string {
	const safeCode = code || '';
	const lang = ( language || '' )
		.replace( /[^a-z0-9+#.-]/gi, '' )
		.toLowerCase();
	const className = lang ? ` class="language-${ lang }"` : '';
	return (
		'<pre class="nvoos-chat-spa-code-block"><code' +
		className +
		'>' +
		escapeHtml( safeCode ) +
		'</code></pre>'
	);
};

renderer.image = function ( href: string, title: string, text: string ): string {
	const safeHref = href || '';
	const safeTitle = title || '';
	const safeText = text || '';
	const titleAttr = safeTitle ? ` title="${ safeTitle }"` : '';
	return `<img src="${ safeHref }" alt="${ safeText }"${ titleAttr } class="nvoos-chat-spa-img" loading="lazy" />`;
};

marked.use( { renderer } );

// ── HTML escaping (standalone, also used by the renderer) ───────────────────

function escapeHtml( text: string ): string {
	return String( text ).replace( /[&<>"']/g, ( ch ) => {
		switch ( ch ) {
			case '&':
				return '&amp;';
			case '<':
				return '&lt;';
			case '>':
				return '&gt;';
			case '"':
				return '&quot;';
			case "'":
				return '&#39;';
			default:
				return ch;
		}
	} );
}

// ── DOMPurify allowlist (aligns with nvoos-markdown) ────────────────────────

const ALLOWED_TAGS = [
	'p',
	'br',
	'strong',
	'em',
	'code',
	'pre',
	'a',
	'ul',
	'ol',
	'li',
	'blockquote',
	'h1',
	'h2',
	'h3',
	'h4',
	'h5',
	'h6',
	'del',
	'img',
	'table',
	'thead',
	'tbody',
	'tr',
	'th',
	'td',
] as const;

const ALLOWED_ATTR = [
	'href',
	'target',
	'rel',
	'class',
	'src',
	'alt',
	'title',
	'loading',
] as const;

// ── Public API ──────────────────────────────────────────────────────────────

/**
 * Render markdown text to sanitised HTML.
 *
 * Returns an empty string for falsy / empty input so callers don't need to
 * guard before passing the result to `dangerouslySetInnerHTML`.
 */
export function renderMarkdown( text: string ): string {
	if ( ! text ) {
		return '';
	}
	try {
		const rawHtml = marked.parse( text ) as string;
		return DOMPurify.sanitize( rawHtml, {
			ALLOWED_TAGS: ALLOWED_TAGS as unknown as string[],
			ALLOWED_ATTR: ALLOWED_ATTR as unknown as string[],
			ALLOW_DATA_ATTR: false,
		} ) as string;
	} catch {
		// If marked or DOMPurify throws for any reason, fall back to
		// escaped plain text wrapped in a paragraph so the user still sees
		// the content.
		return `<p>${ escapeHtml( text ) }</p>`;
	}
}
