// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview Tests for POST /api/image/compose (Proposal 164, slice P2).
 *
 * Compose is the one image operation `/edit` cannot express: `/edit` sends a
 * single image and its operations are a closed set, while composition is a
 * question about two images at once. These tests pin the parts of that
 * contract a caller can observe:
 *
 *  - both accepted body shapes (named parts, positional files);
 *  - refusal of ambiguous input (<2 or >2 positional images);
 *  - the closed provider set (Replicate is excluded: it takes one image);
 *  - `capability: 'image_composition'` on a missing-key 503;
 *  - that the two images reach the provider in a defined order, scene first;
 *  - that `format`/`width`/`height` describe the **output**, unlike `/edit`,
 *    which reports them from the uploaded source.
 *
 * The harness mirrors image.test.js: a real HTTP server, the platform
 * FormData/Blob primitives for multipart bodies, and Sharp for pixel checks.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import sharp from 'sharp';
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
	app.use( imageRouter );
	const server = app.listen( 0 );
	await new Promise( ( resolve ) => server.once( 'listening', resolve ) );
	return { server, url: `http://127.0.0.1:${ server.address().port }` };
}

/**
 * Render a solid-color PNG fixture with the given dimensions.
 *
 * @param {number} width  Width in px.
 * @param {number} height Height in px.
 * @return {Promise<Buffer>} PNG bytes.
 */
function makePng( width = 64, height = 48 ) {
	return sharp( { create: { width, height, channels: 3, background: '#336699' } } ).png().toBuffer();
}

/**
 * Build a compose body with two named image parts.
 *
 * Named parts are the preferred shape and the one the adapter sends. The
 * positional `files[n]` convention is exercised by its own test, where the
 * parts are deliberately appended out of index order.
 *
 * @param {Object} images           Scene and product bytes.
 * @param {Buffer} images.scene     Scene image.
 * @param {Buffer} images.product   Product image.
 * @param {Object} fields           Extra form fields.
 * @return {FormData} Multipart body.
 */
function composeForm( { scene, product }, fields = {} ) {
	const form = new FormData();
	form.append( 'scene', new Blob( [ scene ], { type: 'image/png' } ), 'scene.png' );
	form.append( 'product', new Blob( [ product ], { type: 'image/png' } ), 'product.png' );
	for ( const [ key, value ] of Object.entries( fields ) ) {
		form.append( key, String( value ) );
	}
	return form;
}

/**
 * Run a callback with globalThis.fetch stubbed for provider requests only.
 *
 * @param {Function} handler Stub handler (url, options) => Response.
 * @param {Function} fn      Callback to run under the stub.
 * @return {Promise<*>> Callback result.
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
 * Canned Gemini generateContent REST response with an inline image.
 *
 * @param {Buffer} imageBytes Image bytes to embed.
 * @return {Object} Response payload.
 */
function geminiImagePayload( imageBytes ) {
	return {
		candidates: [
			{
				content: {
					parts: [
						{
							inlineData: {
								mimeType: 'image/png',
								data: imageBytes.toString( 'base64' ),
							},
						},
					],
				},
			},
		],
	};
}

