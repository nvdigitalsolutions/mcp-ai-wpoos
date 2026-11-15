// ---------------------------------------------------------------------------
// readme.ts — the deploy-branch build report.
//
// Every site is force-pushed to its own branch of the shared mirror repo, so
// anything hand-written on that branch is destroyed by the next build. The
// report therefore has to be an ARTIFACT of the build — beside
// design-audit.json and tokens.dtcg.json — not a hand-maintained file.
//
// The shape follows provenance-report practice (SLSA build provenance, Google
// Cloud Build provenance, Cornell's README-for-artifacts guidance): say what
// the artifact is, what produced it, from which source, when, under which
// gates, how to rebuild it, and what the licensing/attribution position is.
// Every value comes from the build inputs; the composer adds nothing and reads
// no clock, so the same inputs produce byte-identical output.
// ---------------------------------------------------------------------------

import type { ContrastAudit } from './contrast.ts';

/** One detected band (Proposal 137). */
export interface ReadmeBand {
	id: string;
	anchor?: string;
	columns?: number;
	/** The detection layer that produced the boundary (semantic, visual, …). */
	via?: string;
}

export interface ReadmeCollection {
	slug: string;
	kind?: string;
	restBase?: string;
}

export interface ReadmeInput {
	name: string;
	summary?: string;
	/** The WordPress/CMS origin this frontend was generated from. */
	cmsUrl: string;
	/** The public origin this branch is served at. */
	publicOrigin?: string;
	/** Scaffold timestamp (ISO 8601). Omitted when unknown. */
	generatedAt?: string;
	generator?: string;
	/** Page builder detected on the source, when it was analysed. */
	builder?: string;
	/** Detected band outline — omitted when the source was not analysed. */
	bands?: ReadmeBand[];
	counts?: { pages?: number; posts?: number; products?: number };
	/** Render mode: `static` (default) or `ssr`. */
	render?: string;
	/** How the site navigates — the resolved operator setting. */
	shape?: string;
	surfaces?: Record<string, boolean>;
	collections?: ReadmeCollection[];
	tracking?: { enabled?: boolean; vendors?: string[]; consentMode?: string };
	/** Resolved design tokens (semantic layer). */
	tokens?: Record<string, string>;
	/** The analysed design concept, one line. */
	concept?: string;
	/** When the source design was analysed (ISO 8601). */
	analysedAt?: string;
	adaptation?: { licensingNote?: string; attribution?: string[] };
	audit?: ContrastAudit;
	/** The site profile in the monorepo, e.g. `sites/jayainc.json`. */
	profilePath?: string;
}

