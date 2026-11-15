// ---------------------------------------------------------------------------
// wireframes.ts — per-page wireframe extraction (Proposal 115 §3/§4).
//
// For every path the source exposes but the REST collections do not cover:
//   - fetch the rendered page (bounded, polite — jayainc throttles);
//   - 200 → sanitize → classify → semantic sections + responsive enrichment;
//   - 404/timeout → a placeholder CTA section naming the content it expects
//     (the content-first-wireframe pattern — the page ships, the structure
//     survives, and the client sees exactly what to rebuild in WordPress).
// Output is the profile's wireframe block — escaped text + media refs only,
// never source markup (the 114 contract). semantic.ts is consumed, not
// modified (composition over modification).
// ---------------------------------------------------------------------------

import { sanitizeHtml } from './sanitize.ts';
import { classifyHtml, type SemanticSection } from './semantic.ts';
import { fetchText, normalizePath } from './inventory.ts';
import { DEFAULT_BREAKPOINTS, explicitColumns, extractBreakpoints, inferColumns, type Breakpoint } from './responsive.ts';
import type { ForgeResponsive, ForgeWireframe, ForgeWireframeSection, SiteProfile } from './types.ts';

export interface WireframeOptions {
	fetch: typeof fetch;
	/** Bound on pages fetched (default 8 — a source site rarely has more). */
	maxPages?: number;
	/** The source stylesheets (from the --design pass) for breakpoint
	 *  extraction; absent ⇒ canonical defaults. */
	css?: string;
	timeoutMs?: number;
}

const GRID_KINDS: ReadonlySet<SemanticSection['kind']> = new Set( [ 'feature-grid', 'icon-list', 'gallery', 'logo-cloud' ] );

/** The canonical slug for a path: `/about-us/` → `about-us`. */
export function slugFromPath( path: string ): string {
	return normalizePath( path )?.slice( 1, -1 ) ?? path.replace( /^\/+|\/+$/g, '' );
}

/** `/about-us/` → `About Us`. */
export function titleFromSlug( slug: string ): string {
	return slug
		.split( '-' )
		.filter( Boolean )
		.map( ( part ) => ( part.length > 0 ? part[ 0 ].toUpperCase() + part.slice( 1 ) : part ) )
		.join( ' ' );
}

/** Anchor targets with no matching id in the page HTML (completeness gap). */
function danglingAnchors( html: string ): string[] {
	const anchors = new Set<string>();
	const hrefRe = /href\s*=\s*["']#([a-z0-9_-]+)["']/gi;
	let match;
	while ( ( match = hrefRe.exec( html ) ) ) {
		anchors.add( match[ 1 ].toLowerCase() );
	}
	return [ ...anchors ]
		.filter( ( anchor ) => ! new RegExp( `id\\s*=\\s*["']${ anchor.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }["']`, 'i' ).test( html ) )
		.sort();
}

/** One section + its responsive behavior. */
function wireframeSection( section: SemanticSection, breakpoints: Breakpoint[], explicit: Partial<Record<string, number>> ): ForgeWireframeSection {
	const responsive: ForgeResponsive = {
		breakpoints: breakpoints.map( ( b ) => b.label ),
		columnSource: 'inferred',
	};
	if ( GRID_KINDS.has( section.kind ) ) {
		const count =
			( section.slots.items?.length ?? 0 ) || ( section.slots.media?.length ?? 0 ) || 0;
		const { columns, source } = inferColumns( count, explicit );
		responsive.columns = columns;
		responsive.columnSource = source;
	}
	return { section, responsive };
}

/** The placeholder CTA for a page the source no longer serves. */
function placeholderSection( title: string, breakpoints: Breakpoint[] ): ForgeWireframeSection {
	const section: SemanticSection = {
		kind: 'cta',
		confidence: 'low',
		slots: {
			title,
			body: [
				'This page is part of the site structure but the source no longer serves it. Rebuild the content in WordPress and the next forge run will capture it here.',
			],
			links: [ { text: 'Back to home', href: '/' } ],
		},
	};
	return wireframeSection( section, breakpoints, {} );
}

/**
 * Extract wireframes for the missing paths. Bounded and sequential (politeness);
 * HTTP errors never throw — they become placeholder wireframes.
 */
export async function extractWireframes(
	profile: SiteProfile,
	missingPaths: string[],
	options: WireframeOptions
): Promise<Record<string, ForgeWireframe>> {
	const base = profile.url.replace( /\/+$/, '' );
	const maxPages = options.maxPages ?? 8;
	const breakpoints = options.css ? extractBreakpoints( options.css ) : DEFAULT_BREAKPOINTS;
	const wireframes: Record<string, ForgeWireframe> = {};

	for ( const path of missingPaths.slice( 0, maxPages ) ) {
		const slug = slugFromPath( path );
		if ( ! /^[a-z0-9][a-z0-9-]*$/.test( slug ) ) {
			continue;
		}
		const { status, text } = await fetchText( options.fetch, `${ base }${ path }`, options );
		const title = titleFromSlug( slug );

		if ( status === 200 && text.trim() ) {
			const sanitized = sanitizeHtml( text );
			const sections = classifyHtml( sanitized, {} );
			const explicit = explicitColumns( sanitized );
			wireframes[ slug ] = {
				title,
				slug,
				source: `${ base }${ path }`,
				extractedAt: new Date().toISOString(),
				status: 'captured',
				danglingAnchors: danglingAnchors( sanitized ),
				sections: sections.map( ( section ) => wireframeSection( section, breakpoints, explicit ) ),
			};
			continue;
		}

		wireframes[ slug ] = {
			title,
			slug,
			source: `${ base }${ path }`,
			extractedAt: new Date().toISOString(),
			status: 'placeholder',
			sections: [ placeholderSection( title, breakpoints ) ],
		};
	}
	return wireframes;
}
