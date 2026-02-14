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

try {
	// Try to load from bundled vendor directory first
	const vendorPath = path.join(__dirname, '..', 'assets', 'vendor');
	
	// Load Tesseract.js
	try {
		Tesseract = require(path.join(vendorPath, 'tesseract.js', 'src'));
	} catch (e) {
		Tesseract = require('tesseract.js'); // Fallback to node_modules
	}
	
	// Load Sharp
	try {
		sharp = require(path.join(vendorPath, 'sharp', 'lib'));
	} catch (e) {
		sharp = require('sharp'); // Fallback to node_modules
	}
	
	// Load Canvas
	try {
		canvas = require(path.join(vendorPath, 'canvas'));
	} catch (e) {
		canvas = require('canvas'); // Fallback to node_modules
	}
} catch (error) {
	console.error(JSON.stringify({
		error: 'Required packages not found. Run: npm install tesseract.js sharp pdfjs-dist canvas'
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
	const {
		maxWidth = 2048,
		maxHeight = 2048,
		enhance = true,
	} = options;

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
			sigma: 1,
			m1: 1,
			m2: 0.5,
			x1: 2,
			y2: 10,
			y3: 20,
		});

		// Gamma correction
		pipeline = pipeline.gamma(1.2);
	}

	// Convert to PNG for best quality
	pipeline = pipeline.png({ compressionLevel: 6 });

	return await pipeline.toBuffer();
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

		// Load PDF.js (lazy load to avoid issues if not needed)
		if (!pdfjsLib) {
			try {
				// Try bundled version first
				const vendorPath = path.join(__dirname, '..', 'assets', 'vendor');
				pdfjsLib = require(path.join(vendorPath, 'pdfjs-dist', 'legacy', 'build', 'pdf.js'));
			} catch (e) {
				try {
					// Fallback to node_modules
					pdfjsLib = require('pdfjs-dist/legacy/build/pdf.js');
				} catch (error) {
					throw new Error('pdfjs-dist not installed. Run: npm install pdfjs-dist');
				}
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

		// Clean up
		await worker.terminate();

		// Combine all page texts
		const allText = results.map(r => `--- Page ${r.page} ---\n${r.text}`).join('\n\n');
		const avgConfidence = results.reduce((sum, r) => sum + r.confidence, 0) / results.length;

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
		console.error('Usage: node ocr-service.js <action> <json-data>');
		console.error('Actions: image, pdf, check-scanned');
		console.error('Example: node ocr-service.js image \'{"path":"/path/to/image.jpg","language":"eng"}\'');
		process.exit(1);
	}

	(async () => {
		try {
			const data = JSON.parse(dataJson);

			switch (action) {
				case 'image':
					if (!data.path) {
						throw new Error('path is required');
					}
					const imageResult = await extractTextFromImage(data.path, data);
					console.log(JSON.stringify(imageResult));
					break;

				case 'pdf':
					if (!data.path) {
						throw new Error('path is required');
					}
					const pdfResult = await extractTextFromPdf(data.path, data);
					console.log(JSON.stringify(pdfResult));
					break;

				case 'check-scanned':
					if (!data.path) {
						throw new Error('path is required');
					}
					const isScanned = await isScannedPdf(data.path);
					console.log(JSON.stringify({ isScanned }));
					break;

				default:
					console.error(JSON.stringify({ error: `Unknown action: ${action}` }));
					process.exit(1);
			}
		} catch (error) {
			console.error(JSON.stringify({ error: error.message }));
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
