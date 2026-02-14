#!/usr/bin/env node
/**
 * Image Preprocessing Service for OCR
 * 
 * Uses Sharp library to preprocess images for better OCR results.
 * Applies: resizing, grayscale, normalization, sharpening, and noise reduction.
 */
const fs = require('fs');
const path = require('path');

// Load sharp from bundled vendor or node_modules
let sharp;
try {
	// Try bundled version first
	const vendorPath = path.join(__dirname, '..', 'assets', 'vendor');
	try {
		sharp = require(path.join(vendorPath, 'sharp', 'lib'));
	} catch (e) {
		sharp = require('sharp'); // Fallback to node_modules
	}
} catch (error) {
	console.log(JSON.stringify({ 
		error: 'Sharp library not available. Image preprocessing disabled.',
		details: error.message
	}));
	process.exit(1);
}

/**
 * Preprocess image for OCR.
 * 
 * @param {object} options - Preprocessing options
 * @param {string} options.input - Input image path
 * @param {string} options.output - Output image path
 * @param {number} options.maxWidth - Maximum width (default: 2048)
 * @param {number} options.maxHeight - Maximum height (default: 2048)
 * @param {boolean} options.grayscale - Convert to grayscale (default: true)
 * @param {boolean} options.normalize - Normalize contrast (default: true)
 * @param {boolean} options.sharpen - Apply sharpening (default: true)
 * @returns {Promise<object>} Processing result
 */
async function preprocessImage(options) {
	try {
		const {
			input,
			output,
			maxWidth = 2048,
			maxHeight = 2048,
			grayscale = true,
			normalize = true,
			sharpen = true,
		} = options;

		if (!input || !output) {
			throw new Error('Both input and output paths are required');
		}

		if (!fs.existsSync(input)) {
			throw new Error(`Input file not found: ${input}`);
		}

		// Start Sharp pipeline
		let pipeline = sharp(input);

		// Get image metadata
		const metadata = await pipeline.metadata();

		// Resize if needed
		if (metadata.width > maxWidth || metadata.height > maxHeight) {
			pipeline = pipeline.resize(maxWidth, maxHeight, {
				fit: 'inside',
				withoutEnlargement: true,
			});
		}

		// Convert to grayscale
		if (grayscale) {
			pipeline = pipeline.grayscale();
		}

		// Normalize (auto-contrast)
		if (normalize) {
			pipeline = pipeline.normalize();
		}

		// Sharpen for better OCR
		if (sharpen) {
			// Sharp sharpening parameters optimized for OCR text recognition:
			// - sigma: Amount of blur to apply before sharpening (reduces noise)
			// - m1: Sharpening multiplier for detected edges
			// - m2: Flat area slope - how much to sharpen areas without edges
			// - x1: Minimum luminance difference to be considered an edge
			// - y2: Luminance threshold for flat areas (edges below this aren't sharpened)
			// - y3: Maximum luminance change (prevents over-sharpening/jagged edges)
			pipeline = pipeline.sharpen({
				sigma: 1,      // Slight blur to reduce noise before sharpening
				m1: 1,         // Edge sharpening amount (1.0 = normal strength)
				m2: 0.5,       // Flat area sharpening (0.5 = moderate)
				x1: 2,         // Min edge detection threshold
				y2: 10,        // Flat area threshold
				y3: 20,        // Max sharpening to prevent jaggies
			});
		}

		// Apply gamma correction for better contrast
		pipeline = pipeline.gamma(1.2);

		// Output as PNG for best OCR quality
		pipeline = pipeline.png({
			compressionLevel: 6,
			adaptiveFiltering: true,
		});

		// Save to output file
		await pipeline.toFile(output);

		return {
			success: true,
			input: input,
			output: output,
			originalSize: {
				width: metadata.width,
				height: metadata.height,
			},
		};
	} catch (error) {
		throw new Error(`Image preprocessing failed: ${error.message}`);
	}
}

/**
 * Convert PDF page to image using Sharp (for testing).
 * Note: Sharp doesn't natively support PDF. This is a placeholder.
 * 
 * @param {object} options - Conversion options
 * @returns {Promise<object>} Conversion result
 */
async function convertPdfPage(options) {
	// Sharp doesn't support PDF input directly
	// This would require ImageMagick or pdf2image
	throw new Error('PDF conversion not supported by Sharp. Use Imagick in PHP instead.');
}

// CLI interface
if (require.main === module) {
	const action = process.argv[2];
	const dataJson = process.argv[3];
	
	if (!action || !dataJson) {
		console.log(JSON.stringify({
			error: 'Invalid usage',
			usage: 'node image-preprocess-service.js <action> <json-data>',
			actions: ['preprocess'],
			example: 'node image-preprocess-service.js preprocess \'{"input":"/path/to/image.jpg","output":"/path/to/output.png"}\''
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
				case 'preprocess':
					const result = await preprocessImage(data);
					console.log(JSON.stringify(result));
					break;
					
				default:
					throw new Error(`Unknown action: ${action}. Valid actions: preprocess`);
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

module.exports = { preprocessImage };
