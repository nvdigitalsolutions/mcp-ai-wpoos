#!/usr/bin/env node
/**
 * OCR Service for Node.js
 * 
 * High-performance OCR using Tesseract.js and PDF.js
 * Processes both images and PDFs entirely in Node.js for better performance.
 */
const fs = require('fs');
const path = require('path');

// Load dependencies from bundled vendor directory
let Tesseract, pdfjsLib, sharp, canvas;
const loadErrors = [];

try {
	// Try to load from bundled vendor directory first
	const vendorPath = path.join(__dirname, '..', 'assets', 'vendor');
	
	// Validate vendor path exists before attempting to load
	const vendorExists = fs.existsSync(vendorPath);
	
	// Load Tesseract.js
	try {
		if (vendorExists) {
			const tesseractPath = path.join(vendorPath, 'tesseract.js', 'src');
			if (fs.existsSync(tesseractPath)) {
				Tesseract = require(tesseractPath);
			} else {
				throw new Error('Bundled tesseract.js not found');
			}
		} else {
			throw new Error('Vendor path does not exist');
		}
	} catch (e) {
		try {
			Tesseract = require('tesseract.js'); // Fallback to node_modules
		} catch (err) {
			loadErrors.push('tesseract.js: ' + err.message);
		}
	}
	
	// Load Sharp
	try {
		if (vendorExists) {
			const sharpPath = path.join(vendorPath, 'sharp', 'lib');
			if (fs.existsSync(sharpPath)) {
				sharp = require(sharpPath);
			} else {
				throw new Error('Bundled sharp not found');
			}
		} else {
			throw new Error('Vendor path does not exist');
		}
	} catch (e) {
		try {
			sharp = require('sharp'); // Fallback to node_modules
		} catch (err) {
			loadErrors.push('sharp: ' + err.message);
		}
	}
	
	// Load Canvas
	try {
		if (vendorExists) {
			const canvasPath = path.join(vendorPath, 'canvas');
			if (fs.existsSync(canvasPath)) {
				canvas = require(canvasPath);
			} else {
				throw new Error('Bundled canvas not found');
			}
		} else {
			throw new Error('Vendor path does not exist');
		}
	} catch (e) {
		try {
			canvas = require('canvas'); // Fallback to node_modules
		} catch (err) {
			loadErrors.push('canvas: ' + err.message);
		}
	}

	// If critical dependencies are missing, exit with error
	if (!Tesseract) {
		console.log(JSON.stringify({
			error: 'Failed to load Tesseract.js. This is a critical dependency. Details: ' + loadErrors.join('; ')
		}));
		process.exit(1);
	}

	// Sharp and Canvas are optional dependencies for enhanced features
	// - Sharp: Image preprocessing for better OCR accuracy
	// - Canvas: PDF rendering for PDF OCR
	// If not available, basic OCR on images will still work, but:
	// - No preprocessing will be applied
	// - PDF OCR will fail with a clear error message
	// These warnings are not logged to avoid cluttering output for standard image OCR
} catch (error) {
	console.log(JSON.stringify({
		error: 'Failed to initialize OCR service: ' + error.message,
		details: loadErrors.join('; ')
	}));
	process.exit(1);
}

/**
 * Preprocess image for better OCR results.
 * 
 * @param {Buffer} imageBuffer - Input image buffer
 * @param {object} options - Preprocessing options
 * @returns {Promise<Buffer>} Preprocessed image buffer
 */
async function preprocessImage(imageBuffer, options = {}) {
	// Check if Sharp is available
	if (!sharp) {
		// Return original buffer if Sharp is not available
		return imageBuffer;
	}

	const {
		maxWidth = 2048,
		maxHeight = 2048,
		enhance = true,
	} = options;

	try {
		let pipeline = sharp(imageBuffer);

	// Get metadata
	const metadata = await pipeline.metadata();

	// Resize if needed
	if (metadata.width > maxWidth || metadata.height > maxHeight) {
		pipeline = pipeline.resize(maxWidth, maxHeight, {
			fit: 'inside',
			withoutEnlargement: true,
		});
	}

	// Convert to grayscale
	pipeline = pipeline.grayscale();

	if (enhance) {
		// Normalize contrast
		pipeline = pipeline.normalize();

		// Sharpen for better text recognition
		pipeline = pipeline.sharpen({
			sigma: 1,      // Slight blur to reduce noise before sharpening
			m1: 1,         // Edge sharpening amount (1.0 = normal strength)
			m2: 0.5,       // Flat area sharpening (0.5 = moderate)
			x1: 2,         // Min edge detection threshold
			// Note: Sharp only accepts sigma, m1, m2, x1, y2, y3
			// y2 and y3 are optional luminance thresholds
		});

		// Gamma correction
		pipeline = pipeline.gamma(1.2);
	}

		// Convert to PNG for best quality
		pipeline = pipeline.png({ compressionLevel: 6 });

		return await pipeline.toBuffer();
	} catch (error) {
		// If preprocessing fails, return original buffer
		return imageBuffer;
	}
}

