/**
 * Tests for the shared-secret authentication middleware.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { authMiddleware, tokenMatches } from './auth.js';

/** Minimal mock Express response. */
function mockRes() {
	return {
		statusCode: 200,
		body: null,
		status( code ) {
			this.statusCode = code;
			return this;
		},
		json( payload ) {
			this.body = payload;
			return this;
		},
	};
}

/** Minimal mock Express request. */
function mockReq( token ) {
	return {
		get: ( name ) => ( 'X-Site-Token' === name ? token : undefined ),
	};
}

test( 'tokenMatches uses timing-safe comparison', () => {
	assert.equal( tokenMatches( 'correct-horse-battery', 'correct-horse-battery' ), true );
	assert.equal( tokenMatches( 'correct-horse-battery', 'wrong' ), false );
	assert.equal( tokenMatches( '', 'secret' ), false );
	assert.equal( tokenMatches( undefined, 'secret' ), false );
} );

test( 'authMiddleware passes when no token is configured (lenient)', () => {
	const original = process.env.WORKER_API_TOKEN;
	delete process.env.WORKER_API_TOKEN;
	delete process.env.AUTH_MODE;
	let nextCalled = false;
	authMiddleware( mockReq( 'anything' ), mockRes(), () => {
		nextCalled = true;
	} );
	assert.equal( nextCalled, true );
	if ( original ) {
		process.env.WORKER_API_TOKEN = original;
	}
} );

test( 'authMiddleware fails closed in strict mode without token configured', () => {
	const originalToken = process.env.WORKER_API_TOKEN;
	const originalMode = process.env.AUTH_MODE;
	delete process.env.WORKER_API_TOKEN;
	process.env.AUTH_MODE = 'strict';
	let nextCalled = false;
	const res = mockRes();
	authMiddleware( mockReq( 'anything' ), res, () => {
		nextCalled = true;
	} );
	assert.equal( nextCalled, false );
	assert.equal( res.statusCode, 503 );
	if ( originalToken ) {
		process.env.WORKER_API_TOKEN = originalToken;
	} else {
		delete process.env.WORKER_API_TOKEN;
	}
	if ( originalMode ) {
		process.env.AUTH_MODE = originalMode;
	} else {
		delete process.env.AUTH_MODE;
	}
} );

test( 'authMiddleware rejects a wrong token with 401', () => {
	const original = process.env.WORKER_API_TOKEN;
	process.env.WORKER_API_TOKEN = 'a-very-long-shared-secret-123456789';
	let nextCalled = false;
	const res = mockRes();
	authMiddleware( mockReq( 'wrong-token' ), res, () => {
		nextCalled = true;
	} );
	assert.equal( nextCalled, false );
	assert.equal( res.statusCode, 401 );
	assert.equal( res.body.error, 'Unauthorized' );
	if ( original ) {
		process.env.WORKER_API_TOKEN = original;
	} else {
		delete process.env.WORKER_API_TOKEN;
	}
} );

test( 'authMiddleware accepts a correct token', () => {
	const original = process.env.WORKER_API_TOKEN;
	process.env.WORKER_API_TOKEN = 'a-very-long-shared-secret-123456789';
	let nextCalled = false;
	authMiddleware( mockReq( 'a-very-long-shared-secret-123456789' ), mockRes(), () => {
		nextCalled = true;
	} );
	assert.equal( nextCalled, true );
	if ( original ) {
		process.env.WORKER_API_TOKEN = original;
	} else {
		delete process.env.WORKER_API_TOKEN;
	}
} );
