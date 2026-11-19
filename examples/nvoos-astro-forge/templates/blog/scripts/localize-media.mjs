#!/usr/bin/env node
// ---------------------------------------------------------------------------
// localize-media.mjs — mirror remote media into the built site (Proposal 139).
//
// Runs AFTER `astro build`, BEFORE pagefind. Astro's <Image> already pulls
// authorized remote images into dist/_astro/, but body-content images, site
// chrome, collage photos and any non-allowlisted host stay hotlinked to the
// CMS. This pass downloads every remaining remote asset the built HTML/CSS
// references, writes it under `<out>/media/` with a deterministic name, and
// rewrites the references — so the deployed artifact keeps working when the
// WordPress host changes, moves, or goes away.
//
// Contract:
//   * static tier (`runtime: static`) → MIRROR: every remote image the build
//     references is downloaded into dist/media, so the artifact has no
//     runtime dependency on the CMS,
//   * managed tier (`runtime: managed`, the bridge/BFF is present) → PROXY:
//     uploads are rewritten to the same-origin `/media/*` proxy the runtime
//     ships, instead of duplicating the media library into every deploy,
//     (override with NVOOS_FORGE_MEDIA_MODE)
//   * never fails the build — a failed download keeps the original URL and is
//     reported (never silent), EXCEPT a webfont: a half-mirrored font sheet
//     ships a third-party chain that a network blip decides, so the face is
//     dropped (the family falls back to its CSS stack) and reported,
//   * idempotent — after a pass every reference is relative, so a second run
//     finds nothing to do,
//   * respects WP_FETCH_PROXY, exactly like the content layer (bot-protected
//     sources block datacenter egress),
//   * only http(s) references in image/CSS/document contexts; og:image and
//     JSON-LD stay absolute, and a link is mirrored only when its extension
//     is on the document allowlist (never an executable or unknown archive).
//
// Env:
//   NVOOS_FORGE_MEDIA_MODE           local | proxy | off — default: `proxy`
//                                    when nvoos.json runtime=managed (the
//                                    /media/* same-origin proxy exists),
//                                    otherwise `local` (mirror into dist/media)
//   NVOOS_FORGE_LOCALIZE_MEDIA       0/off to disable (default: on)
//   NVOOS_FORGE_MEDIA_HOSTS          extra allowlisted media hosts (csv)
//   NVOOS_FORGE_LOCALIZE_ALL         1 = mirror every http(s) asset host
//   NVOOS_FORGE_MEDIA_MAX_MB         per-file cap (default 25)
//   NVOOS_FORGE_MEDIA_MAX_DOC_MB     per-document cap (default 50)
//   NVOOS_FORGE_MEDIA_MAX_TOTAL_MB   build-wide cap (default 500)
//   NVOOS_FORGE_MEDIA_TIMEOUT_MS     per-request timeout (default 20000)
//   WP_SITE_URL / nvoos.json url     the CMS origin (host repair + allowlist)
// ---------------------------------------------------------------------------