/**
 * Extract text from image using Tesseract.js.
 * 
 * @param {string|Buffer} image - Image path or buffer
 * @param {object} options - OCR options
 * @returns {Promise<object>} OCR result
 */
async function extractTextFromImage(image, options = {}) {
	const {
		language = 'eng',
		preprocess = true,
		psm = 3, // Fully automatic page segmentation
	} = options;

	try {
		let imageBuffer = image;

		// Read file if path provided
		if (typeof image === 'string') {
			if (!fs.existsSync(image)) {
				throw new Error(`Image file not found: ${image}`);
			}
			imageBuffer = fs.readFileSync(image);
		}

		// Preprocess if enabled
		if (preprocess) {
			imageBuffer = await preprocessImage(imageBuffer, options);
		}

		// Create Tesseract worker
		const worker = await Tesseract.createWorker(language, 1, {
			logger: () => {}, // Suppress logs
		});

		// Set PSM (Page Segmentation Mode)
		await worker.setParameters({
			tessedit_pageseg_mode: psm,
		});

		// Perform OCR
		const result = await worker.recognize(imageBuffer);

		// Clean up
		await worker.terminate();

		return {
			text: result.data.text,
			confidence: result.data.confidence,
			words: result.data.words.length,
		};
	} catch (error) {
		throw new Error(`OCR failed: ${error.message}`);
	}
}

/**
 * Extract text from PDF using PDF.js and Tesseract.js.
 * 
 * @param {string} pdfPath - Path to PDF file
 * @param {object} options - OCR options
 * @returns {Promise<object>} OCR result
 */
async function extractTextFromPdf(pdfPath, options = {}) {
	const {
		maxPages = 10,
		language = 'eng',
		scale = 2.0, // Render scale (2.0 = 200% = ~192 DPI)
	} = options;

	try {
		if (!fs.existsSync(pdfPath)) {
			throw new Error(`PDF file not found: ${pdfPath}`);
		}

		// Check for Canvas (required for PDF rendering)
		if (!canvas) {
			throw new Error('Canvas module not available. PDF OCR requires canvas for rendering.');
		}

		// Load PDF.js (lazy load to avoid issues if not needed)
		if (!pdfjsLib) {
			try {
				// Try bundled version first with path validation
				const vendorPath = path.join(__dirname, '..', 'assets', 'vendor');
				
				if (fs.existsSync(vendorPath)) {
					const pdfjsPath = path.join(vendorPath, 'pdfjs-dist', 'legacy', 'build', 'pdf.js');
					if (fs.existsSync(pdfjsPath)) {
						pdfjsLib = require(pdfjsPath);
					} else {
						// Try node_modules
						pdfjsLib = require('pdfjs-dist/legacy/build/pdf.js');
					}
				} else {
					// Fallback to node_modules
					pdfjsLib = require('pdfjs-dist/legacy/build/pdf.js');
				}
			} catch (error) {
				throw new Error('pdfjs-dist module not available. PDF OCR requires pdfjs-dist for rendering. Details: ' + error.message);
			}
		}

		// Read PDF file
		const pdfBuffer = fs.readFileSync(pdfPath);

		// Load PDF document
		const loadingTask = pdfjsLib.getDocument({
			data: new Uint8Array(pdfBuffer),
			useSystemFonts: true,
		});
		const pdfDocument = await loadingTask.promise;

		const numPages = Math.min(pdfDocument.numPages, maxPages > 0 ? maxPages : pdfDocument.numPages);
		const results = [];

		// Create shared Tesseract worker for better performance
		const worker = await Tesseract.createWorker(language, 1, {
			logger: () => {}, // Suppress logs
		});

		await worker.setParameters({
			tessedit_pageseg_mode: 3, // Auto page segmentation
		});

		try {
			// Process each page
			for (let pageNum = 1; pageNum <= numPages; pageNum++) {
				const page = await pdfDocument.getPage(pageNum);
				const viewport = page.getViewport({ scale });

				// Create canvas
				const canvasNode = canvas.createCanvas(viewport.width, viewport.height);
				const context = canvasNode.getContext('2d');

				// Render PDF page to canvas
				await page.render({
					canvasContext: context,
					viewport: viewport,
				}).promise;

				// Get image buffer from canvas
				const imageBuffer = canvasNode.toBuffer('image/png');

				// Preprocess image
				const preprocessed = await preprocessImage(imageBuffer, options);

				// Perform OCR
				const result = await worker.recognize(preprocessed);

				results.push({
					page: pageNum,
					text: result.data.text,
					confidence: result.data.confidence,
				});
			}
		} finally {
			// Clean up worker even if error occurs
			await worker.terminate();
		}

		// Combine all page texts
		const allText = results.map(r => `--- Page ${r.page} ---\n${r.text}`).join('\n\n');
		const avgConfidence = results.length > 0 ? results.reduce((sum, r) => sum + r.confidence, 0) / results.length : 0;

		return {
			text: allText,
			pages: numPages,
			confidence: avgConfidence,
			perPage: results,
		};
	} catch (error) {
		throw new Error(`PDF OCR failed: ${error.message}`);
	}
}

