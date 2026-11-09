// ---------------------------------------------------------------------------
// section-kinds.ts — the `data-section` vocabulary for this storefront.
//
// `data-section` describes WHAT a rendered band IS, so agents, tests, and
// downstream consumers can identify bands structurally instead of by CSS
// class. It is advisory: nothing in the render path may depend on it for
// correctness. `data-section-id` (where present) remains the identity key.
//
// The value is the KIND, not the Portable Text type name. The one deliberate
// divergence: the `stat` block renders `data-section="stats"` (plural, the
// band-level name) while its authoring type stays `stat`.
//
// This list is storefront-local. It mirrors the Astro Forge vocabulary
// (`examples/nvoos-astro-forge/lib/types.ts` SECTION_KINDS) only where a kind
// genuinely means the same thing; the forge has no faq/tabs/product-grid/
// promotion kinds because it does not generate those bands.
//
// tests/unit/section-kinds.test.ts fails when a block in
// src/components/blocks/ ships without a `data-section` from this list, or
// when a listed kind is used by no block at all.
// ---------------------------------------------------------------------------

export const SECTION_KINDS = [
	/** hero — headline band with an optional lede. */
	'hero',
	/** image — a single figure. */
	'image',
	/** testimonial — quote + attribution. */
	'testimonial',
	/** faq — accordion of question/answer pairs. */
	'faq',
	/** stats — big number + label (authored as the `stat` type). */
	'stats',
	/** comparison — a labelled fact table. */
	'comparison',
	/** steps — an ordered process. */
	'steps',
	/** team — people and their roles. */
	'team',
	/** tabs — WAI-ARIA tab set of nested Portable Text. */
	'tabs',
	/** product-grid — a live product pick by id. */
	'product-grid',
	/** promotion — an active promotion card by id. */
	'promotion',
] as const;

export type SectionKind = (typeof SECTION_KINDS)[number];

/** True when `value` is a known section kind (the emitted `data-section`). */
export function isSectionKind(value: unknown): value is SectionKind {
	return typeof value === 'string' && (SECTION_KINDS as readonly string[]).includes(value);
}

/**
 * Portable Text type → emitted kind. Every block type maps to exactly one
 * kind; `stat` → `stats` is the only name that changes. Live-data blocks
 * (productGrid, promotion) are included — they render only when their
 * upstream resolves, but the mapping is unconditional.
 */
export const BLOCK_TYPE_TO_KIND = {
	hero: 'hero',
	image: 'image',
	testimonial: 'testimonial',
	faq: 'faq',
	stat: 'stats',
	comparison: 'comparison',
	steps: 'steps',
	team: 'team',
	tabs: 'tabs',
	productGrid: 'product-grid',
	promotion: 'promotion',
} as const satisfies Record<string, SectionKind>;
