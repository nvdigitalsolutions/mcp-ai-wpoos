import { test } from 'node:test';
import assert from 'node:assert/strict';
import { requestJson, HttpError } from './http.js';

function fakeResponse( status, body, headers = {} ) {
	return {
		ok: status >= 200 && status < 300,
		status,
		headers: new Headers( headers ),
		text: async () => ( typeof body === 'string' ? body : JSON.stringify( body ) ),
	};
}

test( 'parses JSON and sends a JSON body', async () => {
	const calls = [];
	const fetchImpl = async ( url, options ) => {
		calls.push( { url, options } );
		return fakeResponse( 200, { hello: 'world' } );
	};
	const result = await requestJson( 'https://example.test/api', {
		method: 'POST',
		body: { a: 1 },
		fetchImpl,
	} );
	assert.equal( result.ok, true );
	assert.deepEqual( result.data, { hello: 'world' } );
	assert.equal( calls[ 0 ].options.method, 'POST' );
	assert.equal( calls[ 0 ].options.headers[ 'Content-Type' ], 'application/json' );
	assert.equal( calls[ 0 ].options.body, '{"a":1}' );
} );

test( 'form bodies are urlencoded', async () => {
	const fetchImpl = async ( url, options ) => {
		assert.equal( options.headers[ 'Content-Type' ], 'application/x-www-form-urlencoded' );
		assert.equal( options.body, 'email=a%40b.test&api_key=secret' );
		return fakeResponse( 200, { ok: true } );
	};
	await requestJson( 'https://example.test/oauth', {
		method: 'POST',
		body: { email: 'a@b.test', api_key: 'secret' },
		bodyType: 'form',
		fetchImpl,
	} );
} );

test( 'retries once on 5xx then succeeds', async () => {
	let attempts = 0;
	const fetchImpl = async () => {
		attempts += 1;
		return attempts === 1 ? fakeResponse( 503, { error: 'busy' } ) : fakeResponse( 200, { ok: true } );
	};
	const result = await requestJson( 'https://example.test/x', { retries: 1, fetchImpl } );
	assert.equal( attempts, 2 );
	assert.equal( result.ok, true );
} );

test( 'non-2xx returns ok:false without throwing', async () => {
	const fetchImpl = async () => fakeResponse( 401, { error: 'unauthorized' } );
	const result = await requestJson( 'https://example.test/x', { fetchImpl } );
	assert.equal( result.ok, false );
	assert.equal( result.status, 401 );
	assert.deepEqual( result.data, { error: 'unauthorized' } );
} );

test( 'network errors surface as HttpError', async () => {
	const fetchImpl = async () => {
		const error = new TypeError( 'fetch failed' );
		throw error;
	};
	await assert.rejects(
		() => requestJson( 'https://example.test/x', { retries: 0, fetchImpl } ),
		( error ) => error instanceof HttpError
	);
} );

test( 'text responses are returned verbatim', async () => {
	const fetchImpl = async () => fakeResponse( 200, 'not json' );
	const result = await requestJson( 'https://example.test/x', { fetchImpl } );
	assert.equal( result.data, 'not json' );
} );
