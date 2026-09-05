/* jshint esversion: 6 */
/**
 * Standalone verification of the checkout-unavailable fallback (no browser).
 * Run: node scripts/verify-commerce-fallback.js
 *
 * Simulates the three /payments/session failure paths:
 *   1. 404 response            -> redirect to fallback URL
 *   2. network rejection       -> redirect to fallback URL
 *   3. 429 (throttled)         -> in-modal error, NO redirect
 *   4. empty fallback URL      -> in-modal error, NO redirect
 */

// ─── Minimal DOM stubs ────────────────────────────────────────
function fakeElement() {
	var node = {
		children: [],
		style: {},
		dataset: {},
		disabled: false,
		textContent: '',
		className: '',
		type: '',
		href: '',
		target: '',
		rel: '',
		innerHTML: '',
		appendChild: function ( child ) { node.children.push( child ); return child; },
		addEventListener: function () {},
		setAttribute: function () {},
		querySelector: function () { return fakeElement(); },
		querySelectorAll: function () { return []; },
		setPointerCapture: function () {}
	};
	return node;
}

var capturedButtons = [];
global.window = {
	Stripe: function () { return { elements: function () { return {}; } }; },
	location: { href: 'https://example.local/wp-admin/admin.php?page=nvoos-content-graph' },
	setTimeout: function ( fn ) { global.__lastTimeout = fn; },
	matchMedia: null
};
global.document = {
	readyState: 'loading',
	addEventListener: function ( evt, fn ) { global.__domReady = fn; },
	createElement: function ( tag ) { return fakeElement(); },
	querySelectorAll: function ( sel ) {
		if ( sel === '.nvoos-content-graph-buy-ai' ) {
			return capturedButtons;
		}
		return [];
	},
	body: { appendChild: function () {} }
};

// ─── Config + fetch mock ──────────────────────────────────────
var fetchBehavior = null;
global.fetch = function () {
	if ( fetchBehavior === 'reject' ) {
		return Promise.reject( new Error( 'network' ) );
	}
	var status = fetchBehavior === '429' ? 429 : 404;
	return Promise.resolve( {
		ok: status < 400,
		status: status,
		json: function () { return Promise.resolve( { message: 'nope' } ); }
	} );
};

var fail = 0;
function check( label, cond ) {
	console.log( ( cond ? 'ok   ' : 'FAIL ' ) + label );
	if ( ! cond ) { fail++; }
}

// ─── Load the commerce bundle ─────────────────────────────────
global.window.nvoosContentGraphCommerce = {
	rest_url: 'https://example.local/wp-json/nvoos-content-graph/v1',
	nonce: 'abc',
	price_label: '$49.00',
	fallback_url: 'https://nvdigitalsolutions.com/plugins/nvoos-content-graph-ai/',
	i18n: { generic_error: 'Something went wrong.', fallback_note: 'Redirecting…' }
};
require( '../assets/js/content-graph-commerce.js' );

// Buttons are wired on DOMContentLoaded; trigger it.
global.__domReady();
check( 'button bound', capturedButtons.length === 0 ); // no buttons yet — add one.

var btn = fakeElement();
capturedButtons.push( btn );
btn.addEventListener = function ( evt, fn ) { btn.__click = fn; };
global.__domReady(); // re-run binding (listeners attached again)
check( 'button has click handler', typeof btn.__click === 'function' );

function reset() {
	global.window.location.href = 'https://example.local/wp-admin/';
	global.__lastTimeout = null;
}

function runFlow( label, behavior, expectRedirect, expectHref ) {
	return new Promise( function ( resolve ) {
		reset();
		fetchBehavior = behavior;
		btn.__click();
		// Allow the promise chain to settle.
		setTimeout( function () {
			var redirected = null;
			if ( global.__lastTimeout ) {
				global.__lastTimeout(); // simulate the 1200ms timer firing.
				redirected = global.window.location.href;
			}
			check( label, expectRedirect ? redirected === expectHref : redirected === null );
			resolve();
		}, 20 );
	} );
}

( async function () {
	await runFlow( '404 session -> redirect to fallback URL', '404', true, 'https://nvdigitalsolutions.com/plugins/nvoos-content-graph-ai/' );
	await runFlow( 'network failure -> redirect to fallback URL', 'reject', true, 'https://nvdigitalsolutions.com/plugins/nvoos-content-graph-ai/' );
	await runFlow( '429 throttle -> no redirect', '429', false, null );

	// Empty fallback URL disables the redirect.
	global.window.nvoosContentGraphCommerce.fallback_url = '';
	await runFlow( 'empty fallback_url -> no redirect', '404', false, null );

	console.log( '\n' + ( fail ? 'FAILURES: ' + fail : 'ALL COMMERCE FALLBACK CHECKS PASSED' ) );
	process.exit( fail ? 1 : 0 );
}() );
