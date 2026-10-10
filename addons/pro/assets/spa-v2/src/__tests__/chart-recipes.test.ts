/**
 * Chart Recipes — spa-v2 unit tests.
 *
 * Covers the `nvoos-chart` fence splitter, schema validation and the
 * render-from-JSON HTML/CSS chart renderer (chartRecipes.ts).
 */

import { describe, it, expect } from 'vitest';

import {
	splitChartSegments,
	parseChartSpec,
	renderChartRecipe,
} from '../components/shared/chartRecipes';

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
		if ( ! parsed.ok ) return;
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
		if ( ! parsed.ok ) return;
		expect( parsed.spec.series ).toHaveLength( 2 );
		expect( parsed.spec.series[ 0 ].color ).toBe( '#ff0000' );
		expect( parsed.spec.series[ 1 ].color ).toBeNull();
	} );

	it( 'coerces numeric strings and drops non-finite entries', () => {
		const parsed = parseChartSpec( '{"type":"bar","values":["12",null,"oops","NaN",7]}' );
		expect( parsed.ok ).toBe( true );
		if ( ! parsed.ok ) return;
		expect( parsed.spec.series[ 0 ].values ).toEqual( [ 12, 7 ] );
	} );

	it( 'rejects invalid JSON and empty input', () => {
		expect( parseChartSpec( 'not json' ).ok ).toBe( false );
		expect( parseChartSpec( '' ).ok ).toBe( false );
	} );

	it( 'rejects an unknown chart type', () => {
		expect( parseChartSpec( '{"type":"pie","values":[1]}' ).ok ).toBe( false );
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
		if ( ! parsed.ok ) return;
		expect( parsed.spec.series[ 0 ].values ).toHaveLength( 120 );
		expect( parsed.spec.labels ).toHaveLength( 40 );
	} );

	it( 'clamps negative donut values to zero', () => {
		const parsed = parseChartSpec( '{"type":"donut","values":[5,-2,3]}' );
		expect( parsed.ok ).toBe( true );
		if ( ! parsed.ok ) return;
		expect( parsed.spec.series[ 0 ].values ).toEqual( [ 5, 0, 3 ] );
	} );
} );

describe( 'renderChartRecipe', () => {
	it( 'renders a bar chart with role, title and safe text', () => {
		const html = renderChartRecipe(
			'{"type":"bar","title":"Sales","labels":["A","B"],"values":[1,4]}',
			{ classPrefix: 'nvoos-pro-spa-chart-recipe', codeBlockClass: 'nvoos-pro-spa-code-block' }
		);
		expect( html ).toContain( 'role="figure"' );
		expect( html ).toContain( 'aria-label="Sales"' );
		expect( html ).toContain( 'nvoos-pro-spa-chart-recipe-body--bar' );
		expect( html ).toContain( '>Sales</div>' );
		expect( html ).toContain( '>A</div>' );
	} );

	it( 'falls back to an escaped code block for invalid fences', () => {
		const html = renderChartRecipe(
			'{"type":"pie","values":[1]}<script>alert(1)</script>',
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
		expect( html ).toContain( '&lt;img' );
		expect( html ).toContain( '&lt;script&gt;' );
		const doc = new DOMParser().parseFromString( html, 'text/html' );
		expect( doc.querySelectorAll( 'img, script' ) ).toHaveLength( 0 );
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

	it( 'renders negative bars with the negative modifier', () => {
		const html = renderChartRecipe(
			'{"type":"bar","values":[-2,3]}',
			{ classPrefix: 'p', codeBlockClass: 'cb' }
		);
		expect( html ).toContain( 'p-bar p-bar--negative' );
	} );
} );
