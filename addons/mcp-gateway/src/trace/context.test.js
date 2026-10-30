/**
 * Trace context unit tests (Phase 0).
 *
 * Asserts the W3C contract: valid inbound traceparent is inherited, missing
 * or malformed headers start a fresh trace, zero ids are rejected, and the
 * middleware attaches the id to the request.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import {
	TRACEPARENT_RE,
	parseTraceparent,
	generateTraceparent,
	startOrInherit,
	traceMiddleware,
} from './context.js';

const VALID =
	'00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

test( 'generateTraceparent produces a W3C-valid header', () => {
	assert.match( generateTraceparent(), TRACEPARENT_RE );
} );

test( 'parseTraceparent accepts a valid version-00 header', () => {
	const parsed = parseTraceparent( VALID );
	assert.deepStrictEqual( parsed, {
		traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
		spanId: '00f067aa0ba902b7',
		flags: '01',
	} );
} );

test( 'parseTraceparent rejects absent, malformed, and zero ids', () => {
	assert.strictEqual( parseTraceparent( '' ), null );
	assert.strictEqual( parseTraceparent( undefined ), null );
	assert.strictEqual( parseTraceparent( 'not-a-traceparent' ), null );
	assert.strictEqual( parseTraceparent( '01-' + 'a'.repeat( 32 ) + '-' + 'b'.repeat( 16 ) + '-00' ), null );
	assert.strictEqual(
		parseTraceparent( `00-${ '0'.repeat( 32 ) }-00f067aa0ba902b7-01` ),
		null
	);
	assert.strictEqual(
		parseTraceparent( `00-4bf92f3577b34da6a3ce929d0e0e4736-${ '0'.repeat( 16 ) }-01` ),
		null
	);
} );

test( 'startOrInherit inherits a valid inbound traceparent', () => {
	const req = { get: () => VALID };
	const traceparent = startOrInherit( req );
	assert.strictEqual( traceparent, VALID );
	assert.strictEqual( req.traceId, '4bf92f3577b34da6a3ce929d0e0e4736' );
	assert.strictEqual( req.traceparent, VALID );
} );

test( 'startOrInherit starts a fresh trace on missing or malformed headers', () => {
	for ( const header of [ undefined, 'garbage', '01-abcdef-abcdef-00' ] ) {
		const req = { get: () => header };
		const traceparent = startOrInherit( req );
		assert.match( traceparent, TRACEPARENT_RE );
		assert.strictEqual( req.traceId, traceparent.slice( 3, 35 ) );
		assert.strictEqual( req.traceparent, traceparent );
	}
} );

test( 'traceMiddleware attaches the trace and echoes the header', () => {
	const req = { get: () => VALID };
	const res = { headers: {}, setHeader: function ( name, value ) { this.headers[ name ] = value; } };
	let passed = false;
	traceMiddleware( req, res, () => {
		passed = true;
	} );
	assert.strictEqual( passed, true );
	assert.strictEqual( req.traceId, '4bf92f3577b34da6a3ce929d0e0e4736' );
	assert.strictEqual( res.headers.traceparent, VALID );
} );
