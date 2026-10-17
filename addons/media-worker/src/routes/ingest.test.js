/**
 * Tests for the ingest route (Proposal 079):
 * POST /api/ingest/parse.
 *
 * The route is exercised end-to-end over a real HTTP server: an Express app
 * mounts the ingest router behind a stub of the auth middleware, multipart
 * bodies are built with the platform FormData/Blob primitives, and the real
 * dependencies (ExcelJS, pdf-parse, pdfkit) produce and consume fixtures.
 * OCR paths (tesseract.js) are deliberately not unit-tested here — they need
 * traineddata downloads; the photo branch is covered by the WordPress seam
 * tests with a mocked client.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'fs';
import os from 'os';
import path from 'path';
import express from 'express';
import ExcelJS from 'exceljs';
import { ingestRouter } from './ingest.js';

/**
 * Start an Express app serving the ingest router.
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
	app.use( ingestRouter );
	const server = app.listen( 0 );
	await new Promise( ( resolve ) => server.once( 'listening', resolve ) );
	return { server, url: `http://127.0.0.1:${ server.address().port }` };
}

/**
 * Build a multipart form body carrying a file plus scalar fields.
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

/**
 * Build an in-memory XLSX workbook fixture with two worksheets.
 *
 * @return {Promise<Buffer>} XLSX bytes.
 */
async function makeXlsx() {
	const workbook = new ExcelJS.Workbook();
	const sales = workbook.addWorksheet( 'Daily Sales' );
	sales.addRow( [ 'Date', 'Item ID', 'Qty Sold' ] );
	sales.addRow( [ '2026-10-01', 'IG-01', 5 ] );
	sales.addRow( [ '2026-10-02', 'IG-02', 3 ] );
	const purchases = workbook.addWorksheet( 'Purchases' );
	purchases.addRow( [ 'Supplier', 'Qty' ] );
	purchases.addRow( [ 'ACME', 12 ] );
	return Buffer.from( await workbook.xlsx.writeBuffer() );
}

/**
 * Read the repo's shipped PDF fixture (a real-world settlement statement
 * with a text layer). Lives in the monorepo root, two levels above the
 * worker package.
 *
 * @return {Buffer|null} PDF bytes, or null when the fixture is absent.
 */
function readPdfFixture() {
	const fixturePath = new URL( '../../../../tests/fixtures/gmail-settlement-sample.pdf', import.meta.url );
	if ( ! fs.existsSync( fixturePath ) ) {
		return null;
	}
	return fs.readFileSync( fixturePath );
}

test( 'xlsx upload returns the matrix contract from the first worksheet', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/parse`, {
			method: 'POST',
			body: multipart( await makeXlsx(), 'daily_sales.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.equal( body.success, true );
		assert.equal( body.format, 'xlsx' );
		assert.deepEqual( body.columns, [ 'Date', 'Item ID', 'Qty Sold' ] );
		assert.equal( body.rows.length, 2 );
		assert.deepEqual( body.rows[ 0 ], [ '2026-10-01', 'IG-01', '5' ] );
		assert.equal( body.meta.engine, 'exceljs' );
		assert.equal( body.meta.sheet, 'Daily Sales' );
		assert.equal( body.meta.source_total, 2 );
	} finally {
		server.close();
	}
} );

test( 'xlsx sheet param selects a worksheet by name', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/parse`, {
			method: 'POST',
			body: multipart( await makeXlsx(), 'daily_sales.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', { sheet: 'Purchases' } ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.deepEqual( body.columns, [ 'Supplier', 'Qty' ] );
		assert.deepEqual( body.rows[ 0 ], [ 'ACME', '12' ] );
		assert.equal( body.meta.sheet, 'Purchases' );
	} finally {
		server.close();
	}
} );

