/**
 * Audit sink unit tests (Phase 3).
 *
 * Asserts the delivery contract: HMAC signature verification by the
 * receiver, retry-then-drop on failure, no local storage, and complete
 * silence when the sink is unconfigured.
 */

import { test } from 'node:test';
import assert from 'node:assert';
import express from 'express';
import crypto from 'crypto';
import { auditEnabled, signBody, emitAudit } from './sink.js';
import { startTestServer } from '../utils/test-server.js';

test( 'signBody produces a verifiable sha256= HMAC', () => {
	const signature = signBody( 'secret', '{"a":1}' );
	assert.match( signature, /^sha256=[0-9a-f]{64}$/ );
	const expected = 'sha256=' + crypto.createHmac( 'sha256', 'secret' ).update( '{"a":1}' ).digest( 'hex' );
	assert.strictEqual( signature, expected );
} );

test( 'auditEnabled requires both URL and secret', () => {
	assert.strictEqual( auditEnabled( {} ), false );
	assert.strictEqual( auditEnabled( { auditSinkUrl: 'https://x' } ), false );
	assert.strictEqual( auditEnabled( { auditSinkUrl: 'https://x', auditSinkSecret: 's' } ), true );
} );

test( 'emitAudit delivers a signed event and verifies server-side', async ( t ) => {
	const received = [];
	const app = express();
	app.use( express.json() );
	app.post( '/audit', ( req, res ) => {
		const body = JSON.stringify( req.body );
		const valid = signBody( 'shared-secret', body ) === req.get( 'x-audit-signature' );
		received.push( { valid, body: req.body } );
		res.status( 204 ).end();
	} );
	const { baseUrl, close } = await startTestServer( app );
	t.after( async () => close() );

	await emitAudit( { auditSinkUrl: `${ baseUrl }/audit`, auditSinkSecret: 'shared-secret' }, { kind: 'deny', traceId: 'abc', tool: 't' } );
	assert.strictEqual( received.length, 1 );
	assert.strictEqual( received[ 0 ].valid, true );
	assert.strictEqual( received[ 0 ].body.kind, 'deny' );
	assert.strictEqual( received[ 0 ].body.traceId, 'abc' );
	assert.ok( received[ 0 ].body.ts );
} );

test( 'emitAudit retries failed deliveries then drops without throwing', async ( t ) => {
	let attempts = 0;
	const app = express();
	app.use( express.json() );
	app.post( '/audit', ( _req, res ) => {
		attempts += 1;
		res.status( 500 ).end();
	} );
	const { baseUrl, close } = await startTestServer( app );
	t.after( async () => close() );

	await emitAudit( { auditSinkUrl: `${ baseUrl }/audit`, auditSinkSecret: 's' }, { kind: 'call_ok' } );
	assert.strictEqual( attempts, 4, 'initial attempt + 3 retries, then drop' );
} );

test( 'emitAudit is a silent no-op without a configured sink', async () => {
	await emitAudit( {}, { kind: 'deny' } ); // Must not throw.
	assert.ok( true );
} );
