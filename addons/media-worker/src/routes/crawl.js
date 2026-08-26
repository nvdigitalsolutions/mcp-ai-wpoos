/**
 * Crawl routes — URL → clean Markdown, batched crawling, and link scans.
 *
 * Endpoints:
 *   POST /api/crawl/markdown       — single URL → Markdown
 *   POST /api/crawl/markdown-batch — multiple URLs (sync or queued async)
 *   POST /api/crawl/links          — extract links from a page
 *
 * Extraction tiers (pullmd-style industry pattern):
 *   1. Static: plain HTTP fetch (redirect hops re-validated) → Readability →
 *      Turndown. Zero browser cost for well-formed pages.
 *   2. Browser: hardened Chromium (existing launcher + SSRF request
 *      interception) for JS-heavy pages, used automatically when the static
 *      tier yields too little text, or explicitly via render: "always".
 *
 * Every URL passes the shared SSRF guard before any fetch or navigation.
 */

import { Router } from 'express';
import axios from 'axios';
import { getQueue } from '../queue.js';
import { launchHardenedBrowser, hardenPage } from '../utils/browser.js';
import { resolvePublicUrl, validatePublicUrl } from '../utils/safe-url.js';
import { extractFromHtml } from '../utils/crawl-extract.js';

export const crawlRouter = Router();

// ── Configuration (env-tunable) ─────────────────────────────

/**
 * Positive integer from an env var, or the fallback.
 *
 * @param {string} name     Env var name.
 * @param {number} fallback Default value.
 * @param {number} [min]    Minimum accepted value.
 * @return {number} Effective value.
 */
function envInt( name, fallback, min = 0 ) {
	const value = Number( process.env[ name ] );
	return Number.isFinite( value ) && value >= min ? value : fallback;
}

export const CRAWL_TIMEOUT_MS = envInt( 'CRAWL_TIMEOUT_MS', 30000, 1000 );
export const CRAWL_MAX_BYTES = envInt( 'CRAWL_MAX_BYTES', 5 * 1024 * 1024, 1024 );
export const CRAWL_MAX_URLS_BATCH = envInt( 'CRAWL_MAX_URLS_BATCH', 10, 1 );
export const CRAWL_MIN_TEXT_CHARS = envInt( 'CRAWL_MIN_TEXT_CHARS', 200, 0 );
export const CRAWL_MAX_REDIRECTS = envInt( 'CRAWL_MAX_REDIRECTS', 5, 0 );
export const CRAWL_USER_AGENT = process.env.CRAWL_USER_AGENT
	|| 'nvoos-media-worker/3.2 (+https://github.com/nvdigitalsolutions/mcp-ai-wpoos)';

const VALID_RENDER_MODES = [ 'auto', 'never', 'always' ];

/**
 * Normalise a caller-supplied render mode.
 *
 * @param {string} render Raw render value.
 * @return {string} Normalised mode, or null when invalid.
 */
export function normaliseRenderMode( render ) {
	if ( undefined === render || null === render || '' === render ) {
		return 'auto';
	}
	if ( 'string' !== typeof render || ! VALID_RENDER_MODES.includes( render ) ) {
		return null;
	}
	return render;
}

// ── Static tier: HTTP fetch with validated redirects ─────────

/**
 * Fetch HTML with every redirect hop re-validated through the SSRF guard
 * (DNS-rebinding control). Never follows more than CRAWL_MAX_REDIRECTS hops
 * and refuses non-HTML payloads.
 *
 * @param {string} rawUrl Raw URL to fetch.
 * @param {Object} [opts] Options (timeout_ms, max_bytes, max_redirects).
 * @param {Object} [deps] Injectable transport ({ get }), for tests.
 * @return {Promise<{html: string, final_url: string, status_code: number}>}
 */
