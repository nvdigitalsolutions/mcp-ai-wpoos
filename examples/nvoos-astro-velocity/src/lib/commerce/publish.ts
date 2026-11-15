// ---------------------------------------------------------------------------
// Publication discipline — Proposal 104 §2.1 (astro-theme-cactus pattern):
// ONE predicate, used by every surface that lists or links a product.
//
// Native docs carry status: 'publish' | 'draft'; woo-mode docs carry any WP
// post status. A MISSING status means published (backward-compatible with
// older bridge transformer output).
// ---------------------------------------------------------------------------

export function isPublished(doc: { status?: string }): boolean {
	return doc.status === undefined || doc.status === 'publish';
}
