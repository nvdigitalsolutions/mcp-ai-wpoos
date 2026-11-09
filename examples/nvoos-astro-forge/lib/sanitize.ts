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
export const SANITIZE_VERSION = 2;

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
		// Form controls (Proposal 114 §3.9) — the RENDER half of the form
		// bridge: markup survives so the semantic classifier can rebuild it
		// as the Form design component. `action`/`method` are NOT allowed —
		// the static site never posts to WordPress directly; the submission
		// endpoint is rebuilt from the profile config at scaffold time.
		'form',
		'label',
		'input',
		'textarea',
		'select',
		'option',
		'optgroup',
		'button',
		'fieldset',
		'legend',
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
		div: ['class', 'id'],
		section: ['class', 'id'],
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
		// Functional attributes only — no `action`/`method`/`onsubmit`/
		// `formaction`/`style`, and no `on*` handlers anywhere. `value` is
		// additionally vetted in transformTags (javascript: prefix dies).
		form: ['class', 'name', 'id', 'role', 'aria-label'],
		label: ['for', 'class', 'id'],
		input: [
			'name',
			'type',
			'value',
			'placeholder',
			'required',
			'checked',
			'autocomplete',
			'class',
			'id',
			'aria-label',
			'maxlength',
			'min',
			'max',
			'step',
			'pattern',
			'multiple',
			'disabled',
			'readonly',
		],
		textarea: ['name', 'placeholder', 'required', 'rows', 'cols', 'autocomplete', 'class', 'id', 'aria-label', 'maxlength'],
		select: ['name', 'required', 'autocomplete', 'class', 'id', 'aria-label', 'multiple', 'disabled'],
		option: ['value', 'selected', 'class'],
		optgroup: ['label'],
		button: ['type', 'class', 'id', 'aria-label'],
		fieldset: ['class'],
		legend: ['class'],
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

const JAVASCRIPT_URL = /^\s*javascript\s*:/i;

/** A plain HTML identifier — no spaces, no quotes, no leading digit. */
const SAFE_ANCHOR_ID = /^[a-z][a-z0-9_-]{0,40}$/i;
/** Ids the forge owns: a source anchor must not shadow these. */
const RESERVED_ANCHOR_ID = /^(nvoos-|pagefind|ds-)/i;

/** Keep a safe, non-reserved `id`; drop anything else. */
function anchorId( tagName: string, attribs: { [key: string]: string } ) {
	const id = attribs.id;
	if ( typeof id === 'string' && ( ! SAFE_ANCHOR_ID.test( id ) || RESERVED_ANCHOR_ID.test( id ) ) ) {
		delete attribs.id;
	}
	return { tagName, attribs };
}

const transformTags: sanitizeHtmlLib.IOptions['transformTags'] = {
	// In-page anchors (one-pager navigation). The source's `id` on a section or
	// div IS the navigation contract — `#about` must land on the rebuilt band —
	// so it survives sanitisation. Only a plain identifier is kept, and a source
	// id may never shadow an id the forge itself owns.
	section: (tagName: string, attribs: { [key: string]: string }) => anchorId(tagName, attribs),
	div: (tagName: string, attribs: { [key: string]: string }) => anchorId(tagName, attribs),
	// Every outbound link that opens a new tab gets the safe rel pair.
	a: (_tagName: string, attribs: { [key: string]: string }) => {
		if (attribs.target === '_blank') {
			attribs.rel = 'noopener noreferrer';
		}
		return { tagName: 'a', attribs };
	},
	// Form-control values that open with a javascript: URL scheme are dropped
	// (a value is inert text, but the XSS gate is fail-closed by default).
	input: (tagName: string, attribs: { [key: string]: string }) => {
		if (typeof attribs.value === 'string' && JAVASCRIPT_URL.test(attribs.value)) {
			delete attribs.value;
		}
		return { tagName, attribs };
	},
	textarea: (tagName: string, attribs: { [key: string]: string }) => {
		if (typeof attribs.value === 'string' && JAVASCRIPT_URL.test(attribs.value)) {
			delete attribs.value;
		}
		return { tagName, attribs };
	},
	option: (tagName: string, attribs: { [key: string]: string }) => {
		if (typeof attribs.value === 'string' && JAVASCRIPT_URL.test(attribs.value)) {
			delete attribs.value;
		}
		return { tagName, attribs };
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
