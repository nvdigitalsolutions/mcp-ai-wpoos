/**
 * Tests for the markup fallback module.
 *
 * @package WP_MCP_AI
 */

require( '../../assets/js/markup/markup-fallback.js' );

const fb = global.window.WPMcpAiMarkupFallback;

describe( 'Markup Fallback — canRenderInline', () => {
	afterEach( () => {
		delete global.window.Konva;
		document.body.classList.remove( 'wp-mcp-ai-markup-disabled' );
	} );

	it( 'returns false when Konva is missing', () => {
		expect( fb.canRenderInline() ).toBe( false );
	} );

	it( 'returns true when Konva is loaded', () => {
		global.window.Konva = { Stage: function () {} };
		// jsdom does not implement canvas.getContext; stub it for this test.
		const proto = global.window.HTMLCanvasElement.prototype;
		const original = proto.getContext;
		proto.getContext = jest.fn( () => ( { /* fake 2d context */ } ) );
		try {
			expect( fb.canRenderInline() ).toBe( true );
		} finally {
			proto.getContext = original;
		}
	} );

	it( 'respects the wp-mcp-ai-markup-disabled body class opt-out', () => {
		global.window.Konva = { Stage: function () {} };
		document.body.classList.add( 'wp-mcp-ai-markup-disabled' );
		expect( fb.canRenderInline() ).toBe( false );
	} );
} );

describe( 'Markup Fallback — build', () => {
	it( 'builds an accessible group with the open + cancel controls', () => {
		const el = fb.build( {
			request_id: 'rq_test',
			instructions: 'Please mask the dog',
			fallback_url: 'http://example.com/edit',
		}, {
			fallbackTitle: 'Open in editor',
			openInTab: 'Open',
			cancel: 'Skip',
		} );

		expect( el.getAttribute( 'role' ) ).toBe( 'group' );
		expect( el.getAttribute( 'aria-label' ) ).toBe( 'Open in editor' );

		const link = el.querySelector( '.wp-mcp-ai-markup-fallback__open' );
		expect( link.getAttribute( 'href' ) ).toBe( 'http://example.com/edit' );
		expect( link.getAttribute( 'target' ) ).toBe( '_blank' );
		expect( link.getAttribute( 'rel' ) ).toBe( 'noopener noreferrer' );
		expect( link.textContent ).toBe( 'Open' );

		const cancel = el.querySelector( '[data-markup-cancel]' );
		expect( cancel.tagName.toLowerCase() ).toBe( 'button' );
		expect( cancel.textContent ).toBe( 'Skip' );

		const instructions = el.querySelector( '.wp-mcp-ai-markup-fallback__instructions' );
		expect( instructions.textContent ).toBe( 'Please mask the dog' );
	} );

	it( 'omits the instructions paragraph when no instructions are provided', () => {
		const el = fb.build( { request_id: 'rq', fallback_url: '#' }, {} );
		expect( el.querySelector( '.wp-mcp-ai-markup-fallback__instructions' ) ).toBeNull();
	} );
} );
