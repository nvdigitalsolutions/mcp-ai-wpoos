// ---------------------------------------------------------------------------
// media-localize-lib.mjs — pure helpers for the post-build asset localizer.
//
// Proposal 138 — "always available locally". Astro's <Image> already
// downloads and optimizes authorized remote media into dist/_astro/, but
// three classes of asset never pass through it:
//   * body-content <img> tags (rendered by set:html from sanitized HTML),
//   * site chrome (logo / favicon / footer logo) and collage photos,
//   * anything whose host is not on the image allowlist.
// This module decides which URLs to mirror, names the files
// deterministically, and rewrites the built HTML/CSS so the deployed
// artifact no longer hotlinks the CMS.
//
// Design rules (industry practice for CMS -> static pipelines — Gatsby's
// createRemoteFileNode, WP2Static's URL rewrite, localize-html):
//   * only http(s) URLs, only known hosts or WordPress asset paths,
//   * never rewrite <a href>, <meta> (og:image must stay absolute) or
//     JSON-LD payloads,
//   * deterministic, content-addressed filenames (idempotent builds),
//   * a failed download keeps the original URL — never a build failure.
//
// Pure and dependency-free so the forge test suite can exercise it under
// both vitest and `tsc --noEmit`.
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';

/** Extensions we treat as images when deriving a file name. */
export const IMAGE_EXTENSIONS = new Set([
	'png',
	'jpg',
	'jpeg',
	'gif',
	'webp',
	'avif',
	'svg',
	'ico',
	'bmp',
	'tif',
	'tiff',
]);

/** Content-Type → file extension (used when the URL carries none). */
export const CONTENT_TYPE_EXTENSION = {
	'image/png': 'png',
	'image/jpeg': 'jpg',
	'image/jpg': 'jpg',
	'image/gif': 'gif',
	'image/webp': 'webp',
	'image/avif': 'avif',
	'image/svg+xml': 'svg',
	'image/x-icon': 'ico',
	'image/vnd.microsoft.icon': 'ico',
	'image/bmp': 'bmp',
	'image/tiff': 'tiff',
	'font/woff': 'woff',
	'font/woff2': 'woff2',
	'font/ttf': 'ttf',
	'font/otf': 'otf',
	'application/font-woff': 'woff',
	'application/font-woff2': 'woff2',
};

/** `/wp-content/...` and `/wp-includes/...` are WordPress-owned paths. */
const WP_ASSET_PATH = /^\/(?:wp-content|wp-includes)\//i;

/** The uploads prefix — the only path the managed `/media/*` proxy serves. */
export const WP_UPLOADS_PREFIX = '/wp-content/uploads/';

/** A URL attribute that carries one URL. */
const SINGLE_URL_ATTRS = [ 'src', 'data-src', 'data-lazy-src', 'poster', 'href' ];
/** A URL attribute that carries a candidate list. */
const SRCSET_ATTRS = [ 'srcset', 'data-srcset' ];

/**
 * Parse a comma/space separated host list (`NVOOS_FORGE_MEDIA_HOSTS`).
 * Hostnames are lower-cased; ports are dropped (image.domains matches the
 * hostname, never the port) and empty entries are ignored.
 *
 * @param {string|undefined|null} value Raw env value.
 * @returns {string[]} Normalized hostnames.
 */
export function parseHostList( value ) {
	return String( value ?? '' )
		.split( /[,\s]+/ )
		.map( ( entry ) => entry.trim().toLowerCase() )
		.filter( Boolean )
		.map( ( entry ) => {
			const candidate = /^[a-z][a-z0-9+.-]*:\/\//i.test( entry ) ? entry : `http://${ entry }`;
			try {
				return new URL( candidate ).hostname;
			} catch {
				return entry;
			}
		} )
		.filter( Boolean );
}

/**
 * The hostname of an absolute URL, or `undefined` when it is not parseable.
 *
 * @param {string} value Candidate URL.
 * @returns {string|undefined} Lower-cased hostname.
 */
export function hostOf( value ) {
	try {
		return new URL( value ).hostname.toLowerCase();
	} catch {
		return undefined;
	}
}

/**
 * Whether a path belongs to WordPress (uploads, plugins, theme assets).
 *
 * @param {string} pathname URL pathname.
 * @returns {boolean} True for `/wp-content/` and `/wp-includes/` paths.
 */
