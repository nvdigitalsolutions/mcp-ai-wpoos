/**
 * PDF route — text extraction, rasterization, generation, merge, watermark.
 *
 * Endpoints:
 *   POST /api/pdf/extract   — extract text from PDF (pdf-parse)
 *   POST /api/pdf/render    — rasterize PDF pages to images (pdfjs-dist)
 *   POST /api/pdf/generate  — generate PDF from HTML/markdown (puppeteer)
 *   POST /api/pdf/merge     — merge multiple PDFs (pdf-lib)
 *   POST /api/pdf/watermark — add watermark to PDF (pdf-lib)
 *
 * File sources: /extract and /render accept a multipart `file` upload
 * (required on managed hosts where WordPress cannot reach the worker's
 * filesystem) or a `source` path. In multi-tenant mode (and with
 * STRICT_PATHS=1) every caller-supplied path must resolve inside the
 * site's namespace (403 otherwise).
 */

import { Router } from 'express';
import multer from 'multer';
import fs from 'fs';
import path from 'path';
import { launchHardenedBrowser, hardenPage } from '../utils/browser.js';
import { validatePublicUrl } from '../utils/safe-url.js';
import { siteBaseDir, siteUploadDir, pathGuard } from '../utils/site-paths.js';

export const pdfRouter = Router();

const upload = multer( { storage: multer.memoryStorage(), limits: { fileSize: 50 * 1024 * 1024 } } );

// ── Helpers ─────────────────────────────────────────────────
function tempFile( req, ext ) {
	return path.join( siteBaseDir( req.site ), `pdf-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }.${ ext }` );
}

function ensureDir( dir ) {
	if ( ! fs.existsSync( dir ) ) fs.mkdirSync( dir, { recursive: true } );
}

/**
 * Resolve the PDF source for a request: multipart upload wins; otherwise a
 * caller-supplied path that must stay inside the site namespace in strict
 * mode.
 *
 * @param {Object} req Express request.
 * @return {string|null} Local path to the PDF, or null when absent.
 */
function resolveSource( req ) {
	if ( req.file ) {
		const dir = siteUploadDir( req.site );
		const filePath = path.join( dir, `upload-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }.pdf` );
		fs.writeFileSync( filePath, req.file.buffer );
		return filePath;
	}
	const raw = ( req.body || {} ).source;
	return pathGuard( req.site, raw );
}

function sendError( res, err ) {
	res.status( err.status && err.status >= 400 && err.status < 600 ? err.status : 500 )
		.json( { success: false, error: err.message } );
}

// ── POST /extract — extract text from PDF ──────────────────
pdfRouter.post( '/extract', upload.single( 'file' ), async ( req, res ) => {
	try {
		const source = resolveSource( req );
		if ( ! source ) {
			return res.status( 400 ).json( { success: false, error: 'Missing source path or file upload' } );
		}
		if ( ! fs.existsSync( source ) ) {
			return res.status( 404 ).json( { success: false, error: `File not found: ${ source }` } );
		}

		const maxPages = ( req.body || {} ).maxPages;
		const pdfParse = ( await import( 'pdf-parse' ) ).default;
		const dataBuffer = fs.readFileSync( source );
		const data = await pdfParse( dataBuffer, { max: maxPages || 0 } );

		res.json( {
			success: true,
			text: data.text,
			pages: data.numpages,
			info: { title: data.info?.Title, author: data.info?.Author, pages: data.numpages },
		} );
	} catch ( err ) {
		sendError( res, err );
	}
} );

// ── POST /render — rasterize PDF pages to images ───────────
pdfRouter.post( '/render', upload.single( 'file' ), async ( req, res ) => {
	try {
		const source = resolveSource( req );
		if ( ! source ) {
			return res.status( 400 ).json( { success: false, error: 'Missing source path or file upload' } );
		}
		if ( ! fs.existsSync( source ) ) {
			return res.status( 404 ).json( { success: false, error: `File not found: ${ source }` } );
		}

		const { pages, scale, outputDir } = req.body || {};

		const pdfjsModule = await import( 'pdfjs-dist/legacy/build/pdf.mjs' );
		// The legacy build exposes named exports; some versions also ship a
		// default export. Tolerate both.
		const pdfjsLib = pdfjsModule.default || pdfjsModule;

		let canvasModule;
		try {
			canvasModule = await import( 'canvas' );
		} catch ( err ) {
			if ( 'ERR_MODULE_NOT_FOUND' === err.code || 'ERR_DLOPEN_FAILED' === err.code ) {
				return res.status( 503 ).json( {
					error: 'capability_unavailable',
					capability: 'pdf-rasterization',
					message: 'PDF rasterization is unavailable: the native canvas module is not installed on this server.',
				} );
			}
			throw err;
		}

		const data = new Uint8Array( fs.readFileSync( source ) );
		const doc = await pdfjsLib.getDocument( { data } ).promise;

		const outDir = pathGuard( req.site, outputDir ) || tempFile( req, '' ).replace( /\.[^.]+$/, '' );
		ensureDir( outDir );

		const renderScale = scale || 2.0;
		const results = [];
		const pageList = pages || Array.from( { length: doc.numPages }, ( _, i ) => i + 1 );

		for ( const pageNum of pageList ) {
			const page = await doc.getPage( pageNum );
			const viewport = page.getViewport( { scale: renderScale } );

			const canvas = canvasModule.createCanvas( viewport.width, viewport.height );
			const ctx = canvas.getContext( '2d' );

			await page.render( { canvasContext: ctx, viewport } ).promise;

			const outPath = path.join( outDir, `page-${ String( pageNum ).padStart( 3, '0' ) }.png` );
			fs.writeFileSync( outPath, canvas.toBuffer( 'image/png' ) );
			results.push( outPath );
		}

		res.json( { success: true, pages: results, count: results.length, outputDir: outDir } );
	} catch ( err ) {
		sendError( res, err );
	}
} );

