// ---------------------------------------------------------------------------
// redirects.ts — the redirect-map generator (096 §3.3; Proposal 132).
//
// Two sources, one deterministic map:
//   1. The redirect plugins' stored rules (Redirection / Rank Math), read
//      through the Forge Bridge's read-only export. These are the legacy URLs
//      the WordPress site keeps alive today — losing them on cutover breaks
//      inbound links and search equity.
//   2. The rebuild's own URL structure (posts/pages/products), which is the
//      authority for where a URL lives NOW and wins on conflict.
//
// Fail-soft by design: a missing bridge, a disabled export, or a timeout is a
// warning on the report — never a failed build.
// ---------------------------------------------------------------------------

import type { SiteProfile } from './types.ts';
import { DEFAULT_USER_AGENT, type WpClient } from './wp-client.ts';

/** HTTP statuses a static host can express in a `_redirects` file. */
export type RedirectStatus = 301 | 302 | 303 | 307 | 308 | 404 | 410;

export interface RedirectEntry {
	from: string;
	to: string;
	status: RedirectStatus;
}

/** Where the bridge export lives + how to authenticate to it. */
export interface BridgeRedirectOptions {
	/**
	 * Full bridge base URL (the export is `${endpoint}/redirects`). Defaults to
	 * the profile's form-bridge endpoint, then `<profile.url>/wp-json/nvoos-forge/v1`.
	 */
	endpoint?: string;
	/** Shared token for a token-gated export (`NVOOS_FORGE_REDIRECTS_TOKEN`). */
	token?: string;
	/** Test seam — the underlying fetch. */
	fetchImpl?: typeof fetch;
	timeoutMs?: number;
}

export interface BridgeRedirectResult {
	redirects: RedirectEntry[];
	truncated: boolean;
	warnings: string[];
}

export interface RedirectReport {
	redirects: RedirectEntry[];
	/** Entries scanned across all collections. */
	scanned: number;
	/** Any source stopped at its cap (never silent). */
	truncated: boolean;
	/** Stored rules that survived the merge from the Forge Bridge. */
	bridge: number;
	/** Non-fatal problems (a failed bridge fetch, an odd payload). */
	warnings: string[];
}

const DEFAULT_TIMEOUT_MS = 10_000;

/** Statuses the bridge may return; anything else is ignored. */
const PORTABLE_STATUSES: ReadonlySet<number> = new Set( [ 301, 302, 303, 307, 308, 404, 410 ] );

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

/** Narrow an unknown JSON value to a record. */
function isRecord( value: unknown ): value is Record<string, unknown> {
	return typeof value === 'object' && value !== null && ! Array.isArray( value );
}

/** A human-readable error message from an unknown throwable. */
function errorMessage( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
}

/**
 * Read the redirect plugins' stored rules through the Forge Bridge.
 *
 * The bridge normalizes Redirection (`redirection_items`) and Rank Math
 * (`rank_math_redirections`) into `{ source, target, type }`; this function
 * validates and re-types that contract. A 404 means "bridge not installed" and
 * is silent; every other failure is a warning so the build still completes.
 */
