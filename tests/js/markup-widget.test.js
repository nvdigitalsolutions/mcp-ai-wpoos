/**
 * Tests for the markup widget module (DOM scaffolding only — Konva is
 * not loaded so the inline canvas path falls through to the fallback,
 * which is the documented behaviour for hosts that do not vendor
 * Konva).
 *
 * @package WP_MCP_AI
 */

require( '../../assets/js/markup/markup-fallback.js' );
require( '../../assets/js/markup/markup-export.js' );
require( '../../assets/js/markup/markup-widget.js' );
require( '../../assets/js/markup/markup-client.js' );

const widget = global.window.WPMcpAiMarkupWidget;
const client = global.window.WPMcpAiMarkupClient;

describe( 'Markup Widget — _internal helpers', () => {
	it( 'fitDimension preserves aspect ratio and never up-scales', () => {
		const fit = widget._internal.fitDimension( 1000, 500, 400, 400 );
		expect( fit.width ).toBe( 400 );
		expect( fit.height ).toBe( 200 );
		expect( fit.scale ).toBeCloseTo( 0.4 );
	} );

	it( 'fitDimension never enlarges small images', () => {
		const fit = widget._internal.fitDimension( 100, 100, 800, 800 );
		expect( fit.width ).toBe( 100 );
		expect( fit.height ).toBe( 100 );
		expect( fit.scale ).toBe( 1 );
	} );

	it( 'imageModes filters down to mask + polygon when mode=mask', () => {
		const modes = widget._internal.imageModes( 'mask', {} );
		expect( modes.map( ( m ) => m.id ).sort() ).toEqual( [ 'mask', 'polygon' ] );
	} );

	it( 'imageModes returns the full set for unknown modes', () => {
		const modes = widget._internal.imageModes( 'something_else', {} );
		expect( modes.length ).toBeGreaterThanOrEqual( 5 );
	} );

	it( 'styleForKind returns a redaction palette for redact shapes', () => {
		const style = widget._internal.styleForKind( 'redact' );
		expect( style.fill ).toBe( 'rgba(0,0,0,0.85)' );
	} );
} );

describe( 'Markup Widget — render fallback path', () => {
	let host;

	beforeEach( () => {
		host = document.createElement( 'div' );
		document.body.appendChild( host );
		// Konva is intentionally not loaded so the fallback path activates.
		delete global.window.Konva;
	} );

	afterEach( () => {
		host.remove();
	} );

	it( 'renders the URL-mode fallback when Konva is not present', async () => {
		await widget.render( host, {
			request_id: 'rq_a',
			target_type: 'image_url',
			mode: 'mask',
			target: { url: 'http://example.com/img.png' },
			fallback_url: 'http://example.com/edit',
			submit_url: 'http://example.com/submit',
		}, {} );

		expect( host.querySelector( '.wp-mcp-ai-markup-fallback' ) ).not.toBeNull();
	} );

	it( 'renders the fallback for document_pdf targets', async () => {
		await widget.render( host, {
			request_id: 'rq_pdf',
			target_type: 'document_pdf',
			mode: 'annotate',
			target: { url: 'http://example.com/doc.pdf' },
			fallback_url: 'http://example.com/edit',
			submit_url: 'http://example.com/submit',
		}, {} );

		expect( host.querySelector( '.wp-mcp-ai-markup-fallback' ) ).not.toBeNull();
	} );
} );

describe( 'Markup Client — handleToolResult', () => {
	let host;

	beforeEach( () => {
		host = document.createElement( 'div' );
		host.setAttribute( 'data-markup-host', 'rq_evt' );
		document.body.appendChild( host );
	} );

	afterEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'returns false for non-markup tool results', () => {
		expect( client.handleToolResult( { type: 'text', value: 'hi' } ) ).toBe( false );
		expect( client.handleToolResult( null ) ).toBe( false );
		expect( client.handleToolResult( { type: 'markup_elicitation' } ) ).toBe( false );
	} );

	it( 'renders into a host matching data-markup-host=<request_id>', () => {
		const handled = client.handleToolResult( {
			type: 'markup_elicitation',
			request_id: 'rq_evt',
			target_type: 'image_url',
			mode: 'mask',
			target: { url: 'http://example.com/x.png' },
			fallback_url: 'http://example.com/fb',
			submit_url: 'http://example.com/submit',
		} );
		expect( handled ).toBe( true );
		expect( host.querySelector( '.wp-mcp-ai-markup-fallback' ) ).not.toBeNull();
	} );

	it( 'reacts to the wp-mcp-ai-chat:tool-result custom event', () => {
		document.dispatchEvent( new CustomEvent( 'wp-mcp-ai-chat:tool-result', {
			detail: {
				host: host,
				result: {
					type: 'markup_elicitation',
					request_id: 'rq_evt',
					target_type: 'image_url',
					mode: 'mask',
					target: { url: 'http://example.com/x.png' },
					fallback_url: 'http://example.com/fb',
					submit_url: 'http://example.com/submit',
				},
			},
		} ) );
		expect( host.querySelector( '.wp-mcp-ai-markup-fallback' ) ).not.toBeNull();
	} );
} );
