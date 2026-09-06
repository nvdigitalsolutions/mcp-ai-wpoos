/**
 * Tests for the crawl route helpers (tiered fetch + extraction).
 *
 * No network or browser access is required: transports are injected, and
 * all URLs use IP literals so resolvePublicUrl() never performs DNS
 * lookups (public literal 93.184.216.34 = example.com's historic address).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
	safeFetchHtml,
	browserFetchHtml,
	crawlUrl,
	normaliseRenderMode,
	CRAWL_MAX_URLS_BATCH,
} from './crawl.js';
import { submitFullCrawl, getFullTaskStatus } from './crawl.js';

const PUBLIC_URL = 'http://93.184.216.34/article';
const PRIVATE_URL = 'http://10.0.0.8/secret';
const ARTICLE_HTML = '<html><head><title>T</title></head><body><article><h1>Hello</h1>'
	+ '<p>' + 'word '.repeat( 120 ) + '</p></article></body></html>';
const THIN_HTML = '<html><head><title>T</title></head><body><p>tiny</p></body></html>';

/** Fake upstream for the full-Crawl4AI proxy tests. */
function fakeUpstreamFetch( handler ) {
	const calls = [];
	const fetchFn = async ( url, init ) => {
		calls.push( { url, init } );
		return handler( url, init, calls.length );
	};
	return { fetchFn, calls };
}

function upstreamResponse( status, body ) {
	return {
		status,
		json: async () => body,
	};
}

/** Strict pass-through URL resolver for proxy tests (no DNS). */
const passthroughResolver = async ( raw ) => new URL( raw );

/** axios-like fake transport. */
function fakeGet( responses ) {
	const calls = [];
	const get = async ( url ) => {
		calls.push( url );
		const entry = responses[ calls.length - 1 ];
		if ( ! entry ) {
			throw new Error( 'unexpected request' );
		}
		if ( entry.throw ) {
			throw entry.throw;
		}
		return {
			status: entry.status,
			headers: entry.headers || { 'content-type': 'text/html; charset=utf-8' },
			data: Buffer.from( entry.body || ARTICLE_HTML ),
		};
	};
	return { get, calls };
}

test( 'normaliseRenderMode accepts auto/never/always and rejects others', () => {
	assert.equal( normaliseRenderMode( undefined ), 'auto' );
	assert.equal( normaliseRenderMode( 'auto' ), 'auto' );
	assert.equal( normaliseRenderMode( 'never' ), 'never' );
	assert.equal( normaliseRenderMode( 'always' ), 'always' );
	assert.equal( normaliseRenderMode( 'nope' ), null );
	assert.equal( normaliseRenderMode( 42 ), null );
} );

test( 'safeFetchHtml fetches HTML and reports final URL/status', async () => {
	const { get, calls } = fakeGet( [ { status: 200, body: ARTICLE_HTML } ] );
	const result = await safeFetchHtml( PUBLIC_URL, {}, { get } );

	assert.equal( result.status_code, 200 );
	assert.equal( result.html, ARTICLE_HTML );
	assert.deepEqual( calls, [ PUBLIC_URL ] );
} );

test( 'safeFetchHtml rejects a redirect to a private address', async () => {
	const { get, calls } = fakeGet( [ {
		status: 302,
		headers: { location: PRIVATE_URL, 'content-type': 'text/html' },
	} ] );

	await assert.rejects( () => safeFetchHtml( PUBLIC_URL, {}, { get } ) );
	assert.deepEqual( calls, [ PUBLIC_URL ], 'the private hop is never fetched' );
} );

test( 'safeFetchHtml refuses non-HTML content types', async () => {
	const { get } = fakeGet( [ { status: 200, headers: { 'content-type': 'application/pdf' }, body: '%PDF' } ] );

	await assert.rejects(
		() => safeFetchHtml( PUBLIC_URL, {}, { get } ),
		( err ) => 415 === err.status
	);
} );