import { createHash } from 'node:crypto';
import { existsSync } from 'node:fs';
import { mkdir, readFile, readdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

import {
	addFontDisplaySwap,
	addImageDimensions,
	collectAssetUrls,
	collectSocialImageUrls,
	collectCssAssetUrls,
	collectDocumentUrls,
	collectStylesheetUrls,
	documentExtension,
	droppedFontFamilies,
	dropUnavailableFontFaces,
	extensionForContentType,
	IMAGE_EXTENSIONS,
	isReencodableImage,
	isWebfontUrl,
	mediaFileName,
	parseHostList,
	proxyMediaPath,
	resolveDownloadUrl,
	rewriteCss,
	rewriteHtml,
	rewriteStylesheet,
	shouldLocalizeUrl,
	stripPreconnects,
	stylesheetAssetUrls,
	svgIntrinsicSize,
	WEBFONT_HOSTS,
} from './media-localize-lib.mjs';

const ROOT = process.cwd();
const CACHE_DIR = path.join( ROOT, '.forge-cache' );
const REPORT_PATH = path.join( CACHE_DIR, 'media-localize.json' );
const CONCURRENCY = 6;
/** Attempts per asset before its URL is treated as unavailable. */
const DOWNLOAD_ATTEMPTS = 3;
/** Linear backoff between attempts (250 ms, 500 ms). */
const RETRY_DELAY_MS = 250;
const USER_AGENT =
	'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

function env( name ) {
	return ( process.env[ name ] ?? '' ).trim();
}

function truthy( value, fallback ) {
	if ( value === '' ) {
		return fallback;
	}
	return ! /^(0|false|off|no)$/i.test( value );
}

function log( message ) {
	console.log( `[forge-media] ${ message }` );
}

/** A non-2xx answer — retried only when it looks transient. */
class HttpStatusError extends Error {
	constructor( status ) {
		super( `HTTP ${ status }` );
		this.status = status;
	}
}

function sleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/** `dist/client` for server output, `dist` for static. */
function outputDir() {
	const client = path.join( ROOT, 'dist', 'client' );
	return existsSync( client ) ? client : path.join( ROOT, 'dist' );
}

/** The scaffolded manifest (`nvoos.json`) — absent in a bare template. */
async function readManifest() {
	try {
		return JSON.parse( await readFile( path.join( ROOT, 'nvoos.json' ), 'utf8' ) );
	} catch {
		return {};
	}
}

/** The CMS origin — env first (mirrors content.config.ts / SmartImage). */
function resolveCmsOrigin( manifest ) {
	const explicit = env( 'WP_SITE_URL' ).replace( /\/+$/, '' );
	if ( explicit ) {
		return explicit;
	}
	return typeof manifest.url === 'string' ? manifest.url.replace( /\/+$/, '' ) : '';
}

/**
 * The committed media snapshot (`media-snapshot/manifest.json`).
 *
 * Shape: `{ version: 1, entries: { [sourceUrl]: { file, contentType } } }`,
 * written by `forge media-snapshot` from a localized build. The directory is
 * copied into the project by the scaffold; it is not served.
 *
 * `authoritative` follows the profile's `media.snapshot: true`: the snapshot
 * is read BEFORE the network, so a CMS-less rebuild is deterministic. Without
 * the flag it is only a fallback when a download fails.
 *
 * FAIL-SOFT: an absent/unreadable snapshot is reported and ignored; a site
 * that never made one keeps building exactly as before.
 */
async function readMediaSnapshot( mediaPolicy ) {
	const requested = env( 'NVOOS_FORGE_MEDIA_SNAPSHOT' );
	const dir = requested ? path.resolve( ROOT, requested ) : path.join( ROOT, 'media-snapshot' );
	const manifestPath = path.join( dir, 'manifest.json' );
	if ( ! existsSync( manifestPath ) ) {
		return { dir: undefined, entries: new Map(), authoritative: mediaPolicy?.snapshot === true };
	}
	try {
		const manifest = JSON.parse( await readFile( manifestPath, 'utf8' ) );
		const entries = new Map( Object.entries( manifest.entries ?? {} ) );
		return { dir, entries, authoritative: mediaPolicy?.snapshot === true };
	} catch ( error ) {
		log( `media snapshot ignored (${ error instanceof Error ? error.message : error }).` );
		return { dir: undefined, entries: new Map(), authoritative: mediaPolicy?.snapshot === true };
	}
}

/** A Content-Type for a snapshot file when its manifest omits one. */
function contentTypeForExtension( extension ) {
	const types = {
		eot: 'application/vnd.ms-fontobject',
		woff: 'font/woff',
		woff2: 'font/woff2',
		ttf: 'font/ttf',
		otf: 'font/otf',
		svg: 'image/svg+xml',
		png: 'image/png',
		jpg: 'image/jpeg',
		jpeg: 'image/jpeg',
		webp: 'image/webp',
		gif: 'image/gif',
		avif: 'image/avif',
		pdf: 'application/pdf',
		css: 'text/css',
	};
	return types[ String( extension ?? '' ).toLowerCase() ] ?? 'application/octet-stream';
}

/**
 * Resolve a group of source URLs to ONE snapshot file, or `undefined`.
 *
 * Aliases of the same asset (a stale host and the CMS host) must all map to
 * the same file; if they disagree, the snapshot cannot answer for the group
 * and the network stays authoritative.
 */
function snapshotEntryFor( originals, snapshot ) {
	if ( ! snapshot.dir || originals.length === 0 ) {
		return undefined;
	}
	const entries = originals.map( ( url ) => snapshot.entries.get( url ) );
	if ( entries.some( ( entry ) => ! entry || ! entry.file ) ) {
		return undefined;
	}
	if ( new Set( entries.map( ( entry ) => entry.file ) ).size !== 1 ) {
		return undefined;
	}
	const entry = entries[ 0 ];
	return {
		...entry,
		path: path.join( snapshot.dir, entry.file ),
	};
}

async function listFiles( dir, extensions, skip = new Set() ) {
	const found = [];
	let entries;
	try {
		entries = await readdir( dir, { withFileTypes: true } );
	} catch {
		return found;
	}
	for ( const entry of entries ) {
		if ( skip.has( entry.name ) ) {
			continue;
		}
		const full = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			found.push( ...( await listFiles( full, extensions, skip ) ) );
		} else if ( extensions.has( path.extname( entry.name ).toLowerCase() ) ) {
			found.push( full );
		}
	}
	return found;
}

/** Run `worker` over `items` with bounded concurrency. */
async function mapLimit( items, limit, worker ) {
	const results = new Array( items.length );
	let cursor = 0;
	const runners = Array.from( { length: Math.min( limit, items.length ) }, async () => {
		while ( cursor < items.length ) {
			const index = cursor++;
			results[ index ] = await worker( items[ index ], index );
		}
	} );
	await Promise.all( runners );
	return results;
}

