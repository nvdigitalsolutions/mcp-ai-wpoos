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
//     reported (never silent),
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
	collectCssAssetUrls,
	collectDocumentUrls,
	collectStylesheetUrls,
	documentExtension,
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

async function download( url, timeoutMs ) {
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
			throw new Error( `HTTP ${ response.status }` );
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
	for ( const url of stylesheetUrls ) {
		try {
			const result = await download( url, timeoutMs );
			if ( result.buffer.length > 4 * 1024 * 1024 ) {
				failed.push( { url, reason: 'stylesheet larger than 4 MB' } );
				continue;
			}
			const text = result.buffer.toString( 'utf8' );
			stylesheets.push( { url, text } );
			for ( const asset of stylesheetAssetUrls( text, url ) ) {
				candidates.add( asset );
			}
		} catch ( error ) {
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
		await writeReport( { mode, origin, localized: [], skipped: [], failed: [], bytes: 0 } );
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
			const cached = await findCached( cacheKey );

			let buffer;
			let contentType;
			let cacheFile;

			if ( cached ) {
				buffer = await readFile( cached.path );
				contentType = cached.contentType;
				cacheFile = cached.path;
			} else {
				const result = await download( downloadUrl, timeoutMs );
				buffer = result.buffer;
				contentType = result.contentType;
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
			let payload = buffer;
			let size = await intrinsicSize( transformer, buffer, extension );
			if ( transformer && isReencodableImage( extension ) ) {
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
				localized.push( { url: original, file: publicPath, bytes: payload.length } );
			}
			totalBytes += payload.length;
		} catch ( error ) {
			const reason = error instanceof Error ? error.message : String( error );
			failed.push( { url: downloadUrl, reason } );
		}
	} );

	// 3. Emit the localized stylesheets now that their assets have local paths.
	for ( const sheet of stylesheets ) {
		const relative = mediaFileName( sheet.url, 'css' );
		const destination = path.join( out, relative );
		await mkdir( path.dirname( destination ), { recursive: true } );
		// 140 §4 — every mirrored sheet gets `font-display` where it was missing:
		// the theme icon font shipped without it, so the browser held text back
		// until 129 KB of woff arrived.
		const rewritten = addFontDisplaySwap( rewriteStylesheet( sheet.text, sheet.url, map ) );
		await writeFile( destination, rewritten, 'utf8' );
		const publicPath = `/${ relative.split( path.sep ).join( '/' ) }`;
		map.set( sheet.url, publicPath );
		localized.push( { url: sheet.url, file: publicPath, bytes: Buffer.byteLength( rewritten ) } );
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
		bytes: totalBytes,
	} );
}

/** Rewrite every collected source in place; returns the changed-file count. */
async function applyRewrite( sources, map, sizes = new Map(), preconnectHosts = new Set() ) {
	let rewrittenFiles = 0;
	for ( const [ file, source ] of sources ) {
		const isHtml = path.extname( file ).toLowerCase() === '.html';
		// 140 §2 — the dimension fill runs after the URL rewrite so it can key on
		// the local path the tag now carries.
		const next = isHtml
			? addImageDimensions( stripPreconnects( rewriteHtml( source, map ), preconnectHosts ), sizes )
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
