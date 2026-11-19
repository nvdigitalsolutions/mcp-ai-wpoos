// ---------------------------------------------------------------------------
// chrome-fact-audit.ts — did the build render the CHROME facts it was given?
//
// `fact-audit.ts` holds the band facts to account; nothing held the chrome. That
// gap shipped twice in one week:
//
//   - the header brand mark was measured per viewport and never serialized, so
//     `.brand-logo` kept its 2.5rem default;
//   - the header's trailing icon links were measured, typed, and then rendered
//     by nothing at all — the rebuild showed a header the source does not have.
//
// Both builds were green. A fact that reaches the profile and stops is
// indistinguishable, to every other gate, from a fact the source never had.
//
// Only facts with a STABLE, unambiguous marker are audited — a marker that can
// appear for another reason would make the report lie. Anything speculative is
// left out deliberately.
// ---------------------------------------------------------------------------

import type { ForgeChrome } from './types.ts';

/** One declared chrome fact that produced no trace in the built page. */
export interface UnrenderedChromeFact {
	/** The profile field that carries it (`logo`, `headerActions`…). */
	fact: string;
	/** What the build should have contained. */
	marker: string;
}

interface ChromeMarker {
	/** The profile field this marker accounts for. */
	key: string;
	/** The class, custom property or text the build must carry. */
	marker: string;
	/** Only audit when the fact is actually declared (and non-empty). */
	when: ( chrome: ForgeChrome ) => boolean;
}

/**
 * The audit table.
 *
 * Each marker is emitted by exactly one template slot, so its absence means the
 * slot did not render — not that the fact was absent. `--forge-*` custom
 * properties are the chrome facts that have no element of their own.
 */
const MARKERS: ChromeMarker[] = [
	{ key: 'logo', marker: 'brand-logo', when: ( c ) => Boolean( c.logo?.src ) },
	{ key: 'tagline', marker: 'brand-tagline', when: ( c ) => Boolean( c.tagline ) },
	{ key: 'topbar', marker: 'site-topbar', when: ( c ) => ( c.topbar ?? [] ).length > 0 },
	{ key: 'nav', marker: 'site-nav', when: ( c ) => ( c.nav ?? [] ).length > 0 },
	{ key: 'call', marker: 'call-button', when: ( c ) => Boolean( c.call ) },
	{
		key: 'headerActions',
		marker: 'header-icon',
		when: ( c ) => ( c.headerActions ?? [] ).length > 0,
	},
	{
		key: 'mobileNav',
		marker: '--forge-mobile-nav-',
		when: ( c ) => Boolean( c.mobileNav ),
	},
	{ key: 'footerBg', marker: '--forge-footer-bg', when: ( c ) => Boolean( c.footerBg ) },
	{ key: 'footerInk', marker: '--forge-footer-ink', when: ( c ) => Boolean( c.footerInk ) },
	{ key: 'headerBg', marker: '--forge-header-bg', when: ( c ) => Boolean( c.headerBg ) },
	{
		key: 'geometry.logoAlign',
		marker: 'site-header--brand-center',
		when: ( c ) => c.geometry?.desktop?.logoAlign === 'center' || c.geometry?.wide?.logoAlign === 'center',
	},
	{
		key: 'geometry.logoHeight',
		marker: 'brand-logo',
		when: ( c ) => Boolean( c.geometry?.desktop?.logoHeight ?? c.geometry?.wide?.logoHeight ),
	},
];

/**
 * Which declared chrome facts left no trace in the built page.
 *
 * `builtHtml` is a WHOLE built document (any page): the chrome is site-wide, so
 * one page is enough and every page should agree.
 */
export function auditChromeFacts(
	chrome: ForgeChrome | undefined,
	builtHtml: string
): UnrenderedChromeFact[] {
	if ( ! chrome ) {
		return [];
	}
	const html = String( builtHtml ?? '' );
	if ( html.length === 0 ) {
		return [];
	}
	const missing: UnrenderedChromeFact[] = [];
	for ( const marker of MARKERS ) {
		if ( ! marker.when( chrome ) ) {
			continue;
		}
		if ( ! html.includes( marker.marker ) ) {
			missing.push( { fact: marker.key, marker: marker.marker } );
		}
	}
	return missing;
}

/** A one-line verdict, or `undefined` when every declared chrome fact rendered. */
export function describeChromeFacts( missing: UnrenderedChromeFact[] ): string | undefined {
	if ( missing.length === 0 ) {
		return undefined;
	}
	const named = missing.map( ( fact ) => `${ fact.fact } (expected \`${ fact.marker }\`)` );
	return `${ missing.length } declared chrome fact(s) left no trace: ${ named.join( ', ' ) }`;
}
