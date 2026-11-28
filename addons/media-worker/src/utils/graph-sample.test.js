// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Tests for the graph sampler and the POST /api/math/graph contract.
 *
 * The sampler is where the interesting decisions live, and it is deliberately
 * free of canvas so it can be tested on any machine: where a plot has gaps,
 * what the automatic y window is, and when a request has produced too little
 * to draw. The route tests below cover the refusals that happen before any
 * rendering is attempted, so they also run without the native binding.
 *
 * The happy path needs chartjs-node-canvas, which is optional; it is exercised
 * by the deployment smoke test rather than here, so a runner without the
 * binding still gets a green suite instead of a skip-everything file.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import * as mathjs from 'mathjs';
import {
	boolValue,
	clampInt,
	graphConfig,
	normaliseHex,
	rangeProblem,
	sampleFunction,
} from './graph-sample.js';
import { mathRouter } from '../routes/math.js';

/**
 * Boot the math router on an ephemeral port and return its base URL.
 *
 * @return {Promise<{ url: string, close: () => Promise<void> }>}
 */
async function serve() {
	const app = express();
	app.use( express.json() );
	app.use( '/api/math', mathRouter );

	const server = app.listen( 0 );
	await new Promise( ( resolve ) => server.once( 'listening', resolve ) );

	const { port } = server.address();

	return {
		url: `http://127.0.0.1:${ port }/api/math`,
		close: () => new Promise( ( resolve ) => server.close( resolve ) ),
	};
}

/** Compile an expression the way the route does. */
function compile( expression ) {
	return mathjs.compile( expression );
}

test( 'a polynomial is sampled across the whole window', () => {
	const { points, finite, yRange } = sampleFunction( compile( 'x^2' ), {
		xMin: -2,
		xMax: 2,
		samples: 5,
	} );

	assert.equal( points.length, 5 );
	assert.equal( finite, 5 );

	// The window is pinned: the first point is xMin and the last is xMax.
	assert.equal( points[ 0 ].x, -2 );
	assert.equal( points[ 4 ].x, 2 );
	assert.equal( points[ 2 ].y, 0 );

	// 0..4 padded by 10% of the range (4) gives -0.4..4.4.
	assert.ok( yRange[ 0 ] < 0 );
	assert.ok( yRange[ 1 ] > 4 );
} );

test( 'a pole is a gap, not a failed request', () => {
	// 1/x over a window whose midpoint is exactly zero: the centre sample is
	// undefined, the rest are not.
	const { points, finite, yRange } = sampleFunction( compile( '1/x' ), {
		xMin: -1,
		xMax: 1,
		samples: 3,
	} );

	assert.equal( points.length, 3 );
	assert.equal( finite, 2 );
	assert.equal( points[ 1 ].y, null );
	assert.ok( null !== yRange );
} );

test( 'a constant function gets a non-degenerate axis', () => {
	const { yRange } = sampleFunction( compile( '7' ), {
		xMin: 0,
		xMax: 10,
		samples: 10,
	} );

	assert.deepEqual( yRange, [ 6, 8 ] );
} );

test( 'an explicit y window is used unchanged', () => {
	const { yRange } = sampleFunction( compile( 'x' ), {
		xMin: 0,
		xMax: 1,
		samples: 10,
		yMin: -5,
		yMax: 5,
	} );

	assert.deepEqual( yRange, [ -5, 5 ] );
} );

test( 'a window with no real values refuses to plot', () => {
	// sqrt of a negative is complex; a real-axis plot has nothing to draw.
	const { finite, yRange } = sampleFunction( compile( 'sqrt(x)' ), {
		xMin: -10,
		xMax: -1,
		samples: 50,
	} );

	assert.equal( finite, 0 );
	assert.equal( yRange, null );
} );

test( 'an unknown symbol surfaces its own message', () => {
	const { finite, yRange, firstError } = sampleFunction( compile( '2a + 1' ), {
		xMin: 0,
		xMax: 1,
		samples: 50,
	} );

	assert.equal( finite, 0 );
	assert.equal( yRange, null );
	assert.match( String( firstError ), /Undefined symbol a/i );
} );