export async function fetchBridgeRedirects(
	profile: SiteProfile,
	options: BridgeRedirectOptions = {}
): Promise<BridgeRedirectResult> {
	const base = (
		options.endpoint ??
		profile.forms?.endpoint ??
		`${ profile.url.replace( /\/+$/, '' ) }/wp-json/nvoos-forge/v1`
	).replace( /\/+$/, '' );
	const url = `${ base }/redirects`;
	const token = options.token ?? process.env.NVOOS_FORGE_REDIRECTS_TOKEN ?? '';
	const fetchImpl = options.fetchImpl ?? fetch;

	const headers: Record<string, string> = {
		accept: 'application/json',
		'user-agent': DEFAULT_USER_AGENT,
	};
	if ( token ) {
		headers[ 'x-forge-redirects-token' ] = token;
	}

	let response: Response;
	try {
		response = await fetchImpl( url, {
			headers,
			signal: AbortSignal.timeout( options.timeoutMs ?? DEFAULT_TIMEOUT_MS ),
		} );
	} catch ( error ) {
		return {
			redirects: [],
			truncated: false,
			warnings: [ `stored redirects unavailable (${ errorMessage( error ) }) — using the URL-structure map only.` ],
		};
	}

	// No bridge on the source site: normal for a plain WordPress install.
	if ( response.status === 404 ) {
		return { redirects: [], truncated: false, warnings: [] };
	}
	if ( ! response.ok ) {
		return {
			redirects: [],
			truncated: false,
			warnings: [ `stored redirects unavailable (HTTP ${ response.status }) — using the URL-structure map only.` ],
		};
	}

	let body: unknown;
	try {
		body = await response.json();
	} catch {
		return {
			redirects: [],
			truncated: false,
			warnings: [ 'stored redirects returned invalid JSON — using the URL-structure map only.' ],
		};
	}

	if ( ! isRecord( body ) || ! Array.isArray( body.redirects ) ) {
		return {
			redirects: [],
			truncated: false,
			warnings: [ 'stored redirects returned an unexpected payload — using the URL-structure map only.' ],
		};
	}

	const redirects: RedirectEntry[] = [];
	const seen = new Set<string>();
	for ( const raw of body.redirects ) {
		if ( ! isRecord( raw ) ) {
			continue;
		}
		const from = typeof raw.source === 'string' ? raw.source : '';
		const to = typeof raw.target === 'string' ? raw.target : '';
		const status = typeof raw.type === 'number' ? raw.type : 301;
		if ( ! from.startsWith( '/' ) || from === '/' || from === to ) {
			continue;
		}
		if ( ! PORTABLE_STATUSES.has( status ) ) {
			continue;
		}
		if ( status < 400 && ! to ) {
			continue;
		}
		if ( seen.has( from ) ) {
			continue;
		}
		seen.add( from );
		redirects.push( { from, to, status: status as RedirectStatus } );
	}
	redirects.sort( ( a, b ) => a.from.localeCompare( b.from ) );

	return { redirects, truncated: body.truncated === true, warnings: [] };
}

/**
 * Generate the redirect map for a profile.
 *
 * Stored (bridge) rules are inserted first; the URL-structure rules
 * (posts → /posts/{id}/{slug}/, pages → / or /{slug}/, products →
 * /products/{id}/{slug}/) overwrite them on conflict because they describe
 * where the rebuilt site actually serves the content. Deterministic (sorted),
 * deduped, and never mapping a URL onto itself.
 *
 * Pass `{ bridge: false }` to skip the bridge entirely (offline builds, tests),
 * or a `BridgeRedirectOptions` object to override the endpoint/token.
 */
export async function generateRedirects(
	profile: SiteProfile,
	client: WpClient,
	options: { bridge?: boolean | BridgeRedirectOptions } = {}
): Promise<RedirectReport> {
	const redirects = new Map<string, RedirectEntry>();
	const bridgeFroms = new Set<string>();
	const warnings: string[] = [];
	let scanned = 0;
	let truncated = false;

	if ( options.bridge !== false ) {
		const bridgeOptions: BridgeRedirectOptions =
			typeof options.bridge === 'object' && options.bridge !== null ? options.bridge : {};
		const result = await fetchBridgeRedirects( profile, {
			...bridgeOptions,
			fetchImpl: bridgeOptions.fetchImpl ?? client.transport().fetchImpl,
		} );
		warnings.push( ...result.warnings );
		truncated = truncated || result.truncated;
		for ( const entry of result.redirects ) {
			if ( ! redirects.has( entry.from ) ) {
				redirects.set( entry.from, entry );
				bridgeFroms.add( entry.from );
			}
		}
	}

	const push = ( source: string, target: string ) => {
		if ( source && source !== target && source !== '/' ) {
			redirects.set( source, { from: source, to: target, status: 301 } );
			// The rebuild's own URL wins over a stored rule for the same source.
			bridgeFroms.delete( source );
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
		bridge: bridgeFroms.size,
		warnings,
	};
}