async function downloadOnce( url, timeoutMs ) {
	const controller = new AbortController();
	const timer = setTimeout( () => controller.abort(), timeoutMs );
	try {
		const response = await fetch( url, {
			signal: controller.signal,
			redirect: 'follow',
			headers: {
				'user-agent': USER_AGENT,
				accept: 'image/avif,image/webp,image/svg+xml,image/*,font/*,*/*;q=0.8',
			},
		} );
		if ( ! response.ok ) {
			throw new HttpStatusError( response.status );
		}
		const buffer = Buffer.from( await response.arrayBuffer() );
		return {
			buffer,
			contentType: response.headers.get( 'content-type' ) ?? '',
			contentLength: Number( response.headers.get( 'content-length' ) ?? buffer.length ),
		};
	} finally {
		clearTimeout( timer );
	}
}

/**
 * Fetch a URL with a bounded retry.
 *
 * A cold build mirrors a dozen woff2 files from Google's CDN, and one 429 or a
 * stalled socket used to leave a half-localised stylesheet behind — a remote
 * `src` in an otherwise local sheet, which only the e2e gate noticed
 * (2026-11-15). A 4xx other than 429 is an answer, not a blip, so it is not
 * retried.
 */
async function download( url, timeoutMs ) {
	let lastError;
	for ( let attempt = 1; attempt <= DOWNLOAD_ATTEMPTS; attempt += 1 ) {
		try {
			return await downloadOnce( url, timeoutMs );
		} catch ( error ) {
			lastError = error;
			const status = error instanceof HttpStatusError ? error.status : undefined;
			const permanent =
				typeof status === 'number' && status >= 400 && status < 500 && status !== 429;
			if ( permanent || attempt === DOWNLOAD_ATTEMPTS ) {
				break;
			}
			await sleep( RETRY_DELAY_MS * attempt );
		}
	}
	throw lastError;
}

/**
 * Pick the media strategy (Proposal 139).
 *
 * `local` mirrors the media into the artifact — correct for the static tier,
 * which has no runtime origin. `proxy` rewrites uploads to the managed
 * runtime's same-origin `/media/*` proxy instead of duplicating the library;
 * it is the default whenever the bridge/BFF is present. `off` does nothing.
 *
 * @param manifest Scaffolded `nvoos.json`.
 * @param mediaPolicy The manifest's `media` block (may be absent).
 * @returns {'local'|'proxy'|'off'} The resolved mode.
 */
function resolveMode( manifest, mediaPolicy ) {
	const requested = env( 'NVOOS_FORGE_MEDIA_MODE' ).toLowerCase();
	if ( [ 'local', 'proxy', 'off' ].includes( requested ) ) {
		return requested;
	}
	if ( mediaPolicy.localize === false || ! truthy( env( 'NVOOS_FORGE_LOCALIZE_MEDIA' ), true ) ) {
		return 'off';
	}
	// The profile's own choice sits between the env override and the runtime
	// default: a managed site whose owner wants a self-contained artifact says
	// so in the profile instead of a per-deploy env var.
	if ( [ 'local', 'proxy', 'off' ].includes( mediaPolicy.mode ) ) {
		return mediaPolicy.mode;
	}
	return manifest.runtime === 'managed' ? 'proxy' : 'local';
}

/**
 * `sharp`, when this project has it. Absent means the pass still mirrors bytes
 * verbatim — re-encoding is an enhancement, never a build dependency.
 *
 * @returns {Promise<Function|null>} The sharp factory.
 */
async function loadSharp() {
	try {
		const module = await import( 'sharp' );
		return module.default ?? module;
	} catch {
		return null;
	}
}

/**
 * Encode a raster image to WebP (Proposal 140 §1).
 *
 * @param {Function} sharp The sharp factory.
 * @param {Buffer} buffer Source bytes.
 * @param {number} quality WebP quality (the JPEG-80 equivalent is 82).
 * @returns {Promise<{buffer: Buffer, size: ?}>} The encode result, or `null`.
 */
async function encodeWebp( sharp, buffer, quality ) {
	try {
		const image = sharp( buffer, { failOn: 'none' } );
		const meta = await image.metadata();
		if ( ! meta || ! meta.width || ! meta.height ) {
			return null;
		}
		const encoded = await image.webp( { quality } ).toBuffer();
		return { buffer: encoded, size: { width: meta.width, height: meta.height } };
	} catch {
		return null;
	}
}

/**
 * Intrinsic dimensions for the `<img>` dimension fill (Proposal 140 §2).
 *
 * @param {Function|null} sharp The sharp factory.
 * @param {Buffer} buffer Asset bytes.
 * @param {string} extension Lower-case extension.
 * @returns {Promise<{width: number, height: number}|null>} Dimensions, if known.
 */