export function isWpAssetPath( pathname ) {
	return WP_ASSET_PATH.test( pathname );
}

/**
 * Map a WordPress upload URL to the managed runtime's same-origin proxy path.
 *
 * The managed tier ships `src/pages/media/[...path].ts`, which forwards
 * `/media/<rest>` to `<CMS>/wp-content/uploads/<rest>` server-to-server —
 * keeping the CMS host out of the HTML. Only uploads are proxied; anything
 * else (theme assets, wp-includes) is not a proxy candidate.
 *
 * @param {string} rawUrl Absolute URL found in the build.
 * @returns {string|undefined} `/media/<rest>`, or undefined when not an upload.
 */
export function proxyMediaPath( rawUrl ) {
	let parsed;
	try {
		parsed = new URL( rawUrl );
	} catch {
		return undefined;
	}
	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		return undefined;
	}
	if ( ! parsed.pathname.toLowerCase().startsWith( WP_UPLOADS_PREFIX ) ) {
		return undefined;
	}
	const rest = parsed.pathname.slice( WP_UPLOADS_PREFIX.length ).replace( /^\/+/, '' );
	return rest ? `/media/${ rest }` : undefined;
}

/**
 * Decide whether a URL should be mirrored into the build.
 *
 * Candidates: the CMS host, an explicitly allowlisted media host, any
 * WordPress asset path, or everything when `localizeAll` is on. `data:`,
 * `blob:`, `mailto:` and relative URLs are never candidates.
 *
 * @param {string} rawUrl Absolute URL found in the build.
 * @param {{ cmsHost?: string, mediaHosts?: Iterable<string>, localizeAll?: boolean }} options Decision inputs.
 * @returns {boolean} True when the URL is a localization candidate.
 */
export function shouldLocalizeUrl( rawUrl, options = {} ) {
	let parsed;
	try {
		parsed = new URL( rawUrl );
	} catch {
		return false;
	}
	if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
		return false;
	}
	if ( options.localizeAll ) {
		return true;
	}
	const host = parsed.hostname.toLowerCase();
	const mediaHosts = options.mediaHosts ? new Set( options.mediaHosts ) : new Set();
	if ( mediaHosts.has( host ) ) {
		return true;
	}
	if ( options.cmsHost && host === String( options.cmsHost ).toLowerCase() ) {
		return true;
	}
	return isWpAssetPath( parsed.pathname );
}

/**
 * Resolve the URL to actually download.
 *
 * A WordPress asset path whose host is neither the CMS nor an allowlisted
 * media host is a stale absolute URL (the classic "site moved domain"
 * breakage — WordPress stores media URLs in the database). Point it at the
 * CMS host, preserving path + query, so the image is still mirrored.
 *
 * @param {string} rawUrl Absolute URL found in the build.
 * @param {{ cmsOrigin?: string, mediaHosts?: Iterable<string> }} options Repair inputs.
 * @returns {string} The URL to fetch.
 */
export function resolveDownloadUrl( rawUrl, options = {} ) {
	let parsed;
	try {
		parsed = new URL( rawUrl );
	} catch {
		return rawUrl;
	}
	if ( ! options.cmsOrigin || ! isWpAssetPath( parsed.pathname ) ) {
		return parsed.href;
	}
	let cms;
	try {
		cms = new URL( options.cmsOrigin );
	} catch {
		return parsed.href;
	}
	const host = parsed.hostname.toLowerCase();
	const mediaHosts = options.mediaHosts ? new Set( options.mediaHosts ) : new Set();
	if ( host === cms.hostname.toLowerCase() || mediaHosts.has( host ) ) {
		return parsed.href;
	}
	return `${ cms.origin }${ parsed.pathname }${ parsed.search }`;
}

/**
 * Map a Content-Type header to a file extension.
 *
 * @param {string|undefined|null} contentType Header value.
 * @param {string} [fallback] Extension when the type is unknown.
 * @returns {string} Bare extension (no dot).
 */
export function extensionForContentType( contentType, fallback = 'bin' ) {
	const type = String( contentType ?? '' )
		.split( ';' )[ 0 ]
		.trim()
		.toLowerCase();
	return CONTENT_TYPE_EXTENSION[ type ] ?? fallback;
}

