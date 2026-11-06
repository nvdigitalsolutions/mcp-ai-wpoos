// blocks.ts — pure helpers for the content blocks (Proposal 126 §blocks).
// Tabs need deterministic ids for the radio-input technique; the djb2 hash
// keeps the id stable across rebuilds (the velocity content-blocks
// convention — same hash, adapted to the static no-JS tab technique).

/** Deterministic id for a tab panel (djb2 over the source key). */
export function tabId(key: string): string {
	let hash = 5381;
	for (let i = 0; i < key.length; i++) {
		hash = ((hash << 5) + hash + key.charCodeAt(i)) >>> 0;
	}
	return `nvoos-tabs-${hash.toString(16)}`;
}