export async function safeFetchHtml( rawUrl, opts = {}, deps = {} ) {
	const transport = deps.get || axios.get;
	const timeoutMs = opts.timeout_ms || CRAWL_TIMEOUT_MS;
	const maxBytes = opts.max_bytes || CRAWL_MAX_BYTES;
	const maxRedirects = 'number' === typeof opts.max_redirects ? opts.max_redirects : CRAWL_MAX_REDIRECTS;

	let current = rawUrl;
	for ( let hop = 0; hop <= maxRedirects; hop++ ) {
		const parsed = await resolvePublicUrl( current );
		const url = parsed.toString();

		let response;
		try {
			response = await transport( url, {
				headers: {
					'User-Agent': CRAWL_USER_AGENT,
					Accept: 'text/html,application/xhtml+xml,*/*;q=0.8',
				},
				responseType: 'arraybuffer',
				maxRedirects: 0,
				validateStatus: ( status ) => status >= 200 && status < 400,
				timeout: timeoutMs,
				maxContentLength: maxBytes,
				maxBodyLength: maxBytes,
			} );
		} catch ( err ) {
			const status = err && err.response && err.response.status ? err.response.status : 502;
			throw Object.assign( new Error( `Fetch failed: ${ err.message }` ), { status } );
		}

		const status = response.status;

		if ( status >= 300 && status < 400 && response.headers && response.headers.location ) {
			if ( hop === maxRedirects ) {
				throw Object.assign( new Error( 'Too many redirects' ), { status: 502 } );
			}
			// resolvePublicUrl() re-validates the next hop above.
			current = new URL( response.headers.location, parsed ).toString();
			continue;
		}

		const contentType = String(
			( response.headers && ( response.headers[ 'content-type' ] || response.headers[ 'Content-Type' ] ) ) || ''
		).toLowerCase();
		if ( '' !== contentType
			&& ! contentType.includes( 'text/html' )
			&& ! contentType.includes( 'application/xhtml+xml' ) ) {
			throw Object.assign( new Error( `Unsupported content type: ${ contentType }` ), { status: 415 } );
		}

		return {
			html: Buffer.from( response.data ).toString( 'utf8' ),
			final_url: url,
			status_code: status,
		};
	}

	throw Object.assign( new Error( 'Too many redirects' ), { status: 502 } );
}

// ── Browser tier: hardened Chromium ──────────────────────────

/**
 * Render a page in the hardened Chromium and return its DOM HTML.
 *
 * @param {string} rawUrl Raw URL to render.
 * @param {Object} [opts] Options (timeout_ms, wait_until, wait_selector).
 * @return {Promise<{html: string, final_url: string, status_code: number}>}
 */
export async function browserFetchHtml( rawUrl, opts = {} ) {
	const parsed = await resolvePublicUrl( rawUrl );
	const url = parsed.toString();
	const timeoutMs = opts.timeout_ms || CRAWL_TIMEOUT_MS;
	const waitUntil = opts.wait_until || 'domcontentloaded';

	const browser = await launchHardenedBrowser();
	try {
		const page = await browser.newPage();
		await hardenPage( page );
		await page.setUserAgent( CRAWL_USER_AGENT );
		const response = await page.goto( url, { waitUntil, timeout: timeoutMs } );
		const statusCode = response && 'function' === typeof response.status ? response.status() : 200;
		if ( opts.wait_selector ) {
			await page.waitForSelector( opts.wait_selector, { timeout: timeoutMs } );
		}
		const html = await page.content();
		return { html, final_url: page.url(), status_code: statusCode };
	} finally {
		await browser.close().catch( () => {} );
	}
}

// ── Tiered crawl ─────────────────────────────────────────────

/**
 * Assemble the canonical per-URL crawl result.
 *
 * @param {string} rawUrl     Original URL.
 * @param {string} finalUrl   Final URL after redirects.
 * @param {number} statusCode HTTP status.
 * @param {boolean} rendered  Whether the browser tier was used.
 * @param {Object} extracted  extractFromHtml() output.
 * @param {number} startedAt  Crawl start timestamp (ms).
 * @param {Object} opts       Original options.
 * @return {Object} Result payload.
 */
function buildResult( rawUrl, finalUrl, statusCode, rendered, extracted, startedAt, opts ) {
	const result = {
		success: true,
		url: rawUrl,
		final_url: finalUrl,
		status_code: statusCode,
		rendered,
		title: extracted.title,
		markdown: extracted.markdown,
		word_count: extracted.word_count,
		extraction_ms: Date.now() - startedAt,
	};
	if ( opts.include_links ) {
		result.links = extracted.links;
	}
	if ( opts.include_metadata ) {
		result.byline = extracted.byline;
		result.excerpt = extracted.excerpt;
		result.site_name = extracted.site_name;
	}
	return result;
}

/**
 * Crawl a single URL using the tiered pipeline.
 *
 * render "never": static fetch only; errors propagate.
 * render "always": hardened Chromium only; errors propagate.
 * render "auto": static first; browser fallback when the fetch fails or the
 * extracted text is thinner than min_text_chars.
 *
 * @param {string} rawUrl Raw URL.
 * @param {Object} [opts] Options (render, timeout_ms, wait_selector,
 *                        min_text_chars, include_links, include_metadata).
 * @param {Object} [deps] Injectable tiers ({ fetchHtml, browserFetch }).
 * @return {Promise<Object>} Result payload.
 */
