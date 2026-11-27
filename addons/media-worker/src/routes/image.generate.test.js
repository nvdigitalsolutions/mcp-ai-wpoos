// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Tests for `provider: 'auto'` on POST /api/image/generate.
 *
 * The route previously required either an explicit provider or a `model` that
 * mapped to one, so the tool-level `provider: auto` — which the WordPress tool
 * honoured by preferring Gemini and falling back to OpenAI — could not be
 * expressed at all. These tests pin the resolver and its refusal.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import { imageRouter } from './image.js';

/**
 * Start an Express app serving the image router behind an auth stub.
 *
 * @return {Promise<{server: import('http').Server, url: string}>} Server.
 */
async function startServer() {
	const app = express();
	app.use( ( req, _res, next ) => {
		req.site = 'default';
		next();
	} );
	// `/generate` reads a JSON body; the production app mounts this globally.
	app.use( express.json() );
	app.use( imageRouter );
	const server = app.listen( 0 );
	await new Promise( ( resolve ) => server.once( 'listening', resolve ) );
	return { server, url: `http://127.0.0.1:${ server.address().port }` };
}

/**
 * Run a callback with globalThis.fetch stubbed for provider requests only.
 *
 * @param {Function} handler Stub handler (url, options) => Response.
 * @param {Function} fn      Callback to run under the stub.
 * @return {Promise<*>} Callback result.
 */
async function withFetchStub( handler, fn ) {
	const original = globalThis.fetch;
	globalThis.fetch = ( url, options ) => {
		if ( 'string' === typeof url && url.includes( '127.0.0.1' ) ) {
			return original( url, options );
		}
		return handler( url, options );
	};
	try {
		return await fn();
	} finally {
		globalThis.fetch = original;
	}
}

/**
 * Save and clear provider keys around a test body.
 *
 * @param {Object}   keys Keys to set.
 * @param {Function} fn   Test body.
 * @return {Promise<*>} Test body result.
 */
async function withKeys( keys, fn ) {
	const names = [ 'GEMINI_API_KEY', 'OPENAI_API_KEY', 'REPLICATE_API_KEY' ];
	const saved = {};
	for ( const name of names ) {
		saved[ name ] = process.env[ name ];
		delete process.env[ name ];
	}
	for ( const [ name, value ] of Object.entries( keys ) ) {
		process.env[ name ] = value;
	}
	try {
		return await fn();
	} finally {
		for ( const name of names ) {
			if ( undefined === saved[ name ] ) {
				delete process.env[ name ];
			} else {
				process.env[ name ] = saved[ name ];
			}
		}
	}
}

test( 'generate resolves provider auto to Gemini when both keys could serve', async () => {
	await withKeys( { GEMINI_API_KEY: 'gsk-test', OPENAI_API_KEY: 'sk-test' }, async () => {
		const imageB64 = Buffer.from( 'canned-image' ).toString( 'base64' );
		let capturedPayload = null;

		const { server, url } = await startServer();
		try {
			await withFetchStub( async ( _url, options ) => {
				capturedPayload = JSON.parse( options.body );
				return new Response( JSON.stringify( {
					candidates: [
						{
							content: {
								parts: [
									{ inlineData: { mimeType: 'image/png', data: imageB64 } },
								],
							},
						},
					],
				} ), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				} );
			}, async () => {
				const res = await fetch( `${ url }/generate`, {
					method: 'POST',
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify( {
						prompt: 'A marble table in morning light',
						provider: 'auto',
						model: 'gemini-2.5-flash-image',
						size: '1536x1024',
					} ),
				} );

				assert.equal( res.status, 200 );
				const body = await res.json();
				assert.equal( body.success, true );
				assert.equal( body.provider, 'gemini', 'auto prefers Gemini, as the WordPress tool did' );
				assert.equal( body.model, 'gemini-2.5-flash-image' );
				assert.equal( body.images.length, 1 );
				assert.equal( body.images[ 0 ].b64_json, imageB64 );
			} );
		} finally {
			server.close();
		}

		const parts = capturedPayload?.contents?.[ 0 ]?.parts || [];
		const texts = parts.filter( ( part ) => part.text ).map( ( part ) => part.text ).join( ' ' );
		assert.match(
			texts,
			/Generate an image with dimensions 1536x1024/,
			'the requested canvas reaches the model as a hint'
		);
		assert.match( texts, /A marble table in morning light/ );
	} );
} );

test( 'generate resolves provider auto to OpenAI when only an OpenAI key exists', async () => {
	await withKeys( { OPENAI_API_KEY: 'sk-test' }, async () => {
		const { server, url } = await startServer();
		try {
			await withFetchStub( async () =>
				new Response( JSON.stringify( {
					data: [ { url: 'https://cdn.example.test/generated.png', revised_prompt: null } ],
				} ), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				} )
			, async () => {
				const res = await fetch( `${ url }/generate`, {
					method: 'POST',
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify( {
						prompt: 'A marble table in morning light',
						provider: 'auto',
						model: 'dall-e-3',
					} ),
				} );

				assert.equal( res.status, 200 );
				const body = await res.json();
				assert.equal( body.provider, 'openai' );
				assert.equal( body.model, 'dall-e-3' );
				assert.equal( body.images[ 0 ].url, 'https://cdn.example.test/generated.png' );
			} );
		} finally {
			server.close();
		}
	} );
} );

test( 'generate answers 503 capability_unavailable when auto has no keys', async () => {
	await withKeys( {}, async () => {
		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/generate`, {
				method: 'POST',
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify( { prompt: 'Anything', provider: 'auto' } ),
			} );

			assert.equal( res.status, 503 );
			const body = await res.json();
			assert.equal( body.capability, 'image_generation' );
			assert.equal( body.provider, null );
			assert.match( body.env_var, /GEMINI_API_KEY or OPENAI_API_KEY/ );
		} finally {
			server.close();
		}
	} );
} );
