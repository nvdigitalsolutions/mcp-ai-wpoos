// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// sanitize.ts — the XSS gate between WordPress HTML and Astro `set:html`.
//
// Versioned, allowlisted, never a passthrough (Proposal 096 §2.4): WordPress
// authors can inject arbitrary markup, and the content layer hands rendered
// HTML straight to `set:html`. Bumping SANITIZE_VERSION when the allowlist
// changes lets generated projects detect a stale vendored copy.
// ---------------------------------------------------------------------------

import sanitizeHtmlLib from 'sanitize-html';

import { readableInk } from './contrast.ts';

/** Bump on every allowlist/behaviour change. Generated projects carry this. */
export const SANITIZE_VERSION = 5;

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
		// 3D product viewer (v5). The tag must SURVIVE: `disallowedTagsMode`
		// discards unknown elements, so an authored <model-viewer> used to vanish
		// from `set:html` with no build error at all. The RUNTIME stays
		// layout-owned — `script` is still forbidden, so the custom element is
		// registered by the generated project, never by content authors.
		'model-viewer',
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
		a: ['href', 'title', 'target', 'rel', 'class', 'id'],
		img: ['src', 'alt', 'title', 'width', 'height', 'srcset', 'sizes', 'loading', 'decoding', 'class'],
		figure: ['class'],
		figcaption: ['class'],
		// The viewer's data contract only: `src`/`poster` are scheme-checked by
		// sanitize-html (http/https/root-relative — a data: or javascript: model
		// URL dies), and there is deliberately NO `environment-image` or
		// `with-credentials`: both would open a fetch/credential surface from a
		// content-authored tag. No `style`, no `on*`.
		'model-viewer': [
			'src',
			'poster',
			'alt',
			'class',
			'id',
			'title',
			'loading',
			'reveal',
			'camera-controls',
			'touch-action',
			'shadow-intensity',
			'auto-rotate',
			'auto-rotate-delay',
			'camera-orbit',
			'field-of-view',
			'ar',
			'ar-modes',
		],
		// NOTE (2026-11-11): `p` deliberately does NOT keep its class YET. A builder
		// styles paragraphs by class (`text-extra-large`, `padding-six-lr`), and
		// stripping them costs fidelity — but restoring them without per-device
		// PARAGRAPH typography facts overshoots (virtuallyjamaica's about band went
		// 742 → 777px and features desktop 1339 → 1876px until the media-height
		// split landed). Land `p: ['class']` together with a paragraph type fact
		// (`bandFacts[].type.paragraph`) and per-class media scoping.
		//   p: ['class'],
		blockquote: ['class', 'cite'],
		code: ['class'],
		pre: ['class'],
		// `data-to-value` is the only data attribute worth keeping: Elementor's
		// counter widget animates from 0, so the START value is the DOM text and
		// the real figure exists only in that attribute. Stripped here, the
		// classifier could only ever read "0" (about-us `14c60321`, 2026-11-24:
		// "≤0" and "0% less" shipped instead of ≤48 and a real percentage). Inert
		// in output — the Stats component renders text, not the attribute.
		span: ['class', 'data-to-value'],
		// Headings keep their CLASS. A builder styles its headings by class
		// (`panel-title`, `heading-style13`, `text-medium`), so stripping it left
		// the rebuilt heading at the template's prose defaults: virtuallyjamaica's
		// accordion titles lost `panel-title` and rendered 17px margins and no
		// 40px icon gutter (measured 2026-11-11).
		h1: ['class'],
		h2: ['class'],
		h3: ['class'],
		h4: ['class'],
		h5: ['class'],
		h6: ['class'],
		// Icon markers (`<i class="icon-flag icon-large">`) — the class is what
		// selects the glyph in the source's own icon sheet; without it every
		// feature/accordion icon renders as an empty box.
		i: ['class', 'aria-hidden'],
		div: ['class', 'id', 'style', 'data-to-value'],
		section: ['class', 'id', 'style'],
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
	// only ONE presentational fact survives, and only for the band/panel
	// elements: a flat `background-color`.
	//
	// WHY: outline-only sources (WPBakery/Brando, Divi, plain HTML) are rebuilt
	// from their OWN sanitized markup, so a panel colour carried inline is the
	// ONLY record of that surface — dropping every style attribute flattened
	// virtuallyjamaica's red #dd3333 “HOW IT WORKS” panel into the page surface.
	// Deliberately excluded: `url()`/`background-image` (a fetch surface),
	// layout properties (they fight the band rhythm), and `color` (text colour
	// stays token-owned so the contrast gate stays meaningful).
	allowedStyles: {
		'*': {
			'background-color': [
				/^#[0-9a-f]{3,8}$/i,
				/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)$/i,
				/^[a-z]{3,20}$/i,
			],
			// A flat photo panel (Brando paints full-bleed columns this way).
			// `url()` values are normalized to http(s)/root-relative by
			// `safeInlineStyle()` below before they ever reach this check.
			'background-image': [/^url\(\s*['"]?(?:https?:\/\/|\/)[^)'"\s]+['"]?\s*\)$/i],
			// Only the DERIVED contrast ink `safeInlineStyle()` appends.
			color: [/^#[0-9a-f]{3,8}$/i],
		},
	},
	// Shortcode artifacts (`[ultimate_spacer height="25"]`) are backend
	// instructions, not copy — they must never render as visible text.
	textFilter: ( text: string ) => text.replace( /\[[a-z][a-z0-9_-]*[^\]]{0,80}\]/gi, '' ),
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

