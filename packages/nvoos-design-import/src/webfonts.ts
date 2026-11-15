// webfonts.ts — Google Fonts loading for harvested brand families
// (Proposal 126 cross-pollination — the forge webfont harvest). The ADS
// font tokens declare the family; a system stack renders with no link, a
// named family loads from Google Fonts. Build-time scope: the default
// theme's tokens drive the static head — runtime theme font overrides ride
// the next rebuild (the forge static model).

/** Families that render locally — never worth a Google Fonts request. */
const SYSTEM_FONT_RE =
	/^(system-ui|ui-sans-serif|ui-serif|ui-monospace|sans-serif|serif|monospace|cursive|fantasy|arial|helvetica|georgia|times|garamond|courier|verdana|tahoma|trebuchet|segoe|menlo|consolas|impact)$/i;

/** Collect the distinct named (non-system) families from token values.
 *  CSS stacks ("a, b") are skipped — they are fallback lists, not a single
 *  harvestable family. */
export function webfontFamilies(values: Array<string | string[] | undefined | null>): string[] {
	const seen = new Set<string>();
	const out: string[] = [];
	for (const entry of values) {
		const list = Array.isArray(entry) ? entry : entry ? [entry] : [];
		for (const raw of list) {
			const family = (raw ?? '').trim().replace(/^['"]|['"]$/g, '');
			if (!family || family.includes(',') || SYSTEM_FONT_RE.test(family)) {
				continue;
			}
			const key = family.toLowerCase();
			if (!seen.has(key)) {
				seen.add(key);
				out.push(family);
			}
		}
	}
	return out;
}

/** The Google Fonts CSS2 URL for the given families, or undefined when
 *  there is nothing to load (the hermetic default: system stacks). */
export function googleFontsUrl(
	families: string[],
	weights: number[] = [300, 400, 500, 600, 700]
): string | undefined {
	if (families.length === 0) {
		return undefined;
	}
	const spec = families
		.map((family) => `family=${encodeURIComponent(family)}:wght@${weights.join(';')}`)
		.join('&');
	return `https://fonts.googleapis.com/css2?${spec}&display=swap`;
}
