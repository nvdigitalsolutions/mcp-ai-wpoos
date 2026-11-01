// ---------------------------------------------------------------------------
// sanitize.ts — the XSS gate between WordPress HTML and Astro `set:html`.
//
// Versioned, allowlisted, never a passthrough (Proposal 096 §2.4): WordPress
// authors can inject arbitrary markup, and the content layer hands rendered
// HTML straight to `set:html`. Bumping SANITIZE_VERSION when the allowlist
// changes lets generated projects detect a stale vendored copy.
// ---------------------------------------------------------------------------

import sanitizeHtmlLib from 'sanitize-html';

/** Bump on every allowlist/behaviour change. Generated projects carry this. */
export const SANITIZE_VERSION = 1;

/** The default sanitize-html options — Gutenberg output minus scripts/events. */
export const SANITIZE_OPTIONS: sanitizeHtmlLib.IOptions = {
	// Gutenberg block vocabulary: layout, text, media, tables.
	allowedTags: [
		'p',
		'a',
		'br',
		'hr',
		'strong',
		'b',
		'em',
		'i',
		'del',
		's',
		'code',
		'pre',
		'mark',
		'span',
		'div',
		'section',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'ul',
		'ol',
		'li',
		'blockquote',
		'figure',
		'figcaption',
		'img',
		'table',
		'thead',
		'tbody',
		'tfoot',
		'tr',
		'th',
		'td',
		'caption',
		'colgroup',
		'col',
		// oEmbed output (YouTube/Vimeo/etc.) — host-restricted below.
		'iframe',
	],
	allowedAttributes: {
		a: ['href', 'title', 'target', 'rel', 'class'],
		img: ['src', 'alt', 'title', 'width', 'height', 'srcset', 'sizes', 'loading', 'decoding', 'class'],
		figure: ['class'],
		figcaption: ['class'],
		blockquote: ['class', 'cite'],
		code: ['class'],
		pre: ['class'],
		span: ['class'],
		div: ['class'],
		section: ['class'],
		ul: ['class'],
		ol: ['class', 'start'],
		li: ['class', 'value'],
		table: ['class'],
		thead: ['class'],
		tbody: ['class'],
		tfoot: ['class'],
		tr: ['class'],
		th: ['class', 'colspan', 'rowspan', 'scope'],
		td: ['class', 'colspan', 'rowspan'],
		caption: ['class'],
		colgroup: ['span'],
		col: ['span'],
		iframe: ['src', 'title', 'width', 'height', 'frameborder', 'allowfullscreen', 'loading', 'referrerpolicy'],
	},
	allowedSchemes: ['http', 'https', 'mailto'],
	allowedSchemesByTag: {
		img: ['http', 'https', 'data'], // data: for tiny inline images Gutenberg can emit
	},
	// Embeds only from the mainstream, privacy-respecting players.
	allowedIframeHostnames: [
		'www.youtube.com',
		'www.youtube-nocookie.com',
		'player.vimeo.com',
		'open.spotify.com',
		'w.soundcloud.com',
	],
	allowProtocolRelative: false,
	// Style attributes are a CSS-injection vector and fight the design system —
	// drop them; the site tokens own the look.
	disallowedTagsMode: 'discard',
};

const transformTags: sanitizeHtmlLib.IOptions['transformTags'] = {
	// Every outbound link that opens a new tab gets the safe rel pair.
	a: (_tagName: string, attribs: { [key: string]: string }) => {
		if (attribs.target === '_blank') {
			attribs.rel = 'noopener noreferrer';
		}
		return { tagName: 'a', attribs };
	},
};

/** Sanitize rendered WordPress HTML for `set:html` (allowlist, versioned). */
export function sanitizeHtml(html: string): string {
	if (!html) {
		return '';
	}
	return sanitizeHtmlLib(html, {
		...SANITIZE_OPTIONS,
		transformTags,
	});
}
