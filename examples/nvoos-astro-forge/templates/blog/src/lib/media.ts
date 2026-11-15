// ---------------------------------------------------------------------------
// media.ts — the runtime media-origin rules (Proposal 139).
//
// Astro only downloads + optimizes images from hosts it has been told are
// authorized (`image.domains` in astro.config.mjs). This module is the
// runtime mirror of that rule: SmartImage uses it to decide whether an image
// is safe to hand to <Image>, and to repair stale absolute media URLs.
//
// The repair is the important half: WordPress stores absolute media URLs in
// the database, so moving the CMS to a new domain (or putting it on a
// subdomain) leaves `…/wp-content/uploads/x.jpg` pointing at the OLD host.
// When the host is neither the CMS nor an explicitly allowlisted media host,
// a WordPress asset path is re-pointed at the CMS origin — the same
// normalization a `wp search-replace` performs, applied at build time.
//
// Keep the env contract in sync with astro.config.mjs and
// scripts/media-localize-lib.mjs.
// ---------------------------------------------------------------------------

import manifest from '../../nvoos.json';

/** The subset of `import.meta.env` this module reads. */
export type MediaEnv = Record<string, string | undefined>;

/** A WordPress-owned asset path (`/wp-content/…`, `/wp-includes/…`). */
const WP_ASSET_PATH = /^\/(?:wp-content|wp-includes)\//i;

/** `import.meta.env` is a getter object — accept it as a plain record. */
function readEnv( env?: MediaEnv ): MediaEnv {
	return env ?? ( import.meta.env as unknown as MediaEnv );
}

/** The scaffolded profile URL, used when `WP_SITE_URL` is unset. */
function manifestUrl(): string {
	return ( manifest as unknown as { url?: string } ).url ?? '';
}

/**
 * The CMS origin (no trailing slash). `WP_SITE_URL` wins, mirroring the
 * content layer and astro.config.mjs.
 *
 * @param env Environment record (defaults to `import.meta.env`).
 * @returns The CMS origin, or `''` when unknown.
 */
export function cmsOrigin( env?: MediaEnv ): string {
	const raw = ( readEnv( env ).WP_SITE_URL ?? manifestUrl() ?? '' ).trim();
	return raw ? raw.replace( /\/+$/, '' ) : '';
}

/** The manifest's `media.hosts` (absent in projects scaffolded before 138). */
function manifestMediaHosts(): string[] {
	const media = ( manifest as unknown as { media?: { hosts?: unknown } } ).media;
	return Array.isArray( media?.hosts ) ? media.hosts.map( String ) : [];
}

/** Normalize a host list: lower-case, drop ports, drop empties. */
function normalizeHosts( hosts: string[] ): string[] {
	return hosts
		.map( ( host ) => String( host ).trim().toLowerCase() )
		.filter( Boolean )
		.map( ( host ) => {
			const candidate = /^[a-z][a-z0-9+.-]*:\/\//i.test( host ) ? host : `http://${ host }`;
			try {
				return new URL( candidate ).hostname;
			} catch {
				return host;
			}
		} )
		.filter( Boolean );
}

/**
 * Extra authorized media hosts: `profile.media.hosts` (baked into the
 * manifest) plus `NVOOS_FORGE_MEDIA_HOSTS` (csv). Ports are dropped —
 * `image.domains` matches hostnames only. The two lists are merged, so a
 * deploy override can never drop a host the profile declared.
 *
 * @param env Environment record (defaults to `import.meta.env`).
 * @returns Lower-cased hostnames.
 */
export function extraMediaHosts( env?: MediaEnv ): string[] {
	const fromManifest = normalizeHosts( manifestMediaHosts() );
	const fromEnv = normalizeHosts( ( readEnv( env ).NVOOS_FORGE_MEDIA_HOSTS ?? '' ).split( /[,\s]+/ ) );
	return [ ...new Set( [ ...fromManifest, ...fromEnv ] ) ];
}

/**
 * Every hostname Astro is authorized to optimize (the CMS host plus the
 * extra media hosts). Mirrors `image.domains` in astro.config.mjs.
 *
 * @param env Environment record (defaults to `import.meta.env`).
 * @returns Authorized hostnames.
 */
export function imageHosts( env?: MediaEnv ): string[] {
	const hosts = new Set<string>( extraMediaHosts( env ) );
	try {
		const origin = cmsOrigin( env );
		if ( origin ) {
			hosts.add( new URL( origin ).hostname.toLowerCase() );
		}
	} catch {
		// An unparseable CMS origin is a config error surfaced by the build.
	}
	return [ ...hosts ];
}

/**
 * Re-point a stale WordPress asset URL at the CMS origin.
 *
 * Only `/wp-content/…` and `/wp-includes/…` paths are touched, and only when
 * their host is neither the CMS nor an allowlisted media host — a CDN or a
 * deliberately external host is never rewritten.
 *
 * @param url Candidate image URL.
 * @param env Environment record (defaults to `import.meta.env`).
 * @returns The repaired URL (or the input, unchanged).
 */
export function repairMediaUrl( url: string | undefined, env?: MediaEnv ): string | undefined {
	if ( ! url ) {
		return url;
	}
	let parsed: URL;
	try {
		parsed = new URL( url );
	} catch {
		return url;
	}
	if ( ! WP_ASSET_PATH.test( parsed.pathname ) ) {
		return url;
	}
	const origin = cmsOrigin( env );
	if ( ! origin ) {
		return url;
	}
	let cms: URL;
	try {
		cms = new URL( origin );
	} catch {
		return url;
	}
	const host = parsed.hostname.toLowerCase();
	if ( host === cms.hostname.toLowerCase() || extraMediaHosts( env ).includes( host ) ) {
		return url;
	}
	return `${ cms.origin }${ parsed.pathname }${ parsed.search }`;
}

/**
 * Whether an image URL may be handed to Astro's `<Image>` (it will be
 * downloaded and optimized at build time, or processed on demand under SSR).
 *
 * @param url Candidate image URL.
 * @param env Environment record (defaults to `import.meta.env`).
 * @returns True when the host is authorized.
 */
export function isAuthorizedImageUrl( url: string | undefined, env?: MediaEnv ): boolean {
	if ( ! url ) {
		return false;
	}
	let parsed: URL;
	try {
		parsed = new URL( url );
	} catch {
		return false;
	}
	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		return false;
	}
	return imageHosts( env ).includes( parsed.hostname.toLowerCase() );
}
