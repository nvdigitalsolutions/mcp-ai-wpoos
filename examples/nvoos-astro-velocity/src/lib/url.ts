// ---------------------------------------------------------------------------
// URL builders — Proposal 092 §5.4 / D5.
//
// The contract has no slug field, so routes are id-keyed with an
// informational slugified-name suffix: /products/{id}/{slug}/. The id is
// the canonical key; the suffix keeps URLs human/SEO-readable. Canonical
// tags cover slug drift on prerendered pages; SSR pages 301 instead (104).
// ---------------------------------------------------------------------------

import limax from 'limax';

/** Slugify a name for URL suffixes.
 *
 * limax (D4 — AstroWind's own slugger) transliterates per Unicode, so
 * international product names get correct ASCII slugs (`Café Crème` →
 * `cafe-creme`) where the old regex dropped diacritics entirely. Apostrophes
 * are dropped (not separated) to keep historical URLs stable; the 80-char
 * cap and the `'item'` fallback are preserved from the original contract.
 */
export function slugify(name: string): string {
	return (
		limax(name, {
			separator: '-',
			separateApostrophes: false,
		}).slice(0, 80) || 'item'
	);
}

export function productPath(id: number, name: string): string {
	return `/products/${id}/${slugify(name)}/`;
}

export function categoryPath(id: number, name: string): string {
	return `/categories/${id}/${slugify(name)}/`;
}

export function brandPath(id: number, name: string): string {
	return `/brands/${id}/${slugify(name)}/`;
}

export function colorPath(id: number, name: string): string {
	return `/colors/${id}/${slugify(name)}/`;
}

/** Absolute canonical URL on the configured site host. */
export function absoluteUrl(site: string | URL, path: string): string {
	const base = typeof site === 'string' ? site : site.toString();
	return `${base.replace(/\/+$/, '')}${path}`;
}
