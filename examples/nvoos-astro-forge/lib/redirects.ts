// ---------------------------------------------------------------------------
// redirects.ts — the live 301-map generator (096 §3.3 long-tail; the same
// rules the template's /redirects.json endpoint applies at build time, but
// computed on demand from the WordPress REST API instead of a build).
// ---------------------------------------------------------------------------

import type { SiteProfile } from './types.ts';
import type { WpClient } from './wp-client.ts';

export interface RedirectEntry {
	from: string;
	to: string;
	status: 301;
}

export interface RedirectReport {
	redirects: RedirectEntry[];
	/** Entries scanned across all collections. */
	scanned: number;
	/** Any collection stopped at its page cap (never silent). */
	truncated: boolean;
}

/** The pathname of a WP canonical link (empty when not same-site/parseable). */
function pathnameOf( link: string | undefined ): string | undefined {
	if ( ! link ) {
		return undefined;
	}
	try {
		return new URL( link ).pathname;
	} catch {
		return undefined;
	}
}

/**
 * Generate the single-hop 301 map for a profile: posts → /posts/{id}/{slug}/,
 * pages → / (front page) or /{slug}/, products → /products/{id}/{slug}/.
 * Deterministic (sorted), deduped, and never mapping a URL onto itself.
 */
export async function generateRedirects( profile: SiteProfile, client: WpClient ): Promise<RedirectReport> {
	const redirects = new Map<string, RedirectEntry>();
	let scanned = 0;
	let truncated = false;
	const push = ( source: string, target: string ) => {
		if ( source && source !== target && source !== '/' && ! redirects.has( source ) ) {
			redirects.set( source, { from: source, to: target, status: 301 } );
		}
	};

	for ( const collection of profile.collections ) {
		const { entries, truncated: collectionTruncated } = await client.fetchAll<{
			id?: number;
			slug?: string;
			link?: string;
			permalink?: string;
		}>( collection.restBase, {
			perPage: collection.perPage,
			maxPages: collection.maxPages,
			fields: [ 'id', 'slug', 'link', 'permalink' ],
		} );
		truncated = truncated || collectionTruncated;
		scanned += entries.length;
		for ( const entry of entries ) {
			const source = pathnameOf( entry.link ?? entry.permalink );
			if ( ! source || entry.slug === undefined || entry.id === undefined ) {
				continue;
			}
			if ( collection.slug === 'pages' ) {
				push( source, entry.slug === profile.frontPage ? '/' : `/${ entry.slug }/` );
			} else if ( collection.slug === 'products' ) {
				push( source, `/products/${ entry.id }/${ entry.slug }/` );
			} else {
				push( source, `/posts/${ entry.id }/${ entry.slug }/` );
			}
		}
	}

	return {
		redirects: [ ...redirects.values() ].sort( ( a, b ) => a.from.localeCompare( b.from ) ),
		scanned,
		truncated,
	};
}
