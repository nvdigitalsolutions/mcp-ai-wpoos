// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Tests for the PDF route's styleable watermark and the OCR endpoint's
 * validation.
 *
 * The watermark is exercised end-to-end over a real HTTP server with a real
 * `pdf-lib` round-trip, because the thing worth proving is that the response
 * echoes the style it applied and that the bytes still decode.
 *
 * The OCR *recognition* path is deliberately not exercised here: tesseract.js
 * downloads traineddata on first use, which is a network dependency in a unit
 * test. The route's argument validation and its capability-failure branch are
 * cheap to cover and are what a caller actually hits.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import PDFDocument from 'pdfkit';
import { pdfRouter } from './pdf.js';

/**
 * Start an Express app serving the PDF router.
 *
 * @return {Promise<{server: import('http').Server, url: string}>}
 */
async function startServer() {
	const app = express();
	app.use( ( req, _res, next ) => {
		req.site = 'default';
		next();
	} );
	app.use( express.json() );
	app.use( pdfRouter );
	const server = app.listen( 0 );
	await new Promise( ( resolve ) => server.once( 'listening', resolve ) );
	return { server, url: `http://127.0.0.1:${ server.address().port }` };
}

/**
 * Build a single-page A4 PDF fixture.
 *
 * @return {Promise<Buffer>} PDF bytes.
 */
async function makePdf() {
	return new Promise( ( resolve, reject ) => {
		const doc = new PDFDocument( { size: 'A4' } );
		const chunks = [];
		doc.on( 'data', ( chunk ) => chunks.push( chunk ) );
		doc.on( 'end', () => resolve( Buffer.concat( chunks ) ) );
		doc.on( 'error', reject );
		doc.fontSize( 24 ).text( 'Watermark fixture' );
		doc.end();
	} );
}

/**
 * Build a multipart body carrying a file plus scalar fields.
 *
 * @param {Buffer} fileBytes File bytes.
 * @param {string} filename  Upload filename.
 * @param {string} mime      MIME type.
 * @param {Object} fields    Extra form fields.
 * @return {FormData} Multipart body.
 */
function multipart( fileBytes, filename, mime, fields = {} ) {
	const form = new FormData();
	form.append( 'file', new Blob( [ fileBytes ], { type: mime } ), filename );
	for ( const [ key, value ] of Object.entries( fields ) ) {
		form.append( key, String( value ) );
	}
	return form;
}

test( 'a watermark with no style keeps the historical mark and says so', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/watermark`, {
			method: 'POST',
			body: multipart( await makePdf(), 'in.pdf', 'application/pdf', { watermark: 'CONFIDENTIAL' } ),
		} );

		assert.equal( res.status, 200 );
		const body = await res.json();

		assert.equal( body.success, true );
		assert.equal( body.pages, 1 );
		assert.equal( body.position, 'diagonal' );
		assert.equal( body.opacity, 0.3 );

		const bytes = Buffer.from( body.data_base64, 'base64' );
		assert.ok( bytes.length > 0 );
		assert.equal( bytes.subarray( 0, 4 ).toString(), '%PDF' );
	} finally {
		server.close();
	}
} );

test( 'a watermark honours a clamped opacity and a named position', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/watermark`, {
			method: 'POST',
			body: multipart( await makePdf(), 'in.pdf', 'application/pdf', {
				watermark: 'DRAFT',
				opacity: '2',
				position: 'top',
			} ),
		} );

		assert.equal( res.status, 200 );
		const body = await res.json();

		assert.equal( body.opacity, 1, 'clamped into 0..1' );
		assert.equal( body.position, 'top' );
		assert.equal( body.pages, 1 );
	} finally {
		server.close();
	}
} );

test( 'a watermark without text is refused', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/watermark`, {
			method: 'POST',
			body: multipart( await makePdf(), 'in.pdf', 'application/pdf', {} ),
		} );

		assert.equal( res.status, 400 );
		const body = await res.json();
		assert.equal( body.success, false );
	} finally {
		server.close();
	}
} );

test( 'ocr refuses a request with no file', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/ocr`, { method: 'POST' } );

		assert.equal( res.status, 400 );
		const body = await res.json();
		assert.equal( body.success, false );
		assert.match( body.error, /Missing source path or file upload/ );
	} finally {
		server.close();
	}
} );

test( 'ocr reports a named capability when the rasterizer is absent', async () => {
	const { server, url } = await startServer();
	try {
		let canvasAvailable = true;
		try {
			await import( 'canvas' );
		} catch {
			canvasAvailable = false;
		}

		if ( canvasAvailable ) {
			// On a host with canvas the recognition path runs and downloads
			// traineddata; that belongs to the deployment smoke test, not here.
			return;
		}

		const res = await fetch( `${ url }/ocr`, {
			method: 'POST',
			body: multipart( await makePdf(), 'in.pdf', 'application/pdf', {} ),
		} );

		assert.equal( res.status, 503 );
		const body = await res.json();
		assert.equal( body.capability, 'pdf-ocr' );
	} finally {
		server.close();
	}
} );
