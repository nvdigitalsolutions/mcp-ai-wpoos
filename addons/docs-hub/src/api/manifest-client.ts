/**
 * Docs Hub API client — fetches data from the REST backend.
 *
 * Manifest and search responses are cached in sessionStorage so that
 * navigating back to a previously visited page doesn't fire a new
 * network request.
 *
 * @since 1.0.0
 */

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface ManifestEntry {
	slug: string;
	title: string;
	source: string;
	plugin_name: string;
	order: number;
	toc: TocItem[];
	prev?: { slug: string; title: string } | null;
	next?: { slug: string; title: string } | null;
	tags?: string[];
	description?: string;
}

export interface TocItem {
	level: number;
	text: string;
	anchor: string;
}

export interface ManifestGroup {
	plugin_name: string;
	source: string;
	pages: ManifestEntry[];
}

export interface Manifest {
	version: string;
	built_at: number;
	tree: ManifestGroup[];
	slug_map: Record<string, string>;
	total_pages: number;
	broken_links: BrokenLink[];
}

export interface BrokenLink {
	source: string;
	target: string;
}

export interface DocPage {
	slug: string;
	title: string;
	content: string;
	toc: TocItem[];
	prev: { slug: string; title: string } | null;
	next: { slug: string; title: string } | null;
	source: string;
	plugin_name: string;
	tags: string[];
	description: string;
	last_modified: number;
	/** Repo-relative file path (e.g. "docs/getting-started.md"). */
	relative_path: string;
	remote_url?: string;
}

export interface SearchResult {
	slug: string;
	title: string;
	excerpt: string;
	score: number;
}

export interface SearchResponse {
	results: SearchResult[];
	total: number;
	query: string;
}

// ---------------------------------------------------------------------------
// Configuration — injected by wp_localize_script
// ---------------------------------------------------------------------------

declare global {
	interface Window {
		NVOOS_DOCS_HUB?: {
			apiUrl: string;
			nonce: string;
			config: {
				section?: string;
				theme?: string;
				search?: string;
				sidebar?: string;
				home?: string;
			};
		};
	}
}

function getConfig() {
	return window.NVOOS_DOCS_HUB ?? { apiUrl: '/wp-json/nvoos-docs/v1', nonce: '', config: {} };
}

// ---------------------------------------------------------------------------
// Caching helpers (sessionStorage)
// ---------------------------------------------------------------------------

const CACHE_TTL_MS = 5 * 60 * 1000; // 5 minutes

interface CacheEntry<T> {
	data: T;
	expires: number;
}

function cacheGet<T>( key: string ): T | null {
	try {
		const raw = sessionStorage.getItem( key );
		if ( ! raw ) {
			return null;
		}
		const entry: CacheEntry<T> = JSON.parse( raw );
		if ( Date.now() > entry.expires ) {
			sessionStorage.removeItem( key );
			return null;
		}
		return entry.data;
	} catch {
		return null;
	}
}

function cacheSet<T>( key: string, data: T ): void {
	try {
		const entry: CacheEntry<T> = { data, expires: Date.now() + CACHE_TTL_MS };
		sessionStorage.setItem( key, JSON.stringify( entry ) );
	} catch {
		// sessionStorage may be unavailable (private browsing quota) — ignore.
	}
}

// ---------------------------------------------------------------------------
// Fetch helpers
// ---------------------------------------------------------------------------

async function apiFetch<T>( path: string, options: RequestInit = {} ): Promise<T> {
	const { apiUrl, nonce } = getConfig();
	const url = `${ apiUrl.replace( /\/$/, '' ) }/${ path.replace( /^\//, '' ) }`;

	const headers: Record<string, string> = {
		'Content-Type': 'application/json',
	};

	if ( nonce ) {
		headers[ 'X-WP-Nonce' ] = nonce;
	}

	const res = await fetch( url, {
		...options,
		headers: { ...headers, ...( options.headers as Record<string, string> ?? {} ) },
	} );

	if ( ! res.ok ) {
		throw new Error( `HTTP ${ res.status }: ${ res.statusText }` );
	}

	return res.json() as Promise<T>;
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Fetch the documentation manifest. Result is cached for 5 minutes.
 */
export async function fetchManifest(): Promise<Manifest> {
	const CACHE_KEY = 'nvoos_dh_manifest';
	const cached = cacheGet<Manifest>( CACHE_KEY );

	if ( cached ) {
		return cached;
	}

	const manifest = await apiFetch<Manifest>( 'manifest' );
	cacheSet( CACHE_KEY, manifest );
	return manifest;
}

/**
 * Fetch the rendered content for a single page by slug.
 * Results are cached by slug.
 */
export async function fetchPage( slug: string ): Promise<DocPage> {
	const CACHE_KEY = `nvoos_dh_page_${ slug }`;
	const cached = cacheGet<DocPage>( CACHE_KEY );

	if ( cached ) {
		return cached;
	}

	const page = await apiFetch<DocPage>( `pages/${ slug.split( '/' ).map( encodeURIComponent ).join( '/' ) }` );
	cacheSet( CACHE_KEY, page );
	return page;
}

/**
 * Search the documentation index.
 */
export async function fetchSearch( query: string ): Promise<SearchResponse> {
	if ( ! query.trim() ) {
		return { results: [], total: 0, query };
	}

	const params = new URLSearchParams( { q: query.trim() } );
	return apiFetch<SearchResponse>( `search?${ params.toString() }` );
}

/**
 * Clear the in-memory sessionStorage caches.
 */
export function clearCache(): void {
	try {
		const keys: string[] = [];
		for ( let i = 0; i < sessionStorage.length; i++ ) {
			const key = sessionStorage.key( i );
			if ( key && key.startsWith( 'nvoos_dh_' ) ) {
				keys.push( key );
			}
		}
		keys.forEach( ( k ) => sessionStorage.removeItem( k ) );
	} catch {
		// Ignore.
	}
}