test( 'csv upload sniffs the delimiter and returns the matrix', async () => {
	const { server, url } = await startServer();
	try {
		const csv = Buffer.from( 'Date;Item ID;Qty Sold\n2026-10-01;IG-01;5\n' );
		const res = await fetch( `${ url }/parse`, {
			method: 'POST',
			body: multipart( csv, 'sales.csv', 'text/csv' ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.equal( body.format, 'csv' );
		assert.deepEqual( body.columns, [ 'Date', 'Item ID', 'Qty Sold' ] );
		assert.deepEqual( body.rows[ 0 ], [ '2026-10-01', 'IG-01', '5' ] );
	} finally {
		server.close();
	}
} );

test( 'jsonl upload infers union headers in first-seen order', async () => {
	const { server, url } = await startServer();
	try {
		const jsonl = Buffer.from( '{"date":"2026-10-01","item_id":"IG-01"}\n{"date":"2026-10-02","item_id":"IG-02","qty":3}\n' );
		const res = await fetch( `${ url }/parse`, {
			method: 'POST',
			body: multipart( jsonl, 'sales.jsonl', 'application/x-ndjson' ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.equal( body.format, 'jsonl' );
		assert.deepEqual( body.columns, [ 'date', 'item_id', 'qty' ] );
		assert.equal( body.rows.length, 2 );
		assert.equal( body.rows[ 0 ][ 2 ], '' );
		assert.equal( body.rows[ 1 ][ 2 ], '3' );
	} finally {
		server.close();
	}
} );

test( 'pdf upload extracts the text layer', async ( t ) => {
	const fixture = readPdfFixture();
	if ( ! fixture ) {
		t.skip( 'monorepo PDF fixture not present' );
		return;
	}
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/parse`, {
			method: 'POST',
			body: multipart( fixture, 'settlement.pdf', 'application/pdf' ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.equal( body.success, true );
		assert.equal( body.format, 'pdf' );
		assert.ok( body.text.includes( 'Settlement' ), 'extracts fixture text' );
		assert.equal( body.meta.engine, 'pdf-parse' );
		assert.equal( body.meta.ocr, false );
	} finally {
		server.close();
	}
} );

test( 'ods upload returns 503 capability_unavailable (caller falls back)', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/parse`, {
			method: 'POST',
			body: multipart( Buffer.from( 'not-a-real-ods' ), 'stock.ods', 'application/vnd.oasis.opendocument.spreadsheet' ),
		} );
		assert.equal( res.status, 503 );
		const body = await res.json();
		assert.equal( body.success, false );
		assert.equal( body.capability, 'ods-parsing' );
	} finally {
		server.close();
	}
} );

test( 'legacy xls upload returns 503 capability_unavailable (caller falls back)', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/parse`, {
			method: 'POST',
			body: multipart( Buffer.from( 'not-a-real-xls' ), 'legacy.xls', 'application/vnd.ms-excel' ),
		} );
		assert.equal( res.status, 503 );
		const body = await res.json();
		assert.equal( body.success, false );
		assert.equal( body.capability, 'legacy-xls-parsing' );
	} finally {
		server.close();
	}
} );

test( 'source path uploads parse from the worker filesystem (shared volume)', async () => {
	const { server, url } = await startServer();
	const tmp = fs.mkdtempSync( path.join( os.tmpdir(), 'ingest-' ) );
	const filePath = path.join( tmp, 'daily_sales.xlsx' );
	fs.writeFileSync( filePath, await makeXlsx() );
	try {
		const res = await fetch( `${ url }/parse`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { source: filePath } ),
		} );
		assert.equal( res.status, 200 );
		const body = await res.json();
		assert.equal( body.format, 'xlsx' );
		assert.deepEqual( body.columns, [ 'Date', 'Item ID', 'Qty Sold' ] );
	} finally {
		fs.rmSync( tmp, { recursive: true, force: true } );
		server.close();
	}
} );

test( 'missing input returns 400', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/parse`, { method: 'POST' } );
		assert.equal( res.status, 400 );
	} finally {
		server.close();
	}
} );

test( 'unknown file extension returns 415', async () => {
	const { server, url } = await startServer();
	try {
		const res = await fetch( `${ url }/parse`, {
			method: 'POST',
			body: multipart( Buffer.from( 'hello' ), 'notes.txt', 'text/plain' ),
		} );
		assert.equal( res.status, 415 );
	} finally {
		server.close();
	}
} );
