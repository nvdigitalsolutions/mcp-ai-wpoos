// ---------------------------------------------------------------------------
// media-localize-lib.mjs — pure helpers for the post-build asset localizer.
//
// Proposal 139 — "always available locally". Astro's <Image> already
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
	// Documents (Proposal 139 §documents) — a typed PDF never lands as .bin.
	'application/pdf': 'pdf',
	'application/msword': 'doc',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
	'application/vnd.ms-excel': 'xls',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
	'application/vnd.ms-powerpoint': 'ppt',
	'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'pptx',
	'application/vnd.oasis.opendocument.text': 'odt',
	'application/vnd.oasis.opendocument.spreadsheet': 'ods',
	'application/vnd.oasis.opendocument.presentation': 'odp',
	'text/csv': 'csv',
	'text/plain': 'txt',
	'application/rtf': 'rtf',
	'application/epub+zip': 'epub',
	'text/calendar': 'ics',
	'application/zip': 'zip',
};

/**
 * Document extensions the localizer mirrors.
 *
 * Deliberately an ALLOWLIST: a link is content, so a mis-typed href must
 * never cause an executable, installer or unknown archive to be pulled into
 * the build.
 */
export const DOCUMENT_EXTENSIONS = new Set( [
	'pdf',
	'doc',
	'docx',
	'xls',
	'xlsx',
	'ppt',
	'pptx',
	'odt',
	'ods',
	'odp',
	'csv',
	'txt',
	'rtf',
	'epub',
	'ics',
	'zip',
] );

/**
 * Raster formats the localizer re-encodes to WebP (Proposal 140).
 *
 * SVG and ICO are excluded (vector / raster-icon, no gain), GIF is excluded
 * because re-encoding drops animation, and WebP/AVIF are already next-gen.
 * The quality mapping is Malte Ubl's measured JPEG→WebP/AVIF equivalence
 * (industrialempathy.com/posts/avif-webp-quality-settings): JPEG 80 ≈
 * WebP 82 ≈ AVIF 64, JPEG 70 ≈ WebP 72 ≈ AVIF 56.
 */
export const REENCODE_EXTENSIONS = new Set( [ 'jpg', 'jpeg', 'png', 'bmp', 'tif', 'tiff' ] );

/**
 * The webfont hosts the localizer mirrors (Proposal 140 §3). A `@font-face`
 * served by a third party is a render-blocking cross-origin chain that can
 * fail outright — self-hosting removes both risks, as long as the family's
 * licence allows it (Google Fonts ships SIL OFL families).
 */