async function intrinsicSize( sharp, buffer, extension ) {
	if ( extension === 'svg' ) {
		return svgIntrinsicSize( buffer.toString( 'utf8' ) );
	}
	// Fonts, stylesheets and the documents 139 mirrors have no `<img>` to size,
	// and handing a PDF to sharp would render page 1 for nothing.
	if ( ! IMAGE_EXTENSIONS.has( extension ) ) {
		return null;
	}
	if ( ! sharp ) {
		return null;
	}
	try {
		const meta = await sharp( buffer, { failOn: 'none' } ).metadata();
		return meta && meta.width > 0 && meta.height > 0 ? { width: meta.width, height: meta.height } : null;
	} catch {
		return null;
	}
}

async function main() {
	const manifest = await readManifest();
	const mediaPolicy = manifest.media && typeof manifest.media === 'object' ? manifest.media : {};

	const mode = resolveMode( manifest, mediaPolicy );
	if ( mode === 'off' ) {
		log( 'disabled (NVOOS_FORGE_MEDIA_MODE=off, NVOOS_FORGE_LOCALIZE_MEDIA=0 or profile.media.localize=false) — remote media stays hotlinked.' );
		return;
	}
	const out = outputDir();
	if ( ! existsSync( out ) ) {
		log( 'no build output found — skipping.' );
		return;
	}

	const origin = resolveCmsOrigin( manifest );
	const snapshot = await readMediaSnapshot( mediaPolicy );
	if ( snapshot.dir ) {
		log(
			`media snapshot: ${ snapshot.entries.size } entr${ snapshot.entries.size === 1 ? 'y' : 'ies' } from ${ path.relative( ROOT, snapshot.dir ) } (${ snapshot.authoritative ? 'authoritative' : 'fallback' }).`
		);
	}
	if ( mediaPolicy?.snapshot === true && ! snapshot.dir ) {
		log( 'WARNING: media.snapshot is true but no media-snapshot/manifest.json was found — this build depends on the origin.' );
	}
	let cmsHost = '';
	try {
		cmsHost = origin ? new URL( origin ).hostname.toLowerCase() : '';
	} catch {
		cmsHost = '';
	}
	const manifestHosts = Array.isArray( mediaPolicy.hosts ) ? mediaPolicy.hosts.map( String ).join( ',' ) : '';
	const mediaHosts = [
		...new Set( [ ...parseHostList( manifestHosts ), ...parseHostList( env( 'NVOOS_FORGE_MEDIA_HOSTS' ) ) ] ),
	];
	const localizeAll = truthy( env( 'NVOOS_FORGE_LOCALIZE_ALL' ), false );
	const maxFileMb = Number(
		env( 'NVOOS_FORGE_MEDIA_MAX_MB' ) ||
			( typeof mediaPolicy.maxFileMb === 'number' ? mediaPolicy.maxFileMb : 25 )
	);
	const maxBytes = Math.max( 0, Number.isFinite( maxFileMb ) ? maxFileMb : 25 ) * 1024 * 1024;
	// Documents get their own, larger cap: a catalogue PDF is legitimately tens
	// of MB where an image that size is a mistake.
	const maxDocBytes = Math.max( 0, Number( env( 'NVOOS_FORGE_MEDIA_MAX_DOC_MB' ) || 50 ) ) * 1024 * 1024;
	const maxTotal = Math.max( 0, Number( env( 'NVOOS_FORGE_MEDIA_MAX_TOTAL_MB' ) || 500 ) ) * 1024 * 1024;
	const timeoutMs = Math.max( 1000, Number( env( 'NVOOS_FORGE_MEDIA_TIMEOUT_MS' ) || 20000 ) );
	// Proposal 140 — self-hosting webfonts and re-encoding images are opt-out,
	// because both remove a runtime dependency on a third party.
	const webfontHosts = truthy( env( 'NVOOS_FORGE_LOCALIZE_FONTS' ), true ) ? WEBFONT_HOSTS : [];
	const webpQuality = Math.min( 100, Math.max( 1, Number( env( 'NVOOS_FORGE_MEDIA_WEBP_QUALITY' ) || 82 ) ) );
	const transformer = await loadSharp();
	const sizes = new Map();
	const optimized = [];
	const unoptimized = [];

	// The build-time proxy seam — same env the content layer honours.
	const proxy = env( 'WP_FETCH_PROXY' );
	if ( proxy ) {
		try {
			const parsed = new URL( proxy );
			if ( parsed.protocol !== 'http:' && parsed.protocol !== 'https:' ) {
				throw new Error( 'must be http:// or https://' );
			}
			const { ProxyAgent, setGlobalDispatcher } = await import( 'undici' );
			setGlobalDispatcher( new ProxyAgent( { uri: proxy } ) );
			log( 'routing media downloads through WP_FETCH_PROXY.' );
		} catch ( error ) {
			log( `WP_FETCH_PROXY is invalid (${ error instanceof Error ? error.message : error }) — downloads use the direct path.` );
		}
	}

	// 1. Collect every asset reference from the built HTML/CSS.
	const htmlFiles = await listFiles( out, new Set( [ '.html' ] ), new Set( [ 'media' ] ) );
	const cssFiles = await listFiles( out, new Set( [ '.css' ] ), new Set( [ 'media' ] ) );
	const sources = new Map();
	const candidates = new Set();
	const stylesheetUrls = new Set();
	const localized = [];
	const skipped = [];
	const failed = [];
	const droppedFamilies = [];
	for ( const file of htmlFiles ) {
		const html = await readFile( file, 'utf8' );
		sources.set( file, html );
		for ( const url of collectAssetUrls( html ) ) {
			candidates.add( url );
		}
		for ( const url of collectStylesheetUrls( html ) ) {
			if ( shouldLocalizeUrl( url, { cmsHost, mediaHosts, localizeAll, webfontHosts } ) ) {
				stylesheetUrls.add( url );
			}
		}
		// Documents (brochures, catalogues, price lists). A link is content, so
		// only the extension allowlist in the lib becomes a candidate.
		for ( const url of collectDocumentUrls( html ) ) {
			if ( shouldLocalizeUrl( url, { cmsHost, mediaHosts, localizeAll } ) ) {
				candidates.add( url );
			}
		}
	}
	for ( const file of cssFiles ) {
		const css = await readFile( file, 'utf8' );
		sources.set( file, css );
		for ( const url of collectCssAssetUrls( css ) ) {
			candidates.add( url );
		}
	}

	// 2. Fetch the external stylesheets and register the assets they
	//    reference (theme icon fonts) as download candidates — the sheet moves
	//    next to its new neighbours, so its `url()` refs must travel too.
	const stylesheets = [];
	const htmlText = [ ...sources ]
		.filter( ( [ file ] ) => path.extname( file ).toLowerCase() === '.html' )
		.map( ( [ , text ] ) => text )
		.join( '\n' );
	// Social images keep their ORIGINAL format (see `collectSocialImageUrls`):
	// some card scrapers do not read WebP, and a missing card is worse than the
	// few KB a re-encode would save.
	const socialImageUrls = collectSocialImageUrls( htmlText );
	for ( const url of stylesheetUrls ) {
		try {
			const fromSnapshot = snapshot.authoritative ? snapshotEntryFor( [ url ], snapshot ) : undefined;
			let text;
			if ( fromSnapshot ) {
				text = await readFile( fromSnapshot.path, 'utf8' );
			} else {
				const result = await download( url, timeoutMs );
				if ( result.buffer.length > 4 * 1024 * 1024 ) {
					failed.push( { url, reason: 'stylesheet larger than 4 MB' } );
					continue;
				}
				text = result.buffer.toString( 'utf8' );
			}
			stylesheets.push( { url, text, source: fromSnapshot ? 'snapshot' : 'network' } );
			for ( const asset of stylesheetAssetUrls( text, url ) ) {
				candidates.add( asset );
			}
		} catch ( error ) {
			// A non-authoritative snapshot is the CMS outage net: a failed sheet
			// download falls back to its committed copy before the report is told.
			const fallback = snapshotEntryFor( [ url ], snapshot );
			if ( fallback ) {
				try {
					const text = await readFile( fallback.path, 'utf8' );
					stylesheets.push( { url, text, source: 'snapshot' } );
					for ( const asset of stylesheetAssetUrls( text, url ) ) {
						candidates.add( asset );
					}
					continue;
				} catch {
					// Fall through to the failure report.
				}
			}
			failed.push( { url, reason: error instanceof Error ? error.message : String( error ) } );
		}
	}

	let wanted = [ ...candidates ].filter( ( url ) =>
		shouldLocalizeUrl( url, { cmsHost, mediaHosts, localizeAll, webfontHosts } )
	);

	// Managed runtime (bridge/BFF present): the deployment already serves
	// `/media/<rest>` from `<CMS>/wp-content/uploads/<rest>` server-to-server, so
	// the media library stays there. What the proxy CANNOT serve still travels
	// with the build: the stylesheets and the fonts they reference. A
	// third-party font sheet is a render-blocking cross-origin chain, and the
	// proxy only matches uploads — leaving the font chain remote was a silent
	// hole in an otherwise self-contained artifact (Proposal 140 §3).
	const proxyMap = new Map();
	const proxied = [];
	const leftRemote = [];
	if ( mode === 'proxy' ) {
		const mirrorable = new Set( stylesheetUrls );
		for ( const sheet of stylesheets ) {
			for ( const asset of stylesheetAssetUrls( sheet.text, sheet.url ) ) {
				mirrorable.add( asset );
			}
		}
		for ( const url of wanted ) {
			if ( isWebfontUrl( url ) ) {
				mirrorable.add( url );
			}
		}
		const local = [];
		for ( const url of wanted ) {
			const proxiedPath = proxyMediaPath( url );
			if ( proxiedPath ) {
				proxyMap.set( url, proxiedPath );
				proxied.push( { url, file: proxiedPath } );
			} else if ( mirrorable.has( url ) ) {
				local.push( url );
			} else {
				leftRemote.push( { url, reason: 'not a WordPress upload — the /media proxy cannot serve it' } );
			}
		}
		wanted = local;
	}

	if ( wanted.length === 0 && proxyMap.size === 0 ) {
		log( `nothing to localize in ${ path.relative( ROOT, out ) } (${ htmlFiles.length } page(s) scanned).` );
		await writeReport( { mode, origin, localized: [], skipped: [], failed: [], droppedFontFamilies: [], bytes: 0 } );
		return;
	}

	// 2. Download (dedupe by the resolved URL — a stale host and the correct
	//    host resolve to the same file).
	const byDownloadUrl = new Map();
	for ( const url of wanted ) {
		const downloadUrl = resolveDownloadUrl( url, { cmsOrigin: origin, mediaHosts } );
		if ( ! byDownloadUrl.has( downloadUrl ) ) {
			byDownloadUrl.set( downloadUrl, [] );
		}
		byDownloadUrl.get( downloadUrl ).push( url );
	}

	await mkdir( path.join( CACHE_DIR, 'media' ), { recursive: true } );
	await mkdir( path.join( out, 'media' ), { recursive: true } );

	const map = new Map( proxyMap );
	let totalBytes = 0;

	await mapLimit( [ ...byDownloadUrl.entries() ], CONCURRENCY, async ( [ downloadUrl, originals ] ) => {
		try {
			const cacheKey = createHash( 'sha1' ).update( downloadUrl ).digest( 'hex' );
			const snapshotEntry = snapshotEntryFor( originals, snapshot );
			const cached = snapshot.authoritative && snapshotEntry ? null : await findCached( cacheKey );

			let buffer;
			let contentType;
			let cacheFile;
			// `source` is a fact for `forge media-snapshot` and for an operator
			// reading the report: a build that silently served stale bytes is a
			// different story from one that downloaded them.
			let source;

			if ( snapshot.authoritative && snapshotEntry ) {
				try {
					buffer = await readFile( snapshotEntry.path );
					contentType = snapshotEntry.contentType || contentTypeForExtension( path.extname( snapshotEntry.file ) );
					source = 'snapshot';
				} catch {
					// A manifest that names a missing file is a broken net, not a
					// reason to fail the build: fall through to cache/network.
					buffer = undefined;
				}
			}

			if ( ! buffer && cached ) {
				buffer = await readFile( cached.path );
				contentType = cached.contentType;
				cacheFile = cached.path;
				source = 'cache';
			} else if ( ! buffer ) {
				try {
					const result = await download( downloadUrl, timeoutMs );
					buffer = result.buffer;
					contentType = result.contentType;
					source = 'network';
					const capBytes = documentExtension( downloadUrl ) ? maxDocBytes : maxBytes;
					if ( result.contentLength > capBytes || buffer.length > capBytes ) {
						skipped.push( { url: downloadUrl, reason: `larger than ${ Math.round( capBytes / 1024 / 1024 ) } MB` } );
						return;
					}
					if ( totalBytes + buffer.length > maxTotal ) {
						skipped.push( { url: downloadUrl, reason: 'build-wide media cap reached' } );
						return;
					}
					cacheFile = cachedPathGuess( cacheKey, contentType, downloadUrl );
					await writeFile( `${ cacheFile }.meta.json`, JSON.stringify( { contentType } ), 'utf8' );
					await writeFile( cacheFile, buffer );
				} catch ( error ) {
					// The CMS is unreachable (or the derivative expired): a committed
					// snapshot is the outage net. Only a network failure degrades — an
					// HTTP 404 is handled by `download()` as a normal failure and lands
					// here too, so the snapshot covers the stale-CDN case.
					if ( snapshotEntry ) {
						buffer = await readFile( snapshotEntry.path );
						contentType = snapshotEntry.contentType || contentTypeForExtension( path.extname( snapshotEntry.file ) );
						source = 'snapshot';
					} else {
						throw error;
					}
				}
			}

			if ( totalBytes + buffer.length > maxTotal ) {
				skipped.push( { url: downloadUrl, reason: 'build-wide media cap reached' } );
				return;
			}

			const urlExtension = path.extname( new URL( downloadUrl ).pathname ).replace( /^\./, '' ).toLowerCase();
			// A document's URL extension outranks its Content-Type: hosts serve
			// PDFs as application/octet-stream (and some test fixtures mislabel
			// them entirely), and the link text promises a .pdf.
			let extension = documentExtension( downloadUrl ) ?? extensionForContentType( contentType, urlExtension || 'bin' );

			// 140 §1 — re-encode raster images to WebP before they ship. A WebP
			// that came out larger than the source is discarded: the source wins.
			// A SOCIAL image (og:image / twitter:image) is never re-encoded: its
			// only consumer is a card scraper, and format compatibility outranks
			// the bytes.
			let payload = buffer;
			let size = await intrinsicSize( transformer, buffer, extension );
			const social =
				socialImageUrls.has( downloadUrl ) || originals.some( ( original ) => socialImageUrls.has( original ) );
			if ( transformer && ! social && isReencodableImage( extension ) ) {
				const encoded = await encodeWebp( transformer, buffer, webpQuality );
				if ( encoded && encoded.buffer.length < payload.length ) {
					optimized.push( {
						url: downloadUrl,
						from: extension,
						to: 'webp',
						bytes: buffer.length,
						optimizedBytes: encoded.buffer.length,
					} );
					payload = encoded.buffer;
					size = encoded.size ?? size;
					extension = 'webp';
				} else {
					unoptimized.push( { url: downloadUrl, reason: 're-encoding produced no smaller file' } );
				}
			}

			for ( const original of originals ) {
				const relative = mediaFileName( original, extension );
				const destination = path.join( out, relative );
				await mkdir( path.dirname( destination ), { recursive: true } );
				await writeFile( destination, payload );
				const publicPath = `/${ relative.split( path.sep ).join( '/' ) }`;
				map.set( original, publicPath );
				if ( size ) {
					sizes.set( publicPath, size );
				}
				localized.push( {
					url: original,
					file: publicPath,
					bytes: payload.length,
					contentType,
					source,
					// A social image keeps its original format on purpose (see
					// `collectSocialImageUrls`): the media-budget gate reads this flag
					// and does not report the JPEG/PNG as an un-optimized leftover.
					...( social ? { social: true } : {} ),
				} );
			}
			totalBytes += payload.length;
		} catch ( error ) {
			const reason = error instanceof Error ? error.message : String( error );
			failed.push( { url: downloadUrl, reason } );
		}
	} );

	// 3. Emit the localized stylesheets now that their assets have local paths.
	//
	// A reference we could not mirror must not stay remote inside a mirrored
	// sheet: that ships a third-party URL a build-to-build network blip decides.
	// `dropUnavailableFontFaces` removes only the `@font-face` blocks that name
	// such a URL (the family falls back to the CSS stack it already declares),
	// so an unavailable image never costs typography. Host-agnostic on purpose:
	// the failing asset in the 2026-11-15 incident was Google's, but a theme font
	// on the CMS host fails the same way.
	const unmirrored = wanted.filter( ( url ) => ! map.has( url ) );
	const countFontFaces = ( css ) => ( css.match( /@font-face/gi ) ?? [] ).length;
	let droppedFaces = 0;
	const sourceStylesheetDir = path.join( CACHE_DIR, 'source-stylesheets' );
	await mkdir( sourceStylesheetDir, { recursive: true } );
	for ( const sheet of stylesheets ) {
		const relative = mediaFileName( sheet.url, 'css' );
		const destination = path.join( out, relative );
		await mkdir( path.dirname( destination ), { recursive: true } );
		// The SNAPSHOT must carry the source sheet, not the rewritten one: a
		// second localization pass over `/media/...` URLs would look for files
		// the snapshot never named (measured 2026-11-15 on the first cold
		// authoritative rebuild). `forge media-snapshot` copies this file.
		const sourcePath = path.join( sourceStylesheetDir, path.basename( relative ) );
		await writeFile( sourcePath, sheet.text, 'utf8' );
		const withoutUnavailable = dropUnavailableFontFaces( sheet.text, unmirrored, sheet.url );
		droppedFaces += countFontFaces( sheet.text ) - countFontFaces( withoutUnavailable );
		// A family that lost its LAST face is a real loss when a built glyph class
		// points at it. Report usage here so the verify gate can warn/fail without
		// re-parsing the source sheet.
		for ( const dropped of droppedFontFamilies( sheet.text, withoutUnavailable, htmlText ) ) {
			droppedFamilies.push( { ...dropped, sheet: sheet.url } );
		}
		// 140 §4 — every mirrored sheet gets `font-display` where it was missing:
		// the theme icon font shipped without it, so the browser held text back
		// until 129 KB of woff arrived.
		const rewritten = addFontDisplaySwap( rewriteStylesheet( withoutUnavailable, sheet.url, map ) );
		await writeFile( destination, rewritten, 'utf8' );
		const publicPath = `/${ relative.split( path.sep ).join( '/' ) }`;
		map.set( sheet.url, publicPath );
		localized.push( { url: sheet.url, file: publicPath, bytes: Buffer.byteLength( rewritten ), contentType: 'text/css', source: sheet.source ?? 'network', sourceFile: path.relative( ROOT, sourcePath ).split( path.sep ).join( '/' ) } );
	}

	if ( droppedFaces > 0 ) {
		log(
			`  dropped ${ droppedFaces } @font-face block(s) that could not be mirrored (the family falls back to the CSS stack).`
		);
	}
	for ( const dropped of droppedFamilies.filter( ( entry ) => entry.used ) ) {
		log(
			`  WARNING: icon font "${ dropped.family }" is used by ${ dropped.classes.slice( 0, 4 ).map( ( name ) => `.${ name }` ).join( ', ' ) }${ dropped.classes.length > 4 ? ', …' : '' } but its last @font-face could not be mirrored — those glyphs will be blank.`
		);
	}

	// 4. Rewrite the sources in place. Any host we now serve ourselves no longer
	// earns a preconnect — once Google's sheet and its woff2 are local, the two
	// hints in the head open connections the page never uses (140 §3).
	const localizedHosts = new Set();
	for ( const url of map.keys() ) {
		try {
			const host = new URL( url ).hostname.toLowerCase();
			if ( WEBFONT_HOSTS.includes( host ) ) {
				localizedHosts.add( host );
			}
		} catch {
			// A malformed key cannot name a host we should strip.
		}
	}
	const rewrittenFiles = await applyRewrite( sources, map, sizes, localizedHosts );

	const humanBytes = ( bytes ) => `${ ( bytes / 1024 / 1024 ).toFixed( 1 ) } MB`;
	const savedBytes = optimized.reduce( ( total, entry ) => total + ( entry.bytes - entry.optimizedBytes ), 0 );
	log(
		`localized ${ localized.length } reference(s) (${ humanBytes( totalBytes ) }) across ${ rewrittenFiles } file(s)` +
			( proxied.length > 0 ? `; proxied ${ proxied.length } upload reference(s) to /media` : '' ) +
			( optimized.length > 0 ? `, re-encoded ${ optimized.length } to WebP (saved ${ Math.round( savedBytes / 1024 ) } KB)` : '' ) +
			( skipped.length + leftRemote.length > 0 ? `, skipped ${ skipped.length + leftRemote.length }` : '' ) +
			( failed.length > 0 ? `, ${ failed.length } failed` : '' ) +
			'.'
	);
	for ( const failure of failed.slice( 0, 10 ) ) {
		log( `  unavailable: ${ failure.url } — ${ failure.reason }` );
	}
	if ( failed.length > 10 ) {
		log( `  …and ${ failed.length - 10 } more in ${ path.relative( ROOT, REPORT_PATH ) }.` );
	}
	await writeReport( {
		mode,
		origin,
		localized,
		optimized,
		unoptimized,
		proxied,
		skipped: [ ...skipped, ...leftRemote ],
		failed,
		droppedFontFamilies: droppedFamilies,
		bytes: totalBytes,
	} );
}

