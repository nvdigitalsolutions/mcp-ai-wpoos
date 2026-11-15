// ---------------------------------------------------------------------------
// Content merge — Proposal 106 §2 hybrid local (L2).
//
// The envelope docs win on slug collision; local collection entries fill
// gaps. Posts additionally sort date-descending (blog convention). Pure and
// unit-tested (the L2 collision matrix lives in content-merge.test.ts).
// ---------------------------------------------------------------------------

/** The shared contract: every source item must expose a slug. */
export interface Slugged {
	slug: string;
}

/**
 * L2 merge: envelope docs keep their order and win collisions; local items
 * whose slug is already present are dropped; the rest are appended in
 * collection order.
 */
export function mergeEnvelopeLocal<E extends Slugged, L extends Slugged>(
	envelope: E[],
	local: L[]
): Array<E | L> {
	const taken = new Set(envelope.map((doc) => doc.slug));
	const additions = local.filter((doc) => !taken.has(doc.slug));
	return [...envelope, ...additions];
}

/** A normalized post list entry — both sources map onto this shape. */
export interface PostEntryLike extends Slugged {
	/** Sortable date (ISO preferred; the lexicographic compare needs one format). */
	date: string;
}

/**
 * Blog merge: envelope wins collisions, local fills gaps, then the whole
 * list sorts date-descending (ISO strings compare lexicographically).
 */
export function mergePostsDesc<E extends PostEntryLike, L extends PostEntryLike>(
	envelope: E[],
	local: L[]
): Array<E | L> {
	return mergeEnvelopeLocal(envelope, local).sort((a, b) => b.date.localeCompare(a.date));
}

/** Find the local doc for a slug, or null — the /pages fallback (O-4).
 *  Astro collection entries key on `id` (the loader-generated slug), so the
 *  lookup compares against `id` while envelope docs carry `slug`. */
export function localBySlug<L extends { id: string }>(local: L[], slug: string): L | null {
	return local.find((doc) => doc.id === slug) ?? null;
}