test( 'rangeProblem rejects shapes a scale cannot take', () => {
	assert.equal( rangeProblem( [ 0, 1 ], 'x_range' ), null );
	assert.match( String( rangeProblem( [ 1 ], 'x_range' ) ), /must be \[min, max\]/ );
	assert.match( String( rangeProblem( [ 1, 0 ], 'x_range' ) ), /max greater than min/ );
	assert.match( String( rangeProblem( [ 'a', 1 ], 'x_range' ) ), /finite numbers/ );
	assert.match( String( rangeProblem( [ 1, 1 ], 'x_range' ) ), /max greater than min/ );
} );

test( 'clampInt holds the documented bounds', () => {
	assert.equal( clampInt( 300, 400, 2000, 800 ), 400 );
	assert.equal( clampInt( 9000, 400, 2000, 800 ), 2000 );
	assert.equal( clampInt( undefined, 400, 2000, 800 ), 800 );
	assert.equal( clampInt( 640, 400, 2000, 800 ), 640 );
} );

test( 'normaliseHex expands shorthand and rejects non-hex', () => {
	assert.equal( normaliseHex( '#abc' ), '#aabbcc' );
	assert.equal( normaliseHex( '#AABBCC' ), '#AABBCC' );
	assert.equal( normaliseHex( 'red' ), null );
	assert.equal( normaliseHex( '#12345' ), null );
	assert.equal( normaliseHex( 42 ), null );
} );

test( "boolValue does not treat the string 'false' as true", () => {
	assert.equal( boolValue( 'false', true ), false );
	assert.equal( boolValue( '0', true ), false );
	assert.equal( boolValue( 'true', false ), true );
	assert.equal( boolValue( false, true ), false );
	assert.equal( boolValue( undefined, true ), true );
} );

test( 'the Chart.js config keeps a numeric x axis and breaks at gaps', () => {
	const points = [ { x: 0, y: 1 }, { x: 1, y: null }, { x: 2, y: 3 } ];
	const config = graphConfig( {
		expression: '1/x',
		points,
		xRange: [ 0, 2 ],
		yRange: [ -1, 4 ],
		title: 'test',
		showGrid: false,
		lineColor: '#112233',
	} );

	assert.equal( config.type, 'line' );
	assert.equal( config.options.parsing, false, 'a category axis would rescale the plot' );
	assert.equal( config.options.scales.x.type, 'linear' );
	assert.deepEqual( [ config.options.scales.x.min, config.options.scales.x.max ], [ 0, 2 ] );
	assert.deepEqual( [ config.options.scales.y.min, config.options.scales.y.max ], [ -1, 4 ] );
	assert.equal( config.options.scales.y.grid.display, false );
	assert.equal( config.data.datasets[ 0 ].spanGaps, false );
	assert.equal( config.data.datasets[ 0 ].data[ 1 ].y, null, 'the gap must survive into the config' );
	assert.equal( config.data.datasets[ 0 ].borderColor, '#112233' );
	assert.equal( config.options.plugins.title.display, true );
	assert.equal( config.options.plugins.legend.display, false );
} );

test( 'an empty title is not displayed', () => {
	const config = graphConfig( {
		expression: 'x',
		points: [ { x: 0, y: 0 } ],
		xRange: [ 0, 1 ],
		yRange: [ 0, 1 ],
		title: '',
		showGrid: true,
		lineColor: '#000000',
	} );

	assert.equal( config.options.plugins.title.display, false );
} );

test( 'POST /graph refuses a missing expression before rendering', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/graph`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { x_range: [ -1, 1 ] } ),
		} );

		assert.equal( res.status, 400 );
		assert.equal( ( await res.json() ).error, 'Missing expression' );
	} finally {
		await app.close();
	}
} );

test( 'POST /graph refuses a malformed x_range', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/graph`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { expression: 'x^2', x_range: [ 5, 1 ] } ),
		} );

		assert.equal( res.status, 400 );
		assert.match( ( await res.json() ).error, /max greater than min/ );
	} finally {
		await app.close();
	}
} );

test( 'POST /graph refuses an inverted y_range', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/graph`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { expression: 'x', x_range: [ 0, 1 ], y_range: [ 9, 3 ] } ),
		} );

		assert.equal( res.status, 400 );
		assert.match( ( await res.json() ).error, /y_range/ );
	} finally {
		await app.close();
	}
} );

test( 'POST /graph reports an unknown symbol as a 400, not an empty plot', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/graph`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { expression: '2a + 1', x_range: [ 0, 1 ] } ),
		} );

		assert.equal( res.status, 400 );
		assert.match( String( ( await res.json() ).error ), /Undefined symbol a/i );
	} finally {
		await app.close();
	}
} );
