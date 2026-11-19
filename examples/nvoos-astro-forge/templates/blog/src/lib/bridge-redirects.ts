// ---------------------------------------------------------------------------
// bridge-redirects.ts — read the source site's stored redirects, safely.
//
// The Forge Bridge (Proposal 132) exposes the redirect plugin's rules at
// `/wp-json/nvoos-forge/v1/redirects`. Reaching it during a BUILD means the
// build can fail on someone else's HTTP, so this module owns the whole
// contract: it never throws, and it never parses a body it has not proven.
//
// Two failure modes are load-bearing:
//
//   1. A cut-over site answers an unknown path with its OWN `index.html` at
//      status 200 (`content-type: text/html`). `response.ok` is true, so
//      `response.json()` threw `Unexpected token '<'` and killed the build —
//      while the caller's contract says a missing bridge is a warning, never a
//      build failure (krishandramika, 2026-11-20).
//
//   2. `content.source: 'materialized'` is a PROMISE that the CMS is gone, and
//      the manifest URL is then the REBUILT site: the request is a
//      self-request that can only ever return the fallback page above. Such a
//      build must not go near the network at all.
// ---------------------------------------------------------------------------

export type RedirectStatus = 301 | 302 | 303 | 307 | 308 | 404 | 410;

export type RedirectRow = { from: string; to: string; status: RedirectStatus };

/** Statuses a static host can express in `_redirects`. */
export const PORTABLE_STATUSES: ReadonlySet< number > = new Set( [ 301, 302, 303, 307, 308, 404, 410 ] );

/** The bridge's REST root, as the plugin registers it. */
const ENDPOINT_SUFFIX = '/wp-json/nvoos-forge/v1';

/** The default ceiling on one build-time request, in milliseconds. */
const DEFAULT_TIMEOUT_MS = 8000;

export interface BridgeRedirectRequest {
	/** The site origin, or an endpoint already ending in `/wp-json/nvoos-forge/v1`. */
	base: string;
	/** `NVOOS_FORGE_REDIRECTS_TOKEN`, when the bridge requires one. */
	token?: string;
	/** `manifest.contentSource` — `materialized` means the CMS is gone. */
	contentSource?: string;
	/** Injected in tests. */
	fetchImpl?: typeof fetch;
	timeoutMs?: number;
}

export interface BridgeRedirectResult {
	rows: RedirectRow[];
	/** One line to print, or `undefined` when there is nothing to say. */
	warning?: string;
}

/** Resolve the redirects URL from an origin OR an already-qualified endpoint. */
export function redirectsUrl( base: string ): string {
	const trimmed = String( base ?? '' ).replace( /\/+$/, '' );
	if ( ! trimmed ) {
		return '';
	}
	return trimmed.endsWith( ENDPOINT_SUFFIX )
		? `${ trimmed }/redirects`
		: `${ trimmed }${ ENDPOINT_SUFFIX }/redirects`;
}

/**
 * Fetch and validate the stored redirect map.
 *
 * Every failure — no base, a dead origin, a 500, an HTML body, a payload of the
 * wrong shape — returns an empty list plus (where it is worth saying) one
 * warning line. Nothing here throws.
 */
export async function fetchBridgeRedirects( request: BridgeRedirectRequest ): Promise< BridgeRedirectResult > {
	// The CMS is gone: the snapshot is the only source, so nothing may contact
	// the old origin. Silent by design — a site built without a CMS is not a
	// site with a broken bridge.
	if ( request.contentSource === 'materialized' ) {
		return { rows: [] };
	}

	const base = String( request.base ?? '' ).replace( /\/+$/, '' );
	if ( ! base ) {
		return { rows: [] };
	}

	const fetchImpl = request.fetchImpl ?? fetch;
	const headers: Record< string, string > = { accept: 'application/json' };
	if ( request.token ) {
		headers[ 'x-forge-redirects-token' ] = request.token;
	}

	let response: Response;
	try {
		response = await fetchImpl( redirectsUrl( base ), {
			headers,
			signal: timeoutSignal( request.timeoutMs ?? DEFAULT_TIMEOUT_MS ),
		} );
	} catch ( error ) {
		return {
			rows: [],
			warning: `stored redirects unavailable (${ messageOf( error ) }) — using the URL-structure map only.`,
		};
	}

	// No bridge on the source site: normal for a plain WordPress install.
	if ( response.status === 404 ) {
		return { rows: [] };
	}
	if ( ! response.ok ) {
		return {
			rows: [],
			warning: `stored redirects unavailable (HTTP ${ response.status }) — using the URL-structure map only.`,
		};
	}

	// Prove the body BEFORE parsing it. A cut-over origin returns HTML at 200,
	// and an unguarded `response.json()` fails the build on it.
	let body: unknown;
	try {
		body = JSON.parse( await response.text() );
	} catch {
		return {
			rows: [],
			warning: 'stored redirects returned a non-JSON body — using the URL-structure map only.',
		};
	}

	const rawList =
		typeof body === 'object' && body !== null ? ( body as { redirects?: unknown } ).redirects : undefined;
	if ( ! Array.isArray( rawList ) ) {
		return {
			rows: [],
			warning: 'stored redirects returned an unexpected payload — using the URL-structure map only.',
		};
	}

	const rows: RedirectRow[] = [];
	const seen = new Set< string >();
	for ( const raw of rawList ) {
		if ( typeof raw !== 'object' || raw === null ) {
			continue;
		}
		const record = raw as Record< string, unknown >;
		const from = typeof record.source === 'string' ? record.source : '';
		const to = typeof record.target === 'string' ? record.target : '';
		const status = typeof record.type === 'number' ? record.type : 301;
		if ( ! from.startsWith( '/' ) || from === '/' || from === to || seen.has( from ) ) {
			continue;
		}
		if ( ! PORTABLE_STATUSES.has( status ) || ( status < 400 && ! to ) ) {
			continue;
		}
		seen.add( from );
		rows.push( { from, to, status: status as RedirectStatus } );
	}

	return { rows };
}

/** A build must not hang on an origin that never answers. */
function timeoutSignal( ms: number ): AbortSignal | undefined {
	try {
		return AbortSignal.timeout( ms );
	} catch {
		return undefined;
	}
}

function messageOf( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
}
