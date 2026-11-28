// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Tests for the integration module and POST /api/math/integral.
 *
 * The two tests that matter most are the ones nerdamer gets wrong: `sin(x^2)`
 * comes back unevaluated, and `e^(-x^2)` comes back malformed with `erf`
 * stripped of its argument. Both must be refusals, not answers. Everything
 * else pins the standard forms and the definite path, which needs no
 * verification because it either yields a number or it does not.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import * as nerdamerModule from 'nerdamer';
import * as calculus from 'nerdamer/calculus';
import { definiteIntegral, indefiniteIntegral } from './integrate.js';
import { mathRouter } from '../routes/math.js';

const nerdamer = nerdamerModule.default;

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

test( 'a polynomial antiderivative is produced and verified', () => {
	const result = indefiniteIntegral( nerdamer, calculus, 'x^2', 'x' );

	assert.equal( result.problem, null );
	assert.equal( result.verified, true );
	assert.equal( result.antiderivative, '(1/3)*x^3' );
	assert.ok( result.samples >= 3 );
	assert.match( result.tex, /frac/ );
} );

test( 'the standard forms integrate', () => {
	const forms = [
		[ 'sin(x)', '-cos(x)' ],
		[ 'cos(2x)', '(1/2)*sin(2*x)' ],
		[ '1/x', 'log(x)' ],
		[ '1/(1+x^2)', 'atan(x)' ],
	];

	for ( const [ integrand, expected ] of forms ) {
		const result = indefiniteIntegral( nerdamer, calculus, integrand, 'x' );

		assert.equal( result.problem, null, `${ integrand } should integrate` );
		assert.equal( result.antiderivative, expected );
	}
} );

test( 'an unevaluated integral is refused', () => {
	// nerdamer returns `integrate(sin(x^2), x)` unchanged.
	const result = indefiniteIntegral( nerdamer, calculus, 'sin(x^2)', 'x' );

	assert.equal( result.antiderivative, null );
	assert.match( String( result.problem ), /could not find an antiderivative/ );
} );

test( 'a malformed antiderivative is caught by verification', () => {
	// nerdamer returns `(1/2)*pi^(1/2)*erf*x`, which looks like an answer: the
	// erf has lost its argument. Differentiating gives a non-numeric symbol.
	const result = indefiniteIntegral( nerdamer, calculus, 'e^(-x^2)', 'x' );

	assert.equal( result.antiderivative, null );
	assert.equal( result.verified, false );
	assert.match( String( result.problem ), /not trusted/ );
	assert.match( String( result.problem ), /not a number/ );
} );

test( 'a definite integral is exact where the engine can be', () => {
	const result = definiteIntegral( nerdamer, calculus, 'sin(x)', 'x', 0, 'pi' );

	assert.equal( result.problem, null );
	assert.equal( result.value, 2 );
	assert.equal( result.exact, '2' );
} );

test( 'a definite integral is numeric where no antiderivative exists', () => {
	// No elementary antiderivative, and the value is still right.
	const result = definiteIntegral( nerdamer, calculus, 'e^(-x^2)', 'x', 0, 1 );

	assert.equal( result.problem, null );
	assert.ok( Math.abs( result.value - 0.746824132812427 ) < 1e-12 );
} );

test( 'a definite integral over a fraction is computed', () => {
	const quadratic = definiteIntegral( nerdamer, calculus, 'x^2', 'x', 0, 1 );
	const reciprocal = definiteIntegral( nerdamer, calculus, '1/x', 'x', 1, 'e' );

	assert.ok( Math.abs( quadratic.value - 1 / 3 ) < 1e-12 );
	assert.equal( reciprocal.value, 1 );
} );

test( 'POST /integral refuses a missing expression', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/integral`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { variable: 'x' } ),
		} );

		assert.equal( res.status, 400 );
		assert.match( ( await res.json() ).error, /Missing expression/ );
	} finally {
		await app.close();
	}
} );

test( 'POST /integral refuses a definite request without limits', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/integral`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { expression: 'x^2', type: 'definite' } ),
		} );

		assert.equal( res.status, 400 );
		assert.match( ( await res.json() ).error, /limi/i );
	} finally {
		await app.close();
	}
} );

test( 'POST /integral answers an indefinite request', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/integral`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { expression: 'x^2', variable: 'x', type: 'indefinite' } ),
		} );
		const body = await res.json();

		assert.equal( res.status, 200 );
		assert.equal( body.antiderivative, '(1/3)*x^3' );
		assert.equal( body.verified, true );
	} finally {
		await app.close();
	}
} );

test( 'POST /integral answers a definite request', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/integral`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { expression: 'sin(x)', variable: 'x', type: 'definite', lower: 0, upper: 3.14159265358979 } ),
		} );
		const body = await res.json();

		assert.equal( res.status, 200 );
		assert.ok( Math.abs( body.value - 2 ) < 1e-9 );
	} finally {
		await app.close();
	}
} );

test( 'POST /integral refuses an untrustworthy antiderivative with 422', async () => {
	const app = await serve();

	try {
		const res = await fetch( `${ app.url }/integral`, {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify( { expression: 'sin(x^2)', variable: 'x', type: 'indefinite' } ),
		} );

		assert.equal( res.status, 422 );
		assert.match( ( await res.json() ).error, /antiderivative/ );
	} finally {
		await app.close();
	}
} );