/** Rewrite every collected source in place; returns the changed-file count. */
async function applyRewrite( sources, map, sizes = new Map(), preconnectHosts = new Set() ) {
	let rewrittenFiles = 0;
	for ( const [ file, source ] of sources ) {
		const isHtml = path.extname( file ).toLowerCase() === '.html';
		// 140 §2 — the dimension fill runs after the URL rewrite so it can key on
		// the local path the tag now carries. `ASTRO_SITE` is the built site's
		// public origin: og:image/twitter:image are mirrored like any asset but
		// must stay absolute, so they are re-pointed at it (a root-relative
		// social image is invalid). Unset → the meta keeps the source URL.
		const next = isHtml
			? addImageDimensions(
					stripPreconnects( rewriteHtml( source, map, { siteOrigin: env( 'ASTRO_SITE' ) } ), preconnectHosts ),
					sizes
			  )
			: rewriteCss( source, map );
		if ( next !== source ) {
			await writeFile( file, next, 'utf8' );
			rewrittenFiles += 1;
		}
	}
	return rewrittenFiles;
}

/** `media/<sha1>.<ext>` — the cache path for a download URL. */
function cachedPathGuess( cacheKey, contentType, downloadUrl ) {
	const urlExtension = path.extname( new URL( downloadUrl ).pathname ).replace( /^\./, '' ).toLowerCase();
	const extension = documentExtension( downloadUrl ) ?? extensionForContentType( contentType, urlExtension || 'bin' );
	return path.join( CACHE_DIR, 'media', `${ cacheKey }.${ extension }` );
}

