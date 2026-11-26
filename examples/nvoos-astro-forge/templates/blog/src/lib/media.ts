// SPDX-License-Identifier: MIT
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

/** The uploads prefix the managed `/media/*` proxy maps (mirrors proxyMediaPath). */
const WP_UPLOADS_PREFIX = '/wp-content/uploads/';

/** `import.meta.env` is a getter object — accept it as a plain record. */
function readEnv( env?: MediaEnv ): MediaEnv {
	return env ?? ( import.meta.env as unknown as MediaEnv );
}

/** The scaffolded profile URL, used when `WP_SITE_URL` is unset. */
function manifestUrl(): string {
	return ( manifest as unknown as { url?: string } ).url ?? '';
}

/**
 * Whether the profile declares the CMS gone (`content.source: materialized`).
 *
 * A materialized site's content comes from the committed snapshot, so there is
 * no origin to download from — or to repair stale paths to.
 */
export function materializedContent(): boolean {
	return ( manifest as unknown as { contentSource?: string } ).contentSource === 'materialized';
}

/**
 * The CMS origin (no trailing slash). `WP_SITE_URL` wins, mirroring the
 * content layer and astro.config.mjs.
 *
 * @param env Environment record (defaults to `import.meta.env`).
 * @returns The CMS origin, or `''` when unknown (or when the CMS is gone).
 */
export function cmsOrigin( env?: MediaEnv ): string {
	// A materialized profile has NO CMS. Keeping the old origin would authorize
	// Astro to download images from a host that is about to 404 — and the build
	// dies on FailedToFetchRemoteImageDimensions. With no origin, SmartImage
	// falls back to a plain <img> and the post-build localizer mirrors the
	// committed snapshot (media.snapshot) instead.
	if ( materializedContent() ) {
		return '';
	}
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
 * A per-scaffold cache token for the proxied media path.
 *
 * The scaffold rewrites `nvoos.json` on every CI run, so `generatedAt` is a new
 * value per deploy and needs no env plumbing — this module is also imported by
 * client scripts, where `process.env` does not exist.
 *
 * @returns Digits only, or `''` when the manifest carries no stamp.
 */
function cacheBustToken(): string {
	const stamp = ( manifest as unknown as { generatedAt?: string } ).generatedAt ?? '';
	return stamp.replace( /\D/g, '' ).slice( 0, 14 );
}

/**
 * The same-origin URL a visitor should load for a CMS asset (Proposal 139).
 *
 * A managed build serves `/media/<uploads path>` from the CMS server-to-server,
 * so emitting an upload through it keeps media same-origin. This is the RUNTIME
 * counterpart of the localizer's rewrite: the localizer edits the PRERENDERED
 * HTML on disk, but a page rendered per request (the 404, any future SSR route)
 * never passes through it — so the template has to ask for the proxied path
 * itself (2026-11-26: the 404 page hotlinked the CMS logo and its icon sheet
 * while every prerendered page carried `/media/...`).
 *
 * Non-uploads, and every static-tier build, come back as the repaired URL — a
 * static artifact must never grow a `/media/` reference nothing serves.
 *
 * A MATERIALIZED build is the other exception, for the same reason: it has no
 * CMS to proxy to. Its uploads are mirrored into `dist/media/<hashed>` by the
 * post-build localizer, and only the localizer knows that name — so the template
 * hands it the CMS URL untouched. Rewriting first would name a path nothing
 * serves AND hide the URL from the localizer's report, which is the only source
 * structured data is repaired from (virtuallyjamaica, 2026-11-26: three
 * documents shipped a `/wp-content/` logo in JSON-LD beside a 503'ing
 * `/media/2018/11/...` `<img>`).
 *
 * The proxy form carries a per-build `?v=` stamp. That path is the CMS's own,
 * not content-hashed, and a CDN keeps it for as long as the CMS says — often a
 * year — so a proxy fix could never reach a visitor that cached the broken
 * answer (eca, 2026-11-26: the corrected logo stayed a 0-byte edge HIT with a
 * year left to run, on a shared zone that is not ours to purge). A new stamp
 * per build makes every deploy a fresh cache key.
 *
 * @param url Candidate asset URL.
 * @param env Environment record (defaults to `import.meta.env`).
 * @returns The `/media/<path>?v=<build>` form for an upload on a managed
 *          CMS-backed build, else the repaired URL.
 */
export function mediaUrl( url: string | undefined, env?: MediaEnv ): string | undefined {
	const repaired = repairMediaUrl( url, env );
	// No CMS, no proxy: a materialized build's media comes from the mirror.
	if ( ! repaired || materializedContent() || ! managedRuntime() ) {
		return repaired;
	}
	let parsed: URL;
	try {
		parsed = new URL( repaired );
	} catch {
		return repaired;
	}
	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		return repaired;
	}
	const path = parsed.pathname;
	if ( ! path.toLowerCase().startsWith( WP_UPLOADS_PREFIX ) ) {
		return repaired;
	}
	const rest = path.slice( WP_UPLOADS_PREFIX.length );
	if ( ! rest ) {
		return repaired;
	}
	const token = cacheBustToken();
	if ( ! token ) {
		return `/media/${ rest }${ parsed.search }`;
	}
	const params = new URLSearchParams( parsed.search );
	params.set( 'v', token );
	return `/media/${ rest }?${ params.toString() }`;
}

/**
 * Whether this build's manifest declares the managed runtime.
 *
 * The localizer resolves its mode from the same field, so the template and the
 * post-build pass agree on which tier this project is.
 *
 * @returns True when `nvoos.json` says `runtime: "managed"`.
 */
function managedRuntime(): boolean {
	return ( manifest as unknown as { runtime?: string } ).runtime === 'managed';
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
