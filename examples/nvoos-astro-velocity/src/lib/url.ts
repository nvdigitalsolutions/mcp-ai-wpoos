// ---------------------------------------------------------------------------
// URL builders — Proposal 092 §5.4 / D5.
//
// The contract has no slug field, so routes are id-keyed with an
// informational slugified-name suffix: /products/{id}/{slug}/. The id is
// the canonical key; the suffix keeps URLs human/SEO-readable. Canonical
// tags (not 301s) cover slug drift on prerendered pages.
// ---------------------------------------------------------------------------

/** Slugify a name for URL suffixes. */
export function slugify(name: string): string {
	return name
		.toLowerCase()
		.trim()
		.replace(/['\u2019]/g, '')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
		.slice(0, 80) || 'item';
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