export async function crawlUrl( rawUrl, opts = {}, deps = {} ) {
	const fetchHtml = deps.fetchHtml || safeFetchHtml;
	const browserFetch = deps.browserFetch || browserFetchHtml;

	const render = normaliseRenderMode( opts.render ) || 'auto';
	const minTextChars = 'number' === typeof opts.min_text_chars ? opts.min_text_chars : CRAWL_MIN_TEXT_CHARS;
	const startedAt = Date.now();

	// Validate up-front for fast, consistent failures (both tiers re-validate).
	await resolvePublicUrl( rawUrl );

	let html = null;
	let finalUrl = rawUrl;
	let statusCode = 0;
	let rendered = false;

	if ( 'always' !== render ) {
		try {
			const result = await fetchHtml( rawUrl, opts );
			html = result.html;
			finalUrl = result.final_url;
			statusCode = result.status_code;
		} catch ( err ) {
			if ( 'never' === render ) {
				throw err;
			}
			// auto: fall through to the browser tier.
		}
	}

	if ( null !== html && 'auto' === render ) {
		const extracted = extractFromHtml( html, finalUrl );
		if ( ( extracted.text || '' ).length >= minTextChars ) {
			return buildResult( rawUrl, finalUrl, statusCode, rendered, extracted, startedAt, opts );
		}
		html = null; // Too thin for static extraction — try the browser tier.
	}

	if ( null === html ) {
		const result = await browserFetch( rawUrl, opts );
		html = result.html;
		finalUrl = result.final_url;
		statusCode = result.status_code;
		rendered = true;
	}

	const extracted = extractFromHtml( html, finalUrl );
	return buildResult( rawUrl, finalUrl, statusCode, rendered, extracted, startedAt, opts );
}

// ── Async batch support (Redis/in-memory queue) ─────────────

const handledBatchQueues = new WeakSet();

/**
 * Ensure the crawl batch handler is registered on a queue (once per queue).
 *
 * @param {Object} queue JobQueue instance.
 */
function ensureBatchHandler( queue ) {
	if ( handledBatchQueues.has( queue ) ) {
		return;
	}
	handledBatchQueues.add( queue );

	queue.process( 'markdown-batch', async ( job ) => {
		const { urls, render, timeout_ms: timeoutMs, include_links: includeLinks, include_metadata: includeMetadata, callback_url: callbackUrl } = job.data;

		const results = [];
		for ( const rawUrl of urls ) {
			try {
				results.push( await crawlUrl( rawUrl, {
					render,
					timeout_ms: timeoutMs,
					include_links: includeLinks,
					include_metadata: includeMetadata,
				} ) );
			} catch ( err ) {
				results.push( { success: false, url: String( rawUrl ), error: err.message, status_code: err.status || null } );
			}
		}

		if ( callbackUrl ) {
			try {
				await axios.post( callbackUrl, { success: true, total: urls.length, results }, { timeout: 10000 } );
			} catch ( err ) {
				console.warn( '[Crawl] Batch callback failed:', err.message );
			}
		}
	} );
}

/**
 * Normalise an optional callback URL (SSRF guard), or null.
 *
 * @param {string} callbackUrl Raw callback URL.
 * @return {string|null} Validated URL or null when absent.
 */
function safeCallbackUrl( callbackUrl ) {
	if ( ! callbackUrl ) {
		return null;
	}
	return validatePublicUrl( callbackUrl ).toString();
}

// ── POST /markdown — single URL ─────────────────────────────

crawlRouter.post( '/markdown', async ( req, res ) => {
	try {
		const { url, render, wait_selector: waitSelector, timeout_ms: timeoutMs, include_links: includeLinks, include_metadata: includeMetadata } = req.body || {};
		if ( ! url || 'string' !== typeof url ) {
			return res.status( 400 ).json( { success: false, error: 'url is required' } );
		}
		if ( null === normaliseRenderMode( render ) ) {
			return res.status( 400 ).json( { success: false, error: 'render must be one of: auto, never, always' } );
		}

		const result = await crawlUrl( url, {
			render,
			wait_selector: waitSelector,
			timeout_ms: timeoutMs,
			include_links: includeLinks,
			include_metadata: includeMetadata,
		} );
		res.json( result );
	} catch ( err ) {
		res.status( err.status && err.status >= 400 && err.status < 600 ? err.status : 502 )
			.json( { success: false, error: err.message } );
	}
} );