/** A flat colour (hex / rgb(a) / named). */
const COLOR_VALUE = /^(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)|[a-z]{3,20})$/i;
/** A background photo URL — absolute http(s) or root-relative only. */
const BG_URL = /url\(\s*(['"]?)((?:https?:\/\/|\/)[^)'"\s]+)\1\s*\)/i;

/** Normalize a flat colour to #rrggbb (named colours return undefined). */
function toHex6( color: string ): string | undefined {
	const trim = color.trim().toLowerCase();
	const rgb = /^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/.exec( trim );
	if ( rgb ) {
		return '#' + [ rgb[ 1 ], rgb[ 2 ], rgb[ 3 ] ].map( ( c ) => Number( c ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
	}
	if ( /^#[0-9a-f]{3}$/.test( trim ) ) {
		return '#' + trim.slice( 1 ).split( '' ).map( ( c ) => c + c ).join( '' );
	}
	return /^#[0-9a-f]{6}$/.test( trim ) ? trim : undefined;
}

/**
 * The ink a coloured panel needs. DERIVED, never copied from the source: the
 * source's own `color` declarations are dropped (text colour stays
 * design-system owned), but a kept panel colour without a legible ink paints
 * dark body copy on a dark surface — virtuallyjamaica's red #dd3333 “how it
 * works” panel rendered #626262 on #dd3333 (2.6:1). WCAG relative luminance
 * decides, so the panel gets white or near-black ink automatically.
 */
function derivedInk( color: string ): string | undefined {
	const hex = toHex6( color );
	if ( ! hex ) {
		return undefined;
	}
	return readableInk( hex, { dark: '#1a1a1a' } );
}

/**
 * Reduce an inline `style` to the two presentational facts a vendor-agnostic
 * rebuild needs, and nothing else.
 *
 * WHY: an outline-only source (WPBakery/Brando, Divi, plain HTML) is rebuilt
 * from its OWN sanitized markup, so an inline panel colour or photo IS the only
 * record of that surface — virtuallyjamaica's red “HOW IT WORKS” panel and its
 * two photo columns were flattened into the page surface when every style
 * attribute was dropped.
 *
 * KEPT: `background-color`, `background-image` (photo panels).
 * DROPPED: layout/positioning (they fight the band rhythm), `color` (text colour
 * stays token-owned so the contrast gate means something), and any value that
 * is not a flat colour or an http(s)/root-relative image URL.
 */
function safeInlineStyle( style: string ): string | undefined {
	const kept: string[] = [];
	for ( const declaration of style.split( ';' ) ) {
		const split = declaration.indexOf( ':' );
		if ( split === -1 ) {
			continue;
		}
		const property = declaration.slice( 0, split ).trim().toLowerCase();
		// `!important` is a cascade weapon, not a fact — strip it.
		const value = declaration.slice( split + 1 ).replace( /!\s*important/i, '' ).trim();
		if ( ! value ) {
			continue;
		}
		if ( property === 'background-color' && COLOR_VALUE.test( value ) ) {
			kept.push( `background-color:${ value }` );
			const ink = derivedInk( value );
			if ( ink ) {
				kept.push( `color:${ ink }` );
			}
			continue;
		}
		if ( property === 'background-image' || property === 'background' ) {
			const match = BG_URL.exec( value );
			if ( match ) {
				kept.push( `background-image:url(${ match[ 2 ] })` );
			}
		}
	}
	return kept.length > 0 ? kept.join( ';' ) : undefined;
}

/** Band/panel attributes: a safe anchor id plus the safe inline-style subset. */
function bandAttribs( tagName: string, attribs: { [key: string]: string } ) {
	const next = anchorId( tagName, attribs );
	if ( typeof next.attribs.style === 'string' ) {
		const safe = safeInlineStyle( next.attribs.style );
		if ( safe ) {
			next.attribs.style = safe;
		} else {
			delete next.attribs.style;
		}
	}
	return next;
}

/** The `a` transform shared by both transform maps. */
function linkAttribs(attribs: { [key: string]: string }) {
	if (attribs.target === '_blank') {
		attribs.rel = 'noopener noreferrer';
	}
	// Legacy NAMED anchors (`<a name="rates"></a>`) are how Flatsome and older
	// builders publish an in-page target for `href="#rates"`. `name` is not an
	// allowed attribute on `a`, so it was dropped and every such link pointed at
	// nothing. Promote it to the modern equivalent id, behind the same safety
	// rules as any other source anchor.
	if ( typeof attribs.name === 'string' ) {
		const name = attribs.name.trim();
		if ( ! attribs.id && SAFE_ANCHOR_ID.test( name ) && ! RESERVED_ANCHOR_ID.test( name ) ) {
			attribs.id = name;
		}
		delete attribs.name;
	}
	return { tagName: 'a', attribs };
}

const transformTags: sanitizeHtmlLib.IOptions['transformTags'] = {
	// In-page anchors (one-pager navigation). The source's `id` on a section or
	// div IS the navigation contract — `#about` must land on the rebuilt band —
	// so it survives sanitisation. Only a plain identifier is kept, and a source
	// id may never shadow an id the forge itself owns.
	section: (tagName: string, attribs: { [key: string]: string }) => bandAttribs(tagName, attribs),
	div: (tagName: string, attribs: { [key: string]: string }) => bandAttribs(tagName, attribs),
	// Every outbound link that opens a new tab gets the safe rel pair.
	a: (_tagName: string, attribs: { [key: string]: string }) => linkAttribs(attribs),
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

/** Options for {@link sanitizeHtml}. */
export interface SanitizeOptions {
	/**
	 * Origins whose absolute links are rewritten to site-relative paths
	 * (`https://client.com/x` becomes `/x`). The same artifact is served from a
	 * staging host before cutover, so a hard-coded CMS origin in an `href` sends
	 * the visitor to production (monicarajiv, 2026-11-15). Apex, `www` and any
	 * scheme count as one origin.
	 */
	internalOrigins?: string[];
}

/** Compare hosts without the `www.` prefix, so apex and `www` are one origin. */
function originKey(url: URL): string {
	const host = url.hostname.toLowerCase().replace(/^www\./, '');
	return url.port ? `${host}:${url.port}` : host;
}

/**
 * Turn an absolute URL that points at one of `origins` into a root-relative path,
 * keeping its query and fragment. Returns null for everything else (external
 * hosts, relative links, `mailto:`/`tel:`), so the caller leaves it alone.
 */
export function relativizeInternalUrl(href: string, origins: string[]): string | null {
	if (!origins.length || !/^(https?:)?\/\//i.test(href)) {
		return null;
	}
	let target: URL;
	try {
		target = new URL(href.startsWith('//') ? `https:${href}` : href);
	} catch {
		return null;
	}
	const key = originKey(target);
	for (const origin of origins) {
		let candidate: URL;
		try {
			candidate = new URL(origin);
		} catch {
			// A malformed origin in the profile must not fail the build.
			continue;
		}
		if (originKey(candidate) === key) {
			return `${target.pathname}${target.search}${target.hash}`;
		}
	}
	return null;
}

/**
 * `transformTags` plus the internal-origin rewrite. sanitize-html replaces the
 * transform map wholesale for a call, so the default map is spread in here
 * rather than merged by the library.
 */
function transformTagsFor(origins: string[]): sanitizeHtmlLib.IOptions['transformTags'] {
	if (origins.length === 0) {
		return transformTags;
	}
	return {
		...transformTags,
		a: (_tagName: string, attribs: { [key: string]: string }) => {
			if (typeof attribs.href === 'string') {
				const internal = relativizeInternalUrl(attribs.href, origins);
				if (internal !== null) {
					attribs.href = internal;
				}
			}
			return linkAttribs(attribs);
		},
	};
}

/** Sanitize rendered WordPress HTML for `set:html` (allowlist, versioned). */
export function sanitizeHtml(html: string, options: SanitizeOptions = {}): string {
	if (!html) {
		return '';
	}
	return sanitizeHtmlLib(html, {
		...SANITIZE_OPTIONS,
		transformTags: transformTagsFor(options.internalOrigins ?? []),
	});
}