/**
 * Check if PDF has readable text (not scanned).
 * 
 * @param {string} pdfPath - Path to PDF file
 * @returns {Promise<boolean>} True if PDF appears to be scanned
 */
async function isScannedPdf(pdfPath) {
	try {
		// Use pdf-parse from bundled vendor directory
		const vendorPath = path.join(__dirname, '..', 'assets', 'vendor');
		let pdfParse;
		try {
			pdfParse = require(path.join(vendorPath, 'pdf-parse'));
		} catch (e) {
			// Fallback to node_modules
			pdfParse = require('pdf-parse');
		}
		
		const dataBuffer = fs.readFileSync(pdfPath);
		const pdfData = await pdfParse(dataBuffer, { max: 1 }); // Just check first page

		// Remove whitespace and count characters
		const cleanText = pdfData.text.trim().replace(/\s+/g, '');

		// If less than 50 characters, likely scanned
		return cleanText.length < 50;
	} catch (error) {
		// If can't parse, assume scanned
		return true;
	}
}

// CLI interface
if (require.main === module) {
	const action = process.argv[2];
	const dataJson = process.argv[3];

	if (!action || !dataJson) {
		console.log(JSON.stringify({
			error: 'Invalid usage',
			usage: 'node ocr-service.js <action> <json-data>',
			actions: ['image', 'pdf', 'check-scanned'],
			example: 'node ocr-service.js image \'{"path":"/path/to/image.jpg","language":"eng"}\''
		}));
		process.exit(1);
	}

	(async () => {
		try {
			let data;
			try {
				data = JSON.parse(dataJson);
			} catch (parseError) {
				throw new Error('Invalid JSON data: ' + parseError.message);
			}

			switch (action) {
				case 'image':
					if (!data.path) {
						throw new Error('path is required for image action');
					}
					const imageResult = await extractTextFromImage(data.path, data);
					console.log(JSON.stringify(imageResult));
					break;

				case 'pdf':
					if (!data.path) {
						throw new Error('path is required for pdf action');
					}
					const pdfResult = await extractTextFromPdf(data.path, data);
					console.log(JSON.stringify(pdfResult));
					break;

				case 'check-scanned':
					if (!data.path) {
						throw new Error('path is required for check-scanned action');
					}
					const isScanned = await isScannedPdf(data.path);
					console.log(JSON.stringify({ isScanned }));
					break;

				default:
					throw new Error(`Unknown action: ${action}. Valid actions: image, pdf, check-scanned`);
			}
			process.exit(0);
		} catch (error) {
			// Output error in JSON format to stdout (not stderr) for easier parsing
			// Note: Stack traces are sanitized in production to avoid exposing server paths
			const errorResponse = { 
				error: error.message,
				action: action
			};
			
			// Only include stack trace in development/debug mode
			if (process.env.NODE_ENV === 'development' || process.env.DEBUG) {
				errorResponse.stack = error.stack;
			}
			
			console.log(JSON.stringify(errorResponse));
			process.exit(1);
		}
	})();
}

module.exports = {
	extractTextFromImage,
	extractTextFromPdf,
	isScannedPdf,
	preprocessImage,
};