/** Find an existing cache entry for a download URL (any extension). */
async function findCached( cacheKey ) {
	let entries;
	try {
		entries = await readdir( path.join( CACHE_DIR, 'media' ) );
	} catch {
		return null;
	}
	for ( const entry of entries ) {
		if ( entry.startsWith( `${ cacheKey }.` ) && entry.endsWith( '.meta.json' ) ) {
			const base = entry.slice( 0, -'.meta.json'.length );
			const filePath = path.join( CACHE_DIR, 'media', base );
			if ( ! existsSync( filePath ) ) {
				continue;
			}
			let contentType = '';
			try {
				contentType = JSON.parse( await readFile( path.join( CACHE_DIR, 'media', entry ), 'utf8' ) ).contentType ?? '';
			} catch {
				contentType = '';
			}
			return { path: filePath, contentType };
		}
	}
	return null;
}

async function writeReport( payload ) {
	try {
		await mkdir( CACHE_DIR, { recursive: true } );
		await writeFile(
			REPORT_PATH,
			`${ JSON.stringify( { generatedAt: new Date().toISOString(), ...payload }, null, 2 ) }\n`,
			'utf8'
		);
	} catch ( error ) {
		log( `could not write the report (${ error instanceof Error ? error.message : error }).` );
	}
}

await main().catch( ( error ) => {
	// Never fail the build: the localized output is an enhancement.
	log( `pass aborted: ${ error instanceof Error ? error.message : error }` );
} );
