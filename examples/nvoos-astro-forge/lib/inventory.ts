// ---------------------------------------------------------------------------
// inventory.ts — page-inventory discovery (Proposal 115 §1/§2).
//
// Multi-source URL inventory (the industry rule: generate the inventory from
// an actual crawl, not the sitemap alone — wp-sitemap.xml is an INDEX of
// sub-sitemaps and can be disabled entirely):
//   1. wp-sitemap.xml + sitemap.xml, index-resolved via the `sitemap` package
//   2. same-origin links on the rendered homepage
//   3. optional Wayback CDX (deleted pages the live site no longer serves)
// The result is diffed against the REST collections (pages) + profile
// exclusions → the missing-page set the wireframe pass rebuilds. The
// homepage navigation is classified into anchors vs. page paths → the
// site-shape verdict (one-pager | multi-page | hybrid), with each nav
// anchor reconciled against the homepage's element ids.
// ---------------------------------------------------------------------------

import { parseSitemap, parseSitemapIndex } from 'sitemap';
import { Readable } from 'node:stream';

import type { ForgeInventory, SiteProfile } from './types.ts';

export interface InventoryDeps {
	fetch: typeof fetch;
	/** Optional Wayback CDX discovery (Proposal 115 §1). */
	wayback?: boolean;
	/** Bound on REST pagination pages (default 5). */
	maxRestPages?: number;
	/** Bound on sub-sitemaps fetched (default 8). */
	maxSubSitemaps?: number;
	/** Wayback CDX row cap (default 500). */
	maxWaybackRows?: number;
	/** Per-request timeout (default 15 s — jayainc throttles). */
	timeoutMs?: number;
}

export interface InventoryEntry {
	path: string;
	foundVia: 'sitemap' | 'links' | 'wayback' | 'rest';
}

