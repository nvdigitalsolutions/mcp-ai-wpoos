/**
 * Markdown Rendering Service for NV oOS Chat — TypeScript edition.
 *
 * Uses marked (CommonMark parser) + DOMPurify (XSS sanitisation).
 * Replaces ~240 lines of custom markdown parsing.
 *
 * NOTE: This module imports `marked` and `dompurify` at the top level.
 * esbuild handles these imports during the build.
 *
 * @package NV_MCP_AI
 * @since   1.2.0
 */

import { marked, Renderer } from 'marked';
import DOMPurify from 'dompurify';

// ── Configure marked ─────────────────────────────────────────────────

// Note: marked v9+ does not support breaks/gfm/sanitize via setOptions.
// These are defaults or handled by our custom renderer + DOMPurify.

const renderer = new Renderer();

renderer.code = function (
	this: Renderer,
	code: string,
	language: string,
): string {
	const safeCode = code || '';
	const lang = ( language || '' ).replace( /[^a-z0-9+#.-]/gi, '' ).toLowerCase();
	const className = lang ? ' class="language-' + lang + '"' : '';
	return '<pre class="wp-mcp-ai-chat__code-block"><code' + className + '>' + escapeHtml( safeCode ) + '</code></pre>';
};

renderer.image = function (
	this: Renderer,
	href: string,
	title: string | null,
	text: string,
): string {
	const safeHref = href || '';
	const safeTitle = title || '';
	const safeText = text || '';
	const titleAttr = safeTitle ? ' title="' + safeTitle + '"' : '';
	return '<img src="' + safeHref + '" alt="' + safeText + '"' + titleAttr + ' class="wp-mcp-ai-chat__image" loading="lazy" />';
};

marked.use( { renderer } );

// ── Exports ──────────────────────────────────────────────────────────

export function escapeHtml( text: string ): string {
	return String( text ).replace( /[&<>"']/g, ( ch ) => {
		switch ( ch ) {
			case '&': return '&amp;';
			case '<': return '&lt;';
			case '>': return '&gt;';
			case '"': return '&quot;';
			case '\'': return '&#39;';
			default: return ch;
		}
	} );
}

export function sanitizeUrl( url: string ): string {
	if ( ! url ) { return '#'; }
	const trimmed = url.trim();
	if ( ! trimmed ) { return '#'; }

	try {
		const parsed = new URL( trimmed, window.location.origin );
		const protocol = parsed.protocol.replace( /:$/, '' ).toLowerCase();
		if ( protocol && ! [ 'http', 'https', 'mailto', 'tel' ].includes( protocol ) ) {
			return '#';
		}
	} catch {
		if ( ! /^https?:/i.test( trimmed ) && ! /^mailto:/i.test( trimmed ) && ! /^tel:/i.test( trimmed ) ) {
			return '#';
		}
	}
	return trimmed.replace( /"/g, '%22' );
}

export function formatInline( text: string ): string {
	let result = text;
	result = result.replace( /~~(?=\S)(.+?)(?<=\S)~~/g, '<del>$1</del>' );
	result = result.replace( /\*\*(?=\S)(.+?)(?<=\S)\*\*/g, '<strong>$1</strong>' );
	result = result.replace( /\*(?=\S)(.+?)(?<=\S)\*/g, '<em>$1</em>' );
	return result;
}

export function renderInlineLabel( text: string ): string {
	if ( ! text ) { return ''; }

	const inlineBase = 'WP_MCP_AI_INLINE_' + Math.random().toString( 36 ).slice( 2 );
	const inlineCodes: { placeholder: string; code: string }[] = [];
	let processed = String( text ).replace( /\r\n|\r|\u2028|\u2029/g, ' ' );

	processed = processed.replace( /`([^`]+)`/g, ( _match, code: string ) => {
		const placeholder = '@@' + inlineBase + '_CODE_' + inlineCodes.length + '@@';
		inlineCodes.push( { placeholder, code } );
		return placeholder;
	} );

	processed = escapeHtml( processed );
	processed = formatInline( processed );

	for ( const item of inlineCodes ) {
		processed = processed.split( item.placeholder ).join( '<code>' + escapeHtml( item.code ) + '</code>' );
	}

	return processed;
}

export function renderMarkdown( text: string ): string {
	if ( ! text ) { return ''; }

	try {
		const rawHtml = marked.parse( text, { async: false } ) as string;
		const sanitized = DOMPurify.sanitize( rawHtml, {
			ALLOWED_TAGS: [
				'p', 'br', 'strong', 'em', 'code', 'pre', 'a',
				'ul', 'ol', 'li', 'blockquote', 'h1', 'h2',
				'h3', 'h4', 'h5', 'h6', 'del', 'img',
			],
			ALLOWED_ATTR: [ 'href', 'src', 'alt', 'title', 'loading', 'class', 'target', 'rel' ],
			ALLOW_DATA_ATTR: false,
		} );
		return sanitized;
	} catch ( error ) {
		console.error( 'Markdown parsing error:', error );
		return '<p>' + escapeHtml( text ) + '</p>';
	}
}

// ── Backward-compatible global ───────────────────────────────────────

( window as unknown as Record< string, unknown > ).wpMcpAiChatMarkdown = {
	renderMarkdown,
	renderInlineLabel,
	escapeHtml,
	sanitizeUrl,
	formatInline,
};
