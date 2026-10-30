// ---------------------------------------------------------------------------
// Client-side normalization — Proposal 092 §3.4 / D7.
//
// The bridge returns absolute backend URLs for media. The storefront
// rewrites them to same-origin /media/* paths so:
//   1. no backend origin ever leaks into rendered HTML, and
//   2. the T1 flip (WP → Laravel media) requires zero app changes —
//      serve.mjs remaps /media/* to the current origin's uploads base.
// ---------------------------------------------------------------------------

import sanitizeHtmlLib from 'sanitize-html';

const UPLOADS_PREFIX = '/wp-content/uploads';

/**
 * Rewrite a backend media URL to the same-origin /media/* path.
 * Keeps the path (minus the uploads base) and query string.
 */
export function normalizeMediaUrl(url: string): string {
	if (!url) {
		return '';
	}
	try {
		const parsed = new URL(url);
		let path = parsed.pathname;
		if (path.startsWith(UPLOADS_PREFIX)) {
			path = path.slice(UPLOADS_PREFIX.length) || '/';
		}
		return `/media${path}${parsed.search}`;
	} catch {
		// Not an absolute URL — return as-is (defensive).
		return url;
	}
}

const SANITIZE_OPTIONS: sanitizeHtmlLib.IOptions = {
	allowedTags: [
		'p',
		'br',
		'strong',
		'em',
		'ul',
		'ol',
		'li',
		'h2',
		'h3',
		'h4',
		'a',
		'blockquote',
		'code',
	],
	allowedAttributes: {
		a: ['href', 'target', 'rel'],
	},
	allowedSchemes: ['http', 'https'],
};

/**
 * Sanitize product description HTML before render.
 * (Upstream content is wp_kses_post-filtered by the bridge; this is the
 * second gate at the render boundary — defense in depth.)
 */
export function sanitizeDescription(html: string): string {
	return sanitizeHtmlLib(html, SANITIZE_OPTIONS);
}

/** Parse a contract date to ISO, or null when invalid (sitemap safety). */
export function toIsoDate(value: string): string | null {
	if (!value) {
		return null;
	}
	const date = new Date(value);
	if (Number.isNaN(date.getTime())) {
		return null;
	}
	return date.toISOString();
}

/** Normalize every media field on a product (used at the fetch edge). */
export function normalizeProductMedia<T extends { image: string; images: string[] }>(
	product: T
): T {
	return {
		...product,
		image: normalizeMediaUrl(product.image),
		images: product.images.map(normalizeMediaUrl),
	};
}