test( 'safeFetchHtml rejects private hosts before any request', async () => {
	const { get, calls } = fakeGet( [ { status: 200 } ] );

	await assert.rejects(
		() => safeFetchHtml( PRIVATE_URL, {}, { get } ),
		( err ) => 400 === err.status
	);
	assert.deepEqual( calls, [], 'no request is made for private hosts' );
} );

test( 'safeFetchHtml caps redirects', async () => {
	const { get } = fakeGet( [
		{ status: 302, headers: { location: PUBLIC_URL + '?r=1' } },
		{ status: 302, headers: { location: PUBLIC_URL + '?r=2' } },
	] );

	await assert.rejects(
		() => safeFetchHtml( PUBLIC_URL, { max_redirects: 1 }, { get } ),
		( err ) => 502 === err.status
	);
} );

test( 'crawlUrl (render never) uses the static tier only', async () => {
	const { get, calls } = fakeGet( [ { status: 200, body: ARTICLE_HTML } ] );
	let browserCalls = 0;

	const result = await crawlUrl( PUBLIC_URL, { render: 'never' }, {
		fetchHtml: ( url, opts ) => safeFetchHtml( url, opts, { get } ),
		browserFetch: async () => {
			browserCalls += 1;
			return { html: ARTICLE_HTML, final_url: PUBLIC_URL, status_code: 200 };
		},
	} );

	assert.equal( result.success, true );
	assert.equal( result.rendered, false );
	assert.ok( result.markdown.length > 0 );
	assert.equal( browserCalls, 0 );
	assert.equal( calls.length, 1 );
} );

test( 'crawlUrl (render auto) falls back to the browser when static text is thin', async () => {
	const { get } = fakeGet( [ { status: 200, body: THIN_HTML } ] );
	let browserCalls = 0;

	const result = await crawlUrl( PUBLIC_URL, { render: 'auto', min_text_chars: 100 }, {
		fetchHtml: ( url, opts ) => safeFetchHtml( url, opts, { get } ),
		browserFetch: async () => {
			browserCalls += 1;
			return { html: ARTICLE_HTML, final_url: PUBLIC_URL, status_code: 200 };
		},
	} );

	assert.equal( result.rendered, true, 'thin static page triggers the browser tier' );
	assert.equal( browserCalls, 1 );
	assert.ok( result.markdown.length > 0 );
} );

test( 'crawlUrl (render always) skips the static tier entirely', async () => {
	const { get, calls } = fakeGet( [ { status: 200, body: ARTICLE_HTML } ] );

	const result = await crawlUrl( PUBLIC_URL, { render: 'always' }, {
		fetchHtml: ( url, opts ) => safeFetchHtml( url, opts, { get } ),
		browserFetch: async () => ( { html: ARTICLE_HTML, final_url: PUBLIC_URL, status_code: 200 } ),
	} );

	assert.equal( result.rendered, true );
	assert.deepEqual( calls, [], 'static fetch never happens in always mode' );
} );

test( 'crawlUrl rejects private URLs before any tier runs', async () => {
	let fetchCalls = 0;
	let browserCalls = 0;

	await assert.rejects(
		() => crawlUrl( PRIVATE_URL, { render: 'auto' }, {
			fetchHtml: async () => {
				fetchCalls += 1;
				return { html: ARTICLE_HTML, final_url: PRIVATE_URL, status_code: 200 };
			},
			browserFetch: async () => {
				browserCalls += 1;
				return { html: ARTICLE_HTML, final_url: PRIVATE_URL, status_code: 200 };
			},
		} ),
		( err ) => 400 === err.status
	);
	assert.equal( fetchCalls, 0 );
	assert.equal( browserCalls, 0 );
} );

test( 'browserFetchHtml validates the URL before launching', async () => {
	await assert.rejects(
		() => browserFetchHtml( PRIVATE_URL ),
		( err ) => 400 === err.status
	);
} );

test( 'crawl batch cap is exposed for route validation', () => {
	assert.ok( CRAWL_MAX_URLS_BATCH >= 1 );
} );

// ── Full-Crawl4AI parity proxy (031 Phase 3) ────────────────