// ── POST /generate — generate PDF from HTML (Puppeteer) ────
pdfRouter.post( '/generate', async ( req, res ) => {
	let browser;
	try {
		const { html, url, outputPath, options } = req.body || {};
		if ( ! html && ! url ) {
			return res.status( 400 ).json( { success: false, error: 'Missing html or url' } );
		}

		// SSRF guard: user-supplied URLs must be publicly routable.
		if ( url ) {
			validatePublicUrl( url );
		}

		browser = await launchHardenedBrowser();
		const page = await browser.newPage();
		await hardenPage( page );

		if ( url ) {
			await page.goto( url, { waitUntil: 'networkidle0', timeout: 30000 } );
		} else {
			await page.setContent( html, { waitUntil: 'networkidle0' } );
		}

		const pdfOpts = {
			format: options?.format || 'A4',
			printBackground: options?.printBackground !== false,
			landscape: options?.landscape || false,
			margin: options?.margin || { top: '20mm', bottom: '20mm', left: '15mm', right: '15mm' },
			...options,
		};

		const outPath = pathGuard( req.site, outputPath ) || tempFile( req, 'pdf' );
		await page.pdf( { path: outPath, ...pdfOpts } );
		await browser.close();

		const stats = fs.statSync( outPath );
		res.json( { success: true, output_path: outPath, size: stats.size, pages: 'see file' } );
	} catch ( err ) {
		if ( browser ) await browser.close().catch( () => {} );
		sendError( res, err );
	}
} );

// ── POST /merge — merge multiple PDFs ──────────────────────
pdfRouter.post( '/merge', async ( req, res ) => {
	try {
		const { sources, outputPath } = req.body || {};
		if ( ! sources || ! Array.isArray( sources ) || sources.length < 2 ) {
			return res.status( 400 ).json( { success: false, error: 'Need at least 2 source PDF paths' } );
		}

		const { PDFDocument } = await import( 'pdf-lib' );

		const merged = await PDFDocument.create();
		for ( const rawSrc of sources ) {
			const src = pathGuard( req.site, rawSrc );
			if ( ! src || ! fs.existsSync( src ) ) {
				return res.status( 404 ).json( { success: false, error: `File not found: ${ rawSrc }` } );
			}
			const srcDoc = await PDFDocument.load( fs.readFileSync( src ) );
			const copiedPages = await merged.copyPages( srcDoc, srcDoc.getPageIndices() );
			copiedPages.forEach( ( p ) => merged.addPage( p ) );
		}

		const outPath = pathGuard( req.site, outputPath ) || tempFile( req, 'pdf' );
		fs.writeFileSync( outPath, await merged.save() );
		const stats = fs.statSync( outPath );

		res.json( { success: true, output_path: outPath, size: stats.size, pages: merged.getPageCount() } );
	} catch ( err ) {
		sendError( res, err );
	}
} );

// ── POST /watermark — add watermark to PDF ─────────────────
pdfRouter.post( '/watermark', async ( req, res ) => {
	try {
		const { source, watermark, outputPath } = req.body || {};
		if ( ! source || ! watermark ) {
			return res.status( 400 ).json( { success: false, error: 'Missing source or watermark text' } );
		}
		const src = pathGuard( req.site, source );
		if ( ! src || ! fs.existsSync( src ) ) {
			return res.status( 404 ).json( { success: false, error: `File not found: ${ source }` } );
		}

		const { PDFDocument, StandardFonts, rgb } = await import( 'pdf-lib' );

		const doc = await PDFDocument.load( fs.readFileSync( src ) );
		const font = await doc.embedFont( StandardFonts.Helvetica );
		const pages = doc.getPages();

		for ( const page of pages ) {
			const { width, height } = page.getSize();
			page.drawText( watermark, {
				x: width / 2 - 150,
				y: height / 2,
				size: 48,
				font,
				color: rgb( 0.75, 0.75, 0.75 ),
				opacity: 0.3,
				rotate: { angle: -45, type: 0, origin: [ width / 2, height / 2 ] },
			} );
		}

		const outPath = pathGuard( req.site, outputPath ) || tempFile( req, 'pdf' );
		fs.writeFileSync( outPath, await doc.save() );
		const stats = fs.statSync( outPath );

		res.json( { success: true, output_path: outPath, size: stats.size, pages: pages.length } );
	} catch ( err ) {
		sendError( res, err );
	}
} );