export const WEBFONT_HOSTS = [ 'fonts.googleapis.com', 'fonts.gstatic.com' ];

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
 * @param {{ cmsHost?: string, mediaHosts?: Iterable<string>, localizeAll?: boolean, webfontHosts?: Iterable<string> }} options Decision inputs.
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
	// Webfonts are opted into separately (Proposal 140): the hosts never serve
	// site media, so accepting them cannot widen the image allowlist.
	if ( options.webfontHosts && new Set( options.webfontHosts ).has( host ) ) {
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
/**
 * Decode the `&amp;` an HTML attribute carries back to `&`.
 *
 * A Google Fonts href in markup reads `…:wght@300;400&amp;display=swap`;
 * the raw attribute is neither a valid request nor the key the rewritten
 * link should carry.
 *
 * @param {string} value Raw attribute text.
 * @returns {string} The decoded value.
 */
function decodeAmp( value ) {
	return String( value ?? '' ).replace( /&amp;/gi, '&' );
}
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
		const href = decodeAmp( ( attributeValue( match[ 0 ], 'href' ) ?? '' ).trim() );
		if ( ! /^https?:\/\//i.test( href ) ) {
			continue;
		}
		// Google’s CSS API carries no `.css` extension (`/css2?family=…`),
		// so a suffix-only rule silently dropped the webfont sheet — and
		// with it the chance to self-host it, while the render-blocking
		// link stayed in the head (Proposal 140 §3).
		if ( /\.css(?:[?#]|$)/i.test( href ) || isWebfontUrl( href ) ) {
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
 * Split a link value into its path and fragment.
 *
 * `brochure.pdf#page=4` must keep its fragment (deep links are content), but
 * the fragment is not part of the download key.
 *
 * @param {string} value Link value.
 * @returns {{ path: string, hash: string }} The parts.
 */
export function splitHash( value ) {
	const raw = String( value ?? '' );
	const index = raw.indexOf( '#' );
	return index < 0 ? { path: raw, hash: '' } : { path: raw.slice( 0, index ), hash: raw.slice( index ) };
}

/**
 * The document extension of a URL, when it is one the localizer mirrors.
 *
 * @param {string} rawUrl Absolute URL.
 * @returns {string|undefined} Lower-cased extension, or undefined.
 */
export function documentExtension( rawUrl ) {
	try {
		const match = /\.([a-z0-9]{1,6})$/i.exec( new URL( rawUrl ).pathname );
		if ( ! match ) {
			return undefined;
		}
		const extension = match[ 1 ].toLowerCase();
		return DOCUMENT_EXTENSIONS.has( extension ) ? extension : undefined;
	} catch {
		return undefined;
	}
}

/**
 * Collect the document links a built document carries.
 *
 * Only `a[href]` with an absolute http(s) URL and an allowlisted extension —
 * page links, anchors, `mailto:` and fragments are never candidates. The
 * fragment is stripped from the returned key (see `splitHash`).
 *
 * @param {string} html Built HTML document.
 * @returns {Set<string>} Absolute document URLs (fragment-free).
 */
export function collectDocumentUrls( html ) {
	const urls = new Set();
	for ( const match of html.matchAll( /<a\b[^>]*>/gi ) ) {
		const href = ( attributeValue( match[ 0 ], 'href' ) ?? '' ).trim();
		if ( ! /^https?:\/\//i.test( href ) ) {
			continue;
		}
		const { path } = splitHash( href );
		if ( documentExtension( path ) ) {
			urls.add( path );
		}
	}
	return urls;
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
				: ( map.get( value.trim() ) ?? map.get( decodeAmp( value.trim() ) ) ?? value );
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
	// Documents (Proposal 139 §documents) — only when the href is a DOCUMENT
	// this build mirrored (an image anchor keeps pointing at the original), and
	// the fragment is re-attached (`brochure.pdf#page=4`).
	out = out.replace( /<a\b[^>]*>/gi, ( tag ) => {
		const href = ( attributeValue( tag, 'href' ) ?? '' ).trim();
		if ( ! href ) {
			return tag;
		}
		const { path, hash } = splitHash( href );
		if ( ! documentExtension( path ) ) {
			return tag;
		}
		const local = map.get( path );
		if ( ! local ) {
			return tag;
		}
		return rewriteTagAttributes( tag, [ 'href' ], new Map( [ [ href, `${ local }${ hash }` ] ] ) );
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

/**
 * Is this extension a raster image worth re-encoding? (Proposal 140 §1.)
 *
 * @param {string} extension Lower-case, no dot.
 * @returns {boolean} True for JPEG/PNG/BMP/TIFF.
 */
export function isReencodableImage( extension ) {
	return REENCODE_EXTENSIONS.has( String( extension ?? '' ).toLowerCase().replace( /^\./, '' ) );
}

/**
 * Is this URL a third-party webfont resource (the Google Fonts CSS API or the
 * static font host)? (Proposal 140 §3.)
 *
 * @param {string} rawUrl Absolute URL.
 * @returns {boolean} True when the URL serves webfont CSS or font binaries.
 */
export function isWebfontUrl( rawUrl ) {
	try {
		const parsed = new URL( rawUrl );
		return WEBFONT_HOSTS.includes( parsed.hostname.toLowerCase() );
	} catch {
		return false;
	}
}

/**
 * Ensure every `@font-face` block declares `font-display` (Proposal 140 §4).
 *
 * Without it the browser blocks text painting until the font arrives
 * (Lighthouse: "Ensure text remains visible during webfont load"). `swap`
 * paints the fallback immediately and swaps in the webfont on arrival — the
 * documented default for body text. A sheets' author choice is respected: a
 * block that already declares `font-display` is left untouched.
 *
 * `@font-face` cannot nest, so a `[^}]*` block match is exact.
 *
 * @param {string} css Stylesheet source.
 * @param {string} value The `font-display` value to inject.
 * @returns {string} The stylesheet, with gaps filled.
 */
export function addFontDisplaySwap( css, value = 'swap' ) {
	return String( css ?? '' ).replace( /@font-face\s*\{[^}]*\}/gi, ( block ) => {
		if ( /font-display\s*:/i.test( block ) ) {
			return block;
		}
		return block.replace( /\{/, `{font-display:${ value };` );
	} );
}

/**
 * Read an SVG's intrinsic size without decoding it.
 *
 * `viewBox` wins (it is the drawing's coordinate space, which is what an
 * `<img>` needs for its aspect ratio); explicit `width`/`height` attributes
 * are the fallback. A responsive SVG with neither has no intrinsic size.
 *
 * @param {string} text SVG source.
 * @returns {{ width: number, height: number }|null} Rounded dimensions.
 */
export function svgIntrinsicSize( text ) {
	const head = String( text ?? '' ).slice( 0, 8192 );
	const tag = head.match( /<svg\b[^>]*>/i );
	if ( ! tag ) {
		return null;
	}
	const viewBox = attributeValue( tag[ 0 ], 'viewBox' );
	if ( viewBox ) {
		const parts = viewBox.trim().split( /[\s,]+/ ).map( Number );
		if ( parts.length === 4 && parts[ 2 ] > 0 && parts[ 3 ] > 0 ) {
			return { width: Math.round( parts[ 2 ] ), height: Math.round( parts[ 3 ] ) };
		}
	}
	const toNumber = ( raw ) => Number( String( raw ?? '' ).replace( /[^\d.]/g, '' ) );
	const width = toNumber( attributeValue( tag[ 0 ], 'width' ) );
	const height = toNumber( attributeValue( tag[ 0 ], 'height' ) );
	if ( width > 0 && height > 0 ) {
		return { width: Math.round( width ), height: Math.round( height ) };
	}
	return null;
}

/**
 * Give every dimension-less `<img>` its intrinsic width/height (Proposal 140 §2).
 *
 * Lighthouse's "use explicit width and height" audit is about the browser
 * reserving space *before* CSS applies — so a localized image whose tag has no
 * attributes still shifts the layout. The attributes lose to any CSS rule, so
 * adding them never fights the design.
 *
 * @param {string} html Built HTML document.
 * @param {Map<string,{width:number,height:number}>} sizes Public path → dimensions.
 * @returns {string} The document, with gaps filled.
 */
export function addImageDimensions( html, sizes ) {
	if ( ! sizes || sizes.size === 0 ) {
		return html;
	}
	return String( html ?? '' ).replace( /<img\b[^>]*>/gi, ( tag ) => {
		if ( /\swidth\s*=/i.test( tag ) || /\sheight\s*=/i.test( tag ) ) {
			return tag;
		}
		const src = attributeValue( tag, 'src' );
		if ( ! src ) {
			return tag;
		}
		const size = sizes.get( src.trim() );
		if ( ! size || ! ( size.width > 0 ) || ! ( size.height > 0 ) ) {
			return tag;
		}
		const attributes = ` width="${ Math.round( size.width ) }" height="${ Math.round( size.height ) }"`;
		return tag.replace( /\s*(\/?)>$/, ( end, selfClosing ) => `${ attributes }${ selfClosing ? ' /' : '' }>` );
	} );
}

/**
 * Drop `preconnect` hints for hosts the build now serves itself (Proposal 140).
 *
 * Once the Google Fonts stylesheet and its woff2 files are mirrored, the two
 * `preconnect` links in the head open connections to hosts the page never
 * contacts — pure waste, and two extra handshakes in the waterfall. Only hosts
 * we actually localized are stripped, so a hint that still earns its keep
 * survives.
 *
 * Written without word-boundary or whitespace escapes on purpose: `rel` is a
 * token list, so a substring test is the honest check, and it keeps the
 * pattern readable.
 *
 * @param {string} html Built HTML document.
 * @param {Iterable<string>} [hosts] Hostnames whose resources are now local.
 * @returns {string} The document, with dead hints removed.
 */
export function stripPreconnects( html, hosts ) {
	const targets = hosts ? new Set( [ ...hosts ].map( ( host ) => String( host ).toLowerCase() ) ) : new Set();
	if ( targets.size === 0 ) {
		return html;
	}
	return String( html ?? '' ).replace( /<link[^>]*>/gi, ( tag ) => {
		const rel = attributeValue( tag, 'rel' );
		if ( ! rel || rel.toLowerCase().indexOf( 'preconnect' ) === -1 ) {
			return tag;
		}
		const href = attributeValue( tag, 'href' );
		if ( ! href ) {
			return tag;
		}
		try {
			return targets.has( new URL( href ).hostname.toLowerCase() ) ? '' : tag;
		} catch {
			return tag;
		}
	} );
}
