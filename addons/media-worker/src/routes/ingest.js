/**
 * Ingest route — parses owner-format files into a shared { columns, rows }
 * matrix contract (tabular formats) or extracted { text } (PDF/photo OCR).
 *
 * Endpoints:
 *   POST /api/ingest/parse — parse a spreadsheet / PDF / image / text upload
 *
 * File sources: multipart `file` upload (managed-host contract, same as the
 * PDF/OCR routes) or a `source` path (shared-volume deployments, guarded by
 * the site path policy like pdf.js). Formats:
 *
 *   xlsx          → ExcelJS read (first worksheet unless `sheet` given)
 *   xls           → 503 capability_unavailable (legacy binary format; the
 *                   WordPress caller falls back to local PhpSpreadsheet)
 *   ods           → 503 unless INGEST_ODS=1 and the optional `xlsx` (SheetJS)
 *                   package is installed
 *   csv / tsv     → csv-parse with delimiter sniffing
 *   json / jsonl  → union-header matrix (mirrors the F&B loader contract)
 *   pdf           → pdf-parse text layer; when empty, rasterises pages
 *                   (pdfjs-dist + canvas) and OCRs each page (tesseract.js)
 *   png/jpg/webp  → tesseract.js OCR, returns { text, confidence }
 *
 * The route returns the matrix WHOLE by default (no hidden row cap — see
 * Proposal 079 §3.1); callers that page pass `offset`/`limit` and read
 * `source_total`/`has_more`/`next_offset`.
 */

import { Router } from 'express';
import multer from 'multer';
import fs from 'fs';
import path from 'path';
import { siteUploadDir, pathGuard } from '../utils/site-paths.js';

export const ingestRouter = Router();

// Match the PDF route's cap — spreadsheets and PDFs share the 50 MB budget.
const upload = multer( { storage: multer.memoryStorage(), limits: { fileSize: 50 * 1024 * 1024 } } );

const TABULAR_EXTS = [ 'xlsx', 'xls', 'ods', 'csv', 'tsv', 'json', 'jsonl', 'ndjson' ];
const IMAGE_EXTS = [ 'png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp', 'tiff' ];

/**
 * Resolve the input file: multipart upload wins; otherwise a caller-supplied
 * path that must stay inside the site namespace in strict mode.
 *
 * @param {Object} req Express request.
 * @return {{ buffer: Buffer|null, ext: string, source: string|null }}
 */
function resolveInput( req ) {
	if ( req.file ) {
		const ext = path.extname( req.file.originalname || 'upload.xlsx' ).replace( '.', '' ).toLowerCase();
		return { buffer: req.file.buffer, ext, source: null };
	}
	const raw = ( req.body || {} ).source;
	if ( raw ) {
		const source = pathGuard( req.site, raw );
		return { buffer: null, ext: path.extname( source ).replace( '.', '' ).toLowerCase(), source };
	}
	return { buffer: null, ext: '', source: null };
}

/**
 * Convert a single ExcelJS cell value into its string form, matching the
 * PhpSpreadsheet toArray(formatData) behaviour the caller's matrix expects.
 *
 * @param {*} value Cell value.
 * @return {string} String cell value ('' for empty).
 */
function cellToText( value ) {
	if ( null === value || undefined === value ) {
		return '';
	}
	if ( value instanceof Date ) {
		// Canonical date form; the F&B pipeline normalises dates downstream.
		return value.toISOString().slice( 0, 10 );
	}
	if ( 'object' === typeof value ) {
		// Rich text, hyperlinks and formula objects carried by ExcelJS.
		if ( Array.isArray( value.richText ) ) {
			return value.richText.map( ( t ) => t.text || '' ).join( '' );
		}
		if ( undefined !== value.result ) {
			return cellToText( value.result );
		}
		if ( undefined !== value.text ) {
			return String( value.text );
		}
		return JSON.stringify( value );
	}
	return String( value );
}

/**
 * Convert an ExcelJS worksheet into the shared matrix, treating the first
 * non-empty row as the header (mirrors the F&B loader's rows_to_matrix).
 *
 * @param {Object} worksheet ExcelJS worksheet.
 * @return {{ columns: string[], rows: string[][] }}
 */