// ── POST /markdown-batch — multiple URLs ────────────────────

crawlRouter.post( '/markdown-batch', async ( req, res ) => {
	try {
		const { urls, render, timeout_ms: timeoutMs, include_links: includeLinks, include_metadata: includeMetadata, async_mode: asyncMode, callback_url: callbackUrl } = req.body || {};
		if ( ! Array.isArray( urls ) || 0 === urls.length ) {
			return res.status( 400 ).json( { success: false, error: 'urls must be a non-empty array' } );
		}
		if ( urls.length > CRAWL_MAX_URLS_BATCH ) {
			return res.status( 400 ).json( { success: false, error: `Batch size exceeds CRAWL_MAX_URLS_BATCH (${ CRAWL_MAX_URLS_BATCH })` } );
		}
		if ( null === normaliseRenderMode( render ) ) {
			return res.status( 400 ).json( { success: false, error: 'render must be one of: auto, never, always' } );
		}

		if ( asyncMode ) {
			const queue = getQueue( 'crawl', req.site );
			ensureBatchHandler( queue );
			const job = await queue.add( 'markdown-batch', {
				urls,
				render,
				timeout_ms: timeoutMs,
				include_links: includeLinks,
				include_metadata: includeMetadata,
				callback_url: safeCallbackUrl( callbackUrl ),
			} );
			return res.json( {
				success: true,
				async: true,
				job_id: job.id,
				message: 'Batch crawl queued. Poll /api/workflow/status for queue progress.',
			} );
		}

		const results = [];
		for ( const rawUrl of urls ) {
			try {
				results.push( await crawlUrl( rawUrl, {
					render,
					timeout_ms: timeoutMs,
					include_links: includeLinks,
					include_metadata: includeMetadata,
				} ) );
			} catch ( err ) {
				results.push( { success: false, url: String( rawUrl ), error: err.message, status_code: err.status || null } );
			}
		}

		res.json( { success: true, total: urls.length, results } );
	} catch ( err ) {
		res.status( err.status && err.status >= 400 && err.status < 600 ? err.status : 502 )
			.json( { success: false, error: err.message } );
	}
} );

// ── POST /links — link extraction ───────────────────────────

crawlRouter.post( '/links', async ( req, res ) => {
	try {
		const { url, render, timeout_ms: timeoutMs, internal_only: internalOnly } = req.body || {};
		if ( ! url || 'string' !== typeof url ) {
			return res.status( 400 ).json( { success: false, error: 'url is required' } );
		}
		if ( null === normaliseRenderMode( render ) ) {
			return res.status( 400 ).json( { success: false, error: 'render must be one of: auto, never, always' } );
		}

		const result = await crawlUrl( url, { render, timeout_ms: timeoutMs, include_links: true } );
		let links = result.links || [];
		if ( internalOnly ) {
			links = links.filter( ( link ) => link.is_internal );
		}

		res.json( { success: true, url, final_url: result.final_url, rendered: result.rendered, links } );
	} catch ( err ) {
		res.status( err.status && err.status >= 400 && err.status < 600 ? err.status : 502 )
			.json( { success: false, error: err.message } );
	}
} );

// ── /full + /full/task/:task_id — full-Crawl4AI parity proxy (031 Phase 3) ──
//
// When CRAWL4AI_FULL_URL points at a real Crawl4AI deployment (sibling
// container or hosted service), the worker acts as the SSRF-validated,
// token-gated forwarder: every target URL is validated by the shared SSRF
// guard BEFORE the payload is proxied upstream, so private/loopback targets
// can never reach the Python service through this route. Without
// CRAWL4AI_FULL_URL the route answers 503 service_not_configured.

const CRAWL_FULL_TIMEOUT_MS = envInt( 'CRAWL_FULL_TIMEOUT_MS', CRAWL_TIMEOUT_MS, 1000 );

/**
 * Base URL of the upstream full-Crawl4AI service, or null when unset.
 *
 * Operator-configured via CRAWL4AI_FULL_URL; protocol-restricted to
 * http/https. Unlike target URLs, the upstream itself is NOT SSRF-checked —
 * it is the whole point of the proxy to reach a private sibling container.
 *
 * @param {Object} [env] Environment source (injectable for tests).
 * @return {URL|null} Parsed base URL, or null when unset/invalid.
 */
function fullCrawlBaseUrl( env = process.env ) {
	const raw = ( env.CRAWL4AI_FULL_URL || '' ).trim();
	if ( ! raw ) {
		return null;
	}
	let parsed;
	try {
		parsed = new URL( raw );
	} catch {
		return null;
	}
	if ( 'http:' !== parsed.protocol && 'https:' !== parsed.protocol ) {
		return null;
	}
	return parsed;
}