/**
 * Deterministic, filesystem-safe filename for a mirrored asset.
 *
 * Content addressing (a URL hash prefix) keeps repeat builds byte-stable and
 * makes identical URLs collide on the same file. The readable suffix keeps
 * the output debuggable.
 *
 * @param {string} rawUrl The ORIGINAL URL (the rewrite key).
 * @param {string} extension Bare extension.
 * @returns {string} Path relative to the output root (`media/<hash>-<slug>.<ext>`).
 */
export function mediaFileName( rawUrl, extension ) {
	const hash = createHash( 'sha1' ).update( rawUrl ).digest( 'hex' ).slice( 0, 12 );
	let slug = 'asset';
	try {
		const segments = new URL( rawUrl ).pathname.split( '/' ).filter( Boolean );
		const last = segments.length > 0 ? segments[ segments.length - 1 ] : '';
		let decoded = last;
		try {
			decoded = decodeURIComponent( last );
		} catch {
			decoded = last;
		}
		slug =
			decoded
				.replace( /\.[a-z0-9]{1,6}$/i, '' )
				.replace( /[^a-z0-9]+/gi, '-' )
				.replace( /^-+|-+$/g, '' )
				.toLowerCase()
				.slice( 0, 48 ) || 'asset';
	} catch {
		slug = 'asset';
	}
	const safeExtension = String( extension || 'bin' )
		.replace( /[^a-z0-9]/gi, '' )
		.toLowerCase() || 'bin';
	return `media/${ hash }-${ slug }.${ safeExtension }`;
}

/**
 * Extract one attribute value from a tag string (first match).
 *
 * @param {string} tag Serialized tag.
 * @param {string} name Attribute name.
 * @returns {string|undefined} Raw (still encoded) value.
 */
function attributeValue( tag, name ) {
	const pattern = new RegExp( `(?:^|\\s)${ name }\\s*=\\s*(["'])([\\s\\S]*?)\\1`, 'i' );
	const match = pattern.exec( tag );
	return match ? match[ 2 ] : undefined;
}

/**
 * Split a `srcset` value into URLs (descriptors ignored).
 *
 * @param {string} value `srcset` attribute value.
 * @returns {string[]} Candidate URLs.
 */
export function srcsetUrls( value ) {
	return String( value ?? '' )
		.split( /,\s+/ )
		.map( ( candidate ) => candidate.trim() )
		.map( ( candidate ) => candidate.split( /\s+/ )[ 0 ] )
		.filter( Boolean );
}

/**
 * Collect every asset URL referenced by built HTML.
 *
 * Contexts: `img[src|srcset|data-src|data-srcset]`, `source[src|srcset]`,
 * `video[poster]`, `link[rel~=icon][href]`, plus every CSS `url(...)`
 * (inline styles and `<style>` blocks). Anchors, meta tags and JSON-LD are
 * deliberately NOT collected: a local path is invalid for `og:image`,
 * structured data and document downloads.
 *
 * @param {string} html Built HTML document.
 * @returns {Set<string>} Absolute URLs.
 */
export function collectAssetUrls( html ) {
	const urls = new Set();
	const collectTag = ( tag, names ) => {
		for ( const name of names ) {
			const value = attributeValue( tag, name );
			if ( ! value ) {
				continue;
			}
			if ( name.endsWith( 'srcset' ) ) {
				for ( const url of srcsetUrls( value ) ) {
					urls.add( url );
				}
			} else if ( /^https?:\/\//i.test( value.trim() ) ) {
				urls.add( value.trim() );
			}
		}
	};
	for ( const match of html.matchAll( /<img\b[^>]*>/gi ) ) {
		collectTag( match[ 0 ], [ ...SINGLE_URL_ATTRS.filter( ( name ) => name !== 'href' ), ...SRCSET_ATTRS ] );
	}
	for ( const match of html.matchAll( /<source\b[^>]*>/gi ) ) {
		collectTag( match[ 0 ], [ 'src', ...SRCSET_ATTRS ] );
	}
	for ( const match of html.matchAll( /<video\b[^>]*>/gi ) ) {
		collectTag( match[ 0 ], [ 'poster' ] );
	}
	for ( const match of html.matchAll( /<link\b[^>]*>/gi ) ) {
		const rel = attributeValue( match[ 0 ], 'rel' );
		if ( rel && /\bicon\b/i.test( rel ) ) {
			collectTag( match[ 0 ], [ 'href' ] );
		}
	}
	for ( const match of html.matchAll( /url\(\s*(?:'|")?([^'")]+?)(?:'|")?\s*\)/gi ) ) {
		const url = ( match[ 1 ] ?? '' ).trim();
		if ( /^https?:\/\//i.test( url ) ) {
			urls.add( url );
		}
	}
	return urls;
}