test( 'submitFullCrawl answers 503 service_not_configured without CRAWL4AI_FULL_URL', async () => {
	const { statusCode, body } = await submitFullCrawl(
		{ urls: [ PUBLIC_URL ] },
		{ baseUrl: null }
	);
	assert.equal( statusCode, 503 );
	assert.equal( body.error, 'service_not_configured' );
} );

test( 'submitFullCrawl rejects missing/empty urls', async () => {
	const { statusCode } = await submitFullCrawl( {}, { baseUrl: new URL( 'http://upstream.test' ) } );
	assert.equal( statusCode, 400 );
	const empty = await submitFullCrawl( { urls: [] }, { baseUrl: new URL( 'http://upstream.test' ) } );
	assert.equal( empty.statusCode, 400 );
} );

test( 'submitFullCrawl SSRF-rejects private targets before forwarding', async () => {
	const resolver = async ( raw ) => {
		const url = new URL( raw );
		if ( '10.0.0.8' === url.hostname ) {
			throw new Error( 'private' );
		}
		return url;
	};
	const { fetchFn, calls } = fakeUpstreamFetch( () => upstreamResponse( 200, { task_id: 't1' } ) );
	const { statusCode } = await submitFullCrawl(
		{ urls: [ PRIVATE_URL ] },
		{ baseUrl: new URL( 'http://upstream.test' ), resolvePublicUrlFn: resolver, fetchFn }
	);
	assert.equal( statusCode, 400 );
	assert.equal( calls.length, 0, 'Upstream must never be contacted for a rejected target' );
} );

test( 'submitFullCrawl forwards to {base}/crawl with normalized urls and relays the response', async () => {
	const { fetchFn, calls } = fakeUpstreamFetch( () => upstreamResponse( 200, { task_id: 't-42' } ) );
	const { statusCode, body } = await submitFullCrawl(
		{ urls: [ PUBLIC_URL ], word_count_threshold: 200 },
		{ baseUrl: new URL( 'http://upstream.test/base/' ), resolvePublicUrlFn: passthroughResolver, fetchFn }
	);
	assert.equal( statusCode, 200 );
	assert.equal( body.task_id, 't-42' );
	assert.equal( calls.length, 1 );
	assert.equal( calls[ 0 ].url, 'http://upstream.test/base/crawl' );
	assert.equal( calls[ 0 ].init.method, 'POST' );
	const sent = JSON.parse( calls[ 0 ].init.body );
	assert.deepEqual( sent.urls, [ PUBLIC_URL ] );
	assert.equal( sent.word_count_threshold, 200 );
} );

test( 'submitFullCrawl answers 502 upstream_unreachable when the upstream fetch throws', async () => {
	const fetchFn = async () => {
		throw new Error( 'ECONNREFUSED' );
	};
	const { statusCode, body } = await submitFullCrawl(
		{ urls: [ PUBLIC_URL ] },
		{ baseUrl: new URL( 'http://upstream.test' ), resolvePublicUrlFn: passthroughResolver, fetchFn }
	);
	assert.equal( statusCode, 502 );
	assert.equal( body.error, 'upstream_unreachable' );
} );

test( 'getFullTaskStatus relays the upstream task payload', async () => {
	const { fetchFn, calls } = fakeUpstreamFetch( () => upstreamResponse( 200, { status: 'completed', task_id: 't-7', results: [] } ) );
	const { statusCode, body } = await getFullTaskStatus(
		't-7',
		{ baseUrl: new URL( 'http://upstream.test' ), fetchFn }
	);
	assert.equal( statusCode, 200 );
	assert.equal( body.status, 'completed' );
	assert.equal( calls[ 0 ].url, 'http://upstream.test/task/t-7' );
} );

test( 'getFullTaskStatus answers 503 without CRAWL4AI_FULL_URL', async () => {
	const { statusCode, body } = await getFullTaskStatus( 't-1', { baseUrl: null } );
	assert.equal( statusCode, 503 );
	assert.equal( body.error, 'service_not_configured' );
} );
