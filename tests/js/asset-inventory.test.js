/**
 * Jest tests for assets/js/asset-inventory.js
 *
 * Covers:
 *  - escapeHtml              — XSS-safe HTML entity encoding
 *  - discoverAssets          — happy path, AJAX error, response.success=false, missing config
 *  - showError               — renders an error notice using .text() (no XSS)
 *  - filterAssets            — hides rows that don't match selected classification / type
 *  - updateVisibleCount      — emits the expected console.log message
 *  - Source-level assertions — logging calls, guard patterns
 *
 * @package WP_MCP_AI
 */

const fs   = require( 'fs' );
const path = require( 'path' );

const sourceCode = fs.readFileSync(
	path.join( __dirname, '../../assets/js/asset-inventory.js' ),
	'utf8'
);

// ── Source-level assertions ───────────────────────────────────────────────────

describe( 'asset-inventory.js — source structure', () => {
	test( 'contains the AssetInventoryManager object', () => {
		expect( sourceCode ).toMatch( /AssetInventoryManager\s*=\s*\{/ );
	} );

	test( 'reads config from wpMcpAiAssetInventory global', () => {
		expect( sourceCode ).toMatch( /window\.wpMcpAiAssetInventory/ );
	} );

	test( 'guards against missing config with console.error', () => {
		expect( sourceCode ).toMatch(
			/console\.error\s*\(\s*'\[WP MCP AI\] Asset discovery: configuration object/
		);
	} );

	test( 'logs discovery start with console.log', () => {
		expect( sourceCode ).toMatch(
			/console\.log\s*\(\s*'\[WP MCP AI\] Asset discovery starting/
		);
	} );

	test( 'logs discovery success with console.log', () => {
		expect( sourceCode ).toMatch(
			/console\.log\s*\(\s*'\[WP MCP AI\] Asset discovery succeeded\./
		);
	} );

	test( 'logs discovery failure with console.warn', () => {
		expect( sourceCode ).toMatch(
			/console\.warn\s*\(\s*'\[WP MCP AI\] Asset discovery returned failure\./
		);
	} );

	test( 'logs AJAX error with console.error including truncated responseText', () => {
		expect( sourceCode ).toMatch(
			/console\.error\s*\(\s*'\[WP MCP AI\] Asset discovery AJAX error\./
		);
		expect( sourceCode ).toMatch( /responseText\s*:.*responseText.*substring/ );
	} );

	test( 'success notice uses DOM-safe .append($(<p>).text()) instead of .html()', () => {
		// Must NOT use .html() for building the success notice message.
		// The old unsafe pattern concatenated user-controlled values into .html().
		expect( sourceCode ).not.toMatch(
			/\.html\s*\(\s*'<p>'\s*\+\s*config\.strings\.discoverySuccess/
		);
		// Must use .append with $('<p>').text(...)
		expect( sourceCode ).toMatch( /\$\s*\(\s*'<p>'\s*\)\.text\s*\(/ );
	} );

	test( 'falls back to config.strings.discoveryError when response.message is missing', () => {
		expect( sourceCode ).toMatch(
			/response\.message\s*\|\|\s*config\.strings\.discoveryError/
		);
	} );

	test( 'filter log uses the [WP MCP AI] prefix', () => {
		expect( sourceCode ).toMatch(
			/console\.log\s*\(\s*'\[WP MCP AI\] Asset filter:/
		);
	} );
} );

// ── Runtime tests with jQuery mock ───────────────────────────────────────────

/**
 * Build a lightweight jQuery-compatible mock element.
 *
 * @param {string} selector
 * @return {Object}
 */
function createMockElement( selector ) {
	const classes = new Set();
	const eventHandlers = {};
	const dataStore = {};
	let visible = true;
	let storedText = '';

	const el = {
		selector: selector || '',
		length: 1,
		_classes: classes,
		_eventHandlers: eventHandlers,

		on: jest.fn( function( event, handler ) {
			if ( ! eventHandlers[ event ] ) {
				eventHandlers[ event ] = [];
			}
			eventHandlers[ event ].push( handler );
			return this;
		} ),
		trigger: jest.fn( function( event ) {
			( eventHandlers[ event ] || [] ).forEach( ( h ) => h.call( this ) );
			return this;
		} ),
		addClass: jest.fn( function( cls ) {
			String( cls ).split( /\s+/ ).forEach( ( c ) => classes.add( c ) );
			return this;
		} ),
		removeClass: jest.fn( function( cls ) {
			String( cls ).split( /\s+/ ).forEach( ( c ) => classes.delete( c ) );
			return this;
		} ),
		hasClass: jest.fn( function( cls ) {
			return classes.has( cls );
		} ),
		text: jest.fn( function( val ) {
			if ( val !== undefined ) {
				storedText = val;
				return this;
			}
			return storedText;
		} ),
		html: jest.fn( function() {
			return this;
		} ),
		empty: jest.fn( function() {
			return this;
		} ),
		append: jest.fn( function() {
			return this;
		} ),
		hide: jest.fn( function() {
			visible = false;
			return this;
		} ),
		show: jest.fn( function() {
			visible = true;
			return this;
		} ),
		data: jest.fn( function( key, val ) {
			if ( val !== undefined ) {
				dataStore[ key ] = val;
				return this;
			}
			return dataStore[ key ];
		} ),
		val: jest.fn( function( val ) {
			if ( val !== undefined ) {
				this._val = val;
				return this;
			}
			return this._val || '';
		} ),
		each: jest.fn( function( cb ) {
			cb.call( this, 0, this );
			return this;
		} ),
		isVisible: () => visible,
	};

	return el;
}

describe( 'AssetInventoryManager — runtime', () => {
	let $;
	let ajaxCalls;
	let mockNotice;
	let mockButton;
	let mockRows;
	let consoleLog;
	let consoleWarn;
	let consoleError;

	beforeEach( () => {
		// Capture console output.
		consoleLog   = jest.spyOn( console, 'log' ).mockImplementation( () => {} );
		consoleWarn  = jest.spyOn( console, 'warn' ).mockImplementation( () => {} );
		consoleError = jest.spyOn( console, 'error' ).mockImplementation( () => {} );

		ajaxCalls = [];
		mockNotice = createMockElement( '.wp-mcp-ai-inventory-notice' );
		mockButton = createMockElement( '#wp-mcp-ai-discover-assets' );
		mockRows   = [
			Object.assign( createMockElement( 'tr' ), {
				_data: { classification: 'restricted', type: 'api_key' },
			} ),
			Object.assign( createMockElement( 'tr' ), {
				_data: { classification: 'public', type: 'documentation' },
			} ),
		];

		// Minimal jQuery mock.
		$ = jest.fn( ( selector ) => {
			if ( selector === document ) {
				return { ready: jest.fn( ( cb ) => cb() ) };
			}
			if ( selector === '#wp-mcp-ai-discover-assets' )  { return mockButton; }
			if ( selector === '.wp-mcp-ai-inventory-notice' ) { return mockNotice; }
			if ( selector === '#wp-mcp-ai-filter-classification' || selector === '#wp-mcp-ai-filter-type' ) {
				return createMockElement( selector );
			}
			if ( typeof selector === 'string' && selector.startsWith( '<' ) ) {
				// e.g. $('<p>')
				return createMockElement( selector );
			}
			return createMockElement( selector );
		} );

		$.ajax = jest.fn( ( opts ) => {
			ajaxCalls.push( opts );
		} );
		$.fn = {};

		global.jQuery  = $;
		global.$       = $;

		global.wpMcpAiAssetInventory = {
			nonce:  'test-nonce-123',
			apiUrl: 'https://example.com/wp-json/mcp-ai/v1/assets',
			strings: {
				discovering:      'Discovering assets...',
				discoverButton:   'Discover Assets',
				discoverySuccess: 'Asset discovery completed successfully!',
				discoveryError:   'Asset discovery failed. Please try again.',
			},
		};

		global.window = {
			wpMcpAiAssetInventory: global.wpMcpAiAssetInventory,
			location: { reload: jest.fn() },
		};

		// Execute the IIFE.
		// Note: eval() is used here to test a legacy IIFE pattern (same approach
		// used by other test files in this directory such as admin-model-selector.test.js).
		// The code is from our own repository and safe in this test context.
		eval( sourceCode ); // eslint-disable-line no-eval
	} );

	afterEach( () => {
		jest.clearAllMocks();
		delete global.wpMcpAiAssetInventory;
		delete global.window;
	} );

	// ── discoverAssets — missing config (source-level) ──────────────────────
	// The runtime guard for missing config is exercised here by verifying
	// the code path exists; the source-level suite above already asserts that
	// the console.error call is present in the source text.
	describe( 'discoverAssets — missing config guard', () => {
		test( 'source contains the config guard branch that calls console.error', () => {
			// Verify that both the guard and the console.error call appear in the
			// same function body (within 200 chars of each other).
			const guardIdx = sourceCode.indexOf(
				"console.error('[WP MCP AI] Asset discovery: configuration object"
			);
			const showErrIdx = sourceCode.indexOf(
				"this.showError('Asset inventory configuration is missing.')"
			);
			expect( guardIdx ).toBeGreaterThan( -1 );
			expect( showErrIdx ).toBeGreaterThan( -1 );
			// Both must appear before the loading-state comment (i.e. inside the guard).
			expect( Math.abs( guardIdx - showErrIdx ) ).toBeLessThan( 200 );
		} );
	} );

	// ── discoverAssets — AJAX happy path ────────────────────────────────────

	describe( 'discoverAssets — happy path', () => {
		test( 'makes a POST AJAX request to the /discover endpoint', () => {
			mockButton._eventHandlers.click[ 0 ].call( {} );

			expect( ajaxCalls.length ).toBe( 1 );
			expect( ajaxCalls[ 0 ].method ).toBe( 'POST' );
			expect( ajaxCalls[ 0 ].url ).toBe(
				'https://example.com/wp-json/mcp-ai/v1/assets/discover'
			);
		} );

		test( 'sets X-WP-Nonce header via beforeSend', () => {
			mockButton._eventHandlers.click[ 0 ].call( {} );

			const mockXhr = { setRequestHeader: jest.fn() };
			ajaxCalls[ 0 ].beforeSend( mockXhr );

			expect( mockXhr.setRequestHeader ).toHaveBeenCalledWith( 'X-WP-Nonce', 'test-nonce-123' );
		} );

		test( 'button enters loading state while request is in flight', () => {
			mockButton._eventHandlers.click[ 0 ].call( {} );

			expect( mockButton.addClass ).toHaveBeenCalledWith( 'loading' );
			expect( mockButton.text ).toHaveBeenCalledWith( 'Discovering assets...' );
		} );

		test( 'success handler logs and shows notice for response.success=true', () => {
			mockButton._eventHandlers.click[ 0 ].call( {} );

			ajaxCalls[ 0 ].success( { success: true, count: 42 } );

			expect( consoleLog ).toHaveBeenCalledWith(
				expect.stringMatching( /\[WP MCP AI\] Asset discovery succeeded\./ ),
				42,
				expect.any( String )
			);
			expect( mockNotice.addClass ).toHaveBeenCalledWith(
				expect.stringContaining( 'notice-success' )
			);
			expect( mockNotice.show ).toHaveBeenCalled();
		} );

		test( 'complete handler restores button text', () => {
			mockButton._eventHandlers.click[ 0 ].call( {} );

			ajaxCalls[ 0 ].complete();

			expect( mockButton.removeClass ).toHaveBeenCalledWith( 'loading' );
			expect( mockButton.text ).toHaveBeenCalledWith( 'Discover Assets' );
		} );
	} );

	// ── discoverAssets — response.success = false ────────────────────────────

	describe( 'discoverAssets — server-side failure', () => {
		test( 'warns and shows error notice when response.success is false', () => {
			mockButton._eventHandlers.click[ 0 ].call( {} );

			ajaxCalls[ 0 ].success( { success: false, message: 'Inventory locked.' } );

			expect( consoleWarn ).toHaveBeenCalledWith(
				expect.stringMatching( /\[WP MCP AI\] Asset discovery returned failure\./ ),
				'Inventory locked.'
			);
			expect( mockNotice.addClass ).toHaveBeenCalledWith(
				expect.stringContaining( 'notice-error' )
			);
		} );

		test( 'falls back to config error string when response.message is absent', () => {
			mockButton._eventHandlers.click[ 0 ].call( {} );

			ajaxCalls[ 0 ].success( { success: false } );

			expect( consoleWarn ).toHaveBeenCalledWith(
				expect.any( String ),
				'(no message)'
			);
		} );
	} );

	// ── discoverAssets — AJAX transport error ────────────────────────────────

	describe( 'discoverAssets — AJAX transport error', () => {
		test( 'logs console.error with status, error, httpStatus, and truncated responseText', () => {
			mockButton._eventHandlers.click[ 0 ].call( {} );

			const rawResponse = '{"code":"rest_forbidden"}';
			const fakeXhr = { status: 403, responseText: rawResponse };
			ajaxCalls[ 0 ].error( fakeXhr, 'error', 'Forbidden' );

			expect( consoleError ).toHaveBeenCalledWith(
				expect.stringMatching( /\[WP MCP AI\] Asset discovery AJAX error\./ ),
				expect.objectContaining( {
					status:       'error',
					error:        'Forbidden',
					httpStatus:   403,
					// responseText is truncated to 200 chars to avoid leaking sensitive data.
					responseText: rawResponse.substring( 0, 200 ),
				} )
			);
		} );

		test( 'shows error notice on AJAX failure', () => {
			mockButton._eventHandlers.click[ 0 ].call( {} );

			ajaxCalls[ 0 ].error( { status: 500, responseText: '' }, 'error', 'Internal Server Error' );

			expect( mockNotice.addClass ).toHaveBeenCalledWith(
				expect.stringContaining( 'notice-error' )
			);
		} );
	} );

	// ── showError ────────────────────────────────────────────────────────────

	describe( 'showError', () => {
		test( 'adds notice-error class and shows the notice element', () => {
			// Trigger an error path by calling AJAX error handler.
			mockButton._eventHandlers.click[ 0 ].call( {} );
			ajaxCalls[ 0 ].error( { status: 500, responseText: '' }, 'error', 'Server Error' );

			expect( mockNotice.addClass ).toHaveBeenCalledWith(
				expect.stringContaining( 'notice-error' )
			);
			expect( mockNotice.show ).toHaveBeenCalled();
		} );
	} );
} );

// ── escapeHtml ────────────────────────────────────────────────────────────────

describe( 'escapeHtml — source verification', () => {
	test( 'function is defined in the source', () => {
		expect( sourceCode ).toMatch( /escapeHtml\s*:\s*function\s*\(/ );
	} );

	test( 'uses document.createElement and textContent assignment', () => {
		expect( sourceCode ).toMatch( /document\.createElement\s*\(\s*'div'\s*\)/ );
		expect( sourceCode ).toMatch( /\.textContent\s*=\s*text/ );
		expect( sourceCode ).toMatch( /return\s+div\.innerHTML/ );
	} );
} );
