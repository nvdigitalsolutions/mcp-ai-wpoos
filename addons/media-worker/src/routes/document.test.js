// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Tests for the Excel route's freeze panes, auto-filter and formula channel.
 *
 * The thing worth proving is the **injection boundary**: a caller's data is text,
 * and only a formula the caller asked for by name becomes executable. OWASP's
 * CSV-injection guidance puts its strongest recommendation on typed XLSX output
 * precisely because a string cell cannot become a formula — so the first test
 * here writes `=1+1` as row data and reads the cell back to prove it stayed a
 * string, and the second proves the explicit channel does produce a formula.
 *
 * The workbook is decoded again with ExcelJS rather than asserting on the
 * request, because "the cell is a string" is a property of the written file, not
 * of the arguments.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import express from 'express';
import ExcelJS from 'exceljs';
import fs from 'fs';
import os from 'os';
import path from 'path';
import { documentRouter } from './document.js';

/**
 * Start an Express app serving the document router.
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
	app.use( documentRouter );
	const server = app.listen( 0 );
	await new Promise( ( resolve ) => server.once( 'listening', resolve ) );
	return { server, url: `http://127.0.0.1:${ server.address().port }` };
}

/**
 * Ask the route for a workbook and decode the returned bytes.
 *
 * @param {string} url     Base URL of the test server.
 * @param {Object} sheets  Sheet definitions.
 * @return {Promise<{status: number, body: Object, workbook: ExcelJS.Workbook|null}>}
 */
async function excel( url, sheets ) {
	const response = await fetch( `${ url }/excel`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { sheets } ),
	} );

	const body = await response.json();

	if ( ! body.success || ! body.data_base64 ) {
		return { status: response.status, body, workbook: null };
	}

	const temp = path.join( os.tmpdir(), `nvoos-doc-test-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }.xlsx` );
	fs.writeFileSync( temp, Buffer.from( body.data_base64, 'base64' ) );

	try {
		const workbook = new ExcelJS.Workbook();
		await workbook.xlsx.readFile( temp );
		return { status: response.status, body, workbook };
	} finally {
		fs.unlinkSync( temp );
	}
}

// ── The injection boundary ──────────────────────────────────

test( 'a data value that looks like a formula stays a string', async () => {
	const { server, url } = await startServer();

	try {
		const { body, workbook } = await excel( url, [
			{ name: 'S', columns: [ { header: 'Note', key: 'a' } ], rows: [ { a: '=1+1' }, { a: '+SUM(A1)' }, { a: '@cmd' } ] },
		] );

		assert.equal( body.success, true );

		const sheet = workbook.getWorksheet( 'S' );

		for ( const row of [ 2, 3, 4 ] ) {
			const cell = sheet.getCell( `A${ row }` );
			assert.equal( typeof cell.value, 'string', `row ${ row } must stay a string` );
			assert.equal( cell.formula, undefined, `row ${ row } must not carry a formula` );
		}

		assert.equal( sheet.getCell( 'A2' ).value, '=1+1' );
	} finally {
		server.close();
	}
} );

test( 'the explicit formula channel writes a real formula', async () => {
	const { server, url } = await startServer();

	try {
		const { body, workbook } = await excel( url, [
			{
				name: 'Calc',
				columns: [ { header: 'A', key: 'a' }, { header: 'B', key: 'b' }, { header: 'Total', key: 't' } ],
				rows: [ { a: 1, b: 2 }, { a: 3, b: 4 } ],
				formulas: { D2: '=SUM(A2:B2)', D3: 'SUM(A3:B3)' },
			},
		] );

		assert.equal( body.success, true );

		const sheet = workbook.getWorksheet( 'Calc' );

		// The leading '=' is accepted and stripped, so both spellings work.
		assert.equal( sheet.getCell( 'D2' ).formula, 'SUM(A2:B2)' );
		assert.equal( sheet.getCell( 'D3' ).formula, 'SUM(A3:B3)' );
	} finally {
		server.close();
	}
} );

test( 'an invalid cell reference is refused', async () => {
	const { server, url } = await startServer();

	try {
		const { status, body } = await excel( url, [
			{ name: 'S', rows: [ [ 1 ] ], formulas: { 'not-a-ref': 'SUM(A1:A2)' } },
		] );

		assert.equal( body.success, false );
		assert.equal( status, 400 );
		assert.match( body.error, /Invalid cell reference/ );
	} finally {
		server.close();
	}
} );

test( 'an empty or control-character formula is refused', async () => {
	const { server, url } = await startServer();

	try {
		const empty = await excel( url, [ { name: 'S', rows: [ [ 1 ] ], formulas: { A2: '=' } } ] );
		assert.equal( empty.body.success, false );
		assert.equal( empty.status, 400 );

		const control = await excel( url, [ { name: 'S', rows: [ [ 1 ] ], formulas: { A2: 'SUM(\u0000A1)' } } ] );
		assert.equal( control.body.success, false );
		assert.equal( control.status, 400 );
	} finally {
		server.close();
	}
} );

// ── Freeze panes and auto-filter ────────────────────────────

test( 'a frozen view is applied', async () => {
	const { server, url } = await startServer();

	try {
		const { body, workbook } = await excel( url, [
			{
				name: 'Frozen',
				columns: [ { header: 'A', key: 'a' } ],
				rows: [ { a: 1 }, { a: 2 } ],
				views: [ { state: 'frozen', xSplit: 0, ySplit: 1, topLeftCell: 'A2', activeCell: 'A1' } ],
			},
		] );

		assert.equal( body.success, true );

		const view = workbook.getWorksheet( 'Frozen' ).views[ 0 ];

		assert.equal( view.state, 'frozen' );
		assert.equal( view.ySplit, 1 );
	} finally {
		server.close();
	}
} );

test( 'an auto-filter range is applied', async () => {
	const { server, url } = await startServer();

	try {
		const { body, workbook } = await excel( url, [
			{
				name: 'Filtered',
				columns: [ { header: 'A', key: 'a' }, { header: 'B', key: 'b' } ],
				rows: [ { a: 1, b: 2 } ],
				autoFilter: 'A1:B1',
			},
		] );

		assert.equal( body.success, true );

		const sheet = workbook.getWorksheet( 'Filtered' );

		assert.ok( sheet.autoFilter, 'an auto-filter must be present' );

		// ExcelJS exposes the range as a string on write and as `{from, to}`
		// cell objects on read. Both mean the same range, so accept either.
		const filter = sheet.autoFilter;

		if ( 'string' === typeof filter ) {
			assert.equal( filter, 'A1:B1' );
		} else {
			assert.equal( filter.from.row, 1 );
			assert.equal( filter.from.column, 1 );
			assert.equal( filter.to.column, 2 );
		}
	} finally {
		server.close();
	}
} );

test( 'a sheet may omit everything new and still write', async () => {
	const { server, url } = await startServer();

	try {
		const { body, workbook } = await excel( url, [ { name: 'Plain', rows: [ [ 'x' ] ] } ] );

		assert.equal( body.success, true );
		assert.equal( workbook.getWorksheet( 'Plain' ).getCell( 'A1' ).value, 'x' );
	} finally {
		server.close();
	}
} );
