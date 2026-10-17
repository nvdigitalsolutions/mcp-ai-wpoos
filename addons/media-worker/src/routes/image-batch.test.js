/**
 * Tests for the image batch route (Proposal 079, Appendix B):
 * POST /api/image/batch.
 *
 * The route is exercised end-to-end over a real HTTP server with the same
 * auth-stub pattern as image.test.js; Sharp is the real dependency so happy
 * paths assert real pixel output. Covers the plugin's sidecar_upload_multi
 * field naming ('files[N]'), format selection, original-format re-encode,
 * width resizing, the no-files error, and the original response fields
 * (filename / original_size / optimized_size / savings_percent).
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import sharp from 'sharp';
import { imageRouter } from './image.js';

/**
 * Start an Express app serving the image router.
 *
 * @return {Promise<{server: import('http').Server, url: string}>}
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
 * Render a solid-color PNG fixture.
 *
 * @param {number} width  Width in px.
 * @param {number} height Height in px.
 * @return {Promise<Buffer>} PNG bytes.
 */
function makePng( width = 64, height = 48 ) {
	return sharp( { create: { width, height, channels: 3, background: '#336699' } } ).png().toBuffer();
}

/**
 * Build a multipart body carrying N files named files[0], files[1], …
 * (the plugin sidecar_upload_multi convention) plus scalar fields.
 *
 * @param {Buffer[]} files  File bytes.
 * @param {Object}   fields Extra form fields.
 * @return {FormData} Multipart body.
 */
function multipartIndexed( files, fields = {} ) {
	const form = new FormData();
	files.forEach( ( bytes, i ) => {
		form.append( `files[${ i }]`, new Blob( [ bytes ], { type: 'image/png' } ), `fixture-${ i }.png` );
	} );
	for ( const [ key, value ] of Object.entries( fields ) ) {
		form.append( key, String( value ) );
	}
	return form;
}

test( 'batch converts indexed uploads to webp and returns per-file base64', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/batch`, {
			method: 'POST',
			body: multipartIndexed( [ await makePng(), await makePng( 32, 32 ) ], { quality: 82 } ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.equal( body.success, true );
		assert.equal( body.processed, 2 );
		assert.equal( body.results.length, 2 );
		assert.deepEqual( body.errors, [] );

		for ( const result of body.results ) {
			assert.ok( result.filename.startsWith( 'fixture-' ) );
			assert.equal( result.format, 'webp' );
			assert.ok( result.original_size > 0 );
			assert.ok( result.optimized_size > 0 );
			assert.ok( 'string' === typeof result.savings_percent );
			const decoded = Buffer.from( result.b64, 'base64' );
			const info = await sharp( decoded ).metadata();
			assert.equal( info.format, 'webp' );
		}
		assert.equal( body.results[ 0 ].width, 64 );
		assert.equal( body.results[ 1 ].width, 32 );
	} finally {
		server.close();
	}
} );

test( 'batch jpeg format produces real jpeg bytes', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/batch`, {
			method: 'POST',
			body: multipartIndexed( [ await makePng() ], { format: 'jpeg', quality: 70 } ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.equal( body.results[ 0 ].format, 'jpeg' );
		const decoded = Buffer.from( body.results[ 0 ].b64, 'base64' );
		const info = await sharp( decoded ).metadata();
		assert.equal( info.format, 'jpeg' );
	} finally {
		server.close();
	}
} );

test( 'batch original format keeps the source format', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/batch`, {
			method: 'POST',
			body: multipartIndexed( [ await makePng() ], { format: 'original' } ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.equal( body.results[ 0 ].format, 'png' );
	} finally {
		server.close();
	}
} );

test( 'batch width resizes without enlarging', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/batch`, {
			method: 'POST',
			body: multipartIndexed( [ await makePng( 64, 48 ) ], { width: 32 } ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.equal( body.results[ 0 ].width, 32 );
	} finally {
		server.close();
	}
} );

test( 'batch with no files returns 400', async () => {
	const { server, url } = await startServer();
	try {
		const form = new FormData();
		form.append( 'quality', '80' );
		const res = await fetch( `${ url }/batch`, { method: 'POST', body: form } );
		assert.equal( res.status, 400 );
	} finally {
		server.close();
	}
} );
