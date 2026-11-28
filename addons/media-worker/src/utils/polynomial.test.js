// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Tests for the polynomial solver and POST /api/math/solve.
 *
 * The case this file exists for is `3x^2 + 2 = 5`. The WordPress fallback
 * matched it with a linear regex, ignored the exponent, and answered `x = 1`
 * under a success envelope. Everything else here pins the boundary: a
 * non-polynomial is refused, an identity reports infinitely many solutions, a
 * constant contradiction reports none, and roots come back tidied rather than
 * as floating-point noise.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import * as mathjs from 'mathjs';
import { rootText, solveEquation } from './polynomial.js';
import { mathRouter } from '../routes/math.js';

/** Real parts, sorted, for order-independent comparison. */
function reals( solutions ) {
	return solutions.map( ( s ) => ( 'number' === typeof s ? s : s.re ) ).sort( ( a, b ) => a - b );
}

/** Boot the math router on an ephemeral port. */
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

test( 'a linear equation is solved exactly', () => {
	const result = solveEquation( mathjs, '2x + 5', '15', 'x' );

	assert.deepEqual( result.solutions, [ 5 ] );
	assert.equal( result.degree, 1 );
	assert.equal( result.infinite, false );
	assert.equal( result.problem, null );
} );

test( 'a bare variable has an implicit coefficient of one', () => {
	assert.deepEqual( solveEquation( mathjs, 'x - 3', '4', 'x' ).solutions, [ 7 ] );
	assert.deepEqual( solveEquation( mathjs, '-x + 3', '1', 'x' ).solutions, [ 2 ] );
} );

test( 'a quadratic returns both real roots', () => {
	const result = solveEquation( mathjs, 'x^2 - 4', '0', 'x' );

	assert.deepEqual( reals( result.solutions ), [ -2, 2 ] );
	assert.equal( result.degree, 2 );
} );

test( 'the exponent is not ignored as it was in WordPress', () => {
	// 3x^2 = 3  ->  x = ±1. The WordPress regex matched `3x` and answered 1.
	const result = solveEquation( mathjs, '3x^2 + 2', '5', 'x' );

	assert.deepEqual( reals( result.solutions ), [ -1, 1 ] );
	assert.equal( result.degree, 2 );
} );

test( 'a cubic returns three integer roots', () => {
	const result = solveEquation( mathjs, 'x^3 - 6x^2 + 11x - 6', '0', 'x' );

	assert.deepEqual( reals( result.solutions ), [ 1, 2, 3 ] );
	assert.equal( result.degree, 3 );
} );

test( 'a quartic goes through the companion matrix', () => {
	// polynomialRoot stops at cubics, so this is the eigs() path.
	const result = solveEquation( mathjs, 'x^4 - 5x^2 + 4', '0', 'x' );

	assert.deepEqual( reals( result.solutions ), [ -2, -1, 1, 2 ] );
	assert.equal( result.degree, 4 );
	assert.equal( result.solutions.length, 4 );
} );

test( 'a real root is a number, not an object with a zero imaginary part', () => {
	const result = solveEquation( mathjs, 'x^2 - 4', '0', 'x' );

	for ( const solution of result.solutions ) {
		assert.equal( typeof solution, 'number' );
	}
} );

test( 'complex roots keep their imaginary part', () => {
	const result = solveEquation( mathjs, 'x^2 + 1', '0', 'x' );

	assert.equal( result.solutions.length, 2 );
	assert.equal( result.solutions.every( ( s ) => 'object' === typeof s ), true );
	assert.deepEqual(
		result.solutions.map( ( s ) => s.im ).sort( ( a, b ) => a - b ),
		[ -1, 1 ]
	);
} );

test( 'a non-polynomial is refused rather than guessed at', () => {
	const result = solveEquation( mathjs, 'sin(x)', '0.5', 'x' );

	assert.equal( result.solutions.length, 0 );
	assert.match( String( result.problem ), /not a polynomial in x/ );
} );

test( 'a symbolic coefficient is refused as parametric', () => {
	const result = solveEquation( mathjs, '2x', 'a', 'x' );

	// rationalize reports both symbols, so this lands on the multi-variable path;
	// either message names the reason, and both refuse rather than guess.
	assert.match( String( result.problem ), /not a polynomial in x alone|coefficients that are not numbers/ );
} );

test( 'solving for the wrong variable is refused', () => {
	const result = solveEquation( mathjs, '2x', '4', 'y' );

	assert.match( String( result.problem ), /not a polynomial in y alone/ );
	assert.match( String( result.problem ), /found: x/ );
} );

test( 'an identity reports infinitely many solutions', () => {
	const result = solveEquation( mathjs, '2x + 1', '1 + 2x', 'x' );

	assert.equal( result.infinite, true );
	assert.deepEqual( result.solutions, [] );
	assert.equal( result.problem, null );
} );

test( 'a contradiction reports none', () => {
	const result = solveEquation( mathjs, '0', '5', 'x' );

	assert.equal( result.infinite, false );
	assert.deepEqual( result.solutions, [] );
	assert.equal( result.problem, null );
} );

test( 'irrational roots are not rounded to integers', () => {
	const result = solveEquation( mathjs, 'x^2 - 2', '0', 'x' );
	const values = reals( result.solutions );

	assert.equal( values.length, 2 );
	assert.ok( Math.abs( values[ 0 ] + Math.SQRT2 ) < 1e-9 );
	assert.ok( Math.abs( values[ 1 ] - Math.SQRT2 ) < 1e-9 );
} );

test( 'rootText renders real and complex roots', () => {
	assert.equal( rootText( 3 ), '3' );
	assert.equal( rootText( { re: 0, im: 2 } ), '0 + 2i' );
	assert.equal( rootText( { re: 1, im: -2 } ), '1 - 2i' );
} );

test( 'POST /solve refuses a missing side', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/solve`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { left: '2x + 5', variable: 'x' } ),
		} );

		assert.equal( res.status, 400 );
		assert.match( ( await res.json() ).error, /Missing right/ );
	} finally {
		await app.close();
	}
} );

test( 'POST /solve refuses a variable that is not an identifier', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/solve`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { left: '2x', right: '4', variable: 'x + 1' } ),
		} );

		assert.equal( res.status, 400 );
		assert.match( ( await res.json() ).error, /variable/i );
	} finally {
		await app.close();
	}
} );

test( 'POST /solve answers a solvable equation', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/solve`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { left: 'x^2 - 4', right: '0', variable: 'x' } ),
		} );
		const body = await res.json();

		assert.equal( res.status, 200 );
		assert.deepEqual( reals( body.solutions ), [ -2, 2 ] );
		assert.equal( body.degree, 2 );
		// Multiple roots use the WordPress text shape: `x1 = 2, x2 = -2`.
		assert.match( body.text, /x1 = / );
	} finally {
		await app.close();
	}
} );

test( 'POST /solve refuses a non-polynomial with 422', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/solve`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { left: 'sin(x)', right: '0.5', variable: 'x' } ),
		} );

		assert.equal( res.status, 422 );
		assert.match( ( await res.json() ).error, /not a polynomial/ );
	} finally {
		await app.close();
	}
} );