/**
 * Save and clear provider keys around a test body.
 *
 * The compose handler resolves credentials for the 'default' site from
 * process.env only, so clearing the env vars is a complete isolation.
 *
 * @param {Object}   keys  Keys to set, e.g. { GEMINI_API_KEY: 'gsk-test' }.
 * @param {Function} fn    Test body.
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

test( 'compose refuses a request carrying only one image', async () => {
	const { server, url } = await startServer();
	try {
		const form = new FormData();
		form.append( 'scene', new Blob( [ await makePng() ], { type: 'image/png' } ), 'scene.png' );

		const res = await fetch( `${ url }/compose`, { method: 'POST', body: form } );

		assert.equal( res.status, 400 );
		const body = await res.json();
		assert.match( body.error, /exactly 2 images; received 1/ );
		assert.match( body.expected, /scene and product/ );
	} finally {
		server.close();
	}
} );

test( 'compose refuses three positional images as ambiguous', async () => {
	const { server, url } = await startServer();
	try {
		const form = new FormData();
		for ( let index = 0; index < 3; index++ ) {
			form.append(
				`files[${ index }]`,
				new Blob( [ await makePng( 8, 8 ) ], { type: 'image/png' } ),
				`fixture-${ index }.png`
			);
		}

		const res = await fetch( `${ url }/compose`, { method: 'POST', body: form } );

		assert.equal( res.status, 400, 'which two of three images were meant is not knowable' );
		const body = await res.json();
		assert.match( body.error, /exactly 2 images; received 3/ );
	} finally {
		server.close();
	}
} );

test( 'compose rejects providers that cannot take two images', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/compose`, {
			method: 'POST',
			body: composeForm(
				{ scene: await makePng(), product: await makePng( 32, 24 ) },
				{ provider: 'replicate' }
			),
		} );

		assert.equal( res.status, 400 );
		const body = await res.json();
		assert.match( body.error, /Unknown compose provider: replicate/ );
		assert.deepEqual( body.allowed_providers, [ 'auto', 'gemini', 'openai' ] );
	} finally {
		server.close();
	}
} );

test( 'compose returns 503 capability_unavailable without provider keys', async () => {
	await withKeys( {}, async () => {
		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/compose`, {
				method: 'POST',
				body: composeForm( { scene: await makePng(), product: await makePng( 32, 24 ) } ),
			} );

			assert.equal( res.status, 503 );
			const body = await res.json();
			assert.equal( body.capability, 'image_composition' );
			assert.equal( body.provider, null );
			assert.match( body.env_var, /GEMINI_API_KEY or OPENAI_API_KEY/ );
			assert.ok( body.tip );
		} finally {
			server.close();
		}
	} );
} );

test( 'compose names the missing key when a provider is requested explicitly', async () => {
	await withKeys( { GEMINI_API_KEY: 'gsk-test' }, async () => {
		const { server, url } = await startServer();
		try {
			const res = await fetch( `${ url }/compose`, {
				method: 'POST',
				body: composeForm(
					{ scene: await makePng(), product: await makePng( 32, 24 ) },
					{ provider: 'openai' }
				),
			} );

			assert.equal( res.status, 503 );
			const body = await res.json();
			assert.equal( body.capability, 'image_composition' );
			assert.equal( body.provider, 'openai' );
			assert.equal( body.env_var, 'OPENAI_API_KEY' );
		} finally {
			server.close();
		}
	} );
} );

test( 'compose sends both images to Gemini, scene first, and reports output metadata', async () => {
	const scene = await makePng( 64, 48 );
	const product = await makePng( 32, 24 );
	const output = await makePng( 96, 72 );

	await withKeys( { GEMINI_API_KEY: 'gsk-test' }, async () => {
		let capturedPayload = null;

		const { server, url } = await startServer();
		try {
			await withFetchStub( async ( _url, options ) => {
				capturedPayload = JSON.parse( options.body );
				return new Response( JSON.stringify( geminiImagePayload( output ) ), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				} );
			}, async () => {
				const res = await fetch( `${ url }/compose`, {
					method: 'POST',
					body: composeForm( { scene, product } ),
				} );

				assert.equal( res.status, 200 );
				const body = await res.json();
				assert.equal( body.success, true );
				assert.equal( body.provider, 'gemini' );
				assert.equal( body.model, 'gemini-3.1-flash-image' );
				assert.equal( body.operation, 'compose' );
				assert.equal( body.format, 'png' );
				assert.ok( body.bytes > 0 );

				// The output is 96x72; the scene is 64x48. These must describe the
				// provider's image, not the upload — that is the `/edit` bug this
				// route must not repeat.
				assert.equal( body.width, 96 );
				assert.equal( body.height, 72 );

				const info = await sharp( Buffer.from( body.b64, 'base64' ) ).metadata();
				assert.equal( info.width, 96 );
				assert.equal( info.height, 72 );

				// With no prompt supplied, the route states its own instruction.
				assert.match( body.prompt, /Place the product shown in the second image/ );
			} );
		} finally {
			server.close();
		}

		const parts = capturedPayload?.contents?.[ 0 ]?.parts || [];
		const texts = parts.filter( ( part ) => part.text ).map( ( part ) => part.text ).join( ' ' );
		assert.match( texts, /Place the product shown in the second image into the scene in the first image\./ );

		const inline = parts
			.filter( ( part ) => part.inlineData )
			.map( ( part ) => part.inlineData.data );
		assert.deepEqual(
			inline,
			[ scene.toString( 'base64' ), product.toString( 'base64' ) ],
			'scene arrives first and product second; the order is part of the instruction'
		);
	} );
} );

test( 'compose orders positional files by their index, not arrival order', async () => {
	const scene = await makePng( 64, 48 );
	const product = await makePng( 32, 24 );
	const output = await makePng( 80, 60 );

	await withKeys( { GEMINI_API_KEY: 'gsk-test' }, async () => {
		let capturedPayload = null;

		const { server, url } = await startServer();
		try {
			await withFetchStub( async ( _url, options ) => {
				capturedPayload = JSON.parse( options.body );
				return new Response( JSON.stringify( geminiImagePayload( output ) ), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				} );
			}, async () => {
				// Product is appended first but carries index 1. Trusting arrival
				// order would put the product in the scene slot — a silent swap.
				const form = new FormData();
				form.append( 'files[1]', new Blob( [ product ], { type: 'image/png' } ), 'product.png' );
				form.append( 'files[0]', new Blob( [ scene ], { type: 'image/png' } ), 'scene.png' );
				form.append( 'prompt', 'Set the watch on the marble table, morning light.' );

				const res = await fetch( `${ url }/compose`, { method: 'POST', body: form } );

				assert.equal( res.status, 200 );
				const body = await res.json();
				assert.equal( body.prompt, 'Set the watch on the marble table, morning light.' );
				assert.equal( body.width, 80 );
				assert.equal( body.height, 60 );
			} );
		} finally {
			server.close();
		}

		const parts = capturedPayload?.contents?.[ 0 ]?.parts || [];
		const texts = parts.filter( ( part ) => part.text ).map( ( part ) => part.text ).join( ' ' );
		assert.match( texts, /Set the watch on the marble table, morning light\./ );

		const inline = parts
			.filter( ( part ) => part.inlineData )
			.map( ( part ) => part.inlineData.data );
		assert.deepEqual(
			inline,
			[ scene.toString( 'base64' ), product.toString( 'base64' ) ],
			'index 0 is the scene even though index 1 arrived first'
		);
	} );
} );

test( 'compose falls back to OpenAI for auto and calls the edits endpoint', async () => {
	const scene = await makePng( 64, 48 );
	const product = await makePng( 32, 24 );
	const output = await makePng( 96, 72 );

	await withKeys( { OPENAI_API_KEY: 'sk-test' }, async () => {
		let capturedUrl = null;
		let capturedBody = null;

		const { server, url } = await startServer();
		try {
			await withFetchStub( async ( target, options ) => {
				capturedUrl = String( target );
				capturedBody = options.body;
				return new Response( JSON.stringify( {
					data: [ { b64_json: output.toString( 'base64' ) } ],
				} ), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				} );
			}, async () => {
				const res = await fetch( `${ url }/compose`, {
					method: 'POST',
					body: composeForm( { scene, product } ),
				} );

				assert.equal( res.status, 200 );
				const body = await res.json();
				assert.equal( body.provider, 'openai', 'auto picks the only configured key' );
				assert.equal( body.model, 'gpt-image-1' );
				assert.equal( body.operation, 'compose' );
				assert.equal( body.width, 96 );
				assert.equal( body.height, 72 );
			} );
		} finally {
			server.close();
		}

		assert.match( capturedUrl, /\/images\/edits$/, 'composition reuses the edits endpoint with two inputs' );
		assert.ok( capturedBody, 'the provider request carried a multipart body' );
	} );
} );
