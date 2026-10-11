/**
 * Chat Chart Recipes Tests
 *
 * Covers the `nvoos-chart` fence splitter, schema validation and the
 * render-from-JSON HTML/CSS chart renderer used by the base chat surface.
 *
 * @package WP_MCP_AI
 */

const {
	splitChartSegments,
	parseChartSpec,
	renderChartRecipe,
} = require( '../../assets/js/chat-chart-recipes.js' );

const chartFixtures = require( './chart-fixtures.json' );

describe( 'splitChartSegments', () => {
	it( 'returns a single markdown segment for plain text', () => {
		const segments = splitChartSegments( 'hello **world**' );
		expect( segments ).toEqual( [ { type: 'markdown', text: 'hello **world**' } ] );
	} );

	it( 'splits a backtick chart fence out of surrounding markdown', () => {
		const text = 'Intro\n\n```nvoos-chart\n{"type":"bar","values":[1,2]}\n```\n\nOutro';
		const segments = splitChartSegments( text );
		expect( segments ).toHaveLength( 3 );
		expect( segments[ 0 ] ).toEqual( { type: 'markdown', text: 'Intro\n' } );
		expect( segments[ 1 ] ).toEqual( { type: 'chart', code: '{"type":"bar","values":[1,2]}' } );
		expect( segments[ 2 ] ).toEqual( { type: 'markdown', text: '\nOutro' } );
	} );

	it( 'splits a tilde chart fence', () => {
		const segments = splitChartSegments( '~~~nvoos-chart\n{"type":"donut","values":[3,1]}\n~~~' );
		expect( segments ).toEqual( [
			{ type: 'chart', code: '{"type":"donut","values":[3,1]}' },
		] );
	} );

	it( 'treats an unclosed fence as a chart segment with the remaining text', () => {
		const segments = splitChartSegments( '```nvoos-chart\n{"type":"bar"' );
		expect( segments ).toEqual( [ { type: 'chart', code: '{"type":"bar"' } ] );
	} );

	it( 'leaves an nvoos-chart fence inside a plain code fence untouched', () => {
		const text = '```\nExample:\n```nvoos-chart\n{"type":"bar"}\n```\n```';
		const segments = splitChartSegments( text );
		expect( segments ).toHaveLength( 1 );
		expect( segments[ 0 ].type ).toBe( 'markdown' );
	} );

	it( 'ignores fences with other language tags', () => {
		const segments = splitChartSegments( '```json\n{"type":"bar"}\n```' );
		expect( segments ).toEqual( [
			{ type: 'markdown', text: '```json\n{"type":"bar"}\n```' },
		] );
	} );
} );

