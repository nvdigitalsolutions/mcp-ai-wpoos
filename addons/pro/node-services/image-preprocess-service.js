#!/usr/bin/env node
/**
 * Image Preprocessing Service for OCR
 * 
 * Uses Sharp library to preprocess images for better OCR results.
 * Applies: resizing, grayscale, normalization, sharpening, and noise reduction.
 */
const fs = require('fs');
const path = require('path');

// Load sharp from node_modules
let sharp;
try {
	sharp = require('sharp');
} catch (error) {
	console.error(JSON.stringify({ 
		error: 'Sharp library not found. Run: npm install sharp' 
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
		console.error('Usage: node image-preprocess-service.js <action> <json-data>');
		console.error('Actions: preprocess');
		console.error('Example: node image-preprocess-service.js preprocess \'{"input":"/path/to/image.jpg","output":"/path/to/output.png"}\'');
		process.exit(1);
	}
	
	(async () => {
		try {
			const data = JSON.parse(dataJson);
			
			switch (action) {
				case 'preprocess':
					const result = await preprocessImage(data);
					console.log(JSON.stringify(result));
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

module.exports = { preprocessImage };