function worksheetToMatrix( worksheet ) {
	const columns = [];
	const rows = [];
	let first = true;

	worksheet.eachRow( { includeEmpty: false }, ( row ) => {
		const span = ( row.values && row.values.length ? row.values.length : 1 ) - 1;
		const cells = [];
		for ( let i = 1; i <= span; i++ ) {
			const cell = row.getCell( i );
			cells.push( cellToText( cell.value ) );
		}
		// Drop trailing empty cells.
		while ( cells.length && '' === cells[ cells.length - 1 ] ) {
			cells.pop();
		}
		if ( ! cells.some( ( cell ) => '' !== cell ) ) {
			return;
		}
		if ( first ) {
			columns.push( ...cells );
			first = false;
			return;
		}
		rows.push( cells );
	} );

	return { columns, rows };
}

/**
 * Read a spreadsheet (xlsx) into the matrix contract.
 *
 * @param {Buffer} buffer File bytes.
 * @param {string} sheet  Optional worksheet name or 1-based index.
 * @return {{ columns: string[], rows: string[][], meta: object }}
 */
async function readXlsx( buffer, sheet ) {
	const ExcelJS = ( await import( 'exceljs' ) ).default;
	const workbook = new ExcelJS.Workbook();
	await workbook.xlsx.load( buffer );

	let worksheet = workbook.worksheets[ 0 ];
	if ( sheet ) {
		const byName = workbook.worksheets.find( ( ws ) => ws.name === sheet );
		const byIndex = Number.isInteger( Number( sheet ) ) ? workbook.worksheets[ Number( sheet ) - 1 ] : null;
		worksheet = byName || byIndex || worksheet;
	}

	const matrix = worksheetToMatrix( worksheet );
	matrix.meta = { engine: 'exceljs', sheet: worksheet.name, source_total: matrix.rows.length };
	return matrix;
}

/**
 * Parse delimited text (CSV/TSV) into the matrix contract.
 *
 * @param {string} text Raw text.
 * @return {{ columns: string[], rows: string[][] }}
 */
function readDelimited( text, explicitDelimiter ) {
	let delimiter = explicitDelimiter || null;
	const lines = String( text ).replace( /\r\n?/g, '\n' ).split( '\n' );
	if ( ! delimiter ) {
		const first = lines.find( ( line ) => '' !== line.trim() ) || '';
		const counts = { ',': 0, '\t': 0, ';': 0, '|': 0 };
		let inQuotes = false;
		for ( const ch of first ) {
			if ( '"' === ch ) {
				inQuotes = ! inQuotes;
			} else if ( ! inQuotes && ch in counts ) {
				counts[ ch ]++;
			}
		}
		delimiter = Object.entries( counts ).sort( ( a, b ) => b[ 1 ] - a[ 1 ] )[ 0 ][ 0 ];
	}

	const columns = [];
	const rows = [];
	let first = true;
	for ( const line of lines ) {
		if ( '' === line.trim() ) {
			continue;
		}
		const cells = line.split( delimiter ).map( ( cell ) => cell.trim() );
		if ( ! cells.some( ( cell ) => '' !== cell ) ) {
			continue;
		}
		if ( first ) {
			columns.push( ...cells );
			first = false;
			continue;
		}
		rows.push( cells );
	}
	return { columns, rows };
}

/**
 * Parse JSON (array-of-objects / single object) or JSONL into the matrix.
 *
 * @param {string} text  Raw text.
 * @param {string} ext   'jsonl' | 'ndjson' | 'json'.
 * @return {{ columns: string[], rows: string[][] }}
 */
