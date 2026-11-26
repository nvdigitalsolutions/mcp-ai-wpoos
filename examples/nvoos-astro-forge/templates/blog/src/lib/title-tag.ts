// SPDX-License-Identifier: MIT
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
//
// `allowH1` is the exception that makes that sentence true: on a page whose body
// IS the document outline — the front page, where no article sits above the
// bands — the first band's h1 must render as an `h1`, or the page publishes no
// h1 at all (eca.directory's home, 2026-11-24: the hero h1 had been demoted to a
// card `<h3>` and every band title was an `h2`). Post bodies keep the default.
// ---------------------------------------------------------------------------

export type TitleTag = 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6';

/**
 * The tag a band title uses.
 *
 * `type` is the band's measured typography block (`SectionTheme.type`); only
 * `titleLevel` and `allowH1` are read. Absent or unrecognised → `h2`, the
 * template default.
 */
export const titleTag = ( type?: { titleLevel?: string; allowH1?: boolean } ): TitleTag => {
	const level = type?.titleLevel;
	if ( level === 'h1' ) {
		return type?.allowH1 ? 'h1' : 'h2';
	}
	return level === 'h3' || level === 'h4' || level === 'h5' || level === 'h6' ? level : 'h2';
};

/**
 * The tag an ITEM title uses, from the level its SOURCE heading carried.
 *
 * An item is not the band's title: a services column is a real `h2` in the
 * source and a wifi icon-box caption is an `h3`, and the band's measured type
 * rule is scoped to that level (`[data-section-id=…] h2{…}`) — so rendering
 * every item at one template level left those headings at the default size
 * (virtuallysrilanka's services columns).
 *
 * An `h1` maps down to `h2` for the same reason as `titleTag`. An absent level
 * keeps the component's own default.
 */
export const itemTitleTag = ( level: number | undefined, fallback: TitleTag ): TitleTag => {
	if ( ! level || ! Number.isFinite( level ) ) {
		return fallback;
	}
	if ( level <= 2 ) {
		return 'h2';
	}
	return ( level >= 6 ? 'h6' : `h${ level }` ) as TitleTag;
};
