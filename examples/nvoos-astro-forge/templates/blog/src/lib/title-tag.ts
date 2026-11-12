// ---------------------------------------------------------------------------
// title-tag.ts — the heading LEVEL a band's title renders at.
//
// The level is a measurable content fact, not a template choice: the analyzer
// records each band's computed headings per tag (`bandFacts[id].headings`), and
// the scaffold carries the tag of the band's LARGEST heading as
// `SectionTheme.type.titleLevel`. Components used to hardcode `<h2>`, so a band
// whose only source heading was an `<h3>` (virtuallyjamaica's contact form) was
// promoted a level and its document outline stopped matching the source.
//
// An `h1` maps DOWN to `h2` on purpose: the page article owns the single `<h1>`
// (the front page renders the source's own h1 inside its first band), so a band
// title that happens to be an h1 in the source must not create a second one.
// ---------------------------------------------------------------------------

export type TitleTag = 'h2' | 'h3' | 'h4' | 'h5' | 'h6';

/**
 * The tag a band title uses.
 *
 * `type` is the band's measured typography block (`SectionTheme.type`); only
 * `titleLevel` is read. Absent or unrecognised → `h2`, the template default.
 */
export const titleTag = ( type?: { titleLevel?: string } ): TitleTag => {
	const level = type?.titleLevel;
	return level === 'h3' || level === 'h4' || level === 'h5' || level === 'h6' ? level : 'h2';
};