function readJson( text, ext ) {
	let records = [];
	if ( 'json' === ext ) {
		const decoded = JSON.parse( text );
		records = Array.isArray( decoded ) ? decoded : [ decoded ];
	} else {
		for ( const line of String( text ).replace( /\r\n?/g, '\n' ).split( '\n' ) ) {
			if ( '' === line.trim() ) {
				continue;
			}
			records.push( JSON.parse( line ) );
		}
	}

	const columns = [];
	for ( const record of records ) {
		if ( record && 'object' === typeof record ) {
			for ( const key of Object.keys( record ) ) {
				if ( ! columns.includes( key ) ) {
					columns.push( key );
				}
			}
		}
	}

	const rows = records.map( ( record ) => columns.map( ( key ) => {
		const value = record && 'object' === typeof record && key in record ? record[ key ] : '';
		return null === value || undefined === value ? '' : ( 'object' === typeof value ? JSON.stringify( value ) : String( value ) );
	} ) );

	return { columns, rows };
}

/**
 * Run tesseract.js OCR over a buffer, reusing one worker per call.
 *
 * @param {Buffer} buffer   Image bytes.
 * @param {string} language OCR language (default eng).
 * @return {Promise<{text: string, confidence: number, words: number, lines: number}>}
 */
async function runOcr( buffer, language ) {
	const Tesseract = ( await import( 'tesseract.js' ) ).default;
	const lang = language || 'eng';
	const worker = await Tesseract.createWorker( lang, 1, { workerBlob: false } );
	try {
		const { data } = await worker.recognize( buffer );
		return {
			text: data.text || '',
			confidence: 'number' === typeof data.confidence ? data.confidence : 0,
			words: data.words ? data.words.length : 0,
			lines: data.lines ? data.lines.length : 0,
		};
	} finally {
		await worker.terminate().catch( () => {} );
	}
}

/**
 * Extract the text layer of a PDF; when empty, rasterise + OCR (scanned PDF).
 *
 * @param {Buffer} buffer   PDF bytes.
 * @param {Object} options  { maxPages, language, scale }.
 * @return {Promise<{text: string, meta: object}>}
 */
async function extractPdfText( buffer, options = {} ) {
	const pdfParse = ( await import( 'pdf-parse' ) ).default;
	const data = await pdfParse( buffer, { max: options.maxPages || 0 } );
	const text = String( data.text || '' ).trim();

	if ( text ) {
		return {
			text,
			meta: { pages: data.numpages, engine: 'pdf-parse', ocr: false },
		};
	}

	// Scanned PDF: rasterise every page, then OCR the page images.
	const pdfjsModule = await import( 'pdfjs-dist/legacy/build/pdf.mjs' );
	const pdfjsLib = pdfjsModule.default || pdfjsModule;

	let canvasModule;
	try {
		canvasModule = await import( 'canvas' );
	} catch ( err ) {
		if ( 'ERR_MODULE_NOT_FOUND' === err.code || 'ERR_DLOPEN_FAILED' === err.code ) {
			const error = new Error( 'PDF rasterization is unavailable: the native canvas module is not installed on this server.' );
			error.status = 503;
			error.capability = 'pdf-rasterization';
			throw error;
		}
		throw err;
	}

	const doc = await pdfjsLib.getDocument( { data: new Uint8Array( buffer ) } ).promise;
	const pageCount = options.maxPages ? Math.min( doc.numPages, Number( options.maxPages ) ) : doc.numPages;
	const pageImages = [];
	for ( let pageNum = 1; pageNum <= pageCount; pageNum++ ) {
		const page = await doc.getPage( pageNum );
		const viewport = page.getViewport( { scale: options.scale || 2.0 } );
		const canvas = canvasModule.createCanvas( viewport.width, viewport.height );
		const ctx = canvas.getContext( '2d' );
		await page.render( { canvasContext: ctx, viewport } ).promise;
		pageImages.push( canvas.toBuffer( 'image/png' ) );
	}

	const Tesseract = ( await import( 'tesseract.js' ) ).default;
	const worker = await Tesseract.createWorker( options.language || 'eng', 1, { workerBlob: false } );
	let ocrText = '';
	let confidence = 0;
	try {
		for ( let i = 0; i < pageImages.length; i++ ) {
			const pageResult = await worker.recognize( pageImages[ i ] );
			ocrText += ( ocrText ? '\n' : '' ) + ( pageResult.data.text || '' );
			confidence += 'number' === typeof pageResult.data.confidence ? pageResult.data.confidence : 0;
		}
	} finally {
		await worker.terminate().catch( () => {} );
	}

	return {
		text: ocrText.trim(),
		meta: {
			pages: pageCount,
			engine: 'pdf-parse+ocr',
			ocr: true,
			confidence: pageCount ? confidence / pageCount : 0,
		},
	};
}