/**
 * Collect the external stylesheets a built document links.
 *
 * Only absolute http(s) `.css` hrefs — the forge's own built stylesheets are
 * relative and already local. These are localized (downloaded + their
 * `url()` assets rewritten) so a theme icon sheet and its fonts stop depending
 * on the CMS host (Proposal 139 §stylesheets).
 *
 * @param {string} html Built HTML document.
 * @returns {Set<string>} Absolute stylesheet URLs.
 */
export function collectStylesheetUrls( html ) {
	const urls = new Set();
	for ( const match of html.matchAll( /<link\b[^>]*>/gi ) ) {
		const rel = attributeValue( match[ 0 ], 'rel' );
		if ( ! rel || ! /\bstylesheet\b/i.test( rel ) ) {
			continue;
		}
		const href = ( attributeValue( match[ 0 ], 'href' ) ?? '' ).trim();
		if ( /^https?:\/\//i.test( href ) && /\.css(?:[?#]|$)/i.test( href ) ) {
			urls.add( href );
		}
	}
	return urls;
}

/**
 * Resolve a stylesheet-relative reference to an absolute http(s) URL.
 * The fragment is dropped: HTTP never sends it, and legacy `?#iefix` /
 * `#font-id` suffixes would otherwise duplicate the same file.
 *
 * @param {string} raw Reference as written in the CSS.
 * @param {string} baseUrl The stylesheet's own URL.
 * @returns {string|undefined} Absolute URL, or undefined for data:/invalid.
 */
export function resolveStylesheetAsset( raw, baseUrl ) {
	try {
		const resolved = new URL( String( raw ?? '' ).trim(), new URL( baseUrl ) );
		if ( resolved.protocol !== 'http:' && resolved.protocol !== 'https:' ) {
			return undefined;
		}
		resolved.hash = '';
		// A bare `?` (as in `font.eot?#iefix`) leaves an empty query that the
		// serializer still prints — strip it so the download key is stable.
		return resolved.href.replace( /\?$/, '' );
	} catch {
		return undefined;
	}
}

/**
 * Every absolute asset URL a stylesheet references.
 *
 * @param {string} css Stylesheet source.
 * @param {string} baseUrl The stylesheet's own URL.
 * @returns {Set<string>} Absolute URLs.
 */
export function stylesheetAssetUrls( css, baseUrl ) {
	const urls = new Set();
	for ( const match of css.matchAll( /url\(\s*(?:'|")?([^'")]+?)(?:'|")?\s*\)/gi ) ) {
		const resolved = resolveStylesheetAsset( match[ 1 ] ?? '', baseUrl );
		if ( resolved ) {
			urls.add( resolved );
		}
	}
	return urls;
}

/**
 * Rewrite a stylesheet's `url()` references for its NEW location.
 *
 * A localized asset becomes its local path; anything else is emitted
 * absolute (the sheet no longer lives beside its original directory, so a
 * relative reference would break). `data:` URIs are left untouched.
 *
 * @param {string} css Stylesheet source.
 * @param {string} baseUrl The stylesheet's ORIGINAL URL.
 * @param {Map<string,string>} map Absolute URL → local path.
 * @returns {string} The rewritten stylesheet.
 */
export function rewriteStylesheet( css, baseUrl, map ) {
	return css.replace( /url\(\s*(?:'|")?([^'")]+?)(?:'|")?\s*\)/gi, ( match, inner ) => {
		const raw = String( inner ?? '' ).trim();
		const resolved = resolveStylesheetAsset( raw, baseUrl );
		const local = ( resolved && map.get( resolved ) ) || map.get( raw );
		if ( local ) {
			return `url(${ local })`;
		}
		return resolved ? `url(${ resolved })` : match;
	} );
}

/**
 * Collect every asset URL referenced by a stylesheet.
 *
 * @param {string} css CSS source.
 * @returns {Set<string>} Absolute URLs.
 */
export function collectCssAssetUrls( css ) {
	const urls = new Set();
	for ( const match of css.matchAll( /url\(\s*(?:'|")?([^'")]+?)(?:'|")?\s*\)/gi ) ) {
		const url = ( match[ 1 ] ?? '' ).trim();
		if ( /^https?:\/\//i.test( url ) ) {
			urls.add( url );
		}
	}
	return urls;
}