/** A table cell: no pipes, no newlines, no padding. */
function cell( value: unknown ): string {
	return String( value ?? '' )
		.replace( /\|/g, '\\|' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/** Collapse a paragraph onto one line (blockquote summaries must not wrap). */
function oneLine( value: string ): string {
	return value.replace( /\s+/g, ' ' ).trim();
}

/** Trim a trailing slash so origins render predictably. */
function origin( value: string | undefined ): string {
	return ( value ?? '' ).replace( /\/+$/, '' );
}

/** The site slug, derived from the profile path when there is one. */
function slugOf( input: ReadmeInput ): string {
	const fromProfile = ( input.profilePath ?? '' ).replace( /^.*\//, '' ).replace( /\.json$/, '' );
	if ( fromProfile ) {
		return fromProfile;
	}
	return oneLine( input.name ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
}

/** A `| label | value |` provenance table body, skipping empty values. */
function provenanceRows( rows: Array<[string, string | undefined]> ): string[] {
	return rows
		.filter( ( [ , value ] ) => ( value ?? '' ).trim() !== '' )
		.map( ( [ label, value ] ) => `| ${ cell( label ) } | ${ value } |` );
}

/**
 * The artifacts this build ships, derived from the manifest's surface flags.
 * Order is stable: SEO first, then the design/agent surfaces.
 */
function artifacts( input: ReadmeInput ): Array<{ path: string; note: string }> {
	const on = ( key: string ): boolean => input.surfaces?.[ key ] !== false;
	const list: Array<{ path: string; note: string }> = [];
	if ( on( 'sitemap' ) ) {
		list.push( { path: 'sitemap.xml', note: 'Every generated URL, with `<lastmod>`.' } );
	}
	if ( on( 'feed' ) ) {
		list.push( { path: 'feed.xml', note: 'The post feed.' } );
	}
	list.push( { path: 'robots.txt', note: 'Crawl policy; points at the sitemap.' } );
	if ( on( 'search' ) ) {
		list.push( { path: 'search/', note: 'Static client-side search index.' } );
	}
	list.push(
		{ path: 'design-audit.json', note: 'WCAG 2.2 contrast audit of every resolved token pair.' },
		{ path: 'tokens.dtcg.json', note: 'The design tokens as a DTCG 2025.10 document.' },
	);
	if ( on( 'markdown' ) ) {
		list.push( { path: 'llms.txt', note: 'Agent-facing index of the markdown page variants.' } );
	}
	return list;
}

/**
 * Compose the branch README for a built forge site.
 *
 * Deterministic: identical inputs produce identical bytes, so a no-op rebuild
 * does not churn this file.
 */
export function composeSiteReadme( input: ReadmeInput ): string {
	const name = oneLine( input.name ) || 'Forge site';
	const cms = origin( input.cmsUrl );
	const pub = origin( input.publicOrigin ) || cms;
	const slug = slugOf( input );
	const render = ( input.render ?? 'static' ).trim() || 'static';
	const lines: string[] = [];

	lines.push( `# ${ name }`, '' );

	if ( ( input.summary ?? '' ).trim() ) {
		lines.push( `> ${ oneLine( input.summary ?? '' ) }`, '' );
	}

	lines.push(
		'**Generated artifact — do not edit this branch by hand.** It is replaced',
		'on every build (the mirror branch is force-pushed), so any local change is',
		'lost. Edit the site profile in the monorepo and rebuild instead.',
		'',
	);

	// -- what this is -------------------------------------------------------
	lines.push(
		'## What this branch is',
		'',
		`The prebuilt output of **nvoos-astro-forge**, an Astro frontend generated`,
		`from \`${ cell( cms ) }\`. WordPress stays the CMS: content is pulled through a`,
		'content-layer loader **at build time**, never at request time. Freshness',
		'comes from rebuild triggers, not runtime fetches.',
		'',
		'This branch exists only to be served — a Velocity app selects it from the',
		'shared mirror repo and uses the repo root as its output directory.',
		'',
	);

	// -- provenance ---------------------------------------------------------
	const provenance = provenanceRows( [
		[ 'Generator', `\`${ cell( input.generator || 'nvoos-astro-forge' ) }\`` ],
		[ 'Source CMS', `\`${ cell( cms ) }\`` ],
		[ 'Public origin', `\`${ cell( pub ) }\`` ],
		[ 'Scaffolded', input.generatedAt ? `\`${ cell( input.generatedAt ) }\`` : '' ],
		[ 'Render mode', `\`${ cell( render ) }\`` ],
		[ 'Site shape', input.shape ? `\`${ cell( input.shape ) }\`` : '' ],
		[ 'Page builder', input.builder ? `\`${ cell( input.builder ) }\`` : '' ],
		[ 'Design analysed', input.analysedAt ? `\`${ cell( input.analysedAt ) }\`` : '' ],
		[ 'Site profile', input.profilePath ? `\`${ cell( input.profilePath ) }\`` : '' ],
	] );
	if ( provenance.length > 0 ) {
		lines.push( '## Provenance', '', '| | |', '| --- | --- |', ...provenance, '' );
	}

	// -- build facts --------------------------------------------------------
	const counts = input.counts ?? {};
	const facts: string[] = [];
	if ( typeof counts.pages === 'number' ) {
		facts.push( `| Pages | ${ counts.pages } |` );
	}
	if ( typeof counts.posts === 'number' ) {
		facts.push( `| Posts | ${ counts.posts } |` );
	}
	if ( typeof counts.products === 'number' ) {
		facts.push( `| Products | ${ counts.products } |` );
	}
	const collections = ( input.collections ?? [] ).filter( ( entry ) => entry.slug );
	if ( collections.length > 0 ) {
		const described = collections
			.map( ( entry ) =>
				entry.restBase ? `\`${ cell( entry.slug ) }\` (${ cell( entry.restBase ) })` : `\`${ cell( entry.slug ) }\``,
			)
			.join( ', ' );
		facts.push( `| Collections | ${ described } |` );
	}
	if ( facts.length > 0 ) {
		lines.push( '## Build facts', '', '| | |', '| --- | --- |', ...facts, '' );
	}

	// -- design -------------------------------------------------------------
	const tokens = Object.keys( input.tokens ?? {} ).length;
	const audit = input.audit;
	if ( tokens > 0 || audit || ( input.concept ?? '' ).trim() ) {
		lines.push( '## Design', '' );
		if ( ( input.concept ?? '' ).trim() ) {
			lines.push( oneLine( input.concept ?? '' ), '' );
		}
		if ( tokens > 0 ) {
			lines.push( `${ tokens } semantic design tokens, exported as \`tokens.dtcg.json\`.`, '' );
		}
		if ( audit && audit.pairs.length > 0 ) {
			const total = audit.pairs.length;
			const passed = audit.pairs.filter( ( pair ) => pair.pass ).length;
			lines.push(
				`Contrast audit: **${ passed }/${ total }** token pairs meet WCAG 2.2 ` +
					'(4.5:1 text, 3:1 non-text).',
				'',
			);
			if ( audit.failures.length > 0 || audit.unparsed.length > 0 ) {
				lines.push( '| Pair | Mode | Ratio | Required |', '| --- | --- | --- | --- |' );
				for ( const pair of [ ...audit.failures, ...audit.unparsed ] ) {
					const ratio = typeof pair.ratio === 'number' ? pair.ratio.toFixed( 2 ) : 'unparsed';
					lines.push(
						`| ${ cell( pair.label ) } | ${ cell( pair.mode ) } | ${ ratio } | ${ pair.required }:1 |`,
					);
				}
				lines.push( '' );
			}
		}
		if ( input.adaptation?.licensingNote || ( input.adaptation?.attribution ?? [] ).length > 0 ) {
			lines.push( '### Attribution', '' );
			if ( input.adaptation?.licensingNote ) {
				lines.push( oneLine( input.adaptation.licensingNote ), '' );
			}
			for ( const note of input.adaptation?.attribution ?? [] ) {
				lines.push( `- ${ oneLine( note ) }` );
			}
			lines.push( '' );
		}
	}

	// -- band structure -----------------------------------------------------
	const bands = input.bands ?? [];
	if ( bands.length > 0 ) {
		const via = input.builder ? ` with the \`${ cell( input.builder ) }\` adapter` : '';
		lines.push(
			'## Band structure',
			'',
			`${ bands.length } vertical bands were detected on the source page${ via }.`,
			'',
			'| # | Band | Anchor | Columns | via |',
			'| --- | --- | --- | --- | --- |',
		);
		bands.forEach( ( band, index ) => {
			lines.push(
				`| ${ index + 1 } | ${ cell( band.id ) } | ${ band.anchor ? `\`#${ cell( band.anchor ) }\`` : '' } | ${
					typeof band.columns === 'number' ? band.columns : ''
				} | ${ cell( band.via ) } |`,
			);
		} );
		lines.push( '' );
	}

	// -- artifacts ----------------------------------------------------------
	const shipped = artifacts( input );
	if ( shipped.length > 0 ) {
		lines.push( '## Machine-readable surfaces', '', '| File | Purpose |', '| --- | --- |' );
		for ( const artifact of shipped ) {
			lines.push( `| \`${ cell( artifact.path ) }\` | ${ cell( artifact.note ) } |` );
		}
		lines.push( '' );
	}

	// -- tracking -----------------------------------------------------------
	if ( input.tracking ) {
		const vendors = ( input.tracking.vendors ?? [] ).filter( Boolean );
		const enabled = input.tracking.enabled === true || vendors.length > 0;
		lines.push( '## Tracking', '' );
		if ( ! enabled ) {
			lines.push( 'No analytics or marketing tags are enabled on this build.', '' );
		} else {
			const consent = input.tracking.consentMode ? ` Consent mode: \`${ cell( input.tracking.consentMode ) }\`.` : '';
			lines.push(
				`${ vendors.length } consent-gated vendor(s): ${ vendors.map( ( vendor ) => `\`${ cell( vendor ) }\`` ).join( ', ' ) }.${ consent }`,
				'',
			);
		}
	}

	// -- rebuild ------------------------------------------------------------
	const target = `../${ slug }-frontend`;
	lines.push(
		'## Rebuild',
		'',
		'From the monorepo (`examples/nvoos-astro-forge`):',
		'',
		'```sh',
		`node scripts/forge.mjs ${ cell( input.profilePath || `sites/${ slug }.json` ) } --target ${ target }`,
		`cd ${ target } && npm install && ASTRO_SITE=${ cell( pub ) } npm run build`,
		'```',
		'',
		`Or dispatch the **Forge Sites** workflow with \`site: ${ slug }\`. It`,
		'rebuilds, re-runs the gates, and force-pushes this branch.',
		'',
	);

	lines.push( '---', '', `_Generated by \`${ cell( input.generator || 'nvoos-astro-forge' ) }\`._`, '' );

	return lines.join( '\n' );
}
