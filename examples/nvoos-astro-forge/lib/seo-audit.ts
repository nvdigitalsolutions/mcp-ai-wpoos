// ---------------------------------------------------------------------------
// seo-audit.ts — the `forge verify --seo` gate (Proposal 131 §2.6).
//
// Pure over one built HTML document, so it unit-tests without a build. It
// covers the failure classes a rebuild actually introduces: a double-branded
// <title>, a dropped robots directive, missing Open Graph/Twitter tags, a
// canonical that points somewhere unexpected, and JSON-LD that does not
// parse or is not wired into the site graph.
// ---------------------------------------------------------------------------

import { Parser } from 'htmlparser2';

export interface SeoFinding {
	code: string;
	severity: 'fail' | 'warn';
	message: string;
}

export interface SeoAuditOptions {
	/** The rebuilt site's display name (double-brand detection). */
	siteName: string;
	/** Pathname of the audited page (`/`, `/contact/`, …). */
	pathname: string;
	/** The URL this page should canonicalise to, when known. */
	expectedUrl?: string;
	/**
	 * Proposal 136 §4.5 — also require every fact in the page's JSON-LD to have
	 * a visible counterpart (`verify --seo --structured`).
	 */
	structured?: boolean;
}

interface HeadFacts {
	title?: string;
	metas: Record< string, string >;
	canonical?: string;
	jsonLd: string[];
	lang?: string;
}

/** Collect the head facts the audit needs. */
export function scanSeoHtml( html: string ): HeadFacts {
	const facts: HeadFacts = { metas: {}, jsonLd: [] };
	let inTitle = false;
	let inJsonLd = false;
	const titleParts: string[] = [];
	const jsonParts: string[] = [];

	const parser = new Parser(
		{
			onopentag( name, attribs ) {
				if ( name === 'html' ) {
					facts.lang = attribs.lang;
					return;
				}
				if ( name === 'title' ) {
					inTitle = true;
					return;
				}
				if ( name === 'meta' ) {
					const key = ( attribs.property ?? attribs.name ?? '' ).toLowerCase();
					if ( key && typeof attribs.content === 'string' ) {
						facts.metas[ key ] = attribs.content;
					}
					return;
				}
				if ( name === 'link' && ( attribs.rel ?? '' ).toLowerCase() === 'canonical' && attribs.href ) {
					facts.canonical = attribs.href;
					return;
				}
				if ( name === 'script' && ( attribs.type ?? '' ).toLowerCase() === 'application/ld+json' ) {
					inJsonLd = true;
				}
			},
			ontext( text ) {
				if ( inTitle ) {
					titleParts.push( text );
				}
				if ( inJsonLd ) {
					jsonParts.push( text );
				}
			},
			onclosetag( name ) {
				if ( name === 'title' ) {
					inTitle = false;
				}
				if ( name === 'script' && inJsonLd ) {
					inJsonLd = false;
					facts.jsonLd.push( jsonParts.join( '' ).trim() );
					jsonParts.length = 0;
				}
			},
		},
		{ decodeEntities: true, lowerCaseTags: true, lowerCaseAttributeNames: true }
	);
	parser.write( html );
	parser.end();

	facts.title = titleParts.join( '' ).trim() || undefined;
	return facts;
}

function countOccurrences( haystack: string, needle: string ): number {
	if ( ! needle ) {
		return 0;
	}
	const lowerHaystack = haystack.toLowerCase();
	const lowerNeedle = needle.toLowerCase();
	let index = lowerHaystack.indexOf( lowerNeedle );
	let count = 0;
	while ( index !== -1 ) {
		count++;
		index = lowerHaystack.indexOf( lowerNeedle, index + lowerNeedle.length );
	}
	return count;
}

/**
 * Audit one built page. `fail` is a gate failure; `warn` only fails under
 * --strict-seo.
 */
export function auditSeo( html: string, options: SeoAuditOptions ): SeoFinding[] {
	const findings: SeoFinding[] = [];
	const push = ( code: string, severity: 'fail' | 'warn', message: string ) => findings.push( { code, severity, message } );
	const facts = scanSeoHtml( html );

	// --- title ---------------------------------------------------------------
	if ( ! facts.title ) {
		push( 'title-missing', 'fail', 'The page has no <title>.' );
	} else if ( options.siteName && countOccurrences( facts.title, options.siteName ) > 1 ) {
		push(
			'title-double-brand',
			'fail',
			`The title repeats the site name: "${ facts.title }" — a source SEO title already carries it, so the template must not append it again.`
		);
	}

	// --- description / canonical / robots ------------------------------------
	if ( ! facts.metas.description ) {
		push( 'meta-description-missing', 'warn', 'No <meta name="description">.' );
	}
	if ( ! facts.canonical ) {
		push( 'canonical-missing', 'warn', 'No <link rel="canonical"> — set the site URL (ASTRO_SITE) so canonical URLs are emitted.' );
	} else if ( options.expectedUrl && facts.canonical !== options.expectedUrl ) {
		push(
			'canonical-mismatch',
			'warn',
			`Canonical points at ${ facts.canonical } but this page is ${ options.expectedUrl }.`
		);
	}
	if ( ! facts.metas.robots ) {
		push( 'robots-missing', 'warn', 'No <meta name="robots"> — the template should always declare one.' );
	}
	const robots = ( facts.metas.robots ?? '' ).toLowerCase();
	if ( options.pathname === '/' && robots.includes( 'noindex' ) ) {
		push( 'homepage-noindex', 'fail', 'The homepage is noindex — the whole rebuilt site would be dropped from search.' );
	}

	// --- social --------------------------------------------------------------
	for ( const key of [ 'og:title', 'og:type', 'og:url' ] ) {
		if ( ! facts.metas[ key ] ) {
			push( 'og-incomplete', 'warn', `Missing ${ key } — a link preview loses it.` );
		}
	}
	if ( ! facts.metas[ 'twitter:card' ] ) {
		push( 'twitter-card-missing', 'warn', 'Missing twitter:card.' );
	}

	// --- JSON-LD -------------------------------------------------------------
	for ( const block of facts.jsonLd ) {
		try {
			JSON.parse( block );
		} catch ( error ) {
			push( 'jsonld-invalid', 'fail', `JSON-LD does not parse: ${ error instanceof Error ? error.message : String( error ) }` );
		}
	}
	if ( facts.jsonLd.length > 0 && !facts.jsonLd.some( ( block ) => block.includes( '"@graph"' ) ) ) {
		push( 'jsonld-graph-missing', 'warn', 'JSON-LD is not wired into a site graph (@graph with Organization/WebSite).' );
	}

	// --- html ----------------------------------------------------------------
	if ( ! facts.lang ) {
		push( 'html-lang-missing', 'warn', 'The <html> element has no lang attribute.' );
	}

	// --- structured data matches visible text (Proposal 136 §4.5) -------------
	if ( options.structured ) {
		findings.push( ...auditStructuredData( html ) );
	}

	return findings;
}