describe( 'parseChartSpec', () => {
	it( 'parses the values shorthand into a single unnamed series', () => {
		const parsed = parseChartSpec( '{"type":"bar","values":[1,2,3],"labels":["a","b","c"]}' );
		expect( parsed.ok ).toBe( true );
		expect( parsed.spec.type ).toBe( 'bar' );
		expect( parsed.spec.series ).toHaveLength( 1 );
		expect( parsed.spec.series[ 0 ].values ).toEqual( [ 1, 2, 3 ] );
		expect( parsed.spec.labels ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'parses multi-series data and keeps only valid colours', () => {
		const parsed = parseChartSpec(
			'{"type":"line","series":[' +
			'{"name":"A","values":[1,2],"color":"#ff0000"},' +
			'{"name":"B","values":[3,4],"color":"url(javascript:alert(1))"}]}'
		);
		expect( parsed.ok ).toBe( true );
		expect( parsed.spec.series ).toHaveLength( 2 );
		expect( parsed.spec.series[ 0 ].color ).toBe( '#ff0000' );
		expect( parsed.spec.series[ 1 ].color ).toBeNull();
	} );

	it( 'coerces numeric strings and drops non-finite entries', () => {
		const parsed = parseChartSpec( '{"type":"bar","values":["12",null,"oops","NaN",7]}' );
		expect( parsed.ok ).toBe( true );
		expect( parsed.spec.series[ 0 ].values ).toEqual( [ 12, 7 ] );
	} );

	it( 'rejects invalid JSON', () => {
		expect( parseChartSpec( 'not json' ).ok ).toBe( false );
		expect( parseChartSpec( '' ).ok ).toBe( false );
	} );

	it( 'rejects an unknown chart type', () => {
		expect( parseChartSpec( '{"type":"bogus","values":[1]}' ).ok ).toBe( false );
	} );

	it( 'rejects specs with no usable data', () => {
		expect( parseChartSpec( '{"type":"bar"}' ).ok ).toBe( false );
		expect( parseChartSpec( '{"type":"bar","values":[]}' ).ok ).toBe( false );
		expect( parseChartSpec( '{"type":"bar","values":[null,null]}' ).ok ).toBe( false );
	} );

	it( 'caps labels, series and points', () => {
		const longValues = JSON.stringify( Array.from( { length: 300 }, ( _, i ) => i ) );
		const parsed = parseChartSpec(
			'{"type":"line","values":' + longValues +
			',"labels":' + longValues + '}'
		);
		expect( parsed.ok ).toBe( true );
		expect( parsed.spec.series[ 0 ].values ).toHaveLength( 120 );
		expect( parsed.spec.labels ).toHaveLength( 40 );
	} );

	it( 'clamps negative donut values to zero', () => {
		const parsed = parseChartSpec( '{"type":"donut","values":[5,-2,3]}' );
		expect( parsed.ok ).toBe( true );
		expect( parsed.spec.series[ 0 ].values ).toEqual( [ 5, 0, 3 ] );
	} );
} );

describe( 'renderChartRecipe', () => {
	it( 'renders a bar chart with role, title and safe text', () => {
		const html = renderChartRecipe(
			'{"type":"bar","title":"Sales","labels":["A","B"],"values":[1,4]}',
			{ classPrefix: 'wp-mcp-ai-chat__chart-recipe', codeBlockClass: 'wp-mcp-ai-chat__code-block' }
		);
		expect( html ).toContain( 'role="figure"' );
		expect( html ).toContain( 'aria-label="Sales"' );
		expect( html ).toContain( 'wp-mcp-ai-chat__chart-recipe-body--bar' );
		expect( html ).toContain( '>Sales</div>' );
		expect( html ).toContain( '>A</div>' );
	} );

	it( 'falls back to an escaped code block for invalid fences', () => {
		const html = renderChartRecipe(
			'{"type":"funnel","values":[1]}<script>alert(1)</script>',
			{ classPrefix: 'x', codeBlockClass: 'cb' }
		);
		expect( html ).toContain( 'class="cb"' );
		expect( html ).toContain( '&lt;script&gt;alert(1)&lt;/script&gt;' );
	} );

	it( 'neutralises XSS attempts in titles and labels', () => {
		const html = renderChartRecipe(
			'{"type":"bar","title":"<img src=x onerror=alert(1)>","labels":["<script>bad()</script>"],"values":[1]}',
			{ classPrefix: 'p', codeBlockClass: 'cb' }
		);
		// Title and label text is escaped (safe-by-construction textContent).
		expect( html ).toContain( '&lt;img' );
		expect( html ).toContain( '&lt;script&gt;' );
		// No live elements are produced by the payload.
		const doc = new window.DOMParser().parseFromString( html, 'text/html' );
		expect( doc.querySelectorAll( 'img, script' ) ).toHaveLength( 0 );
		// No event handler attributes survive anywhere.
		expect( doc.querySelectorAll( '[onerror], [onload], [onclick]' ) ).toHaveLength( 0 );
	} );

	it( 'renders a donut with a conic-gradient ring and a total', () => {
		const html = renderChartRecipe(
			'{"type":"donut","labels":["X","Y"],"values":[30,70]}',
			{ classPrefix: 'p', codeBlockClass: 'cb' }
		);
		expect( html ).toContain( 'conic-gradient(' );
		expect( html ).toContain( 'p-donut-total' );
		expect( html ).toContain( '>100</div>' );
} );

it( 'coerces data.items rows into a single series with labels', () => {
	const html = renderChartRecipe(
		'{"type":"donut","data":{"items":[{"label":"X","value":30},{"label":"Y","value":70}]}}',
		{ classPrefix: 'p', codeBlockClass: 'cb' }
	);
	expect( html ).toContain( 'conic-gradient(' );
	expect( html ).toContain( 'p-donut-legend-label' );
	expect( html ).toContain( '>X</span>' );
	expect( html ).toContain( '>Y</span>' );
} );

	it( 'renders a heatmap with a computed grid template', () => {
		const html = renderChartRecipe(
			'{"type":"heatmap","labels":["r1","r2"],"series":[{"name":"c1","values":[1,2]},{"name":"c2","values":[3,4]}]}',
			{ classPrefix: 'p', codeBlockClass: 'cb' }
		);
		expect( html ).toContain( 'auto repeat(2, minmax(0, 1fr))' );
		expect( html ).toContain( 'p-heat-col-label' );
	} );

	it( 'connects line points but leaves dot charts unconnected', () => {
		const line = renderChartRecipe(
			'{"type":"line","values":[1,2,3]}',
			{ classPrefix: 'p', codeBlockClass: 'cb' }
		);
		expect( line ).toContain( 'p-plot-seg' );

		const dot = renderChartRecipe(
			'{"type":"dot","values":[1,2,3]}',
			{ classPrefix: 'p', codeBlockClass: 'cb' }
		);
		expect( dot ).not.toContain( 'p-plot-seg' );
		expect( dot ).toContain( 'p-plot-dot' );
	} );

	it( 'left-anchors positive-only bars with no center axis', () => {
		const html = renderChartRecipe(
			'{"type":"bar","values":[1,4]}',
			{ classPrefix: 'p', codeBlockClass: 'cb' }
		);
		expect( html ).toContain( 'p-body p-body--bar' );
		expect( html ).not.toContain( 'p-body--bar-center' );
		const doc = new window.DOMParser().parseFromString( html, 'text/html' );
		const bar = doc.querySelector( '.p-bar' );
		expect( bar ).not.toBeNull();
		expect( bar.style.left ).toBe( '0%' );
		expect( parseFloat( bar.style.width ) ).toBeCloseTo( 25 );
	} );

	it( 'renders negative bars with the negative modifier', () => {
		const html = renderChartRecipe(
			'{"type":"bar","values":[-2,3]}',
			{ classPrefix: 'p', codeBlockClass: 'cb' }
		);
		expect( html ).toContain( 'p-body p-body--bar-center' );
		expect( html ).toContain( 'p-bar p-bar--negative' );
		const doc = new window.DOMParser().parseFromString( html, 'text/html' );
		const negative = doc.querySelector( '.p-bar--negative' );
		expect( negative ).not.toBeNull();
		expect( parseFloat( negative.style.left ) ).toBeCloseTo( 0 );
		expect( parseFloat( negative.style.width ) ).toBeCloseTo( 50 );
	} );

	it( 'renders a violin plot with mirrored KDE halves and a median dot', () => {
		const values = [ 1, 2, 2, 3, 3, 3, 4, 4, 5, 6 ];
		const html = renderChartRecipe(
			JSON.stringify( {
				type: 'violin',
				series: [
					{ name: 'Group A', values },
					{ name: 'Group B', values: values.map( ( v ) => v + 1 ) },
				],
			} ),
			{ classPrefix: 'p', codeBlockClass: 'cb' }
		);
		const doc = new window.DOMParser().parseFromString( html, 'text/html' );
		expect( doc.querySelectorAll( '.p-violin-item' ) ).toHaveLength( 2 );
		expect( doc.querySelectorAll( '.p-violin-half' ).length ).toBeGreaterThan( 20 );
		expect( doc.querySelectorAll( '.p-violin-dot' ) ).toHaveLength( 2 );
		expect( html ).toContain( 'p-track p-track--center' );
		const half = doc.querySelector( '.p-violin-half' );
		expect( parseFloat( half.style.width ) ).toBeGreaterThan( 0 );
		// The left half extends left of the centerline; both halves must
		// stay within the track (-50%..100% of the centerline).
		expect( parseFloat( half.style.left ) ).toBeGreaterThan( -60 );
		expect( parseFloat( half.style.left ) ).toBeLessThan( 100 );
	} );
} );

	describe( 'shared conformance fixtures', () => {
		chartFixtures.cases.forEach( ( fixture ) => {
			it( 'fixture: ' + fixture.name, () => {
				const html = renderChartRecipe( fixture.code, { classPrefix: 'f', codeBlockClass: 'f-code-block' } );
				const doc = new window.DOMParser().parseFromString( html, 'text/html' );
				fixture.checks.forEach( ( check ) => {
					if ( typeof check.count === 'number' ) {
						expect( doc.querySelectorAll( check.selector ) ).toHaveLength( check.count );
						return;
					}
					const node = doc.querySelector( check.selector );
					expect( node ).not.toBeNull();
					if ( check.property ) {
						const raw = node.style.getPropertyValue( check.property );
						if ( typeof check.number === 'number' ) {
							expect( parseFloat( raw ) ).toBeCloseTo( check.number, check.precision || 2 );
						} else {
							expect( raw ).toBe( check.expect );
						}
					}
				} );
			} );
		} );
	} );