/**
 * Append a path segment to the upstream base URL (preserving any base path).
 *
 * @param {URL}    base Parsed upstream base URL.
 * @param {string} seg  Path segment(s) to append.
 * @return {string} Full upstream URL.
 */
function upstreamUrl( base, seg ) {
	const url = new URL( base.toString() );
	url.pathname = ( url.pathname.replace( /\/+$/, '' ) + '/' + seg ).replace( /^\/+/, '/' );
	url.search = '';
	url.hash = '';
	return url.toString();
}

/**
 * Validate + forward a full-Crawl4AI crawl submission to the upstream.
 *
 * Contract-preserving: the request body is forwarded as-is (except that
 * every `urls` entry is replaced by its SSRF-validated normalisation) and
 * the upstream response is relayed verbatim, so the WordPress plugin's
 * Crawl4AI client needs no awareness of the proxy.
 *
 * @param {Object} [payload] Request body.
 * @param {Object} [deps]    Injectable ({ baseUrl, resolvePublicUrlFn, fetchFn }).
 * @return {Promise<{statusCode: number, body: Object}>} HTTP result.
 */
export async function submitFullCrawl( payload = {}, deps = {} ) {
	const base = undefined !== deps.baseUrl ? deps.baseUrl : fullCrawlBaseUrl();
	if ( ! base ) {
		return { statusCode: 503, body: { error: 'service_not_configured' } };
	}

	const resolver = deps.resolvePublicUrlFn || resolvePublicUrl;
	const urls = Array.isArray( payload.urls ) ? payload.urls : null;
	if ( ! urls || 0 === urls.length ) {
		return { statusCode: 400, body: { error: 'urls must be a non-empty array' } };
	}

	// SSRF guard: validate every target before forwarding.
	const normalized = [];
	for ( const rawUrl of urls ) {
		if ( 'string' !== typeof rawUrl || '' === rawUrl ) {
			return { statusCode: 400, body: { error: 'Every url must be a non-empty string.' } };
		}
		try {
			normalized.push( ( await resolver( rawUrl ) ).toString() );
		} catch {
			return { statusCode: 400, body: { error: `Invalid URL: ${ rawUrl }` } };
		}
	}

	const fetchFn = deps.fetchFn || fetch;
	let upstream;
	try {
		upstream = await fetchFn( upstreamUrl( base, 'crawl' ), {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { ...payload, urls: normalized } ),
			signal: AbortSignal.timeout( CRAWL_FULL_TIMEOUT_MS ),
		} );
	} catch {
		return { statusCode: 502, body: { error: 'upstream_unreachable' } };
	}

	const json = await upstream.json().catch( () => ( {} ) );
	return { statusCode: upstream.status, body: json };
}

/**
 * Relay a task-status poll to the upstream full-Crawl4AI service.
 *
 * @param {string} taskId Task identifier.
 * @param {Object} [deps] Injectable ({ baseUrl, fetchFn }).
 * @return {Promise<{statusCode: number, body: Object}>} HTTP result.
 */
export async function getFullTaskStatus( taskId, deps = {} ) {
	const base = undefined !== deps.baseUrl ? deps.baseUrl : fullCrawlBaseUrl();
	if ( ! base ) {
		return { statusCode: 503, body: { error: 'service_not_configured' } };
	}
	if ( 'string' !== typeof taskId || '' === taskId ) {
		return { statusCode: 400, body: { error: 'task_id is required' } };
	}

	const fetchFn = deps.fetchFn || fetch;
	let upstream;
	try {
		upstream = await fetchFn( upstreamUrl( base, `task/${ encodeURIComponent( taskId ) }` ), {
			signal: AbortSignal.timeout( CRAWL_FULL_TIMEOUT_MS ),
		} );
	} catch {
		return { statusCode: 502, body: { error: 'upstream_unreachable' } };
	}

	const json = await upstream.json().catch( () => ( {} ) );
	return { statusCode: upstream.status, body: json };
}

crawlRouter.post( '/full', async ( req, res ) => {
	const { statusCode, body } = await submitFullCrawl( req.body || {} );
	res.status( statusCode ).json( body );
} );

crawlRouter.get( '/full/task/:task_id', async ( req, res ) => {
	const { statusCode, body } = await getFullTaskStatus( req.params.task_id );
	res.status( statusCode ).json( body );
} );
