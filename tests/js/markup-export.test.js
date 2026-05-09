/**
 * Tests for the markup export module.
 *
 * @package WP_MCP_AI
 */

require( '../../assets/js/markup/markup-export.js' );

const exporter = global.window.WPMcpAiMarkupExport;

describe( 'Markup Export — buildEnvelope', () => {
	it( 'wraps shapes in a W3C AnnotationCollection envelope', () => {
		const env = exporter.buildEnvelope( {
			requestId: 'rq_1',
			mode: 'mask',
			shapes: [
				{ kind: 'rect', x: 10, y: 20, w: 50, h: 30 },
			],
			dimension: { width: 200, height: 200 },
		} );
		expect( env.markup[ '@context' ] ).toBe( 'http://www.w3.org/ns/anno.jsonld' );
		expect( env.markup.type ).toBe( 'AnnotationCollection' );
		expect( env.markup.request_id ).toBe( 'rq_1' );
		expect( env.markup.mode ).toBe( 'mask' );
		expect( env.markup.dimension ).toEqual( { width: 200, height: 200 } );
		expect( env.markup.items ).toHaveLength( 1 );
		expect( env.markup.items[ 0 ].type ).toBe( 'Annotation' );
		expect( env.markup.items[ 0 ].target.selector.type ).toBe( 'FragmentSelector' );
		expect( env.markup.items[ 0 ].target.selector.value ).toBe( 'xywh=pixel:10,20,50,30' );
	} );

	it( 'caps the number of shapes at 64', () => {
		const shapes = [];
		for ( let i = 0; i < 100; i++ ) {
			shapes.push( { kind: 'rect', x: i, y: i, w: 5, h: 5 } );
		}
		const env = exporter.buildEnvelope( {
			requestId: 'rq_2',
			mode: 'region',
			shapes: shapes,
			dimension: { width: 500, height: 500 },
		} );
		expect( env.markup.items.length ).toBeLessThanOrEqual( 64 );
	} );

	it( 'emits an SvgSelector for polygon shapes', () => {
		const env = exporter.buildEnvelope( {
			requestId: 'rq_p',
			mode: 'mask',
			shapes: [
				{ kind: 'polygon', points: [
					{ x: 10, y: 10 }, { x: 100, y: 10 }, { x: 50, y: 80 },
				] },
			],
			dimension: { width: 200, height: 200 },
		} );
		const sel = env.markup.items[ 0 ].target.selector;
		expect( sel.type ).toBe( 'SvgSelector' );
		expect( sel.value ).toMatch( /^<svg /i );
		expect( sel.value ).toContain( '<polygon' );
		expect( sel.value ).toContain( '10,10 100,10 50,80' );
	} );

	it( 'rejects polygons with fewer than 3 points', () => {
		const env = exporter.buildEnvelope( {
			requestId: 'rq_p2',
			mode: 'mask',
			shapes: [
				{ kind: 'polygon', points: [ { x: 1, y: 1 }, { x: 2, y: 2 } ] },
			],
			dimension: { width: 200, height: 200 },
		} );
		expect( env.markup.items ).toHaveLength( 0 );
	} );

	it( 'emits a position vector body for arrow shapes', () => {
		const env = exporter.buildEnvelope( {
			requestId: 'rq_pos',
			mode: 'position',
			shapes: [
				{ kind: 'position', from: { x: 10, y: 20 }, to: { x: 100, y: 80 } },
			],
			dimension: { width: 200, height: 200 },
		} );
		const ann = env.markup.items[ 0 ];
		expect( ann.body.vector ).toEqual( {
			from: { x: 10, y: 20 }, to: { x: 100, y: 80 }, normalized: false,
		} );
		expect( ann.motivation ).toBe( 'editing' );
	} );

	it( 'sets motivation=redacting for redact rectangles', () => {
		const env = exporter.buildEnvelope( {
			requestId: 'rq_r',
			mode: 'redact',
			shapes: [ { kind: 'redact', x: 0, y: 0, w: 10, h: 10 } ],
			dimension: { width: 100, height: 100 },
		} );
		expect( env.markup.items[ 0 ].motivation ).toBe( 'redacting' );
	} );

	it( 'clamps coordinates to the source dimension', () => {
		const env = exporter.buildEnvelope( {
			requestId: 'rq_c',
			mode: 'region',
			shapes: [ { kind: 'rect', x: 1000, y: 1000, w: 1000, h: 1000 } ],
			dimension: { width: 100, height: 100 },
		} );
		const sel = env.markup.items[ 0 ].target.selector.value;
		expect( sel ).toBe( 'xywh=pixel:100,100,0,0' );
	} );

	it( 'rejects oversized SVG payloads', () => {
		// Build a polygon with thousands of points pushing over MAX_SVG_BYTES.
		const points = [];
		for ( let i = 0; i < 4000; i++ ) {
			points.push( { x: i, y: i } );
		}
		const env = exporter.buildEnvelope( {
			requestId: 'rq_big',
			mode: 'mask',
			shapes: [ { kind: 'polygon', points: points } ],
			dimension: { width: 5000, height: 5000 },
		} );
		expect( env.markup.items ).toHaveLength( 0 );
	} );
} );

describe( 'Markup Export — submitEnvelope', () => {
	beforeEach( () => {
		global.fetch = jest.fn();
	} );

	it( 'POSTs the envelope to the submit URL with auth headers', async () => {
		global.fetch.mockResolvedValue( {
			ok: true,
			json: () => Promise.resolve( { success: true } ),
		} );

		await exporter.submitEnvelope( '/api/markup/x/submit', { foo: 'bar' }, {
			nonce: 'NONCE',
			bearer: 'BEARER',
			guestToken: 'GUEST',
		} );

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		const call = global.fetch.mock.calls[ 0 ];
		expect( call[ 0 ] ).toBe( '/api/markup/x/submit' );
		expect( call[ 1 ].method ).toBe( 'POST' );
		expect( call[ 1 ].headers[ 'Content-Type' ] ).toBe( 'application/json' );
		expect( call[ 1 ].headers[ 'X-WP-Nonce' ] ).toBe( 'NONCE' );
		expect( call[ 1 ].headers.Authorization ).toBe( 'Bearer BEARER' );
		expect( call[ 1 ].headers[ 'X-WP-MCP-AI-Guest' ] ).toBe( 'GUEST' );
		expect( JSON.parse( call[ 1 ].body ) ).toEqual( { foo: 'bar' } );
	} );

	it( 'rejects when the server returns a non-OK status', async () => {
		global.fetch.mockResolvedValue( {
			ok: false,
			status: 422,
			json: () => Promise.resolve( { code: 'bad', message: 'invalid' } ),
		} );
		await expect(
			exporter.submitEnvelope( '/api/markup/x/submit', {}, {} )
		).rejects.toThrow( 'invalid' );
	} );
} );