/**
 * Rewrite the URL candidates inside a `srcset` value.
 *
 * @param {string} value `srcset` value.
 * @param {Map<string,string>} map Original URL → local path.
 * @returns {string} The rewritten value (unchanged when nothing matched).
 */
export function rewriteSrcset( value, map ) {
	let changed = false;
	const parts = String( value ?? '' )
		.split( /,\s+/ )
		.map( ( candidate ) => {
			const trimmed = candidate.trim();
			const match = /^(\S+)([\s\S]*)$/.exec( trimmed );
			if ( ! match ) {
				return candidate;
			}
			const local = map.get( match[ 1 ] );
			if ( ! local ) {
				return candidate;
			}
			changed = true;
			return `${ local }${ match[ 2 ] }`;
		} );
	return changed ? parts.join( ', ' ) : value;
}

/**
 * Rewrite asset URLs inside one serialized tag.
 *
 * @param {string} tag Serialized tag (e.g. `<img ...>`).
 * @param {string[]} names Attribute names to consider.
 * @param {Map<string,string>} map Original URL → local path.
 * @returns {string} The rewritten tag.
 */
function rewriteTagAttributes( tag, names, map ) {
	let out = tag;
	for ( const name of names ) {
		const pattern = new RegExp( `((?<![\\w-])${ name }\\s*=\\s*)(["'])([\\s\\S]*?)\\2`, 'gi' );
		out = out.replace( pattern, ( match, prefix, quote, value ) => {
			const next = name.toLowerCase().endsWith( 'srcset' )
				? rewriteSrcset( value, map )
				: ( map.get( value.trim() ) ?? value );
			return next === value ? match : `${ prefix }${ quote }${ next }${ quote }`;
		} );
	}
	return out;
}

/**
 * Rewrite every localized asset URL in a built HTML document.
 *
 * Only the attribute contexts the collector reads are touched — `<a href>`,
 * `<meta content>` (og:image) and JSON-LD `<script>` payloads are left
 * absolute so SEO surfaces stay valid.
 *
 * @param {string} html Built HTML document.
 * @param {Map<string,string>} map Original URL → local path (`/media/...`).
 * @returns {string} The rewritten document.
 */
export function rewriteHtml( html, map ) {
	if ( map.size === 0 ) {
		return html;
	}
	const single = SINGLE_URL_ATTRS.filter( ( name ) => name !== 'href' );
	let out = html;
	out = out.replace( /<img\b[^>]*>/gi, ( tag ) => rewriteTagAttributes( tag, [ ...single, ...SRCSET_ATTRS ], map ) );
	out = out.replace( /<source\b[^>]*>/gi, ( tag ) => rewriteTagAttributes( tag, [ 'src', ...SRCSET_ATTRS ], map ) );
	out = out.replace( /<video\b[^>]*>/gi, ( tag ) => rewriteTagAttributes( tag, [ 'poster' ], map ) );
	out = out.replace( /<link\b[^>]*>/gi, ( tag ) => {
		const rel = attributeValue( tag, 'rel' );
		if ( ! rel ) {
			return tag;
		}
		if ( /\bicon\b/i.test( rel ) || /\bstylesheet\b/i.test( rel ) ) {
			return rewriteTagAttributes( tag, [ 'href' ], map );
		}
		return tag;
	} );
	out = out.replace( /url\(\s*(?:'|")?([^'")]+?)(?:'|")?\s*\)/gi, ( match, inner ) => {
		const local = map.get( String( inner ?? '' ).trim() );
		// Unquoted on purpose: the reference often sits inside a quoted HTML
		// style attribute, where a nested quote would truncate the attribute.
		// Generated media paths never contain a character unquoted url() forbids.
		return local ? `url(${ local })` : match;
	} );
	return out;
}

/**
 * Rewrite every localized asset URL in a built stylesheet.
 *
 * @param {string} css Stylesheet source.
 * @param {Map<string,string>} map Original URL → local path.
 * @returns {string} The rewritten source.
 */
export function rewriteCss( css, map ) {
	if ( map.size === 0 ) {
		return css;
	}
	return css.replace( /url\(\s*(?:'|")?([^'")]+?)(?:'|")?\s*\)/gi, ( match, inner ) => {
		const local = map.get( String( inner ?? '' ).trim() );
		return local ? `url(${ local })` : match;
	} );
}