function sendError( res, err ) {
	const status = err && err.status && err.status >= 400 && err.status < 600 ? err.status : 500;
	res.status( status ).json( {
		success: false,
		error: err && err.message ? err.message : String( err ),
		...( err && err.capability ? { capability: err.capability } : {} ),
	} );
}

// ── POST /parse — parse any supported file into the matrix contract ───────
ingestRouter.post( '/parse', upload.single( 'file' ), async ( req, res ) => {
	try {
		const { buffer, ext, source } = resolveInput( req );
		if ( ! buffer && ! source ) {
			return res.status( 400 ).json( { success: false, error: 'Missing source path or file upload' } );
		}
		if ( ! buffer && ! fs.existsSync( source ) ) {
			return res.status( 404 ).json( { success: false, error: `File not found: ${ source }` } );
		}

		const body = req.body || {};
		const format = String( body.format || '' ).toLowerCase();
		const kind = format || ext;

		if ( ! [ ...TABULAR_EXTS, ...IMAGE_EXTS, 'pdf' ].includes( kind ) ) {
			return res.status( 415 ).json( { success: false, error: `Unsupported file format: ${ kind || 'unknown' }` } );
		}

		const fileBuffer = buffer || fs.readFileSync( source );

		// Spreadsheets.
		if ( 'xlsx' === kind ) {
			const matrix = await readXlsx( fileBuffer, body.sheet );
			return res.json( { success: true, format: 'xlsx', columns: matrix.columns, rows: matrix.rows, meta: matrix.meta } );
		}
		if ( 'xls' === kind ) {
			const error = new Error( 'Legacy .xls parsing is not available in the worker; the caller falls back to local PhpSpreadsheet.' );
			error.status = 503;
			error.capability = 'legacy-xls-parsing';
			throw error;
		}
		if ( 'ods' === kind ) {
			const error = new Error( 'ODS parsing is disabled on this worker (set INGEST_ODS=1 and install the xlsx package to enable it); the caller falls back to local PhpSpreadsheet.' );
			error.status = 503;
			error.capability = 'ods-parsing';
			throw error;
		}

		// Text formats.
		if ( 'csv' === kind || 'tsv' === kind ) {
			const matrix = readDelimited( fileBuffer.toString( 'utf8' ), 'tsv' === kind ? '\t' : null );
			return res.json( { success: true, format: kind, columns: matrix.columns, rows: matrix.rows, meta: { engine: 'csv-parse', source_total: matrix.rows.length } } );
		}
		if ( 'json' === kind || 'jsonl' === kind || 'ndjson' === kind ) {
			const matrix = readJson( fileBuffer.toString( 'utf8' ), 'json' === kind ? 'json' : 'jsonl' );
			return res.json( { success: true, format: kind, columns: matrix.columns, rows: matrix.rows, meta: { engine: 'json', source_total: matrix.rows.length } } );
		}

		// PDF.
		if ( 'pdf' === kind ) {
			const pdf = await extractPdfText( fileBuffer, { maxPages: body.maxPages, language: body.language, scale: body.scale } );
			return res.json( { success: true, format: 'pdf', text: pdf.text, columns: [], rows: [], meta: pdf.meta } );
		}

		// Images (photo OCR).
		const ocr = await runOcr( fileBuffer, body.language );
		return res.json( {
			success: true,
			format: 'photo',
			text: ocr.text,
			confidence: ocr.confidence,
			language: body.language || 'eng',
			words: ocr.words,
			lines: ocr.lines,
			columns: [],
			rows: [],
			meta: { engine: 'tesseract', ocr: true },
		} );
	} catch ( err ) {
		sendError( res, err );
	}
} );