const ASSET_EXT = /\.(?:jpe?g|png|gif|webp|avif|svg|ico|css|js|xml|pdf|zip|gz|woff2?|ttf|eot|mp4|webm|json|txt)(?:[?#]|$)/i;
const SKIP_PATH = /^\/?(?:wp-content|wp-includes|wp-json|wp-admin|feed|comments)/i;

/** Normalize a URL to a lowercased, query/hash-free path with one leading
 *  and trailing slash (`/about/`); non-path URLs return null. */
export function normalizePath( raw: string, baseUrl?: string ): string | null {
	let value = raw.trim();
	if ( ! value ) {
		return null;
	}
	if ( /^[a-z][a-z0-9+.-]*:/i.test( value ) ) {
		// Absolute URL — keep only same-origin paths.
		try {
			const url = new URL( value );
			if ( baseUrl && url.host !== new URL( baseUrl ).host ) {
				return null;
			}
			value = url.pathname;
		} catch {
			return null;
		}
	}
	if ( /^[?#]/.test( value ) ) {
		return null;
	}
	value = value.split( '#' )[ 0 ].split( '?' )[ 0 ];
	if ( ASSET_EXT.test( value ) || SKIP_PATH.test( value ) ) {
		return null;
	}
	let path = decodeURIComponent( value ).toLowerCase();
	if ( ! path.startsWith( '/' ) ) {
		path = `/${ path }`;
	}
	path = path.replace( /\/{2,}/g, '/' );
	if ( path === '/' ) {
		return '/';
	}
	if ( path.length > 1 ) {
		path = path.replace( /\/+$/, '' );
	}
	return `${ path }/`;
}

/** Bounded text fetch — timeout, size cap, status passthrough (never throws
 *  for HTTP errors; network failures surface as status 0). */
export async function fetchText(
	fetchImpl: typeof fetch,
	url: string,
	options: { timeoutMs?: number; maxBytes?: number } = {}
): Promise<{ status: number; text: string }> {
	const timeoutMs = options.timeoutMs ?? 15_000;
	const maxBytes = options.maxBytes ?? 2 * 1024 * 1024;
	try {
		const response = await fetchImpl( url, {
			redirect: 'follow',
			signal: AbortSignal.timeout( timeoutMs ),
			headers: { 'user-agent': 'nvoos-astro-forge/0.1 (+inventory)' },
		} );
		const text = ( await response.text() ).slice( 0, maxBytes );
		return { status: response.status, text };
	} catch {
		return { status: 0, text: '' };
	}
}

async function sitemapPaths( fetchImpl: typeof fetch, base: string, deps: InventoryDeps ): Promise<string[]> {
	const paths = new Set<string>();
	const indexUrls = [ `${ base }/wp-sitemap.xml`, `${ base }/sitemap.xml` ];
	const seen = new Set<string>();
	for ( let i = 0; i < indexUrls.length; i++ ) {
		const { status, text } = await fetchText( fetchImpl, indexUrls[ i ], deps );
		if ( status !== 200 || ! text.trim() ) {
			continue;
		}
		let locs: string[] = [];
		try {
			// sitemap.js v9: two parsers — the INDEX shape (wp-sitemap.xml) and
			// the URLSET shape (sitemap.xml); both take a Readable stream.
			const indexEntries = await parseSitemapIndex( Readable.from( [ text ] ) );
			if ( Array.isArray( indexEntries ) && indexEntries.length > 0 ) {
				locs = indexEntries.map( ( entry ) => String( entry.url ?? '' ) );
			} else {
				const entries = await parseSitemap( Readable.from( [ text ] ) );
				locs = ( Array.isArray( entries ) ? entries : [] ).map( ( entry ) => String( entry.url ?? '' ) );
			}
		} catch {
			continue;
		}
		const subs: string[] = [];
		for ( const loc of locs ) {
			if ( /\.xml(?:[?#]|$)/i.test( loc ) && ! seen.has( loc ) && subs.length < ( deps.maxSubSitemaps ?? 8 ) ) {
				seen.add( loc );
				subs.push( loc );
				continue;
			}
			const path = normalizePath( loc, base );
			if ( path ) {
				paths.add( path );
			}
		}
		// Resolve sub-sitemaps (the wp-sitemap index shape).
		for ( const sub of subs ) {
			const { status: subStatus, text: subText } = await fetchText( fetchImpl, sub, deps );
			if ( subStatus !== 200 ) {
				continue;
			}
			try {
				const entries = await parseSitemap( Readable.from( [ subText ] ) );
				for ( const entry of Array.isArray( entries ) ? entries : [] ) {
					const path = normalizePath( String( entry.url ?? '' ), base );
					if ( path ) {
						paths.add( path );
					}
				}
			} catch {
				// A malformed sub-sitemap skips silently — never blocks discovery.
			}
		}
	}
	return [ ...paths ].sort();
}

async function linkPaths( fetchImpl: typeof fetch, base: string, deps: InventoryDeps ): Promise<string[]> {
	const { status, text } = await fetchText( fetchImpl, base, deps );
	if ( status !== 200 ) {
		return [];
	}
	const paths = new Set<string>();
	const hrefRe = /href\s*=\s*["']([^"']+)["']/gi;
	let match;
	while ( ( match = hrefRe.exec( text ) ) ) {
		const path = normalizePath( match[ 1 ], base );
		if ( path ) {
			paths.add( path );
		}
	}
	return [ ...paths ].sort();
}

async function waybackPaths( fetchImpl: typeof fetch, base: string, deps: InventoryDeps ): Promise<string[]> {
	if ( ! deps.wayback ) {
		return [];
	}
	const host = new URL( base ).host;
	const url = `https://web.archive.org/cdx/search/cdx?url=${ encodeURIComponent( `${ host }/*` ) }&output=json&fl=original&collapse=urlkey&filter=statuscode:200&limit=${ deps.maxWaybackRows ?? 500 }`;
	const { status, text } = await fetchText( fetchImpl, url, { timeoutMs: 30_000, maxBytes: 4 * 1024 * 1024 } );
	if ( status !== 200 ) {
		return [];
	}
	const paths = new Set<string>();
	try {
		const rows = JSON.parse( text ) as string[][];
		for ( let i = 1; i < rows.length; i++ ) {
			const path = normalizePath( rows[ i ]?.[ 0 ] ?? '', base );
			if ( path ) {
				paths.add( path );
			}
		}
	} catch {
		return [];
	}
	return [ ...paths ].sort();
}

/** REST-covered page paths (the pages collection, paginated + bounded). */
async function coveredPaths( profile: SiteProfile, deps: InventoryDeps ): Promise<{ covered: string[]; excluded: string[] }> {
	const pagesCollection = profile.collections.find( ( c ) => c.slug === 'pages' );
	if ( ! pagesCollection ) {
		return { covered: [], excluded: [] };
	}
	const base = profile.url.replace( /\/+$/, '' );
	const covered = new Set<string>( [ '/' ] );
	const maxPages = deps.maxRestPages ?? 5;
	for ( let page = 1; page <= maxPages; page++ ) {
		const { status, text } = await fetchText(
			deps.fetch,
			`${ base }/wp-json/${ pagesCollection.restBase }?_fields=slug,link&per_page=100&page=${ page }`,
			deps
		);
		if ( status !== 200 ) {
			break;
		}
		let rows: Array<{ slug?: string; link?: string }>;
		try {
			rows = JSON.parse( text );
		} catch {
			break;
		}
		if ( ! Array.isArray( rows ) ) {
			break;
		}
		for ( const row of rows ) {
			const path = normalizePath( row.link ?? `/${ row.slug ?? '' }/`, base );
			if ( path && path !== '/' ) {
				covered.add( path );
			}
		}
		if ( rows.length < 100 ) {
			break;
		}
	}
	const excluded = new Set<string>();
	for ( const slug of pagesCollection.exclude ?? [] ) {
		const path = normalizePath( `/${ slug }/` );
		if ( path ) {
			excluded.add( path );
		}
	}
	if ( profile.frontPage ) {
		covered.add( '/' );
	}
	return { covered: [ ...covered ].sort(), excluded: [ ...excluded ].sort() };
}

/** Homepage nav targets: anchors (#x, /#x) and distinct page paths, from the
 *  first <nav> region (fallback: the whole document). */
function navTargets( homeHtml: string, base: string ): { anchors: string[]; pages: string[] } {
	const navMatch = /<nav\b[^>]*>([\s\S]*?)<\/nav>/i.exec( homeHtml );
	const region = navMatch?.[ 1 ] ?? homeHtml;
	const anchors = new Set<string>();
	const pages = new Set<string>();
	const hrefRe = /href\s*=\s*["']([^"']+)["']/gi;
	let match;
	while ( ( match = hrefRe.exec( region ) ) ) {
		const raw = match[ 1 ];
		const anchorMatch = /^(?:https?:\/\/[^/]+)?\/?#([a-z0-9_-]+)\/?$/i.exec( raw.trim() );
		if ( anchorMatch ) {
			anchors.add( anchorMatch[ 1 ].toLowerCase() );
			continue;
		}
		const path = normalizePath( raw, base );
		if ( path && path !== '/' ) {
			pages.add( path );
		}
	}
	return { anchors: [ ...anchors ].sort(), pages: [ ...pages ].sort() };
}

/** Anchors without a matching id attribute in the homepage HTML. */
function danglingAnchors( homeHtml: string, anchors: string[] ): string[] {
	return anchors.filter( ( anchor ) => {
		const re = new RegExp( `id\\s*=\\s*["']${ anchor.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }["']`, 'i' );
		return ! re.test( homeHtml );
	} );
}

/**
 * The full inventory: sitemaps + homepage links (+ optional Wayback), diffed
 * against the REST pages collection and profile exclusions, with the
 * site-shape verdict and anchor reconciliation.
 */
export async function discoverInventory( profile: SiteProfile, deps: InventoryDeps ): Promise<ForgeInventory> {
	const base = profile.url.replace( /\/+$/, '' );
	const [ sitemaps, links, wayback, rest ] = await Promise.all( [
		sitemapPaths( deps.fetch, base, deps ),
		linkPaths( deps.fetch, base, deps ),
		waybackPaths( deps.fetch, base, deps ),
		coveredPaths( profile, deps ),
	] );
	const home = await fetchText( deps.fetch, base, deps );
	const { anchors, pages: navPages } = navTargets( home.text, base );

	const exposed = new Set<string>( [ ...sitemaps, ...links, ...wayback ] );
	exposed.delete( '/' );
	for ( const path of [ ...rest.covered, ...rest.excluded ] ) {
		exposed.delete( path );
	}
	const missing = [ ...exposed ].sort();

	const shape: ForgeInventory['shape'] =
		anchors.length > 0 && navPages.length === 0 ? 'one-pager' : anchors.length === 0 && navPages.length > 0 ? 'multi-page' : anchors.length > 0 ? 'hybrid' : 'multi-page';

	return {
		shape,
		navAnchors: anchors,
		navPages,
		covered: rest.covered,
		excluded: rest.excluded,
		missing,
		danglingAnchors: danglingAnchors( home.text, anchors ),
		analyzedAt: new Date().toISOString(),
	};
}