// ---------------------------------------------------------------------------
// Structured data ↔ visible text (Proposal 136 §4.5)
//
// Google's own AI-features guidance lists "making sure your structured data
// matches the visible text on the page" as a fundamental. This is the
// mechanical form of that rule: every number, price and date in the JSON-LD
// must appear in the rendered text of the band that contributed it — a price
// in the markup that nobody can see is a mismatch, and a price visible only in
// JSON-LD is a promise the page does not keep.
// ---------------------------------------------------------------------------

/** Fact-shaped leaf values: prices, bare numbers, and ISO dates. */
const PRICE_FACT = /^[$€£¥₹]?\s?\d[\d.,]*(?:\s?(?:\/|per)\s?[a-z]+)?$/i;
const DATE_FACT = /^\d{4}-\d{2}-\d{2}/;

/** Normalize for comparison: lowercase, no currency symbols, single spaces. */
function normalizeFact( value: string ): string {
	return value
		.toLowerCase()
		.replace( /[$€£¥₹]/g, '' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/** The page's visible text (scripts and styles removed). */
function visibleText( html: string ): string {
	return normalizeFact(
		html
			.replace( /<script[\s\S]*?<\/script>/gi, ' ' )
			.replace( /<style[\s\S]*?<\/style>/gi, ' ' )
			.replace( /<[^>]+>/g, ' ' )
	);
}

/** The visible text of every `data-section="pricing"` band, concatenated. */
function pricingBandText( html: string ): string {
	const opens = [ ...html.matchAll( /<div[^>]*\bdata-section="([a-z-]+)"[^>]*>/g ) ];
	const bands: string[] = [];
	for ( let index = 0; index < opens.length; index++ ) {
		if ( opens[ index ][ 1 ] !== 'pricing' ) {
			continue;
		}
		const start = opens[ index ].index ?? 0;
		const end = opens[ index + 1 ]?.index ?? html.length;
		bands.push( html.slice( start, end ) );
	}
	return visibleText( bands.join( ' ' ) );
}

interface StructuredFact {
	path: string;
	value: string;
}

/** Every fact-shaped leaf in a JSON-LD document, with its path. */
function collectFacts( node: unknown, path: string, out: StructuredFact[] ): void {
	if ( Array.isArray( node ) ) {
		node.forEach( ( child, index ) => collectFacts( child, `${ path }[${ index }]`, out ) );
		return;
	}
	if ( node && typeof node === 'object' ) {
		for ( const [ key, value ] of Object.entries( node as Record< string, unknown > ) ) {
			collectFacts( value, path ? `${ path }.${ key }` : key, out );
		}
		return;
	}
	if ( typeof node === 'number' ) {
		out.push( { path, value: String( node ) } );
		return;
	}
	if ( typeof node === 'string' && ( PRICE_FACT.test( node.trim() ) || DATE_FACT.test( node.trim() ) ) ) {
		out.push( { path, value: node.trim() } );
	}
}

/**
 * Facts in the page's JSON-LD that the page does not show. Empty is the only
 * acceptable result — a price in the graph and not on the page is either a
 * stale build or an offer nobody can buy.
 */
export function auditStructuredData( html: string ): SeoFinding[] {
	const findings: SeoFinding[] = [];
	const facts = scanSeoHtml( html );
	const pageText = visibleText( html );
	const bandText = pricingBandText( html );

	for ( const block of facts.jsonLd ) {
		let document: unknown;
		try {
			document = JSON.parse( block );
		} catch {
			continue; // the parse failure is already reported by auditSeo
		}
		const leaves: StructuredFact[] = [];
		collectFacts( document, '', leaves );
		for ( const leaf of leaves ) {
			const needle = normalizeFact( leaf.value );
			if ( ! needle ) {
				continue;
			}
			// A price must be visible IN A PRICING BAND when one exists — a price
			// that only appears in unrelated body copy is not the offer shown.
			const haystack = bandText && PRICE_FACT.test( leaf.value ) ? bandText : pageText;
			if ( ! haystack.includes( needle ) ) {
				findings.push( {
					code: 'structured-fact-not-visible',
					severity: 'fail',
					message: `JSON-LD ${ leaf.path } = "${ leaf.value }" has no visible counterpart on the page.`,
				} );
			}
		}
	}
	return findings;
}
